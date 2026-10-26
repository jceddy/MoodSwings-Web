// The iOS Keychain, for the one secret key that protects the saved session (see IosKeychainKeyStore.cs).
// Two plain C functions, called through P/Invoke ("__Internal"): read an item and write an item, both generic
// passwords found by service + account.

#import <Foundation/Foundation.h>
#import <Security/Security.h>

static NSMutableDictionary *MoodKeychainQuery(const char *service, const char *account)
{
    NSMutableDictionary *query = [NSMutableDictionary dictionary];
    query[(__bridge id)kSecClass] = (__bridge id)kSecClassGenericPassword;
    query[(__bridge id)kSecAttrService] = [NSString stringWithUTF8String:service];
    query[(__bridge id)kSecAttrAccount] = [NSString stringWithUTF8String:account];
    return query;
}

extern "C" {

// Copies the stored value into `buffer` (up to `capacity` bytes) and returns how many bytes it is.
// 0 means there is no such item; -1 means the Keychain could not be read (or the value does not fit).
int MoodKeychainGet(const char *service, const char *account, unsigned char *buffer, int capacity)
{
    NSMutableDictionary *query = MoodKeychainQuery(service, account);
    query[(__bridge id)kSecReturnData] = (__bridge id)kCFBooleanTrue;
    query[(__bridge id)kSecMatchLimit] = (__bridge id)kSecMatchLimitOne;

    CFTypeRef result = NULL;
    OSStatus status = SecItemCopyMatching((__bridge CFDictionaryRef)query, &result);
    if (status == errSecItemNotFound) {
        return 0;
    }
    if (status != errSecSuccess || result == NULL) {
        return -1;
    }

    NSData *data = (__bridge_transfer NSData *)result;
    if ((int)data.length > capacity) {
        return -1;
    }
    memcpy(buffer, data.bytes, data.length);
    return (int)data.length;
}

// Stores `length` bytes, replacing any value already there. Returns 1 if it was stored, 0 if not.
// Readable once the device has been unlocked after a restart, and never copied to another device or into a backup.
int MoodKeychainSet(const char *service, const char *account, const unsigned char *data, int length)
{
    NSMutableDictionary *query = MoodKeychainQuery(service, account);
    SecItemDelete((__bridge CFDictionaryRef)query);

    query[(__bridge id)kSecValueData] = [NSData dataWithBytes:data length:(NSUInteger)length];
    query[(__bridge id)kSecAttrAccessible] = (__bridge id)kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly;
    return SecItemAdd((__bridge CFDictionaryRef)query, NULL) == errSecSuccess ? 1 : 0;
}

}

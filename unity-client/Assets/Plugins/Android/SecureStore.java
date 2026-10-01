package com.moodswings;

import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;

import java.security.KeyStore;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

/**
 * AES-256-GCM with a non-exportable key held in the Android Keystore.
 * Blob layout: 12-byte IV, then ciphertext+tag. Called from
 * AndroidKeystoreSecretProtector.cs. Needs API 23+ for KeyGenParameterSpec;
 * the project's minSdk is higher. Exercised by AndroidKeystoreTests.
 */
public final class SecureStore {
    private static final String PROVIDER = "AndroidKeyStore";
    private static final String ALIAS = "moodswings.session.v1";
    private static final String TRANSFORMATION = "AES/GCM/NoPadding";
    private static final int IV_LENGTH = 12;
    private static final int TAG_BITS = 128;

    private SecureStore() {
    }

    private static SecretKey key() throws Exception {
        KeyStore keyStore = KeyStore.getInstance(PROVIDER);
        keyStore.load(null);

        if (!keyStore.containsAlias(ALIAS)) {
            KeyGenerator generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, PROVIDER);
            generator.init(new KeyGenParameterSpec.Builder(
                    ALIAS, KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT)
                    .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                    .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                    .setKeySize(256)
                    .build());
            generator.generateKey();
        }

        return (SecretKey) keyStore.getKey(ALIAS, null);
    }

    public static byte[] encrypt(byte[] plain) throws Exception {
        Cipher cipher = Cipher.getInstance(TRANSFORMATION);
        cipher.init(Cipher.ENCRYPT_MODE, key());
        byte[] iv = cipher.getIV();
        byte[] encrypted = cipher.doFinal(plain);

        byte[] blob = new byte[iv.length + encrypted.length];
        System.arraycopy(iv, 0, blob, 0, iv.length);
        System.arraycopy(encrypted, 0, blob, iv.length, encrypted.length);
        return blob;
    }

    public static byte[] decrypt(byte[] blob) throws Exception {
        if (blob == null || blob.length <= IV_LENGTH) {
            throw new IllegalArgumentException("blob too short");
        }

        Cipher cipher = Cipher.getInstance(TRANSFORMATION);
        cipher.init(Cipher.DECRYPT_MODE, key(), new GCMParameterSpec(TAG_BITS, blob, 0, IV_LENGTH));
        return cipher.doFinal(blob, IV_LENGTH, blob.length - IV_LENGTH);
    }
}

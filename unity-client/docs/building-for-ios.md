# Building the MOOD iOS client (on a Mac, onto an iPhone or iPad over USB)

This is how to turn the `unity-client` project into an app on your own iPhone or iPad, using a Mac, Xcode and a
USB cable. It needs no App Store account and no paid Apple membership.

> **Status.** The iOS support in the project (the build script, the Keychain plugin, the Xcode project settings) has been
> written and compiles in the Unity Editor, but **the iOS build itself has not been run yet**: the machine the client is
> normally built on is Windows, which can't build for iOS. The steps below follow Unity's and Apple's documented workflow;
> if a step doesn't match what you see, the [Troubleshooting](#troubleshooting) section covers the usual causes, and the
> first real build is the test of everything here.

**On this page**

1. [What you need](#1-what-you-need)
2. [Set up the Mac (once)](#2-set-up-the-mac-once)
3. [Get the code and the card art](#3-get-the-code-and-the-card-art)
4. [Make the Xcode project](#4-make-the-xcode-project)
5. [Sign the app in Xcode](#5-sign-the-app-in-xcode)
6. [Get the iPhone or iPad ready](#6-get-the-iphone-or-ipad-ready)
7. [Build, install and run](#7-build-install-and-run)
8. [Updating later](#8-updating-later)
9. [From the command line](#9-from-the-command-line)
10. [What is different on iOS](#10-what-is-different-on-ios)
11. [Troubleshooting](#troubleshooting)
12. [Beyond a cable: TestFlight and the App Store](#beyond-a-cable-testflight-and-the-app-store)

---

## 1. What you need

| Thing | Notes |
| --- | --- |
| A **Mac** | Intel or Apple silicon, running a macOS that your Xcode and the Unity Editor both support (check the *Unity 6.6 system requirements* page and Xcode's own in the App Store). |
| **Xcode** | Free from the Mac App Store; it is large (several GB) and takes a while to download. |
| **Unity Hub** and **Unity 6000.6.3f1** | The exact version the project uses, installed from the Hub **with the "iOS Build Support" module ticked**. |
| An **Apple ID** | A free one works. You sign in to Xcode with it; it becomes your "Personal Team". |
| An **iPhone or iPad** on **iOS 15 or later** | Plus a USB cable that carries data (a charge-only cable won't be seen). |
| **Git** and **Python 3** | Git and Python 3 both come with Xcode's command line tools (`xcode-select --install`). |

> **Free account limits.** An app signed with a free Personal Team stops launching **7 days** after it was installed;
> you reinstall it from Xcode (step 7) and it works for another 7 days. A paid
> [Apple Developer Program](https://developer.apple.com/programs/) membership (about US$99 a year) makes it last a year
> and also allows TestFlight. A free account can also have only a few apps installed at once, and its bundle
> identifiers must be unique across all of Apple (see [Troubleshooting](#troubleshooting)).

## 2. Set up the Mac (once)

1. **Install Xcode** from the Mac App Store, then **open it once** and accept the licence and the extra components it
   offers to install. In **Xcode ▸ Settings ▸ Components** (called *Platforms* in newer versions) make sure the
   **iOS** platform is installed.
2. **Point the command line at it**, if you have several Xcodes or only the command line tools:

   ```bash
   sudo xcode-select -s /Applications/Xcode.app/Contents/Developer
   xcodebuild -version
   ```

3. **Sign in to Xcode with your Apple ID**: **Xcode ▸ Settings ▸ Accounts ▸ +** ▸ *Apple ID*. Your name then appears
   with a team called **(Personal Team)**.
4. **Install Unity Hub**, then in it **Installs ▸ Install Editor ▸ Archive** and pick **6000.6.3f1** (use the
   *download archive* link if it isn't listed). Tick **iOS Build Support**. You can also add it later with the install's
   gear icon ▸ **Add modules**.
5. **Python and Pillow**, which turn the card art into something Unity can import (a Python virtual environment keeps
   this tidy, and avoids the "externally managed environment" error newer Pythons give for a plain `pip install`):

   ```bash
   python3 -m venv ~/mood-venv
   source ~/mood-venv/bin/activate
   pip install pillow
   ```

   (Run `source ~/mood-venv/bin/activate` again in any new Terminal window before step 3 below.)

## 3. Get the code and the card art

```bash
git clone https://github.com/jceddy/MoodSwings-Web.git
cd MoodSwings-Web
git checkout development        # or the branch you were asked to build
cd unity-client
python tools/convert_card_art.py
```

The last command makes the card images Unity uses (`Assets/Resources/CardArt/`, which is not kept in git) from the art
in `web-static/img` and `unity-client/art-overrides`. It prints a line like `142 images: 142 converted ...`. **Don't
skip it**: without it every card is blank.

## 4. Make the Xcode project

Unity doesn't make the iOS app itself; it makes an **Xcode project**, and Xcode makes the app from that.

1. In Unity Hub, **Add ▸ Add project from disk** and choose the **`unity-client`** folder (not the repository root).
   Open it with **6000.6.3f1**. The first time, Unity imports every asset, which takes **many minutes**.
2. When the Editor is open, choose the menu **MoodSwings ▸ Build iOS Xcode Project**. It switches the project to
   iOS, applies the iOS settings (bundle identifier, iPhone *and* iPad, landscape, iOS 15 minimum, the app icon) and
   writes the Xcode project to **`unity-client/Build/iOS`**. Expect another long wait the first time; watch the progress bar.
3. When the Console says `IosBuild: Succeeded`, you have **`Build/iOS/Unity-iPhone.xcodeproj`**.

**Choosing the signing team and bundle identifier up front (optional).** You can do both in Xcode in the next step, but
the build can also set them. Close Unity, then from a Terminal in `unity-client` run the build headless with these
variables (the Team ID is the 10-character code shown for your team at
[developer.apple.com/account](https://developer.apple.com/account) ▸ *Membership details*, or in Xcode's team picker):

```bash
export MOOD_IOS_TEAM_ID=ABCDE12345                  # your Team ID (optional)
export MOOD_IOS_BUNDLE_ID=com.yourname.moodswings   # only if com.jceddy.moodswings is unavailable to you (optional)
/Applications/Unity/Hub/Editor/6000.6.3f1/Unity.app/Contents/MacOS/Unity \
  -batchmode -quit -projectPath . -buildTarget iOS \
  -executeMethod MoodSwings.Editor.IosBuild.Build -logFile -
```

(A project can only be open in one Unity at a time, so close the Editor first.) The log ends with `IosBuild: Succeeded`.

## 5. Sign the app in Xcode

1. Open the project: `open Build/iOS/Unity-iPhone.xcodeproj` (or double-click it).
2. In the left sidebar click the blue **Unity-iPhone** project, then under **TARGETS** choose **Unity-iPhone**
   (the app, not *UnityFramework* or the tests), then the **Signing & Capabilities** tab.
3. Tick **Automatically manage signing** and, under **Team**, choose your **(Personal Team)**.
4. If Xcode says **"Failed to register bundle identifier"** or the identifier "is not available", the name
   `com.jceddy.moodswings` is already claimed by another Apple account. Change **Bundle Identifier** to one of your own,
   such as `com.yourname.moodswings` (or set `MOOD_IOS_BUNDLE_ID` and redo step 4).

The error banner should go away once a team is chosen. Xcode creates the certificate and provisioning profile itself the
first time it builds for a device.

## 6. Get the iPhone or iPad ready

1. **Plug it in** with the USB cable and **unlock** it. If it asks **Trust This Computer?**, tap **Trust** and enter the
   passcode.
2. **Developer Mode** (iOS 16 and later). The switch only appears after the device has been connected to Xcode once, so
   do this after step 1: **Settings ▸ Privacy & Security ▸ Developer Mode** ▸ on. The device restarts; unlock it and
   confirm **Turn On** when it asks.
3. In Xcode, the device should appear in the **run destination** menu at the top of the window (to the right of the
   *Unity-iPhone* scheme). The first time, Xcode shows *Preparing the device* / *Processing symbol files*, which takes a
   few minutes. If it is not listed, open **Window ▸ Devices and Simulators** and look for it there (see
   [Troubleshooting](#troubleshooting)).

## 7. Build, install and run

1. Choose your device in the run-destination menu.
2. Press **▶ Run** (**⌘R**). Xcode compiles the game (the first build is slow, often 10 minutes or more, because Unity's
   IL2CPP turns all the C# into C++ first), installs it on the device over USB, and starts it.
3. **First launch only — trust yourself.** If the phone says **Untrusted Developer**, go to **Settings ▸ General ▸ VPN &
   Device Management**, tap your Apple ID under *Developer App*, tap **Trust**, then open **MOOD** again.

The app stays installed after you unplug the cable; the 7-day limit above applies to free accounts.

## 8. Updating later

To get a newer version of the game:

```bash
cd MoodSwings-Web
git pull
cd unity-client
python tools/convert_card_art.py     # if the card art changed
```

then repeat [step 4](#4-make-the-xcode-project) (delete nothing by hand: the build replaces `Build/iOS`), open the project
in Xcode and press **▶ Run** again. Installing over the old copy keeps the saved login. The same step renews an app
whose 7 days have run out.

## 9. From the command line

Everything in steps 5–7 can also be done without clicking, once you know your Team ID. These commands are standard
Xcode tools but **have not been run against this project**:

```bash
cd Build/iOS

# What devices can I see?  (the Identifier column is the device id)
xcrun devicectl list devices

# Build for a device, signing with your team.
xcodebuild -project Unity-iPhone.xcodeproj -scheme Unity-iPhone -configuration Release \
  -destination 'generic/platform=iOS' -derivedDataPath ../iOS-derived \
  -allowProvisioningUpdates DEVELOPMENT_TEAM=ABCDE12345 build

# Install and start it on the connected device.
xcrun devicectl device install app --device <device id> ../iOS-derived/Build/Products/Release-iphoneos/MOOD.app
xcrun devicectl device process launch --device <device id> com.jceddy.moodswings
```

(`devicectl` needs Xcode 15 or later. Use your own bundle identifier in the last line if you changed it.)

## 10. What is different on iOS

It is the same client, in the same landscape layout (it respects the notch and the home bar), with these differences:

| | iOS |
| --- | --- |
| **Saved login** | Kept, protected by a key in the app's iOS Keychain (`Assets/Plugins/iOS/MoodKeychain.mm`, `IosKeychainKeyStore`). It isn't copied to a new device or into a backup. |
| **In-app updates** | None, and Settings has no updates section. Updating means rebuilding and reinstalling from Xcode (step 8). |
| **Push notifications** | None yet. They need Apple's push service (APNs) and a paid membership, and the server doesn't send them to iOS devices. |
| **Adding art packs** | Not offered yet (there is no file picker for the iOS Files app). The art that comes with the app is used. |
| **Vibration** | Not offered yet (the short buzzes for your turn are Android-only), so Settings has no Vibration switch. |
| **Quit** | There is no Quit entry: iOS apps are left with the home gesture. |
| **Leaving the app** | Like any phone app, the game is paused when you leave it, so its music stops. |

## Troubleshooting

**Unity says the iOS module isn't installed** (*"iOS Build Support not installed"*). In Unity Hub, **Installs ▸ 6000.6.3f1 ▸
⚙ ▸ Add modules ▸ iOS Build Support**, then reopen the project.

**All the cards are blank / grey.** The card art wasn't generated: run `python tools/convert_card_art.py` (step 3),
then make the Xcode project again.

**Xcode: "No profiles for 'com.…' were found" / "Signing requires a development team".** In **Signing & Capabilities**
tick *Automatically manage signing* and choose a team (step 5). Make sure you are signed in under **Xcode ▸ Settings ▸ Accounts**.

**Xcode: "Failed to register bundle identifier".** Someone else's Apple account has the identifier; pick your own (step 5.4).

**The device isn't listed as a run destination.** Unlock it; replug the cable (try another, data-capable one and a port on
the Mac itself, not a hub); accept **Trust This Computer** on the device; look under **Window ▸ Devices and Simulators**
and, if it shows a warning, follow it. Restarting both the Mac and the device fixes a surprising number of these.

**"Developer Mode disabled" when running.** Turn it on (step 6.2). If the switch isn't in Settings yet, connect the device
to Xcode and try to run once; it appears after that.

**"Unable to verify app" / "Untrusted Developer" on the device.** Trust your Apple ID under **Settings ▸ General ▸ VPN & Device
Management** (step 7.3). The device needs internet access for the first trust.

**The app opens, then stops opening after about a week.** That is the free-account limit; run it from Xcode again (step 8).

**Build errors that mention the Unity-generated code** (for example *linker* or *duplicate symbol* errors, or "file not
found" in `Libraries/`): they usually come from a half-finished earlier build. Delete `Build/iOS`, make the project again
(step 4) and rebuild. If it still fails, send the first red error from Xcode's **Report navigator** (⌘9).

**A Windows-made Xcode project fails with "Permission denied" on `MapFileParser.sh`.** Unity on Windows can't set the
"executable" flag on that file. Build the Xcode project on the Mac instead; or, if the project was copied over,
run `chmod +x Build/iOS/MapFileParser.sh` and `chmod -R +x Build/iOS/Il2CppOutputProject` once.

## Beyond a cable: TestFlight and the App Store

With a paid Apple Developer Program membership you can put builds on **TestFlight** (up to 10,000 testers, no cable and
no 7-day limit) and eventually the **App Store**. The steps are Apple's own: in Xcode choose **Product ▸ Archive** and
then **Distribute App ▸ TestFlight & App Store**, after creating the app in
[App Store Connect](https://appstoreconnect.apple.com). The project already sets what those uploads usually ask about: the
export-compliance answer is stored in the app (`ITSAppUsesNonExemptEncryption = NO`, from `Assets/Editor/IosPostProcess.cs`),
and each upload needs a higher build number than the last, which you give the build with `MOOD_IOS_BUILD_NUMBER` (step 4).
The App Store also wants a 1024 × 1024 icon **without transparency**, which the current icon may not be.

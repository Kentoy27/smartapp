# SmartApp Android app

This native Android app opens the existing SmartApp service at
`https://smartapp.sdokab.com/smartapp/home`. It uses the same accounts,
permissions, and backend as the web app. Document uploads use Android's
system file picker, and downloads are saved to the device's Downloads folder.
A branded startup screen remains visible while the first page loads.
The photo capture action requests camera permission only when needed. The
system picker grants access only to documents the user selects; the app does
not request broad access to device storage.

## Build

Install JDK 17 and Android SDK Platform 35 / Build Tools 35.0.0, then run:

```powershell
.\gradlew.bat assembleDebug
```

The installable APK is written to
`app\build\outputs\apk\debug\app-debug.apk`. Copy the APK intended for
distribution to `public\downloads\smartapp-android.apk` so the profile menu can
serve it. Sideloaded updates must use the same signing key as the previously
installed APK. Debug APKs use Android's debug key and are intended for direct
installation, not Play Store publishing.

## Required updates

The Android app checks the server's required version before opening SmartApp.
If its installed version code is lower, it blocks app access and opens the
website's update page. To publish an update:

1. Increase `versionCode` and `versionName` in `app\build.gradle`.
2. Build and sign the APK with the same signing key as the installed app, then
   replace `public\downloads\smartapp-android.apk`.
3. Set `ANDROID_APP_VERSION_CODE` and `ANDROID_APP_VERSION_NAME` to match the
   APK in the server environment and run the database migrations.

The update page creates a random download URL whose token is stored hashed.
The link supports retries for 15 minutes, after which it expires. The public
website/PWA is not blocked by the Android version policy.

The Android installer is independent of the Progressive Web App install flow;
both options remain available in the profile's Download App modal.

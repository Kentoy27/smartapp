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

The Android installer is independent of the Progressive Web App install flow;
both options remain available in the profile's Download App modal.

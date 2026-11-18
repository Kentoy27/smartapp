# SmartApp Android app

This native Android app opens the existing SmartApp service at
`https://smartapp.sdokab.com/smartapp/home`. It uses the same accounts,
permissions, and backend as the web app. Android file uploads use the system
file picker, and downloads are saved to the device's Downloads folder. A
branded startup screen remains visible while the first page loads.

## Build

Install JDK 17 and Android SDK Platform 35 / Build Tools 35.0.0, then run:

```powershell
.\gradlew.bat assembleDebug
```

The installable APK is written to
`app\build\outputs\apk\debug\app-debug.apk`. Copy the release APK to
`public\downloads\smartapp-android.apk` so the profile menu can distribute it.
Debug APKs are signed with Android's debug key and are intended for direct
installation, not Play Store publishing.

The Android installer is independent of the Progressive Web App install flow;
both options remain available in the profile's Download App modal.

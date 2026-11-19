<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Update SmartApp</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #172554; background: #eff6ff; }
        body { box-sizing: border-box; display: grid; min-height: 100vh; margin: 0; padding: 24px; place-items: center; }
        main { box-sizing: border-box; width: min(100%, 480px); padding: 32px; border-radius: 20px; background: #fff; box-shadow: 0 16px 48px #1e3a8a1a; }
        h1 { margin: 0 0 12px; font-size: 1.7rem; }
        p { color: #475569; line-height: 1.6; }
        .version { color: #1d4ed8; font-weight: 700; }
        a { display: block; margin-top: 24px; padding: 14px 18px; border-radius: 10px; background: #2563eb; color: #fff; font-weight: 700; text-align: center; text-decoration: none; }
        .note { margin-bottom: 0; font-size: .9rem; }
    </style>
</head>
<body>
<main>
    <h1>Get SmartApp for Android</h1>
    <p>Download the latest SmartApp installer. If you are updating, install the APK and open SmartApp again.</p>
    <p class="version">Version {{ $versionName }}</p>
    @if ($downloadUrl)
        <a href="{{ $downloadUrl }}">Download the update</a>
        <p class="note">This private download link can be retried for {{ $expiresInMinutes }} minutes, then it expires. After installing, open SmartApp again.</p>
    @else
        <p class="note" role="status">The Android installer is not available yet. Please try again later.</p>
    @endif
</main>
</body>
</html>

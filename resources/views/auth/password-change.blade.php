<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f1f5f9">
    <title>Change your password — SmartApp</title>
    <link rel="stylesheet" href="{{ public_asset('css/dashboard.css') }}?v=68">
    <style>
        body { display: grid; min-height: 100vh; place-items: center; padding: 20px; }
        .password-change-card { width: min(100%, 440px); padding: 28px; border: 1px solid var(--border); border-radius: 14px; background: var(--bg-panel); box-shadow: var(--shadow-card); }
        .password-change-card h1 { margin-bottom: 8px; color: var(--text-strong); font-size: 1.4rem; }
        .password-change-card p { margin-bottom: 18px; color: var(--text-muted); font-size: 0.9rem; line-height: 1.55; }
        .password-change-card .field { display: grid; gap: 6px; margin-bottom: 14px; }
        .password-change-card label { color: var(--text-strong); font-size: 0.85rem; font-weight: 600; }
        .password-change-card input { width: 100%; min-height: 42px; padding: 9px 11px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg-input); color: var(--text); font: inherit; }
        .password-change-card input:focus { outline: 2px solid var(--primary); outline-offset: -1px; }
        .password-change-card .password-guidance { margin: 0 0 18px; font-size: 0.8rem; }
        .password-change-card button { width: 100%; min-height: 42px; }
        .password-change-error { margin: 6px 0 0; color: var(--danger); font-size: 0.8rem; }
        @media (max-width: 480px) { .password-change-card { padding: 20px; } }
    </style>
</head>
<body>
    <main class="password-change-card">
        <h1>Choose a new password</h1>
        <p>
            For your security, you need to replace the temporary password before you can use SmartApp.
            @if ($canUseGoogleSignIn)
                You signed in with your linked Google account, so you can set a new password below.
            @else
                Enter the temporary password your administrator gave you.
            @endif
        </p>

        <form method="POST" action="{{ route('password.change.update') }}">
            @csrf
            @unless ($canUseGoogleSignIn)
                <div class="field">
                    <label for="current_password">Temporary password</label>
                    <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                    @error('current_password') <span class="password-change-error">{{ $message }}</span> @enderror
                </div>
            @endunless

            <div class="field">
                <label for="password">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>
                @error('password') <span class="password-change-error">{{ $message }}</span> @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            </div>

            <p class="password-guidance">Use at least 12 characters with uppercase and lowercase letters, a number, and a symbol.</p>
            <button type="submit" class="btn-primary">Save new password</button>
        </form>

        <form method="POST" action="{{ route('logout') }}" style="margin-top: 12px">
            @csrf
            <button type="submit" class="btn-ghost">Sign out</button>
        </form>
    </main>
</body>
</html>

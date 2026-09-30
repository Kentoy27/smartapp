<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f1f5f9">
    <link rel="icon" type="image/svg+xml" href="{{ public_asset('smap.svg') }}?v=2">
    <link rel="shortcut icon" href="{{ public_asset('smap.svg') }}?v=2">
    <link rel="stylesheet" href="{{ public_asset('css/login.css') }}?v=1">
    <title>Login — SmartApp</title>
    <script>
        /* Apply the saved theme before first paint to avoid a flash.
           Reads localStorage with a cookie fallback (private mode and
           partitioned iframes can block localStorage). Default: light. */
        (function () {
            function readStoredTheme() {
                var theme = 'light';
                try {
                    var saved = localStorage.getItem('smartapp-theme');
                    if (saved === 'dark' || saved === 'light') return saved;
                } catch (e) {}
                try {
                    var match = document.cookie.match(/(?:^|;\s*)smartapp-theme=([^;]+)/);
                    if (match && (match[1] === 'dark' || match[1] === 'light')) return match[1];
                } catch (e) {}
                return theme;
            }

            var theme = readStoredTheme();
            document.documentElement.setAttribute('data-theme', theme);
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.setAttribute('content', theme === 'dark' ? '#0f172a' : '#f1f5f9');
        })();
    </script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        /* Kill the double-tap-zoom delay for every control on touch devices */
        button, input {
            touch-action: manipulation;
        }

        /* ---------- THEME VARIABLES ---------- */
        :root {
            color-scheme: light;
            --bg: #f1f5f9;
            --bg-panel: #ffffff;
            --bg-input: #ffffff;
            --text: #1e293b;
            --text-strong: #0f172a;
            --text-muted: #64748b;
            --text-faint: #94a3b8;
            --border: #e2e8f0;
            --shadow-card: 0 20px 60px rgba(0, 0, 0, 0.12);
            --primary: #3b82f6;
            --primary-strong: #2563eb;
            --danger: #dc2626;
            --danger-text: #f87171;
            --accent-soft: rgba(59, 130, 246, 0.1);
            --card-highlight: rgba(255, 255, 255, 0.8);
        }

        [data-theme="dark"] {
            color-scheme: dark;
            --bg: #0f172a;
            --bg-panel: #1e293b;
            --bg-input: #0f172a;
            --text: #e2e8f0;
            --text-strong: #f8fafc;
            --text-muted: #94a3b8;
            --text-faint: #64748b;
            --border: #334155;
            --shadow-card: 0 20px 60px rgba(0, 0, 0, 0.5);
            --primary: #3b82f6;
            --primary-strong: #2563eb;
            --danger: #dc2626;
            --danger-text: #f87171;
            --accent-soft: rgba(96, 165, 250, 0.12);
            --card-highlight: rgba(255, 255, 255, 0.04);
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background:
                linear-gradient(rgba(59, 130, 246, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(59, 130, 246, 0.035) 1px, transparent 1px),
                var(--bg);
            background-size: 32px 32px;
            color: var(--text);
            min-height: 100vh;
            min-height: 100dvh; /* small-browser UI (address bar) aware */
            display: flex;
            justify-content: center;
            /* Side gutters even when the viewport is narrower than the card */
            padding: 24px 16px;
            /* If the on-screen keyboard shrinks the viewport, let the page
               scroll to reach the button instead of clipping the card */
            overflow-y: auto;
        }

        /* ---------- SHELL: split layout (brand panel + form) ---------- */
        .login-shell {
            position: relative;
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr);
            width: 100%;
            max-width: 980px;
            /* Safe flex centering: centers when there's room, but never clips
               the top on short screens — it top-aligns and scrolls instead */
            margin: auto;
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow-card), 0 0 0 8px var(--card-highlight);
            overflow: hidden;
        }

        .login-shell::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #1d4ed8, #3b82f6 45%, #60a5fa);
            z-index: 1;
        }

        .login-card {
            position: relative;
            background: var(--bg-panel);
            /* The shell now carries the chrome; keep the card bare so a
               cached login.css can't double up borders */
            border: none;
            border-radius: 0;
            box-shadow: none;
            padding: 44px 44px 32px;
            width: 100%;
            max-width: none;
            margin: 0;
        }

        .login-card::before {
            display: none;
        }

        /* ---------- BRAND PANEL ---------- */
        .login-aside {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 34px;
            padding: 44px 40px;
            color: #fff;
            overflow: hidden;
            background:
                radial-gradient(120% 90% at 85% 8%, rgba(255, 255, 255, 0.18), transparent 55%),
                radial-gradient(110% 90% at 8% 100%, rgba(15, 23, 42, 0.35), transparent 60%),
                linear-gradient(160deg, #1d4ed8 0%, #3b82f6 55%, #60a5fa 100%);
        }

        /* Faint grid overlay echoing the app's background pattern */
        .login-aside::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(rgba(255, 255, 255, 0.07) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 32px 32px;
            pointer-events: none;
        }

        .login-aside > * {
            position: relative;
        }

        .aside-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .aside-mark {
            display: grid;
            place-items: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.22);
            color: #fff;
        }

        .aside-wordmark {
            font-size: 1.3rem;
            font-weight: 700;
            color: #fff;
        }

        .aside-wordmark span {
            font-weight: 400;
            color: rgba(255, 255, 255, 0.78);
        }

        .aside-body h2 {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.25;
            letter-spacing: -0.01em;
            margin-bottom: 10px;
        }

        .aside-body > p {
            font-size: 0.9rem;
            line-height: 1.6;
            color: rgba(255, 255, 255, 0.82);
        }

        .aside-points {
            list-style: none;
            margin: 30px 0 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .aside-points li {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .point-icon {
            display: grid;
            place-items: center;
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            border-radius: 9px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: #fff;
        }

        .point-text strong {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: #fff;
        }

        .point-text span {
            display: block;
            margin-top: 2px;
            font-size: 0.8rem;
            line-height: 1.45;
            color: rgba(255, 255, 255, 0.75);
        }

        /* On wide screens the panel carries the brand, so the small
           in-form wordmark would only duplicate it. The extra qualifier
           outranks the base .brand-header rule further down. */
        @media (min-width: 900px) {
            .login-card .brand-header {
                display: none;
            }
        }

        /* ---------- INLINE VALIDATION ---------- */
        .login-error {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            margin-top: 6px;
            color: var(--danger);
            font-size: 0.8125rem;
            font-weight: 500;
            line-height: 1.45;
        }

        [data-theme="dark"] .login-error {
            color: var(--danger-text);
        }

        .input-invalid,
        .input-invalid:focus {
            border-color: var(--danger);
        }

        .input-invalid:focus {
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12);
        }

        .caps-hint {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #b45309;
        }

        .caps-hint::before {
            content: "⚠";
            font-size: 0.85rem;
        }

        .caps-hint[hidden] {
            display: none;
        }

        [data-theme="dark"] .caps-hint {
            color: #fbbf24;
        }

        /* ---------- FAILURE NUDGE ---------- */
        @keyframes login-shake {
            10%, 90% { transform: translateX(-1px); }
            20%, 80% { transform: translateX(2px); }
            30%, 50%, 70% { transform: translateX(-4px); }
            40%, 60% { transform: translateX(4px); }
        }

        .login-shell.shake {
            animation: login-shake 0.5s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
        }

        @media (prefers-reduced-motion: reduce) {
            .login-shell.shake {
                animation: none;
            }
        }

        .brand-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
        }

        .brand-mark {
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            border-radius: 9px;
            background: var(--accent-soft);
            color: var(--primary);
        }

        .brand-name {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: 0;
        }

        .brand-name .brand-blue {
            color: var(--primary);
        }

        .brand-name .brand-black {
            color: var(--text-strong);
        }

        .login-card h1 {
            font-size: 1.65rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-strong);
        }

        .login-card p {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-bottom: 28px;
            line-height: 1.5;
        }

        .field {
            margin-bottom: 18px;
        }

        .field label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            top: 50%;
            left: 12px;
            width: 17px;
            height: 17px;
            color: var(--text-faint);
            transform: translateY(-50%);
            pointer-events: none;
        }

        .field input {
            width: 100%;
            min-height: 46px;
            padding: 10px 12px 10px 40px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-strong);
            /* >= 16px stops iOS Safari auto-zooming the page on focus */
            font-size: 16px;
            font-family: inherit;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
        }

        .field input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .field input::placeholder {
            color: var(--text-faint);
        }

        .field input:hover:not(:focus):not(.input-invalid) {
            border-color: var(--text-faint);
        }

        .input-wrap:focus-within .input-icon {
            color: var(--primary);
        }

        .form-extra {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin: 8px 0 20px;
        }

        .remember-me {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 0.8125rem;
            font-weight: 500;
            cursor: pointer;
            user-select: none;
        }

        .remember-me input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
            cursor: pointer;
            margin: 0;
        }

        .remember-me:hover {
            color: var(--text);
        }

        button.btn {
            width: 100%;
            min-height: 48px;
            padding: 11px 16px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.9375rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            cursor: pointer;
            box-shadow: 0 8px 16px rgba(37, 99, 235, 0.18);
            transition: background 0.2s, transform 0.2s, opacity 0.2s, box-shadow 0.2s;
            position: relative;
            overflow: hidden;
        }

        button.btn:hover {
            background: linear-gradient(135deg, #2f81f7, #1d4ed8);
            box-shadow: 0 12px 22px rgba(37, 99, 235, 0.3);
            transform: translateY(-1px);
        }

        button.btn:active {
            transform: translateY(0) scale(0.98);
        }

        button.btn:focus-visible,
        a.google-btn:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 3px;
        }

        button.btn:disabled {
            cursor: wait;
            opacity: 0.9;
        }

        .btn-content {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }

        .btn-spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: #fff;
            border-radius: 50%;
            display: none;
            animation: spin 0.8s linear infinite;
        }

        button.btn.loading .btn-spinner {
            display: inline-block;
        }

        button.btn.loading .btn-text {
            opacity: 0.95;
        }

        button.btn.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        button.btn.success .btn-spinner {
            display: none;
        }

        button.btn.success .btn-text::before {
            content: "✓";
            margin-right: 8px;
            font-weight: 800;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ---------- DIVIDER + GOOGLE BUTTON ---------- */
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 24px 0 20px;
            color: var(--text-faint);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        a.google-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 11px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-strong);
            font-size: 0.9375rem;
            font-weight: 600;
            font-family: inherit;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s;
            touch-action: manipulation;
        }

        a.google-btn:hover {
            background: var(--bg);
            border-color: var(--text-faint);
        }

        a.google-btn:active {
            transform: scale(0.98);
        }

        a.google-btn svg {
            flex-shrink: 0;
        }

        @media (max-width: 480px) {
            a.google-btn {
                padding: 13px;
            }
        }

        .footer-text {
            text-align: center;
            margin-top: 20px;
            font-size: 0.8125rem;
            color: var(--text-faint);
        }

        /* ---------- RESPONSIVE ---------- */
        /* Below 900px the brand panel steps aside and the form stands alone */
        @media (max-width: 899px) {
            .login-aside {
                display: none;
            }

            .login-shell {
                grid-template-columns: 1fr;
                max-width: 420px;
            }

            .login-card {
                padding: 32px 24px 26px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 32px 14px 24px;
            }

            .login-shell {
                max-width: none;
            }

            .login-card {
                padding: 30px 20px 24px;
            }

            .login-card h1 {
                font-size: 1.3rem;
            }

            .login-card p {
                margin-bottom: 20px;
            }

            /* Comfortable thumb-sized tap targets */
            .field input {
                padding: 12px 14px 12px 40px;
            }

            button.btn {
                padding: 13px;
                font-size: 1rem;
            }

            .theme-toggle {
                top: 10px;
                right: 10px;
                padding: 11px; /* larger touch target */
            }
        }

        /* ---------- THEME TOGGLE ---------- */
        .theme-toggle {
            position: fixed;
            top: 16px;
            right: 16px;
            background: var(--bg-panel);
            border: 1px solid var(--border);
            color: var(--text-faint);
            cursor: pointer;
            padding: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
            transition: color 0.15s, background 0.15s, transform 0.3s ease;
            -webkit-tap-highlight-color: transparent;
            /* Kill the double-tap-zoom delay so every tap fires a click */
            touch-action: manipulation;
            -webkit-user-select: none;
            user-select: none;
        }

        .theme-toggle:hover {
            color: var(--text-strong);
        }

        .theme-toggle:focus {
            outline: none;
        }

        .theme-toggle:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        .theme-toggle .icon-sun {
            display: none;
        }

        .theme-toggle .icon-moon {
            display: flex;
        }

        [data-theme="dark"] .theme-toggle .icon-sun {
            display: flex;
        }

        [data-theme="dark"] .theme-toggle .icon-moon {
            display: none;
        }

        .theme-toggle:active {
            transform: rotate(30deg);
        }

        /* Smooth whole-page color transition while switching themes.
           Applied to <html> for ~400ms by the toggle script. */
        .theme-switching,
        .theme-switching *,
        .theme-switching *::before,
        .theme-switching *::after {
            transition:
                background-color 0.35s ease,
                color 0.35s ease,
                border-color 0.35s ease,
                box-shadow 0.35s ease,
                fill 0.35s ease,
                stroke 0.35s ease !important;
        }

        @media (prefers-reduced-motion: reduce) {
            .theme-switching,
            .theme-switching *,
            .theme-switching *::before,
            .theme-switching *::after {
                transition: none !important;
            }
        }

        /* Pop animation for the freshly-shown icon after a switch */
        @keyframes theme-icon-in {
            from { transform: rotate(-90deg) scale(0.4); opacity: 0; }
            to   { transform: rotate(0deg) scale(1);    opacity: 1; }
        }

        .theme-toggle.spun svg {
            animation: theme-icon-in 0.35s ease;
        }

        /* ---------- PASSWORD FIELD ---------- */
        .password-wrap {
            position: relative;
        }

        /* More specific than `.field input` on purpose: the responsive block
           above bumps the input padding, which would otherwise slide typed
           text underneath the toggle button */
        .field .password-wrap input {
            padding-right: 44px;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 6px;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            background: none;
            border: none;
            border-radius: 6px;
            color: var(--text-faint);
            cursor: pointer;
            transition: color 0.15s;
            -webkit-tap-highlight-color: transparent;
            /* Kill the double-tap-zoom delay so every tap fires a click */
            touch-action: manipulation;
        }

        .password-toggle:hover {
            color: var(--text-strong);
        }

        .password-toggle:focus {
            outline: none;
        }

        .password-toggle:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        /* Eye is shown while the password is hidden; eye-off once revealed */
        .password-toggle .icon-eye {
            display: flex;
        }

        .password-toggle .icon-eye-off {
            display: none;
        }

        .password-toggle.revealed .icon-eye {
            display: none;
        }

        .password-toggle.revealed .icon-eye-off {
            display: flex;
        }

        @media (max-width: 480px) {
            .field .password-wrap input {
                padding-right: 48px;
            }

            .password-toggle {
                right: 8px;
                padding: 10px; /* larger touch target */
            }
        }
    </style>
</head>
<body>
    <button type="button" class="theme-toggle" id="themeToggle" title="Toggle light/dark mode" aria-label="Toggle light/dark mode">
        <x-icon name="sun" :size="18" class="icon-sun" />
        <x-icon name="moon" :size="18" class="icon-moon" />
    </button>

    <div class="login-shell" id="loginShell">
        <aside class="login-aside">
            <div class="aside-brand">
                <span class="aside-mark" aria-hidden="true">
                    <x-icon name="shield" :size="20" />
                </span>
                <span class="aside-wordmark">Smart<span>App</span></span>
            </div>

            <div class="aside-body">
                <h2>Everything your team needs, in one dashboard.</h2>
                <p>Create accounts, assign roles and watch changes land in real time — no reloads, no guesswork.</p>

                <ul class="aside-points">
                    <li>
                        <span class="point-icon" aria-hidden="true"><x-icon name="user-round" :size="16" /></span>
                        <div class="point-text">
                            <strong>Live user management</strong>
                            <span>Add, edit or remove accounts — the table updates instantly.</span>
                        </div>
                    </li>
                    <li>
                        <span class="point-icon" aria-hidden="true"><x-icon name="shield" :size="16" /></span>
                        <div class="point-text">
                            <strong>Role-based access</strong>
                            <span>Super admins decide who manages the system.</span>
                        </div>
                    </li>
                    <li>
                        <span class="point-icon" aria-hidden="true"><x-icon name="lock" :size="16" /></span>
                        <div class="point-text">
                            <strong>Secure by default</strong>
                            <span>Hashed passwords, remembered sessions and Google sign-in.</span>
                        </div>
                    </li>
                </ul>
            </div>
        </aside>

        <div class="login-card">
        <div class="brand-header" aria-label="SmartApp brand">
            <span class="brand-mark" aria-hidden="true">
                <x-icon name="shield" :size="18" />
            </span>
            <div class="brand-name"><span class="brand-blue">Smart</span><span class="brand-black">App</span></div>
        </div>

        <h1>Welcome back</h1>
        <p>Sign in to your SmartApp account</p>

        @php
            $rememberedLogin = old('login', request()->cookie('smartapp_login', ''));
            // "Remember me" is on by default: it is what keeps a long-idle
            // browser signed in (Laravel silently restores the session from
            // the remember cookie instead of bouncing you to this screen).
            // old('remember') is null on a fresh form and '0'/'1' after a
            // failed submit thanks to the hidden twin below.
            $rememberChecked = old('remember') !== null
                ? filter_var(old('remember'), FILTER_VALIDATE_BOOLEAN)
                : true;
            $sessionExpired = request()->boolean('expired');
        @endphp

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="field">
                <label for="login">Email or Username</label>
                <div class="input-wrap">
                    <input
                        id="login"
                        name="login"
                        type="text"
                        value="{{ $rememberedLogin }}"
                        placeholder="Enter your email or username"
                        autocomplete="username"
                        autocapitalize="none"
                        autocorrect="off"
                        spellcheck="false"
                        required
                        class="@error('login') input-invalid @enderror"
                    >
                    <x-icon name="user-round" :size="17" class="input-icon" />
                </div>
                @error('login')
                    <div class="login-error" role="alert">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="password-wrap">
                    <div class="input-wrap">
                        <input
                            id="password"
                            name="password"
                            type="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                            class="@error('password') input-invalid @enderror"
                        >
                        <x-icon name="lock" :size="17" class="input-icon" />
                    </div>
                    <button
                        type="button"
                        class="password-toggle"
                        id="passwordToggle"
                        aria-label="Show password"
                        aria-pressed="false"
                        aria-controls="password"
                        title="Show password"
                    >
                        <x-icon name="eye" :size="18" class="icon-eye" />
                        <x-icon name="eye-off" :size="18" class="icon-eye-off" />
                    </button>
                </div>
                <div class="caps-hint" id="capsHint" role="status" hidden>Caps Lock is on</div>
                @error('password')
                    <div class="login-error" role="alert">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-extra">
                <label class="remember-me" for="remember">
                    {{-- Hidden twin: an unchecked box still posts remember=0,
                         so the choice survives a failed submit (PHP keeps the
                         last value of a repeated field). --}}
                    <input type="hidden" name="remember" value="0">
                    <input id="remember" type="checkbox" name="remember" value="1" {{ $rememberChecked ? 'checked' : '' }}>
                    <span>Remember me</span>
                </label>
            </div>

            <button type="submit" class="btn">
                <span class="btn-content">
                    <span class="btn-spinner" aria-hidden="true"></span>
                    <span class="btn-text">Sign in</span>
                </span>
            </button>
        </form>

        <div class="divider">or</div>

        <a href="{{ route('google.redirect') }}" class="google-btn">
            <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/>
                <path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 16.318 4 9.656 8.337 6.306 14.691z"/>
                <path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0 1 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/>
                <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 0 1-4.087 5.571l.003-.002 6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/>
            </svg>
            Sign in with Google
        </a>

        <div class="footer-text">
            SmartApp &copy; {{ date('Y') }}
        </div>
    </div><!-- /.login-card -->
    </div><!-- /.login-shell -->

    @php
        $loginSuccessMessage = session('success');
        $loginErrorMessage = $errors->first();
        /* Set by the dashboard's session-expiry handler (see the ?expired=1
           query flag and the smartappSessionExpired sessionStorage flag). */
        $loginExpiredMessage = $sessionExpired
            ? 'You were signed out after a long period of inactivity. Please sign in again.'
            : '';
    @endphp
    <div
        id="loginFeedback"
        data-success="{{ $loginSuccessMessage }}"
        data-error="{{ $loginErrorMessage }}"
        data-expired="{{ $loginExpiredMessage }}"
        hidden
    ></div>
    <script src="{{ public_asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script>
        var loginFeedback = document.getElementById('loginFeedback');
        var loginSuccessMessage = loginFeedback ? loginFeedback.dataset.success : '';
        var loginErrorMessage = loginFeedback ? loginFeedback.dataset.error : '';
        var loginExpiredMessage = loginFeedback ? loginFeedback.dataset.expired : '';

        if (window.Swal && loginSuccessMessage) {
            Swal.fire({
                icon: 'success',
                title: 'Login successful',
                text: loginSuccessMessage,
                toast: true,
                position: 'top-end',
                timer: 4200,
                timerProgressBar: true,
                showConfirmButton: false,
                customClass: { popup: 'login-alert-popup' },
                background: document.documentElement.getAttribute('data-theme') === 'dark' ? '#1e293b' : '#ffffff',
                color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#e2e8f0' : '#1e293b'
            });
        } else if (window.Swal && loginExpiredMessage) {
            Swal.fire({
                icon: 'info',
                title: 'Session expired',
                text: loginExpiredMessage,
                toast: true,
                position: 'top-end',
                timer: 6000,
                timerProgressBar: true,
                showConfirmButton: false,
                customClass: { popup: 'login-alert-popup' },
                background: document.documentElement.getAttribute('data-theme') === 'dark' ? '#1e293b' : '#ffffff',
                color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#e2e8f0' : '#1e293b'
            });
        } else if (window.Swal && loginErrorMessage) {
            Swal.fire({
                icon: 'error',
                title: 'Login failed',
                text: loginErrorMessage,
                toast: true,
                position: 'top-end',
                timer: 5000,
                timerProgressBar: true,
                showConfirmButton: false,
                customClass: { popup: 'login-alert-popup' },
                background: document.documentElement.getAttribute('data-theme') === 'dark' ? '#1e293b' : '#ffffff',
                color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#e2e8f0' : '#1e293b'
            });
        }

        (function () {
            var key = 'smartapp-theme';
            var root = document.documentElement;
            var toggle = document.getElementById('themeToggle');
            var meta = document.querySelector('meta[name="theme-color"]');
            var resetTimer;

            if (!toggle) return;

            function persist(theme) {
                try { localStorage.setItem(key, theme); } catch (e) {}
                document.cookie = key + '=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
            }

            function apply(theme, animate) {
                root.setAttribute('data-theme', theme);
                persist(theme);
                if (meta) {
                    meta.setAttribute('content', theme === 'dark' ? '#0f172a' : '#f1f5f9');
                }

                var label = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
                toggle.setAttribute('aria-pressed', String(theme === 'dark'));
                toggle.setAttribute('aria-label', label);
                toggle.setAttribute('title', label);

                if (animate) {
                    root.classList.add('theme-switching');
                    toggle.classList.add('spun');
                    clearTimeout(resetTimer);
                    resetTimer = setTimeout(function () {
                        root.classList.remove('theme-switching');
                        toggle.classList.remove('spun');
                    }, 400);
                }
            }

            toggle.addEventListener('click', function () {
                apply(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark', true);
            });

            apply(root.getAttribute('data-theme') || 'light', false);
        })();

        (function() {
            var loginInput = document.getElementById('login');
            var rememberInput = document.getElementById('remember');
            var form = document.querySelector('form[method="POST"][action*="login"]');
            var storageKey = 'smartapp_login';

            function readCookie(name) {
                var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
                return match ? decodeURIComponent(match[1]) : '';
            }

            function persistRememberedLogin() {
                if (!loginInput || !rememberInput || !rememberInput.checked) return;
                var value = (loginInput.value || '').trim();
                if (!value) return;
                try { localStorage.setItem(storageKey, value); } catch (e) {}
                document.cookie = storageKey + '=' + encodeURIComponent(value) + '; path=/; max-age=' + (60 * 60 * 24 * 30) + '; SameSite=Lax';
            }

            function clearRememberedLogin() {
                try { localStorage.removeItem(storageKey); } catch (e) {}
                document.cookie = storageKey + '=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax';
            }

            var savedValue = '';
            try {
                savedValue = localStorage.getItem(storageKey) || '';
            } catch (e) {}
            if (!savedValue) {
                savedValue = readCookie(storageKey);
            }

            if (loginInput && savedValue && !loginInput.value) {
                loginInput.value = savedValue;
            }

            if (rememberInput && savedValue) {
                rememberInput.checked = true;
            }

            if (loginInput) {
                loginInput.addEventListener('input', function() {
                    if (rememberInput && rememberInput.checked) {
                        persistRememberedLogin();
                    }
                });
            }

            if (rememberInput) {
                rememberInput.addEventListener('change', function() {
                    if (rememberInput.checked) {
                        persistRememberedLogin();
                    } else {
                        clearRememberedLogin();
                    }
                });
            }

            if (form) {
                form.addEventListener('submit', function() {
                    if (rememberInput && rememberInput.checked) {
                        persistRememberedLogin();
                    } else {
                        clearRememberedLogin();
                    }
                });
            }
        })();

        (function() {
            var form = document.querySelector('form[method="POST"][action*="login"]');
            var submitButton = form ? form.querySelector('button[type="submit"]') : null;

            if (form && submitButton) {
                form.addEventListener('submit', function(event) {
                    if (!form.checkValidity()) {
                        return;
                    }

                    event.preventDefault();
                    submitButton.disabled = true;
                    submitButton.classList.add('loading');
                    submitButton.setAttribute('aria-busy', 'true');

                    var label = submitButton.querySelector('.btn-text');
                    if (label) {
                        label.textContent = 'Signing in...';
                    }

                    setTimeout(function() {
                        submitButton.classList.remove('loading');
                        submitButton.setAttribute('aria-busy', 'false');
                        form.submit();
                    }, 900);
                });
            }
        })();

        (function() {
            var input = document.getElementById('password');
            var toggle = document.getElementById('passwordToggle');

            if (!input || !toggle) return;

            function render(revealed) {
                input.type = revealed ? 'text' : 'password';
                toggle.classList.toggle('revealed', revealed);
                toggle.setAttribute('aria-pressed', String(revealed));
                var label = revealed ? 'Hide password' : 'Show password';
                toggle.title = label;
                toggle.setAttribute('aria-label', label);
            }

            toggle.addEventListener('click', function() {
                // Flip from the current type, so the state stays correct even
                // if a password manager altered it
                render(input.type === 'password');
            });
        })();

        /* Focus the field the user actually needs to type in */
        (function() {
            var login = document.getElementById('login');
            var pw = document.getElementById('password');
            var target = login && login.value ? pw : login;
            if (!target) return;
            try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); }
        })();

        /* Warn while Caps Lock is on — mistyped passwords are the #1 login failure */
        (function() {
            var pw = document.getElementById('password');
            var hint = document.getElementById('capsHint');
            if (!pw || !hint) return;

            function sync(event) {
                var on = !!(event.getModifierState && event.getModifierState('CapsLock'));
                hint.hidden = !on;
            }

            pw.addEventListener('keydown', sync);
            pw.addEventListener('keyup', sync);
            pw.addEventListener('blur', function() { hint.hidden = true; });
        })();

        /* Nudge the card so the eye lands on the error after a failed attempt */
        (function() {
            if (!loginErrorMessage) return;
            var shell = document.getElementById('loginShell');
            if (!shell) return;
            shell.classList.add('shake');
            shell.addEventListener('animationend', function() {
                shell.classList.remove('shake');
            }, { once: true });
        })();
    </script>
</body>
</html>

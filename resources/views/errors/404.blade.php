<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f1f5f9">
    <link rel="icon" type="image/svg+xml" href="{{ public_asset('smap.svg') }}?v=2">
    <title>Page Not Found — SmartApp</title>
    <script>
        (function () {
            var theme = 'light';
            try {
                var saved = localStorage.getItem('smartapp-theme');
                if (saved === 'dark' || saved === 'light') theme = saved;
            } catch (e) {}
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <style>
        :root {
            color-scheme: light;
            --bg: #f1f5f9;
            --panel: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
        }

        [data-theme="dark"] {
            color-scheme: dark;
            --bg: #0f172a;
            --panel: #1e293b;
            --text: #f8fafc;
            --muted: #94a3b8;
            --border: #334155;
            --primary: #60a5fa;
            --primary-hover: #93c5fd;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
        }

        main {
            width: min(100%, 460px);
            padding: 48px 36px;
            text-align: center;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.12);
        }

        .brand {
            margin-bottom: 28px;
            font-size: 1.35rem;
            font-weight: 700;
        }

        .brand-blue { color: #3b82f6; }
        .brand-black { color: var(--text); }

        .code {
            margin: 0;
            color: var(--primary);
            font-size: clamp(4rem, 18vw, 7rem);
            line-height: 0.9;
            letter-spacing: 0;
        }

        h1 {
            margin: 24px 0 8px;
            font-size: 1.5rem;
        }

        p {
            margin: 0 0 28px;
            color: var(--muted);
        }

        a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 20px;
            border-radius: 8px;
            background: var(--primary);
            color: #ffffff;
            font-weight: 600;
            text-decoration: none;
        }

        a:hover { background: var(--primary-hover); }
    </style>
</head>
<body>
    <main>
        <div class="brand" aria-label="SmartApp brand">
            <span class="brand-blue">Smart</span><span class="brand-black">App</span>
        </div>
        <p class="code">404</p>
        <h1>Page not found</h1>
        <p>The page you requested does not exist or is not available to your account.</p>
        <a href="{{ route('home') }}">Back to Dashboard</a>
    </main>
</body>
</html>

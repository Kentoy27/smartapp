<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — SmartApp</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            background: #1e293b;
            border-radius: 12px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }

        .login-card h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 6px;
            color: #f8fafc;
        }

        .login-card p {
            color: #94a3b8;
            font-size: 0.875rem;
            margin-bottom: 24px;
        }

        .alert {
            background: #dc2626;
            color: #fff;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 0.8125rem;
            margin-bottom: 16px;
        }

        .field {
            margin-bottom: 16px;
        }

        .field label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 6px;
        }

        .field input {
            width: 100%;
            padding: 10px 12px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            color: #f1f5f9;
            font-size: 0.9375rem;
            transition: border-color 0.2s;
        }

        .field input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .field input.error {
            border-color: #ef4444;
        }

        .field .error-text {
            color: #f87171;
            font-size: 0.75rem;
            margin-top: 4px;
        }

        button.btn {
            width: 100%;
            padding: 11px;
            background: #3b82f6;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        button.btn:hover {
            background: #2563eb;
        }

        button.btn:active {
            transform: scale(0.98);
        }

        .footer-text {
            text-align: center;
            margin-top: 20px;
            font-size: 0.8125rem;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>Welcome back</h1>
        <p>Sign in to your SmartApp account</p>

        @if ($errors->any())
            <div class="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="field">
                <label for="login">Email or Username</label>
                <input
                    id="login"
                    name="login"
                    type="text"
                    value="{{ old('login') }}"
                    placeholder="Enter your email or username"
                    autocomplete="username"
                    {{ $errors->has('login') ? 'class="error"' : '' }}
                    required
                >
                @error('login')
                    <div class="error-text">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    {{ $errors->has('password') ? 'class="error"' : '' }}
                    required
                >
                @error('password')
                    <div class="error-text">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="btn">Sign in</button>
        </form>

        <div class="footer-text">
            SmartApp &copy; {{ date('Y') }}
        </div>
    </div>
</body>
</html>

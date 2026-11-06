@extends('layouts.dashboard')

@section('title', 'Change Password — SmartApp')

@section('content')
    <section class="card" style="max-width: 620px; margin: 32px auto; padding: 28px;">
        <div class="page-header">
            <h1>Choose a new password</h1>
            <p>Your account is using an initial password. Set a private password before continuing.</p>
        </div>

        <form method="POST" action="{{ route('password.change') }}" class="modal-form">
            @csrf
            <div class="field">
                <label for="password">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>
                <span class="field-hint">Use at least 12 characters with uppercase and lowercase letters, a number, and a symbol.</span>
                @error('password') <div class="error-text" role="alert">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn-primary">Update password</button>
        </form>

        <form method="POST" action="{{ route('logout') }}" style="margin-top: 16px;">
            @csrf
            <button type="submit" class="btn-ghost">Sign out</button>
        </form>
    </section>
@endsection

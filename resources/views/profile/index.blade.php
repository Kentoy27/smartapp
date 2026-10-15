@extends('layouts.dashboard')

@section('title', 'Profile — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Profile</h1>
        <p>Your account details. One profile serves every role — the signature SmartApp stamps onto the documents it generates for you is managed from <strong>Digital Signature</strong> in the account menu.</p>
    </div>

    @php($account = Auth::user())

    {{-- ACCOUNT SUMMARY --}}
    <div class="profile-card">
        <div class="profile-identity">
            <div class="profile-avatar">{{ strtoupper(substr($account->username, 0, 2)) }}</div>
            <div class="profile-identity-text">
                <strong>{{ $account->name ?: $account->username }}</strong>
                <span class="profile-role">{{ $account->roleLabel() }}</span>
            </div>
        </div>

        <div class="profile-meta-grid">
            <div class="profile-meta-item">
                <span class="profile-meta-label">Username</span>
                <span class="profile-meta-value">{{ $account->username }}</span>
            </div>
            <div class="profile-meta-item">
                <span class="profile-meta-label">Email</span>
                <span class="profile-meta-value">{{ $account->email ?: '—' }}</span>
            </div>
            @if ($account->position)
                <div class="profile-meta-item">
                    <span class="profile-meta-label">Position</span>
                    <span class="profile-meta-value">{{ $account->position }}</span>
                </div>
            @endif
            @if ($account->school)
                <div class="profile-meta-item">
                    <span class="profile-meta-label">School</span>
                    <span class="profile-meta-value">{{ $account->school->name }}</span>
                </div>
            @endif
            <div class="profile-meta-item">
                <span class="profile-meta-label">Role</span>
                <span class="profile-meta-value">{{ $account->roleLabel() }}</span>
            </div>
        </div>
    </div>

    {{-- The digital signature is not repeated here: the account menu's
         "Digital Signature" dialog hosts that component on every page (see
         layouts.dashboard), so this page keeps the account summary alone. --}}
@endsection

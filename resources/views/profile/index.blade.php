@extends('layouts.dashboard')

@section('title', 'Profile — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Profile</h1>
        <p>Your account details and your digital signature. One profile serves every role — the signature you keep here is the one stamped onto the documents SmartApp generates for you.</p>
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

        <dl class="profile-meta">
            <dt>Username</dt>
            <dd>{{ $account->username }}</dd>

            <dt>Email</dt>
            <dd>{{ $account->email ?: '—' }}</dd>

            @if ($account->position)
                <dt>Position</dt>
                <dd>{{ $account->position }}</dd>
            @endif

            @if ($account->school)
                <dt>School</dt>
                <dd>{{ $account->school->name }}</dd>
            @endif

            <dt>Role</dt>
            <dd>{{ $account->roleLabel() }}</dd>
        </dl>
    </div>

    {{-- DIGITAL SIGNATURE: the same component is used for a user and for a
         superadmin — the signature belongs to the account, not the role. --}}
    <livewire:profile.digital-signature />
@endsection

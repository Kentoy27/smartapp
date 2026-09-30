@extends('layouts.dashboard')

@section('title', 'Dashboard — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Welcome back, {{ $user->username }}</h1>
        <p>Here's what's happening with your account today.</p>
    </div>

    {{-- OPCR tools: staff-facing only — the superadmin dashboard stays bare. --}}
    @if (! $isSuperAdmin)
        @if ($opcrfLocked)
            {{-- LOCKED CARD: an OPCRF with its MOVs has already been
                 submitted, so the cycle is complete — no more downloads or
                 uploads from the dashboard. --}}
            <div class="card opcr-card-locked">
                <div class="card-head">
                    <div class="card-title">OPCRF Template</div>
                    <a href="{{ route('opcrf.index') }}" class="card-link">Go to Opcrf →</a>
                </div>
                <div class="opcr-template-row">
                    <span class="opcr-file-badge opcr-file-badge--locked" aria-hidden="true">
                        <x-icon name="lock" :size="22" />
                    </span>
                    <div class="opcr-file-meta">
                        <span class="opcr-file-name">OPCRF submitted — locked</span>
                        <span class="opcr-file-sub">Your OPCRF and MOVs are in. Nothing more to download or upload here.</span>
                        <span class="opcr-file-sub">Manage your MOVs any time on the <a href="{{ route('opcrf.index') }}">Opcrf page</a>.</span>
                    </div>
                    <span class="opcr-locked-pill">
                        <x-icon name="lock" :size="14" />
                        <span>Locked</span>
                    </span>
                </div>
            </div>
        @else
            <div class="card">
                <div class="card-head">
                    <div class="card-title">OPCRF Template</div>
                    <a href="{{ route('opcrf.index') }}" class="card-link">Go to Opcrf →</a>
                </div>
                <div class="opcr-template-row">
                    <span class="opcr-file-badge" aria-hidden="true">
                        <x-icon name="file-spreadsheet" :size="22" />
                    </span>
                    <div class="opcr-file-meta">
                        @if ($opcrfPartOne['full_open'])
                            <span class="opcr-file-name">OPCRF-TEMPLATE.xlsx</span>
                            <span class="opcr-file-sub">Office Performance Commitment and Review Form — download, fill out, and upload it back here</span>
                        @else
                            <span class="opcr-file-name">OPCRF-PART-I.xlsx</span>
                            <span class="opcr-file-sub">Office Performance Commitment and Review Form — Part I only, available now</span>
                            <span class="opcr-file-sub">Parts II–IV become available {{ $opcrfPartOne['full_open_date'] ? 'on '.$opcrfPartOne['full_open_date'].', ' : 'at ' }}{{ $opcrfPartOne['label'] }}</span>
                        @endif
                    </div>
                    <a href="{{ route('opcrf.template') }}" class="opcr-download-btn" download>
                        <x-icon name="download" :size="16" />
                        <span>Download</span>
                    </a>

                    {{-- UPLOAD: button sits beside Download (rendered by the
                         OpcrfUpload component). Clicking it pops up the
                         "Upload your OPCR in here" window; the analyzed
                         workbook then opens the "Review before submitting"
                         modal, and confirming that pops up the locked
                         "Upload your MOVs" window. --}}
                    <livewire:opcrf-upload />
                </div>
            </div>
        @endif
    @endif
@endsection

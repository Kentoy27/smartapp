@extends('layouts.dashboard')

@section('title', $partLabel.' — SmartApp')

@section('content')
    <div class="page-header">
        <h1>{{ $partLabel }}</h1>
        <p>{{ $partBlurb }}</p>
    </div>

    @if ($notice)
        <div class="opcrf-part-card opcrf-part-card--{{ $notice['tone'] }}" style="margin-bottom:16px">
            <p class="opcrf-part-message">{{ $notice['message'] }}</p>
            @if ($notice['detail'] !== '')
                <p class="opcrf-part-detail">{{ $notice['detail'] }}</p>
            @endif
        </div>
    @endif

    @if ($part === 1)
        {{-- PART 1 carries the form itself, filled from the account — the
             same registered identity the personalized template uses, so the
             typed form and the downloaded workbook always agree. --}}
        <livewire:opcrf-form />
    @else
        {{-- PARTS 2–4 open on their own schedule. What goes in them is the
             department's own content; what this page guarantees is that the
             Part could only be reached while its window was open. --}}
        <div class="card">
            <div class="card-head">
                <div class="card-title">{{ $partLabel }} is open</div>
                @if ($schedule)
                    <span class="card-link">
                        Open until {{ \App\Support\OpcrfAccess::when($schedule->end_datetime) }}
                    </span>
                @endif
            </div>

            <p class="card-text">
                You have reached {{ $partLabel }} inside its access period
                ({{ \App\Support\OpcrfAccess::when($schedule?->start_datetime) }}
                &ndash; {{ \App\Support\OpcrfAccess::when($schedule?->end_datetime) }}).
            </p>

            @if ($schedule?->description)
                <p class="card-text opcrf-part-instructions">
                    <strong>From your administrator:</strong> {{ $schedule->description }}
                </p>
            @endif
        </div>
    @endif

    <p class="opcrf-part-back">
        <a href="{{ route('opcrf.index') }}">&larr; Back to all OPCRF Parts</a>
    </p>
@endsection
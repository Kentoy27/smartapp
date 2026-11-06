@extends('layouts.dashboard')

@section('title', $plan->label().' — AIP Preview')

@section('content')
    <div class="page-header aip-record-preview__header">
        <div>
            <p class="memory-breadcrumb">
                <a href="{{ route('aip.index') }}" wire:navigate.hover>Annual Implementation Plan</a>
                <span aria-hidden="true">›</span>
                <span>{{ $plan->fiscalYearLabel() }}</span>
            </p>
            <h1>{{ $plan->label() }}</h1>
            <p>{{ $plan->school?->name ?? 'Your school' }} · Submitted {{ $plan->submitted_at?->format('M j, Y g:i A') ?? '—' }}</p>
        </div>
        <span class="badge {{ $plan->statusBadgeClass() }}">{{ $plan->statusLabelFor(auth()->user()) }}</span>
    </div>

    <section class="card aip-record-preview" aria-labelledby="aip-record-preview-title">
        <div class="card-head">
            <div>
                <h2 class="card-title" id="aip-record-preview-title">Submitted AIP preview</h2>
                <p class="mem-toolbar-copy">{{ count($rows) }} row(s) analyzed from the uploaded Word document.</p>
            </div>
            <div class="aip-records-card__actions">
                <a class="btn-primary btn-ghost--small" href="{{ route('aip.plans.export', ['plan' => $plan->id]) }}">
                    <x-icon name="download" :size="14" />
                    Download AIP
                </a>
                @if ($plan->source_file_path)
                    <a class="btn-ghost btn-ghost--small" href="{{ route('aip.plans.source', ['plan' => $plan->id]) }}">
                    <x-icon name="download" :size="14" />
                    Download original DOCX
                    </a>
                @endif
            </div>
        </div>

        @if ($plan->original_file_name)
            <p class="aip-record-preview__filename">Uploaded file: <strong>{{ $plan->original_file_name }}</strong></p>
        @endif

        @include('aip.partials.preview-table', ['rows' => $rows])
        @if ($plan->submitted_at !== null)
            @include('aip.partials.signatures', ['plan' => $plan])
        @endif
    </section>
@endsection

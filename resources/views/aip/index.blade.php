@extends('layouts.dashboard')

@section('title', 'Annual Implementation Plan — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Annual Implementation Plan (AIP)</h1>
        <p>Review your submitted AIP records and their current review status.</p>
    </div>

    @if (session('status'))
        <div class="mem-banner mem-banner--notice" role="status">{{ session('status') }}</div>
    @endif

    <section class="card aip-records-card" aria-labelledby="aip-records-heading">
        <div class="card-head aip-records-card__head">
            <div>
                <h2 class="card-title" id="aip-records-heading">My AIP records</h2>
                <p class="mem-toolbar-copy">{{ $plans->count() }} plan(s) on file.</p>
            </div>
            <div class="aip-records-card__actions">
                <livewire:aip-submission-upload :key="'aip-upload-records'" />
                <a href="{{ route('aip.template.download') }}" class="btn-ghost btn-ghost--small">Download blank AIP</a>
            </div>
        </div>

        <div class="table-wrap aip-records-card__table-wrap">
            <table class="data-table aip-records-table">
                <caption>Your school's Annual Implementation Plan records</caption>
                <thead>
                    <tr>
                        <th scope="col">Plan</th>
                        <th scope="col">Submitted</th>
                        <th scope="col">Uploaded file</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="users-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($plans as $plan)
                        <tr wire:key="aip-record-{{ $plan->id }}">
                            <td>
                                <span class="cell-strong">{{ $plan->label() }}</span>
                                <span class="cell-sub">{{ $plan->fiscalYearLabel() }}</span>
                            </td>
                            <td>{{ $plan->submitted_at?->format('M j, Y g:i A') ?? '—' }}</td>
                            <td class="aip-records-table__filename">{{ $plan->original_file_name ?: 'Online AIP' }}</td>
                            <td>
                                <span class="badge {{ $plan->statusBadgeClass() }}">{{ $plan->statusLabelFor(auth()->user()) }}</span>
                                @if ($plan->status === \App\Models\AipPlan::STATUS_DRAFT)
                                    <span class="cell-sub">Not visible to reviewers until submitted.</span>
                                @endif
                                @if ($plan->review_remarks)
                                    <span class="cell-sub aip-records-table__remarks">{{ $plan->review_remarks }}</span>
                                @endif
                            </td>
                            <td class="users-actions-col">
                                <div class="aip-records-table__row-actions">
                                    <div x-data class="aip-record-preview-trigger">
                                        <button
                                            type="button"
                                            class="users-action"
                                            aria-haspopup="dialog"
                                            @click="$refs.aipPreview.showModal()"
                                        >Preview</button>
                                        <dialog
                                            x-ref="aipPreview"
                                            class="modal aip-preview-modal aip-record-preview-dialog"
                                            aria-labelledby="aip-record-preview-title-{{ $plan->id }}"
                                            @click.self="$el.close()"
                                            @keydown.escape="$el.close()"
                                        >
                                            <div class="modal-head aip-record-preview-dialog__head">
                                                <div>
                                                    <h2 id="aip-record-preview-title-{{ $plan->id }}">Submitted AIP preview</h2>
                                                    <p>{{ $plan->label() }} · {{ $plan->fiscalYearLabel() }} · {{ count($plan->form_data['rows'] ?? []) }} analyzed row(s)</p>
                                                </div>
                                                <button type="button" class="modal-close" @click="$refs.aipPreview.close()" aria-label="Close preview">×</button>
                                            </div>
                                            <div class="aip-record-preview-dialog__body aip-preview-body">
                                                @if ($plan->original_file_name)
                                                    <p class="aip-record-preview__filename">Uploaded file: <strong>{{ $plan->original_file_name }}</strong></p>
                                                @endif
                                                @include('aip.partials.preview-table', ['rows' => $plan->form_data['rows'] ?? []])
                                                @if ($plan->submitted_at !== null)
                                                    @include('aip.partials.signatures', ['plan' => $plan])
                                                @endif
                                            </div>
                                        </dialog>
                                    </div>
                                    <a class="users-action" href="{{ route('aip.plans.export', ['plan' => $plan->id]) }}">Download AIP</a>
                                    @if ($plan->source_file_path)
                                        <a class="users-action" href="{{ route('aip.plans.source', ['plan' => $plan->id]) }}">DOCX</a>
                                    @endif
                                    @if ($plan->isReturned() && $plan->canEditContentsBy(auth()->user()))
                                        <a
                                            class="users-action"
                                            href="{{ route('aip.plans.edit', ['plan' => $plan->id]) }}"
                                            wire:navigate
                                        >Update / Upload AIP</a>
                                    @endif
                                    @if ($plan->status === \App\Models\AipPlan::STATUS_DRAFT)
                                        <form method="POST" action="{{ route('aip.plans.submit', ['plan' => $plan->id]) }}">
                                            @csrf
                                            <button type="submit" class="users-action">Submit for review</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="table-empty" colspan="5">No AIP records yet. Upload a completed AIP to create your first submission.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

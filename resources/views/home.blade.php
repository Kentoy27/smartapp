@extends('layouts.dashboard')

@section('title', 'Dashboard — SmartApp')

@section('content')
    <section class="dashboard-welcome{{ $user->hasAdminAccess() ? ' dashboard-welcome--admin' : '' }}">
        <div class="dashboard-welcome__copy">
            <span class="dashboard-welcome__eyebrow">
                {{ $user->hasAdminAccess()
                    ? 'ADMIN WORKSPACE'
                    : ($isBudgetReviewer
                        ? 'BUDGET REVIEW WORKSPACE'
                        : ($isBookkeeper
                            ? 'WFP CHECKING WORKSPACE'
                            : ($user->isPsdsRole()
                                ? 'DISTRICT MONITORING WORKSPACE'
                                : ($user->canAccessOpcrfTemplate() ? 'STAFF WORKSPACE' : 'VIEWER WORKSPACE')))) }}
            </span>
            <h1>Welcome back, {{ $user->username }}</h1>
            <p>
                {{ $user->hasAdminAccess()
                    ? 'Review submissions and keep your school data moving.'
                    : ($isBudgetReviewer
                        ? 'Review submitted WFP plans.'
                        : ($user->isPsdsRole()
                            ? 'Monitor the AIP, OPCRF, and WFP submissions of the schools in your districts.'
                            : ($user->canAccessOpcrfTemplate()
                                ? 'Your performance plan and financial plan, together in one place.'
                            : ($user->canReviewAllOpcrfSubmissions()
                                ? 'Review School Head AIP, OPCRF, and WFP submissions.'
                                : 'View OPCRF part schedules and status.')))) }}
            </p>
        </div>
        @if ($user->canAccessAip())
            <a href="{{ route('mov.index') }}" class="dashboard-welcome__link" wire:navigate>
                <x-icon name="upload" :size="16" />
                <span>Open MOV checklist</span>
                <span aria-hidden="true">→</span>
            </a>
        @elseif ($isBookkeeper)
            <a href="{{ route('budget.per-line-item.index') }}" class="dashboard-welcome__link" wire:navigate>
                <x-icon name="check-check" :size="16" />
                <span>Check the annual report</span>
                <span aria-hidden="true">→</span>
            </a>
        @elseif ($user->isPsdsRole())
            <a href="{{ route('psds.monitoring') }}" class="dashboard-welcome__link" wire:navigate>
                <x-icon name="eye" :size="16" />
                <span>Open School Submission Monitoring</span>
                <span aria-hidden="true">→</span>
            </a>
        @endif
    </section>

    @if ($user->hasAdminAccess())
        <livewire:admin-dashboard-stats />

        {{-- Every school's uploaded Work and Financial Plan — the other
             half of the WfpUpload flow on /wfp, listed here so the
             superadmin sees who has handed their plan in. --}}
        <livewire:wfp-uploads-list />
    @elseif ($isBudgetReviewer)
        <div class="staff-dashboard-grid staff-dashboard-grid--budget">
            <section class="staff-dashboard-panel" aria-labelledby="dashboard-budget-heading">
                <div class="staff-dashboard-panel__intro">
                    <span class="staff-dashboard-panel__step">01</span>
                    <div>
                        <h2 id="dashboard-budget-heading">Budget review</h2>
                        <p>Review submitted financial plans and certify each school’s allotment. This role cannot edit or submit school plans.</p>
                    </div>
                </div>

                <div class="dashboard-action-cards">
                    <a href="{{ route('budget.lineitems.index') }}" class="dashboard-action-card" wire:navigate>
                        <span class="dashboard-action-card__icon"><x-icon name="file-spreadsheet" :size="16" /></span>
                        <span>Monthly Expenditure</span>
                        <span aria-hidden="true">→</span>
                    </a>
                    <a href="{{ route('budget.per-line-item.index') }}" class="dashboard-action-card" wire:navigate>
                        <span class="dashboard-action-card__icon"><x-icon name="check-check" :size="16" /></span>
                        <span>Per Line Item Expenditure</span>
                        <span aria-hidden="true">→</span>
                    </a>
                    {{-- <a href="{{ route('aip.review') }}" class="dashboard-action-card" wire:navigate>
                        <span class="dashboard-action-card__icon"><x-icon name="file-spreadsheet" :size="16" /></span>
                        <span>Review submitted AIP plans</span>
                        <span aria-hidden="true">→</span>
                    </a> --}}
                </div>
            </section>

            {{-- Allotments are the other half of the Budget office's work:
                 monitoring the year's ceilings and certifying the amount each
                 school may actually consume. --}}
            {{-- <livewire:budget-allotment-monitor /> --}}

            {{-- <section class="staff-dashboard-panel budget-panel--full" aria-labelledby="dashboard-wfp-uploads-heading">
                <div class="staff-dashboard-panel__intro">
                    <span class="staff-dashboard-panel__step">02</span>
                    <div>
                        <h2 id="dashboard-wfp-uploads-heading">Submitted WFP plans</h2>
                        <p>Every school’s uploaded Work and Financial Plan, newest first. Download a workbook to review it.</p>
                    </div>
                </div>
                <livewire:wfp-uploads-list />
            </section> --}}
        </div>
    @elseif ($isBookkeeper)
        {{-- The Bookkeeper's dashboard: the same division-wide checking
             surfaces the Budget office works — the lines whose account
             titles and codes are checked, and the annual report the check
             is signed on. No OPCRF or MOV panel exists here: this role's
             module is the checking alone. --}}
        <div class="staff-dashboard-grid">
            <section class="staff-dashboard-panel staff-dashboard-panel--wfp" aria-labelledby="dashboard-bookkeeper-heading">
                <div class="staff-dashboard-panel__intro">
                    <span class="staff-dashboard-panel__step">01</span>
                    <div>
                        <h2 id="dashboard-bookkeeper-heading">Financial plan</h2>
                        <p>
                            The schools’ Monthly Expenditure lines and annual reports, read-only.
                            The School Head owns the lines; you check their account titles and codes.
                        </p>
                    </div>
                </div>

                <div class="dashboard-action-cards">
                    <a href="{{ route('budget.lineitems.index') }}" class="dashboard-action-card" wire:navigate>
                        <span class="dashboard-action-card__icon"><x-icon name="file-spreadsheet" :size="16" /></span>
                        <span>Monthly Expenditure</span>
                        <span aria-hidden="true">→</span>
                    </a>
                    <a href="{{ route('budget.per-line-item.index') }}" class="dashboard-action-card" wire:navigate>
                        <span class="dashboard-action-card__icon"><x-icon name="check-check" :size="16" /></span>
                        <span>Per Line Item Expenditure</span>
                        <span aria-hidden="true">→</span>
                    </a>
                </div>
            </section>

            @php
                $assignedSchoolIds = $user->assignedSchoolIds();
                $assignedSchools = $assignedSchoolIds
                    ? \App\Models\School::query()->whereIn('id', $assignedSchoolIds)->get()->sortBy(fn ($school) => array_search($school->id, $assignedSchoolIds, true))
                    : collect();
            @endphp

            @if ($assignedSchools->isNotEmpty())
                <section class="staff-dashboard-panel" aria-labelledby="dashboard-bookkeeper-schools">
                    <div class="staff-dashboard-panel__intro">
                        <span class="staff-dashboard-panel__step">02</span>
                        <div>
                            <h2 id="dashboard-bookkeeper-schools">Your schools</h2>
                            <p>
                                Schools on your assignment list. Open a school’s annual report to check its
                                account titles and codes.
                            </p>
                        </div>
                    </div>

                    <div class="dashboard-school-list">
                        @foreach ($assignedSchools as $school)
                            <a href="{{ route('budget.per-line-item.index', ['school' => $school->id]) }}" class="dashboard-school-card" wire:navigate>
                                <span class="dashboard-school-card__name">{{ $school->name }}</span>
                                <span class="dashboard-school-card__code">{{ $school->school_id }}</span>
                                <span aria-hidden="true">→</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    @elseif ($user->isPsdsRole())
        {{-- The PSDS's dashboard IS the monitoring workspace: the summary
             cards and the per-school submission table, exactly what the
             /psds page shows. One component serves both, so the figures can
             never disagree. --}}
        <livewire:psds-monitoring />
    @elseif ($user->canReviewAllOpcrfSubmissions())
        <livewire:viewer-submission-stats />
    @elseif ($user->canAccessOpcrfTemplate())
        <section class="dashboard-workspaces" aria-labelledby="dashboard-workspaces-title">
            <div class="dashboard-workspaces__head">
                <div>
                    <h2 id="dashboard-workspaces-title">Your workspaces</h2>
                    <p>Prepare, manage, and follow your school’s plans.</p>
                </div>
            </div>

        <div class="staff-dashboard-grid staff-dashboard-grid--school-head{{ $wfpTemplate !== null ? ' has-wfp' : '' }}">
            <section class="staff-dashboard-panel" aria-labelledby="dashboard-opcrf-heading">
                <div class="staff-dashboard-panel__intro">
                    <span class="staff-dashboard-panel__step">01</span>
                    <div>
                        <h2 id="dashboard-opcrf-heading">Office Performance Commitment and Review Form (OPCRF)</h2>
                        <p>Prepare your OPCRF, upload your commitment, and follow its review status.</p>
                    </div>
                </div>
                <livewire:dashboard-summary />
                <div class="staff-dashboard-panel__actions">
                    <livewire:opcrf-upload />
                </div>
            </section>

            @if ($wfpTemplate !== null)
                <section class="staff-dashboard-panel staff-dashboard-panel--wfp" aria-labelledby="dashboard-wfp-heading">
                    <div class="staff-dashboard-panel__intro">
                        <span class="staff-dashboard-panel__step">02</span>
                        <div>
                            <h2 id="dashboard-wfp-heading">Financial plan</h2>
                            <p>Generate and download your school’s WFP workbook.</p>
                        </div>
                    </div>

                    <article class="card dashboard-plan-card">
                        <div class="dashboard-plan-card__heading">
                            <span class="opcr-file-badge" aria-hidden="true">
                                <x-icon name="file-spreadsheet" :size="22" />
                            </span>
                            <div>
                                <h3>WFP Template</h3>
                                <span class="opcr-file-name">{{ $wfpTemplate['name'] }}</span>
                            </div>
                        </div>
                        <p class="dashboard-plan-card__description">{{ $wfpTemplate['description'] }}</p>
                        <div class="dashboard-plan-card__actions">
                            <livewire:wfp-generate-button :key="'wfp-generate-card'" />
                        </div>
                        <a href="{{ route('wfp.index') }}" class="dashboard-plan-card__link" wire:navigate.hover>
                            Open WFP workspace <span aria-hidden="true">→</span>
                        </a>
                        <a href="{{ route('wfp.matrices.index') }}" class="dashboard-plan-card__link" wire:navigate.hover>
                            <x-icon name="file-spreadsheet" :size="14" />
                            Monthly Expenditure Matrix <span aria-hidden="true">→</span>
                        </a>
                    </article>
                </section>
            @endif

            @if ($canUploadAip)
                <section class="staff-dashboard-panel staff-dashboard-panel--aip" aria-labelledby="dashboard-aip-heading">
                    <div class="staff-dashboard-panel__intro">
                        <span class="staff-dashboard-panel__step">03</span>
                        <div>
                            <h2 id="dashboard-aip-heading">Annual Implementation Plan (AIP)</h2>
                            <p>Analyze and preview your completed AIP before submitting it for review.</p>
                        </div>
                    </div>

                    <article class="card dashboard-plan-card aip-dashboard-card">
                        <div class="aip-dashboard-card__intro">
                            <span class="opcr-file-badge" aria-hidden="true">
                                <x-icon name="file-spreadsheet" :size="22" />
                            </span>
                            <div>
                                <h3>Upload completed AIP</h3>
                                <p>DOCX Word document · preview the extracted rows before you submit.</p>
                            </div>
                        </div>

                        <div class="aip-dashboard-card__actions">
                            <livewire:aip-submission-upload :key="'aip-upload-dashboard'" />
                            <a href="{{ route('aip.template.download') }}" class="btn-ghost btn-ghost--small">Download blank AIP</a>
                        </div>
                        <a href="{{ route('aip.index') }}" class="dashboard-plan-card__link" wire:navigate.hover>
                            View AIP records <span aria-hidden="true">→</span>
                        </a>
                    </article>
                </section>
            @endif
        </div>
        </section>
    @endif
@endsection

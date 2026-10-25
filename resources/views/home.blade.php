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
                        : ($user->canAccessOpcrfTemplate() ? 'STAFF WORKSPACE' : 'VIEWER WORKSPACE')) }}
                        : ($isBookkeeper ? 'WFP CHECKING WORKSPACE' : 'STAFF WORKSPACE')) }}
            </span>
            <h1>Welcome back, {{ $user->username }}</h1>
            <p>
                {{ $user->hasAdminAccess()
                    ? 'Review submissions and keep your school data moving.'
                    : ($isBudgetReviewer
                        ? 'Review submitted WFP and AIP plans.'
                        : ($user->canAccessOpcrfTemplate()
                            ? 'Your performance plan and financial plan, together in one place.'
                            : 'View OPCRF part schedules and status.')) }}
            </p>
        </div>
        @if ($user->canAccessOpcrfTemplate())
            <a href="{{ route('mov.index') }}" class="dashboard-welcome__link" wire:navigate.hover>
                        ? 'Review submitted plans and keep the schools’ allotments certified.'
                        : ($isBookkeeper
                            ? 'Read the schools’ expenditure lines and check their account titles and codes.'
                            : 'Your performance plan and financial plan, together in one place.')) }}
            </p>
        </div>
        @if (! $user->hasAdminAccess() && ! $isBudgetReviewer && ! $isBookkeeper)
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
        @endif
    </section>

    @if ($user->hasAdminAccess())
        <livewire:admin-dashboard-stats />
        <livewire:opcrf-analytics />

        {{-- Every school's uploaded Work and Financial Plan — the other
             half of the WfpUpload flow on /wfp, listed here so the
             superadmin sees who has handed their plan in. --}}
        <livewire:wfp-uploads-list />
    @elseif ($isBudgetReviewer)
        <div class="staff-dashboard-grid">
            <section class="staff-dashboard-panel" aria-labelledby="dashboard-budget-heading">
                <div class="staff-dashboard-panel__intro">
                    <span class="staff-dashboard-panel__step">01</span>
                    <div>
                        <h2 id="dashboard-budget-heading">Budget review</h2>
                        <p>Review submitted financial plans and certify each school’s allotment. This role cannot edit or submit school plans.</p>
                    </div>
                </div>
                <livewire:wfp-uploads-list />
                <a href="{{ route('aip.review') }}" class="dashboard-plan-card__link" wire:navigate.hover>
                    <x-icon name="eye" :size="14" />
                    Review submitted AIP plans <span aria-hidden="true">→</span>
                </a>

                {{-- The per-line list the budget is checked against: every
                     school's expenditure lines, read-only. It lives beside the
                     plans the Budget office already reviews because it is the
                     detail behind the same money. --}}
                <a href="{{ route('budget.lineitems.index') }}" class="dashboard-plan-card__link" wire:navigate>
                    <x-icon name="check-check" :size="14" />
                    Check expenditure line items <span aria-hidden="true">→</span>
                </a>

                {{-- The printed annual report those lines add up to: the same
                     form the School Head reads, per school. --}}
                <a href="{{ route('budget.per-line-item.index') }}" class="dashboard-plan-card__link" wire:navigate>
                    <x-icon name="file-spreadsheet" :size="14" />
                    Per line item expenditure <span aria-hidden="true">→</span>
                </a>
            </section>

            {{-- Allotments are the other half of the Budget office's work:
                 monitoring the year's ceilings and certifying the amount each
                 school may actually consume. --}}
            <livewire:budget-allotment-monitor />
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

                <a href="{{ route('budget.lineitems.index') }}" class="dashboard-plan-card__link" wire:navigate>
                    <x-icon name="file-spreadsheet" :size="14" />
                    Monthly Expenditure <span aria-hidden="true">→</span>
                </a>

                <a href="{{ route('budget.per-line-item.index') }}" class="dashboard-plan-card__link" wire:navigate>
                    <x-icon name="check-check" :size="14" />
                    Per Line Item Expenditure <span aria-hidden="true">→</span>
                </a>
            </section>
        </div>
    @elseif ($user->canAccessOpcrfTemplate())
        <div class="staff-dashboard-grid{{ $wfpTemplate !== null ? ' has-wfp' : '' }}">
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
        </div>
    @endif
@endsection

@extends('layouts.dashboard')

@section('title', 'Dashboard — SmartApp')

@section('content')
    <section class="dashboard-welcome{{ $user->hasAdminAccess() ? ' dashboard-welcome--admin' : '' }}">
        <div class="dashboard-welcome__copy">
            <span class="dashboard-welcome__eyebrow">
                {{ $user->hasAdminAccess() ? 'ADMIN WORKSPACE' : 'STAFF WORKSPACE' }}
            </span>
            <h1>Welcome back, {{ $user->username }}</h1>
            <p>
                {{ $user->hasAdminAccess()
                    ? 'Review submissions and keep your school data moving.'
                    : 'Your performance plan and financial plan, together in one place.' }}
            </p>
        </div>
        @unless ($user->hasAdminAccess())
            <a href="{{ route('mov.index') }}" class="dashboard-welcome__link" wire:navigate>
                <x-icon name="upload" :size="16" />
                <span>Open MOV checklist</span>
                <span aria-hidden="true">→</span>
            </a>
        @endunless
    </section>

    @if ($user->hasAdminAccess())
        <livewire:opcrf-analytics />

        {{-- Every school's uploaded Work and Financial Plan — the other
             half of the WfpUpload flow on /wfp, listed here so the
             superadmin sees who has handed their plan in. --}}
        <livewire:wfp-uploads-list />
    @else
        <div class="staff-dashboard-grid{{ $wfpTemplate !== null ? ' has-wfp' : '' }}">
            <section class="staff-dashboard-panel" aria-labelledby="dashboard-opcrf-heading">
                <div class="staff-dashboard-panel__intro">
                    <span class="staff-dashboard-panel__step">01</span>
                    <div>
                        <h2 id="dashboard-opcrf-heading">Performance plan</h2>
                        <p>Prepare your OPCRF and follow its review status.</p>
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
                        <a href="{{ route('wfp.index') }}" class="dashboard-plan-card__link" wire:navigate>
                            Open WFP workspace <span aria-hidden="true">→</span>
                        </a>
                        <a href="{{ route('wfp.matrices.index') }}" class="dashboard-plan-card__link" wire:navigate>
                            <x-icon name="file-spreadsheet" :size="14" />
                            Monthly Expenditure Matrix <span aria-hidden="true">→</span>
                        </a>
                    </article>
                </section>
            @endif
        </div>
    @endif
@endsection

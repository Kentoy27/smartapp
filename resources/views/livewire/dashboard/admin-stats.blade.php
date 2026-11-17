<div>
<section class="card admin-dashboard-stats" wire:poll.visible.60s aria-labelledby="admin-dashboard-stats-title">
    <div class="card-head admin-dashboard-stats__header">
        <div>
            <span class="admin-dashboard-stats__eyebrow">SYSTEM SNAPSHOT</span>
            <h2 class="card-title" id="admin-dashboard-stats-title">System Overview</h2>
            <p class="admin-dashboard-stats__description">A live summary of schools, accounts, and submitted records.</p>
        </div>
        <div class="admin-dashboard-stats__actions">
            <span class="admin-dashboard-stats__scope">
                <span class="admin-dashboard-stats__scope-dot" aria-hidden="true"></span>
                Across all schools
            </span>
            <button
                type="button"
                class="btn-primary admin-dashboard-stats__open"
                wire:click="openStatistics"
                aria-haspopup="dialog"
            >
                <x-icon name="chart-column" :size="16" />
                <span>Statistics</span>
            </button>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card admin-dashboard-stat admin-dashboard-stat--schools">
            <div class="admin-dashboard-stat__heading">
                <span class="admin-dashboard-stat__icon"><x-icon name="school" :size="18" /></span>
                <div class="stat-label">Schools</div>
            </div>
            <div class="stat-value">{{ number_format($this->schools()) }}</div>
            <div class="stat-sub">Registered in the system</div>
        </div>

        <div class="stat-card admin-dashboard-stat admin-dashboard-stat--heads">
            <div class="admin-dashboard-stat__heading">
                <span class="admin-dashboard-stat__icon"><x-icon name="user-round" :size="18" /></span>
                <div class="stat-label">School Heads</div>
            </div>
            <div class="stat-value">{{ number_format($this->schoolHeads()) }}</div>
            <div class="stat-sub">School Head accounts</div>
        </div>

        <div class="stat-card admin-dashboard-stat admin-dashboard-stat--opcrf">
            <div class="admin-dashboard-stat__heading">
                <span class="admin-dashboard-stat__icon"><x-icon name="file-spreadsheet" :size="18" /></span>
                <div class="stat-label">OPCRF Submissions</div>
            </div>
            <div class="stat-value">{{ number_format($this->opcrfSubmissions()) }}</div>
            <div class="stat-sub">Across all review stages</div>
        </div>

        <div class="stat-card admin-dashboard-stat admin-dashboard-stat--wfp">
            <div class="admin-dashboard-stat__heading">
                <span class="admin-dashboard-stat__icon"><x-icon name="chart-column" :size="18" /></span>
                <div class="stat-label">WFP Uploads</div>
            </div>
            <div class="stat-value">{{ number_format($this->wfpUploads()) }}</div>
            <div class="stat-sub">Current uploaded plans</div>
        </div>

        <div class="stat-card admin-dashboard-stat admin-dashboard-stat--movs">
            <div class="admin-dashboard-stat__heading">
                <span class="admin-dashboard-stat__icon"><x-icon name="folder-open" :size="18" /></span>
                <div class="stat-label">MOV Uploads</div>
            </div>
            <div class="stat-value">{{ number_format($this->movUploads()) }}</div>
            <div class="stat-sub">Evidence files on record</div>
        </div>
    </div>
</section>

@if ($showStatisticsModal)
    <div
        class="modal-backdrop is-open admin-statistics-backdrop"
        x-data
        @keydown.escape.window="$wire.closeStatistics()"
        @click.self="$wire.closeStatistics()"
        role="presentation"
    >
        <div
            class="modal modal--wide admin-statistics-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="adminStatisticsTitle"
            @click.stop
        >
            <div class="modal-head admin-statistics-modal__head">
                <div>
                    <span class="admin-dashboard-stats__eyebrow">SYSTEM REPORT</span>
                    <h2 id="adminStatisticsTitle">OPCRF Statistics Overview</h2>
                </div>
                <button
                    type="button"
                    class="modal-close"
                    wire:click="closeStatistics"
                    aria-label="Close statistics"
                    title="Close"
                >×</button>
            </div>
            <div class="admin-statistics-modal__body">
                <livewire:opcrf-analytics wire:key="admin-opcrf-statistics" />
            </div>
        </div>
    </div>
@endif
</div>

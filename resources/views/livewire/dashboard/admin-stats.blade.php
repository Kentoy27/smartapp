<section class="card admin-dashboard-stats" wire:poll.15s aria-labelledby="admin-dashboard-stats-title">
    <div class="card-head">
        <h2 class="card-title" id="admin-dashboard-stats-title">System Overview</h2>
        <span class="admin-dashboard-stats__scope">Across all schools</span>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Schools</div>
            <div class="stat-value">{{ $this->schools() }}</div>
            <div class="stat-sub">Registered in the system</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">School Heads</div>
            <div class="stat-value">{{ $this->schoolHeads() }}</div>
            <div class="stat-sub">School Head accounts</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">OPCRF Submissions</div>
            <div class="stat-value">{{ $this->opcrfSubmissions() }}</div>
            <div class="stat-sub">Across all review stages</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">WFP Uploads</div>
            <div class="stat-value">{{ $this->wfpUploads() }}</div>
            <div class="stat-sub">Current uploaded plans</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">MOV Uploads</div>
            <div class="stat-value">{{ $this->movUploads() }}</div>
            <div class="stat-sub">Evidence files on record</div>
        </div>
    </div>
</section>

{{-- OPCRF ANALYTICS: the superadmin's dashboard card. How the review
     workload is going — counts, the average self-rating, the split by
     workflow status, and the newest submissions waiting to be dealt with.

     Polls like the rest of the dashboard's data-driven cards, so a review
     completed on the Review Opcrf page lands here without a reload. Every
     number is scoped to the submissions the signed-in superadmin may see
     (see OpcrfAnalytics). --}}
<div class="card opcrf-analytics" wire:poll.visible.60s>
    <div class="card-head">
        <div class="card-title">OPCRF Analytics</div>
        <a href="{{ route('opcrf.review') }}" class="card-link">Go to Review Opcrf →</a>
    </div>

    @if ($this->total() === 0)
        <p class="card-text">
            No OPCRF submissions to report on yet. Everything your staff members
            submit will be counted here.
        </p>
    @else
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Submitted</div>
                <div class="stat-value">{{ $this->total() }}</div>
                <div class="stat-sub">OPCRF on record</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Awaiting you</div>
                <div class="stat-value">{{ $this->awaiting() }}</div>
                <div class="stat-sub">In your review queue</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Approved</div>
                <div class="stat-value">{{ $this->approved() }}</div>
                <div class="stat-sub">Signed off</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">For revision</div>
                <div class="stat-value">{{ $this->returned() }}</div>
                <div class="stat-sub">Back with the staff</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Avg. self-rating</div>
                <div class="stat-value">{{ $this->averageRating() }}</div>
                <div class="stat-sub">Out of 5</div>
            </div>
        </div>

        <div class="opcrf-analytics-section">
            <div class="opcrf-analytics-section-title">By status</div>

            {{-- One stacked bar: each status is a slice of the whole. A
                 title carries the exact share, so the bar is readable
                 without relying on colour alone. --}}
            <div class="opcrf-analytics-bar" role="img" aria-label="Submissions by workflow status">
                @foreach ($this->breakdown() as $slice)
                    <span
                        class="opcrf-analytics-seg opcrf-analytics-seg--{{ $slice['tone'] }}"
                        style="width: {{ $slice['percent'] }}%"
                        title="{{ $slice['label'] }} — {{ $slice['count'] }} ({{ $slice['percent'] }}%)"
                    ></span>
                @endforeach
            </div>

            <div class="opcrf-analytics-legend">
                @foreach ($this->breakdown() as $slice)
                    <span class="opcrf-analytics-legend-item">
                        <span
                            class="opcrf-analytics-dot opcrf-analytics-seg--{{ $slice['tone'] }}"
                            aria-hidden="true"
                        ></span>
                        <span>{{ $slice['label'] }}</span>
                        <strong>{{ $slice['count'] }}</strong>
                    </span>
                @endforeach
            </div>
        </div>

        <div class="opcrf-analytics-section">
            <div class="opcrf-analytics-section-title">Review workflow</div>
            <div class="opcrf-workflow-flow" role="list" aria-label="OPCRF review workflow">
                <div class="opcrf-workflow-flow__step" role="listitem">
                    <span class="opcrf-workflow-flow__number">01</span>
                    <strong>School Head</strong>
                    <span>Submits full OPCRF</span>
                </div>
                <span class="opcrf-workflow-flow__arrow" aria-hidden="true">→</span>
                <div class="opcrf-workflow-flow__step" role="listitem">
                    <span class="opcrf-workflow-flow__number">02</span>
                    <strong>SDS Validator</strong>
                    <span>Checks Parts I–IV</span>
                </div>
                <span class="opcrf-workflow-flow__arrow" aria-hidden="true">→</span>
                <div class="opcrf-workflow-flow__step" role="listitem">
                    <span class="opcrf-workflow-flow__number">03</span>
                    <strong>Ma’am Eve</strong>
                    <span>Reviews the OPCRF</span>
                </div>
                <span class="opcrf-workflow-flow__arrow" aria-hidden="true">→</span>
                <div class="opcrf-workflow-flow__step" role="listitem">
                    <span class="opcrf-workflow-flow__number">04</span>
                    <strong>Sir SY</strong>
                    <span>Completes next review</span>
                </div>
                <span class="opcrf-workflow-flow__arrow" aria-hidden="true">→</span>
                <div class="opcrf-workflow-flow__step opcrf-workflow-flow__step--approved" role="listitem">
                    <span class="opcrf-workflow-flow__number">05</span>
                    <strong>Approved</strong>
                    <span>Review complete</span>
                </div>
            </div>
            <div class="opcrf-workflow-flow__return">
                <span aria-hidden="true">↩</span>
                <span><strong>Return for revision</strong> sends the OPCRF back to the School Head for updates.</span>
            </div>
        </div>

        <div class="opcrf-analytics-section">
            <div class="opcrf-analytics-section-title">Latest submissions</div>

            <ul class="opcrf-analytics-recent">
                @foreach ($this->recent() as $submission)
                    <li>
                        <span class="opcrf-analytics-who">
                            {{ $submission->employee_name !== '' ? $submission->employee_name : ($submission->user?->username ?? 'Unknown') }}
                        </span>
                        <span class="badge {{ $submission->statusBadgeClass() }}">
                            {{ $submission->statusLabelFor(Auth::user()) }}
                        </span>
                        <span class="opcrf-analytics-when">
                            {{ $submission->submitted_at?->format('M j, Y') ?? '—' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

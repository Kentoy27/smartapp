<section class="viewer-submission-overview" wire:poll.visible.60s aria-labelledby="viewer-submission-title">
    @php($stats = $this->stats())
    <div class="viewer-submission-overview__header">
        <div>
            <span class="viewer-submission-overview__eyebrow">REVIEW WORKSPACE</span>
            <h2 id="viewer-submission-title">School Head submissions</h2>
            <p>Review submitted AIP, OPCRF, and WFP documents, and browse SIP records.</p>
        </div>
        <span class="viewer-submission-overview__total">
            <strong>{{ number_format($stats['total']) }}</strong>
            <span>review submissions</span>
        </span>
    </div>

    <div class="viewer-submission-overview__cards">
        <a class="viewer-submission-card viewer-submission-card--aip" href="{{ route('aip.review') }}" wire:navigate.hover>
            <span class="viewer-submission-card__icon"><x-icon name="file-spreadsheet" :size="19" /></span>
            <span class="viewer-submission-card__label">AIP Review</span>
            <strong>{{ number_format($stats['aip']) }}</strong>
            <span class="viewer-submission-card__link">Open AIP Review <span aria-hidden="true">→</span></span>
        </a>
        <a class="viewer-submission-card viewer-submission-card--opcrf" href="{{ route('opcrf.review') }}" wire:navigate.hover>
            <span class="viewer-submission-card__icon"><x-icon name="eye" :size="19" /></span>
            <span class="viewer-submission-card__label">OPCRF</span>
            <strong>{{ number_format($stats['opcrf']) }}</strong>
            <span class="viewer-submission-card__link">Open OPCRF Review <span aria-hidden="true">→</span></span>
        </a>
        <a class="viewer-submission-card viewer-submission-card--wfp" href="{{ route('wfp.submissions.index') }}" wire:navigate.hover>
            <span class="viewer-submission-card__icon"><x-icon name="chart-column" :size="19" /></span>
            <span class="viewer-submission-card__label">WFP Review</span>
            <strong>{{ number_format($stats['wfp']) }}</strong>
            <span class="viewer-submission-card__link">Open WFP Review <span aria-hidden="true">→</span></span>
        </a>
        <a class="viewer-submission-card viewer-submission-card--sip" href="{{ route('sip.index') }}" wire:navigate.hover>
            <span class="viewer-submission-card__icon"><x-icon name="school" :size="19" /></span>
            <span class="viewer-submission-card__label">SIP</span>
            <strong>{{ number_format($stats['sip']) }}</strong>
            <span class="viewer-submission-card__link">Browse SIP Records <span aria-hidden="true">→</span></span>
        </a>
    </div>

    <section class="viewer-submission-flow" aria-labelledby="viewer-submission-flow-title">
        <div class="viewer-submission-flow__heading">
            <div>
                <h3 id="viewer-submission-flow-title">Submission workflow</h3>
                <p>Current status across AIP, OPCRF, and WFP review queues</p>
            </div>
            <span>{{ number_format($stats['total']) }} submissions</span>
        </div>

        <div class="viewer-submission-flow__diagram" role="group" aria-label="Submission status flow">
            <div class="viewer-submission-flow__node viewer-submission-flow__node--submitted">
                <span class="viewer-submission-flow__step">01 · IN REVIEW QUEUE</span>
                <strong>{{ number_format($stats['awaiting'] + $stats['in_review']) }}</strong>
                <span>Awaiting or in review</span>
            </div>
            <span class="viewer-submission-flow__arrow" aria-hidden="true">→</span>
            <div class="viewer-submission-flow__outcomes">
                <div class="viewer-submission-flow__node viewer-submission-flow__node--returned">
                    <span class="viewer-submission-flow__step">02 · RETURNED</span>
                    <strong>{{ number_format($stats['returned']) }}</strong>
                    <span>Needs School Head action</span>
                </div>
                <div class="viewer-submission-flow__node viewer-submission-flow__node--approved">
                    <span class="viewer-submission-flow__step">03 · APPROVED</span>
                    <strong>{{ number_format($stats['approved']) }}</strong>
                    <span>Approved documents</span>
                </div>
            </div>
        </div>
    </section>
</section>

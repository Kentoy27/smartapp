{{-- THE BUDGET DASHBOARD'S ALLOTMENT MONITOR: the current year at a glance,
     and the way into the page where the amount is set and certified.

     The figures are the School Allotments page's own overview (the same
     helper), so the dashboard is a summary of that page rather than a second
     set of numbers to reconcile. --}}
<section class="staff-dashboard-panel" aria-labelledby="dashboard-allotment-heading">
    <div class="staff-dashboard-panel__intro">
        <span class="staff-dashboard-panel__step">02</span>
        <div>
            <h2 id="dashboard-allotment-heading">Allotment monitoring</h2>
            <p>
                See what the schools may consume in CY {{ $year }}, and certify
                the amounts that are available.
            </p>
        </div>
    </div>

    <section class="allotment-summary allotment-summary--dashboard" aria-label="Allotment monitoring for CY {{ $year }}">
        <div class="allotment-summary__item">
            <span class="allotment-summary__label">Total allotted</span>
            <strong class="allotment-summary__value">
                {{ number_format($overview['allotted_total'], 2) }}
            </strong>
            <span class="allotment-summary__hint">
                Across {{ $overview['allotted_schools'] }} of {{ $overview['schools'] }} schools
            </span>
        </div>

        <div class="allotment-summary__item">
            <span class="allotment-summary__label">Consumed against allotments</span>
            <strong class="allotment-summary__value">
                {{ number_format($overview['consumed_total'], 2) }}
            </strong>
            <span class="allotment-summary__hint">CY {{ $year }} matrix lines</span>
        </div>

        <div class="allotment-summary__item">
            <span class="allotment-summary__label">
                {{ $overview['remaining_total'] < 0 ? 'Over allotment' : 'Remaining' }}
            </span>
            <strong @class([
                'allotment-summary__value',
                'is-over' => $overview['remaining_total'] < 0,
            ])>
                {{ number_format(abs($overview['remaining_total']), 2) }}
            </strong>
            <span class="allotment-summary__hint">Across schools with an allotment</span>
        </div>

        <div class="allotment-summary__item">
            <span class="allotment-summary__label">Certified</span>
            <strong class="allotment-summary__value">
                {{ $overview['certified_schools'] }} / {{ $overview['allotted_schools'] }}
            </strong>
            <span class="allotment-summary__hint">
                @if ($overview['allotted_schools'] === $overview['certified_schools'])
                    {{ $overview['allotted_schools'] === 0 ? 'No allotments set yet' : 'All allotments certified' }}
                @else
                    {{ $overview['allotted_schools'] - $overview['certified_schools'] }} awaiting certification
                @endif
            </span>
        </div>
    </section>

    <a href="{{ route('budget.allotments.index') }}" class="dashboard-plan-card__link" wire:navigate>
        <x-icon name="wallet" :size="14" />
        Open School Allotments <span aria-hidden="true">→</span>
    </a>
</section>

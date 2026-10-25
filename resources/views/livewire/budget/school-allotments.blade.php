{{-- SCHOOL ALLOTMENTS — the Budget office's control for the ceiling each
     School Head may consume in a calendar year, and for certifying that the
     amount is available.

     The allotment is stored per school and year; the consumption beside it is
     read from that school's Monthly Expenditure Matrices (quantity × unit
     cost), so the balance is always the current one. The amount field is the
     only editable figure: a blank value clears the allotment. Changing an
     amount clears its certification, because the certificate covers the
     amount that was on the row when it was signed. --}}
<div class="school-allotment-component">

    <div class="page-header">
        <h1>School Allotments</h1>
        <p>
            Set the annual allotment budget each School Head may consume through
            the school’s Monthly Expenditure Matrices, then certify the amount
            as available.
        </p>
    </div>

    @if ($successMessage)
        <div
            wire:key="allotment-alert-{{ md5($successMessage) }}"
            x-data
            x-init="
                if (window.smartAlert) {
                    smartAlert.success({{ \Illuminate\Support\Js::from($successMessage) }});
                }
                $wire.clearSuccess();
            "
            class="success-alert-sentinel"
            aria-hidden="true"
        ></div>
    @endif

    @if ($errorMessage)
        <div class="ac-import-error" role="alert">{{ $errorMessage }}</div>
    @endif

    {{-- THE MONITORING STRIP: the whole year, not the page. It answers
         "how much is allotted, how much is spent, and how many schools has
         the Budget office certified?" before the reader scans a single row. --}}
    <section
        class="allotment-summary allotment-summary--monitor"
        aria-label="Allotment monitoring for CY {{ $this->year }}"
    >
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
            <span class="allotment-summary__hint">CY {{ $this->year }} matrix lines</span>
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

        <p class="allotment-summary__note">
            Certification states that the amount is available for the school.
            Changing an amount clears its certification; the Budget office
            certifies the new figure on its own.
        </p>
    </section>

    <div class="card">
        <div class="card-head users-toolbar">
            <div class="users-search">
                <input
                    type="search"
                    placeholder="Search school, School ID, district, or School Head…"
                    wire:model.live.debounce.300ms="search"
                    aria-label="Search schools"
                >

                @if (trim($search) !== '')
                    <button type="button" class="users-clear" wire:click="clearSearch" title="Clear search">×</button>
                @endif
            </div>

            <label class="users-per-page">
                <span>Year</span>
                <select wire:model.live="year" aria-label="Budget year">
                    @foreach ($this->years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>

            <label class="users-per-page">
                <span>Rows</span>
                <select wire:model.live="perPage">
                    @foreach ([10, 25, 50] as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="table-wrap">
            <table class="data-table school-allotment-table">
                <thead>
                    <tr>
                        <th scope="col">School</th>
                        <th scope="col">School Head</th>
                        <th scope="col">Allotted budget</th>
                        <th scope="col" class="allotment-money">Consumed</th>
                        <th scope="col" class="allotment-money">Remaining</th>
                        <th scope="col">Certification</th>
                        <th scope="col" class="users-actions-col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($schools as $school)
                        @php
                            $record = $allotments->get($school->id);
                            $allotted = $record !== null ? (float) $record->amount : null;
                            $spent = (float) ($consumed[$school->id] ?? 0.0);
                            $remaining = $allotted !== null ? round($allotted - $spent, 2) : null;
                            $isOver = $allotted !== null && $spent > $allotted;
                            $isCertified = $record?->isCertified() ?? false;
                        @endphp

                        <tr wire:key="school-allotment-{{ $school->id }}">
                            <td>
                                <span class="cell-strong">{{ $school->name }}</span>
                                <span class="cell-sub">
                                    {{ $school->district?->name ?? 'No district' }}@if ($school->school_id) · {{ $school->school_id }}@endif
                                </span>
                            </td>

                            <td>
                                @forelse ($school->users as $head)
                                    <span class="cell-strong">{{ $head->name !== '' ? $head->name : $head->username }}</span>
                                    <span class="cell-sub">{{ $head->username }}</span>
                                @empty
                                    <span class="badge badge-muted">No SH account</span>
                                @endforelse
                            </td>

                            <td>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    class="allotment-input"
                                    placeholder="Not set"
                                    wire:model="amounts.{{ $school->id }}"
                                    wire:key="allotment-amount-{{ $school->id }}"
                                    aria-label="Allotment for {{ $school->name }} in {{ $this->year }}"
                                >
                                @error('amounts.'.$school->id)
                                    <span class="error-text">{{ $message }}</span>
                                @enderror

                                @if ($record !== null)
                                    <span class="cell-sub">
                                        Set by {{ $record->setter?->username ?? '—' }}
                                        @if ($record->updated_at)
                                            · {{ $record->updated_at->format('M j, Y') }}
                                        @endif
                                    </span>
                                @endif
                            </td>

                            <td class="allotment-money">{{ number_format($spent, 2) }}</td>

                            <td class="allotment-money">
                                @if ($remaining === null)
                                    <span class="allotment-muted">—</span>
                                @else
                                    <span @class(['allotment-remaining', 'is-over' => $isOver])>
                                        {{ number_format($remaining, 2) }}
                                    </span>
                                    <span @class(['badge', 'allotment-badge--over' => $isOver, 'badge-muted' => ! $isOver])>
                                        {{ $isOver ? 'Over' : 'Within' }}
                                    </span>
                                @endif
                            </td>

                            <td>
                                @if ($record === null)
                                    <span class="allotment-muted">—</span>
                                    <span class="cell-sub">Set an amount first</span>
                                @elseif ($isCertified)
                                    <span class="badge allotment-badge--certified">Certified</span>
                                    <span class="cell-sub">
                                        {{ $record->certifier?->name ?: $record->certifier?->username }}
                                        · {{ $record->certified_at->format('M j, Y') }}
                                    </span>

                                    @if ($canCertify)
                                        <button
                                            type="button"
                                            class="allotment-certify"
                                            wire:click="openCertifyModal({{ $school->id }})"
                                            title="Certify the {{ $this->year }} allotment for {{ $school->name }} again"
                                        >Re-certify</button>
                                    @endif
                                @else
                                    <span class="badge badge-muted">Not certified</span>

                                    @if ($canCertify)
                                        <button
                                            type="button"
                                            class="allotment-certify"
                                            wire:click="openCertifyModal({{ $school->id }})"
                                            title="Certify the {{ $this->year }} allotment for {{ $school->name }}"
                                        >Certify</button>
                                    @else
                                        <span class="cell-sub">The Budget office certifies</span>
                                    @endif
                                @endif
                            </td>

                            <td class="users-actions-col">
                                <button
                                    type="button"
                                    class="users-action"
                                    wire:click="save({{ $school->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="save"
                                    title="Save the {{ $this->year }} allotment for {{ $school->name }}"
                                >
                                    <span class="users-action-inner">
                                        <x-icon name="check" :size="14" />
                                        <span>Save</span>
                                    </span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="table-empty">
                                @if (trim($search) !== '')
                                    No schools match “{{ $search }}”.
                                @else
                                    No schools to display yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="users-footer">
            <span class="users-count">
                @if ($schools->total() > 0)
                    {{ $schools->firstItem() }}–{{ $schools->lastItem() }} of {{ $schools->total() }}
                @else
                    0 schools
                @endif
            </span>

            {{ $schools->links() }}
        </div>

        <p class="allotment-footnote">
            Consumed is the sum of the school’s CY {{ $this->year }} Monthly
            Expenditure Matrix lines (quantity × unit cost). Clearing an amount
            removes the allotment; the expenditure record is untouched. A
            certified amount appears as the certifying officer on the School
            Head’s Per Line Item Expenditure report.
        </p>
    </div>

    {{-- CERTIFY CONFIRMATION: the certification is a statement the Budget
         officer signs, so it is confirmed against the school, year and exact
         amount before it is recorded. Rendered only while it is open. --}}
    @if ($certifyingSchool && $certifyingAllotment)
        @php
            $certifySpent = (float) $certifyingSpent;
            $certifyAmount = (float) $certifyingAllotment->amount;
            $certifyRemaining = round($certifyAmount - $certifySpent, 2);
        @endphp

        <div
            class="modal-backdrop is-open"
            @click="self && $wire.closeCertifyModal()"
            role="presentation"
        >
            <div
                class="modal modal--confirmation"
                role="dialog"
                aria-modal="true"
                aria-labelledby="certifyAllotmentTitle"
                @click.stop
            >
                <div class="modal-head">
                    <h2 id="certifyAllotmentTitle">Certify Availability of Allotment</h2>
                    <button
                        type="button"
                        class="modal-close"
                        wire:click="closeCertifyModal"
                        aria-label="Close"
                        title="Close"
                    >×</button>
                </div>

                <div class="modal-form">
                    <p class="delete-modal-description">
                        Certify that
                        <strong>{{ number_format($certifyAmount, 2) }}</strong>
                        is available for <strong>{{ $certifyingSchool->name }}</strong>
                        in CY {{ $this->year }}?
                    </p>

                    <p class="allotment-footnote">
                        Consumed so far: {{ number_format($certifySpent, 2) }} ·
                        {{ $certifyRemaining < 0 ? 'Over allotment by' : 'Remaining' }}:
                        {{ number_format(abs($certifyRemaining), 2) }}
                    </p>

                    <p class="allotment-footnote">
                        Signed as
                        <strong>{{ auth()->user()->name ?: auth()->user()->username }}</strong>,
                        {{ auth()->user()->position ?: 'Budget Officer III' }}.
                        The School Head’s report will name you as the certifying
                        officer.
                    </p>

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="btn-ghost"
                            wire:click="closeCertifyModal"
                            wire:loading.attr="disabled"
                            wire:target="certify"
                        >Cancel</button>
                        <button
                            type="button"
                            class="btn-primary"
                            wire:click="certify({{ $certifyingSchool->id }})"
                            wire:loading.attr="disabled"
                            wire:target="certify"
                        >
                            <span wire:loading.remove wire:target="certify">
                                <x-icon name="check" :size="16" /> Certify availability
                            </span>
                            <span wire:loading wire:target="certify">Certifying…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

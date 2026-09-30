<div class="opcrf-form-component">

    {{-- SUCCESS ALERT: popped once after the confirmed submit, then the
         message is cleared so later renders never re-fire it. --}}
    @if ($successMessage)
        <div
            wire:key="alert-{{ md5($successMessage) }}"
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

    <div class="card">
        <div class="card-head">
            <div class="card-title">Submit your OPCRF</div>
            <a href="{{ route('opcrf.template') }}" class="card-link opcrf-card-link" download>
                <x-icon name="download" :size="14" />
                <span>Download template</span>
            </a>
        </div>
        <p class="card-text">
            Fill in the same details as the OPCRF-TEMPLATE.xlsx form, then press
            <strong>Submit</strong>. A review panel will pop up first so you can
            double-check everything before it is recorded — nothing is saved until
            you confirm it there.
        </p>

        <form wire:submit="submitForReview" class="opcrf-form">
            <div class="opcrf-grid">
                <div class="field">
                    <label for="opcrfEmployeeName">Name of Employee</label>
                    <input
                        id="opcrfEmployeeName"
                        type="text"
                        placeholder="e.g. Jane D. Doe"
                        autocomplete="name"
                        wire:model="employee_name"
                        @class(['error' => $errors->has('employee_name')])
                    >
                    @error('employee_name') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="opcrfPosition">Position/Designation</label>
                    <input
                        id="opcrfPosition"
                        type="text"
                        placeholder="e.g. Teacher I"
                        autocomplete="organization-title"
                        wire:model="position"
                        @class(['error' => $errors->has('position')])
                    >
                    @error('position') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="opcrfReviewPeriod">Review Period</label>
                    <input
                        id="opcrfReviewPeriod"
                        type="text"
                        placeholder="e.g. January to December {{ date('Y') }}"
                        wire:model="review_period"
                        @class(['error' => $errors->has('review_period')])
                    >
                    @error('review_period') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="opcrfDivision">Strand/Bureau/Center/Service/Region/Division</label>
                    <input
                        id="opcrfDivision"
                        type="text"
                        placeholder="e.g. Schools Division Office"
                        wire:model="division_office"
                        @class(['error' => $errors->has('division_office')])
                    >
                    @error('division_office') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field opcrf-field--full">
                    <label for="opcrfObjectives">Objectives / Major Final Outputs</label>
                    <textarea
                        id="opcrfObjectives"
                        rows="4"
                        placeholder="List each objective, one per line…"
                        wire:model="objectives"
                        @class(['error' => $errors->has('objectives')])
                    ></textarea>
                    @error('objectives') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field opcrf-field--full">
                    <label for="opcrfAccomplishments">Actual Accomplishments</label>
                    <textarea
                        id="opcrfAccomplishments"
                        rows="4"
                        placeholder="What was actually delivered, per objective…"
                        wire:model="accomplishments"
                        @class(['error' => $errors->has('accomplishments')])
                    ></textarea>
                    @error('accomplishments') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="opcrfRating">Self Rating (1–5)</label>
                    <input
                        id="opcrfRating"
                        type="number"
                        step="0.01"
                        min="1"
                        max="5"
                        placeholder="e.g. 4.50"
                        wire:model="self_rating"
                        @class(['error' => $errors->has('self_rating')])
                    >
                    <small class="opcrf-rating-scale">5 = Outstanding · 4 = Very Satisfactory · 3 = Satisfactory · 2 = Unsatisfactory · 1 = Poor</small>
                    @error('self_rating') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="opcrfRemarks">Remarks <span class="opcrf-optional">(optional)</span></label>
                    <input
                        id="opcrfRemarks"
                        type="text"
                        placeholder="Anything the reviewing officer should know"
                        wire:model="remarks"
                        @class(['error' => $errors->has('remarks')])
                    >
                    @error('remarks') <div class="error-text">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="opcrf-form-actions">
                <span class="opcrf-review-hint">
                    <x-icon name="shield" :size="14" />
                    You'll get a chance to review everything before it's submitted.
                </span>
                <button type="submit" class="opcrf-submit-btn" wire:loading.attr="disabled" wire:target="submitForReview">
                    <span wire:loading.remove wire:target="submitForReview">Submit OPCRF</span>
                    <span wire:loading wire:target="submitForReview">Checking…</span>
                </button>
            </div>
        </form>
    </div>

    {{-- REVIEW MODAL: pops up within the page when the form passes validation.
         The entered details are rendered as an Excel-style sheet (same design
         as the upload flow's review modal): navy title banner, header block,
         bordered objectives table and an AVERAGE (QET) footer. Nothing has
         been saved yet at this point. --}}
    @if ($showReview)
        @php
            $objectiveLines = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', $objectives)),
                fn ($line) => $line !== ''
            ));
            $accomplishmentLines = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', $accomplishments)),
                fn ($line) => $line !== ''
            ));
            $sheetRows = [];
            foreach (range(0, max(count($objectiveLines), count($accomplishmentLines)) - 1) as $i) {
                $sheetRows[] = [
                    'objective' => $objectiveLines[$i] ?? '—',
                    'accomplishment' => $accomplishmentLines[$i] ?? '—',
                ];
            }
        @endphp

        <div
            class="modal-backdrop is-open"
            x-data
            @keydown.escape.window="$wire.cancelReview()"
            @click.self="$wire.cancelReview()"
            role="presentation"
        >
            <div class="modal modal--opcrf-review modal--opcrf-sheet" role="dialog" aria-modal="true" aria-labelledby="opcrfReviewTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="opcrfReviewTitle">Review your OPCRF</h2>
                    <button type="button" class="modal-close" wire:click="cancelReview" aria-label="Close" title="Close">×</button>
                </div>

                <p class="opcrf-review-note">
                    Please check every detail below — shown exactly like the Excel
                    form. This is what will be recorded.
                </p>

                <div class="modal-form opcrf-review-body">
                    <div class="opcrf-review-summary opcrf-review-summary--sheet">
                        <div class="opcrf-sheet">
                            <div class="opcrf-sheet-banner">
                                OFFICE PERFORMANCE COMMITMENT AND REVIEW FORM (OPCRF)
                            </div>

                            <div class="opcrf-sheet-headerblock">
                                <div class="opcrf-sheet-headrow">
                                    <span class="opcrf-sheet-headlabel">Name of Employee:</span>
                                    <span class="opcrf-sheet-headvalue">{{ $employee_name }}</span>
                                </div>
                                <div class="opcrf-sheet-headrow">
                                    <span class="opcrf-sheet-headlabel">Position/Designation:</span>
                                    <span class="opcrf-sheet-headvalue">{{ $position }}</span>
                                </div>
                                <div class="opcrf-sheet-headrow">
                                    <span class="opcrf-sheet-headlabel">Review Period:</span>
                                    <span class="opcrf-sheet-headvalue">{{ $review_period }}</span>
                                </div>
                                <div class="opcrf-sheet-headrow">
                                    <span class="opcrf-sheet-headlabel">Strand/Bureau/Center/Service/Region/Division:</span>
                                    <span class="opcrf-sheet-headvalue">{{ $division_office }}</span>
                                </div>
                            </div>

                            <div class="opcrf-sheet-tablewrap">
                                <table class="opcrf-sheet-table opcrf-sheet-table--form">
                                    <thead>
                                        <tr>
                                            <th class="opcrf-sheet-num" scope="col">#</th>
                                            <th class="opcrf-sheet-obj" scope="col">Objectives <span class="opcrf-sheet-th-sub">(based on Office Functions)</span></th>
                                            <th class="opcrf-sheet-acc" scope="col">Actual Accomplishments</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($sheetRows as $i => $row)
                                            <tr wire:key="sheetrow-{{ $i }}">
                                                <td class="opcrf-sheet-num">{{ $i + 1 }}</td>
                                                <td class="opcrf-sheet-obj">{{ $row['objective'] }}</td>
                                                <td class="opcrf-sheet-acc">{{ $row['accomplishment'] }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="3" class="opcrf-sheet-empty">No objectives were entered.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="2">AVERAGE (QET)</td>
                                            <td class="opcrf-sheet-rate">{{ number_format((float) $self_rating, 2) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <div class="opcrf-sheet-remarks">
                                <div class="opcrf-sheet-headrow">
                                    <span class="opcrf-sheet-headlabel">Remarks:</span>
                                    <span class="opcrf-sheet-headvalue {{ $remarks === '' ? 'is-empty' : '' }}">
                                        {{ $remarks !== '' ? $remarks : '—' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <p class="opcrf-review-confirm-line">
                            Submitted by <strong>{{ auth()->user()->username }}</strong>
                            on {{ now()->format('M j, Y g:i A') }}.
                        </p>
                    </div>

                    @if ($errors->any())
                        <div class="opcrf-sheet-errors" role="alert">
                            <strong>This OPCRF can't be submitted yet:</strong>
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="cancelReview">Go back &amp; edit</button>
                        <button type="button" class="btn-primary" wire:click="confirmSubmit" wire:loading.attr="disabled" wire:target="confirmSubmit">
                            <span wire:loading.remove wire:target="confirmSubmit">Confirm &amp; Submit</span>
                            <span wire:loading wire:target="confirmSubmit">Submitting…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

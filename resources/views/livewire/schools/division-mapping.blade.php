{{-- SCHOOL DIVISION MAPPING — the administrator's page for assigning the
     financial division of records that predate the division columns.

     The feature's migration carried every existing row over as Elementary
     rather than guessing a High School's Junior/Senior division. This page is
     the explicit mapping: pick the school, the division its records currently
     read as, and the division they belong to. Conflicts are reported and left
     untouched — a mapping never merges or overwrites money. --}}
<div class="division-mapping">

    <div class="page-header">
        <h1>School Division Mapping</h1>
        <p>
            Assign the school division of existing financial records. Records
            created before divisions existed read as <strong>Elementary</strong>;
            map a High School’s records to <strong>Junior High School</strong> or
            <strong>Senior High School</strong> here. Nothing is assigned
            automatically, and a record is never merged or overwritten — a period
            that already has a record in the target division is reported and left
            as it is.
        </p>
    </div>

    @if ($successMessage)
        <div
            wire:key="division-mapping-alert-{{ md5($successMessage) }}"
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

    <div class="card">
        <div class="card-head">
            <h2 class="card-title">Map a school’s records</h2>
        </div>

        <div class="division-mapping__form">
            <div class="field">
                <label for="mappingSchool">School</label>
                <select id="mappingSchool" wire:model.live="school">
                    <option value="">Select School</option>
                    @foreach ($this->schools as $schoolOption)
                        <option value="{{ $schoolOption->id }}">
                            {{ $schoolOption->name }}@if ($schoolOption->school_id) · {{ $schoolOption->school_id }}@endif
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="mappingSource">Records currently read as</label>
                <select id="mappingSource" wire:model.live="sourceDivision">
                    @foreach ($this->divisionOptions() as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="mappingTarget">Move them to</label>
                <select id="mappingTarget" wire:model.live="targetDivision">
                    <option value="">Select Division</option>
                    @foreach ($this->divisionOptions() as $option)
                        @if ($option['value'] !== $sourceDivision)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
        </div>

        @if ($this->preview !== null)
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Record</th>
                            <th scope="col">{{ \App\Support\SchoolDivision::label($sourceDivision) }} rows</th>
                            <th scope="col">Conflicts in target</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->preview as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td>{{ number_format($row['count']) }}</td>
                                <td>
                                    @if ($row['conflicts'] > 0)
                                        <span class="badge">{{ number_format($row['conflicts']) }} left untouched</span>
                                    @else
                                        <span class="badge badge-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="division-mapping__actions">
                <button
                    type="button"
                    class="btn btn-primary"
                    wire:click="map"
                    wire:loading.attr="disabled"
                    wire:target="map"
                    @disabled($targetDivision === '')
                >
                    <span wire:loading.remove wire:target="map">Map records</span>
                    <span wire:loading wire:target="map">Mapping…</span>
                </button>
                <span class="division-mapping__hint">
                    The whole mapping runs in one transaction: it lands in full or not at all.
                </span>
            </div>
        @endif

        @if ($conflictNotes !== [])
            <div class="ac-import-error" role="alert">
                <strong>Left untouched — a target-division record already exists for the same period:</strong>
                <ul>
                    @foreach ($conflictNotes as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <style>
        .division-mapping__form {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            padding: 16px 20px;
        }

        .division-mapping__actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 16px 20px;
        }

        .division-mapping__hint {
            color: var(--text-faint);
            font-size: 0.8125rem;
        }

        .division-mapping .ac-import-error ul {
            margin: 8px 0 0;
            padding-left: 18px;
        }
    </style>
</div>

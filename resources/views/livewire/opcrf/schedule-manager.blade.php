<div class="opcrf-schedule">

    {{-- SUCCESS: popped once after a save/toggle/delete. --}}
    @if ($successMessage)
        <div
            wire:key="sched-alert-{{ md5($successMessage) }}"
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
            <div>
                <div class="card-title">OPCRF Schedule</div>
                <p class="card-text">
                    Control exactly when each OPCRF Part may be opened. A Part
                    that is not inside its access period is locked for staff —
                    the rule is applied on the server, every time a Part is opened.
                </p>
            </div>
            @if ($canCreate)
                <button type="button" class="users-add-btn" wire:click="openCreate()">
                    <x-icon name="plus" :size="16" />
                    <span>Add Schedule</span>
                </button>
            @endif
        </div>

        {{-- YEAR SWITCHER: the schedule is per OPCRF year, and the year is
             never hardcoded — these are the years that exist plus the default. --}}
        <div class="schedule-years" role="tablist" aria-label="OPCRF year">
            @foreach ($years as $option)
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $option === $year ? 'true' : 'false' }}"
                    class="schedule-year {{ $option === $year ? 'is-active' : '' }}"
                    wire:click="selectYear({{ $option }})"
                >
                    OPCRF {{ $option }}
                </button>
            @endforeach
        </div>

        <div class="table-wrap">
            <table class="data-table schedule-table">
                <thead>
                    <tr>
                        <th>OPCRF Year</th>
                        <th>Part</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Status</th>
                        <th class="schedule-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            $schedule = $row['schedule'];
                            $status = $row['status'];
                            $notScheduled = $status === \App\Support\OpcrfAccess::STATUS_NOT_SCHEDULED;
                        @endphp
                        <tr wire:key="sched-{{ $year }}-{{ $row['part'] }}">
                            <td>{{ $year }}</td>
                            <td><strong>{{ $row['label'] }}</strong></td>

                            <td>
                                @if ($schedule)
                                    <span class="schedule-when">{{ $schedule->start_datetime->format('M j, Y') }}</span>
                                    <span class="schedule-when">{{ $schedule->start_datetime->format('g:i A') }}</span>
                                @else
                                    <span class="is-empty">—</span>
                                @endif
                            </td>

                            <td>
                                @if ($schedule)
                                    <span class="schedule-when">{{ $schedule->end_datetime->format('M j, Y') }}</span>
                                    <span class="schedule-when">{{ $schedule->end_datetime->format('g:i A') }}</span>
                                @else
                                    <span class="is-empty">—</span>
                                @endif
                            </td>

                            {{-- The status is computed from the current server
                                 time, not stored and not sent by the browser. --}}
                            <td>
                                <span class="badge {{ $notScheduled ? 'badge-muted' : \App\Models\OpcrfSchedule::statusBadgeClass($status) }}">
                                    {{ $notScheduled ? 'Not scheduled' : \App\Models\OpcrfSchedule::statusLabel($status) }}
                                </span>
                            </td>

                            <td class="schedule-actions-col">
                                <div class="schedule-actions">
                                    @if ($schedule)
                                        <button type="button" class="users-action" wire:click="openEdit({{ $schedule->id }})">
                                            <span class="users-action-inner">
                                                <x-icon name="pencil" :size="14" />
                                                <span>Edit</span>
                                            </span>
                                        </button>

                                        <button type="button" class="users-action" wire:click="toggle({{ $schedule->id }})">
                                            <span class="users-action-inner">
                                                <x-icon :name="$schedule->is_enabled ? 'lock' : 'unlock'" :size="14" />
                                                <span>{{ $schedule->is_enabled ? 'Disable' : 'Enable' }}</span>
                                            </span>
                                        </button>

                                        @if ($confirmingDeleteId === $schedule->id)
                                            <button type="button" class="users-action users-action--danger" wire:click="delete({{ $schedule->id }})">
                                                <span class="users-action-inner">
                                                    <x-icon name="trash" :size="14" />
                                                    <span>Confirm delete</span>
                                                </span>
                                            </button>
                                            <button type="button" class="users-action" wire:click="cancelDelete()">
                                                <span class="users-action-inner"><span>Keep</span></span>
                                            </button>
                                        @else
                                            <button type="button" class="users-action users-action--danger" wire:click="confirmDelete({{ $schedule->id }})">
                                                <span class="users-action-inner">
                                                    <x-icon name="trash" :size="14" />
                                                    <span>Delete</span>
                                                </span>
                                            </button>
                                        @endif
                                    @else
                                        <button type="button" class="users-action" wire:click="openCreate({{ $row['part'] }})">
                                            <span class="users-action-inner">
                                                <x-icon name="plus" :size="14" />
                                                <span>Add Schedule</span>
                                            </span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>

                        @if ($schedule?->description)
                            <tr class="schedule-note-row" wire:key="sched-note-{{ $schedule->id }}">
                                <td colspan="6">
                                    <span class="schedule-note-label">Instructions shown to staff:</span>
                                    {{ $schedule->description }}
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="schedule-footnote">
            Times are shown in the application timezone
            (<strong>{{ config('app.timezone') }}</strong>) — the same zone used to
            decide whether a Part is open, so what you see here is exactly what staff are judged by.
        </p>
    </div>

    {{-- AUDIT TRAIL: who changed a window, from what, to what. Survives
         the schedule row being deleted. --}}
    <div class="card schedule-log-card">
        <div class="card-head">
            <div class="card-title">Schedule activity — {{ $year }}</div>
        </div>

        @if ($logs->isEmpty())
            <p class="card-text">No schedule changes recorded for {{ $year }} yet.</p>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Superadmin</th>
                            <th>Part</th>
                            <th>Action</th>
                            <th>Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr wire:key="log-{{ $log->id }}">
                                <td class="schedule-when">{{ $log->created_at->format('M j, Y g:i A') }}</td>
                                <td>{{ $log->actor_name }}</td>
                                <td>Part {{ $log->part_number }}</td>
                                <td>
                                    <span class="badge badge-muted">{{ $log->summary() }}</span>
                                </td>
                                <td class="schedule-change">
                                    @if ($log->action === \App\Models\OpcrfScheduleLog::ACTION_DELETED)
                                        <span class="is-empty">Window removed</span>
                                    @else
                                        @php
                                            $old = $log->old_values['start_datetime'] ?? null;
                                            $new = $log->new_values['start_datetime'] ?? null;
                                        @endphp
                                        @if ($old)
                                            <span class="schedule-change-old">
                                                {{ \Illuminate\Support\Carbon::parse($old)->format('M j, Y g:i A') }}
                                                &rarr;
                                                {{ \Illuminate\Support\Carbon::parse($log->old_values['end_datetime'] ?? $old)->format('M j, Y g:i A') }}
                                            </span>
                                        @endif
                                        @if ($new)
                                            <span class="schedule-change-new">
                                                {{ \Illuminate\Support\Carbon::parse($new)->format('M j, Y g:i A') }}
                                                &rarr;
                                                {{ \Illuminate\Support\Carbon::parse($log->new_values['end_datetime'] ?? $new)->format('M j, Y g:i A') }}
                                            </span>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ADD / EDIT MODAL --}}
    @if ($showModal)
        <div
            class="modal-backdrop is-open"
            x-data
            @keydown.escape.window="$wire.closeModal()"
            @click.self="$wire.closeModal()"
            role="presentation"
        >
            <div class="modal modal--schedule" role="dialog" aria-modal="true" aria-labelledby="scheduleModalTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="scheduleModalTitle">
                        {{ $editingId ? 'Edit Schedule' : 'Add Schedule' }} — OPCRF {{ $year }}
                    </h2>
                    <button type="button" class="modal-close" wire:click="closeModal" aria-label="Close" title="Close">×</button>
                </div>

                <form wire:submit="save" class="modal-form schedule-form">
                    <div class="field">
                        <label for="schedYear">OPCRF Year</label>
                        <input id="schedYear" type="number" wire:model="year" min="2000" max="2999" class="{{ $errors->has('year') ? 'error' : '' }}">
                        @error('year') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="schedPart">Part</label>
                        <select id="schedPart" wire:model="part_number" class="{{ $errors->has('part_number') ? 'error' : '' }}">
                            @foreach (\App\Models\OpcrfSchedule::parts() as $part)
                                <option value="{{ $part }}">Part {{ $part }}</option>
                            @endforeach
                        </select>
                        @error('part_number') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="schedule-field-row">
                        <div class="field">
                            <label for="schedStartDate">Start Date</label>
                            <input id="schedStartDate" type="date" wire:model="start_date" class="{{ $errors->has('start_date') ? 'error' : '' }}">
                            @error('start_date') <div class="error-text">{{ $message }}</div> @enderror
                        </div>

                        <div class="field">
                            <label for="schedStartTime">Start Time</label>
                            <input id="schedStartTime" type="time" wire:model="start_time" class="{{ $errors->has('start_time') ? 'error' : '' }}">
                            @error('start_time') <div class="error-text">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="schedule-field-row">
                        <div class="field">
                            <label for="schedEndDate">End Date</label>
                            <input id="schedEndDate" type="date" wire:model="end_date" class="{{ $errors->has('end_date') ? 'error' : '' }}">
                            @error('end_date') <div class="error-text">{{ $message }}</div> @enderror
                        </div>

                        <div class="field">
                            <label for="schedEndTime">End Time</label>
                            <input id="schedEndTime" type="time" wire:model="end_time" class="{{ $errors->has('end_time') ? 'error' : '' }}">
                            @error('end_time') <div class="error-text">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    @error('window.end') <div class="error-text">{{ $message }}</div> @enderror

                    <div class="field">
                        <label for="schedDesc">Description <span class="opcrf-optional">(optional)</span></label>
                        <textarea
                            id="schedDesc"
                            wire:model="description"
                            rows="3"
                            placeholder="Instructions staff see on this Part, e.g. Bring your signed WFP and AIP."
                            class="{{ $errors->has('description') ? 'error' : '' }}"
                        ></textarea>
                        @error('description') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <label class="schedule-enabled">
                        <input type="checkbox" wire:model="is_enabled">
                        <span>
                            <strong>Enabled</strong>
                            <small>Switching this off closes the Part immediately, even inside its window.</small>
                        </span>
                    </label>

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="closeModal">Cancel</button>
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
                            Save Schedule
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
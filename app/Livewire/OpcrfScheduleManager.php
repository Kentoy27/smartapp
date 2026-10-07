<?php

namespace App\Livewire;

use App\Models\OpcrfSchedule;
use App\Models\OpcrfScheduleLog;
use App\Support\OpcrfAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The superadmin's OPCRF calendar: when each Part of the form may be opened.
 *
 * One row per (year, Part). Saving a row decides, from that moment, whether
 * a staff member can open the Part — the access rules themselves live in
 * App\Support\OpcrfAccess, so this component only edits the data those rules
 * read. Extending a deadline is an ordinary edit here, and it applies
 * immediately because nothing about the decision is cached.
 *
 * Every change is written to opcrf_schedule_logs with the actor's name
 * copied at the time, before and after values, and what was done — a
 * deadline is something staff plan around, so the record of who moved it
 * outlives the row.
 *
 * The component is only ever mounted for a superadmin; ensureSuperadmin() refuses
 * anyone else at the top of every action, so a crafted Livewire call cannot
 * reach the write path.
 */
#[Title('OPCRF Schedule — SmartApp')]
class OpcrfScheduleManager extends Component
{
    /**
     * The OPCRF year being administered. Read from the query string when the
     * superadmin switches years.
     */
    public int $year = 0;

    /** Add/Edit modal state. */
    public bool $showModal = false;

    /** The schedule being edited, or null when adding. */
    public ?int $editingId = null;

    public int $part_number = 1;

    public string $start_date = '';

    public string $start_time = '08:00';

    public string $end_date = '';

    public string $end_time = '17:00';

    public string $description = '';

    public bool $is_enabled = true;

    /**
     * The start/end fields combined into one comparable pair, rebuilt on
     * every change so the ordering rule always sees what is on screen.
     *
     * @var array{start?: string, end?: string}
     */
    public array $window = [];

    /** Confirm-before-delete state. */
    public ?int $confirmingDeleteId = null;

    public ?string $successMessage = null;

    /**
     * Refuse anyone who is not a superadmin, on mount and on every action.
     *
     * The route is already superadmin-only, but the component is the thing
     * that writes, so it checks for itself rather than trusting the page that
     * happened to render it.
     */
    public function mount(): void
    {
        $this->ensureSuperadmin();

        $this->year = (int) ($this->year ?: OpcrfAccess::currentYear());
    }

    protected function ensureSuperadmin(): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);
    }

    /**
     * Server-side validation for the schedule form.
     *
     * The end must not precede the start, and the (year, Part) pair must be
     * free — one window per Part per year, enforced here and by a unique
     * index, so a race cannot slip a duplicate past the check.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2999'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'end_time' => ['required', 'date_format:H:i'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_enabled' => ['boolean'],

            // The window itself, built from the four fields above — one rule
            // so the ordering check and the messages live in one place.
            'window' => ['required', 'array'],
            'window.start' => ['required', 'date'],
            'window.end' => ['required', 'date', 'after_or_equal:window.start'],

            'part_number' => [
                'required',
                'integer',
                'in:1,2,3,4',
                // One window per Part per year. Reported on the Part control,
                // because that is the field the superadmin has to change to
                // fix it.
                \Illuminate\Validation\Rule::unique('opcrf_schedules', 'part_number')
                    ->where('opcrf_year', $this->year)
                    ->ignore($this->editingId),
            ],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'start_date' => 'start date',
            'start_time' => 'start time',
            'end_date' => 'end date',
            'end_time' => 'end time',
            'part_number' => 'Part',
        ];
    }

    /**
     * The Part/date/time fields, plus the combined window the rules above
     * compare. Rebuilt on every validation pass so `after_or_equal` always
     * sees the values currently on screen.
     */
    public function updated(): void
    {
        $this->buildWindow();
    }

    private function buildWindow(): void
    {
        if ($this->start_date === '' || $this->end_date === '') {
            $this->window = [];

            return;
        }

        $start = $this->toMoment($this->start_date, $this->start_time);
        $end = $this->toMoment($this->end_date, $this->end_time);

        $this->window = [
            'start' => $start?->toDateTimeString(),
            'end' => $end?->toDateTimeString(),
        ];
    }

    /**
     * Combine a date and an HH:MM pair into a moment in the APPLICATION's
     * timezone — the same zone the access rules compare against, so what the
     * superadmin types is exactly what the staff member is judged by.
     */
    private function toMoment(string $date, string $time): ?Carbon
    {
        try {
            return Carbon::createFromFormat(
                'Y-m-d H:i',
                $date.' '.$time,
                config('app.timezone')
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Switch the year being administered.
     */
    public function selectYear(int $year): void
    {
        $this->ensureSuperadmin();
        $this->year = $year;
    }

    /**
     * Open the Add modal for one Part.
     */
    public function openCreate(?int $part = null): void
    {
        $this->ensureSuperadmin();
        $this->resetValidation();
        $this->successMessage = null;

        $this->editingId = null;
        $this->part_number = $part ?: OpcrfSchedule::PART_ONE;
        $this->start_date = now()->addDay()->format('Y-m-d');
        $this->start_time = '08:00';
        $this->end_date = now()->addDays(30)->format('Y-m-d');
        $this->end_time = '17:00';
        $this->description = '';
        $this->is_enabled = true;
        $this->buildWindow();

        $this->showModal = true;
    }

    /**
     * Open the Edit modal for an existing schedule, prefilled with the
     * window exactly as stored.
     */
    public function openEdit(int $scheduleId): void
    {
        $this->ensureSuperadmin();
        $this->resetValidation();
        $this->successMessage = null;

        $schedule = OpcrfSchedule::findOrFail($scheduleId);

        $this->editingId = $schedule->id;
        $this->year = $schedule->opcrf_year;
        $this->part_number = $schedule->part_number;
        $this->start_date = $schedule->start_datetime->format('Y-m-d');
        $this->start_time = $schedule->start_datetime->format('H:i');
        $this->end_date = $schedule->end_datetime->format('Y-m-d');
        $this->end_time = $schedule->end_datetime->format('H:i');
        $this->description = (string) $schedule->description;
        $this->is_enabled = (bool) $schedule->is_enabled;
        $this->buildWindow();

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->ensureSuperadmin();
        $this->showModal = false;
        $this->editingId = null;
        $this->resetValidation();
    }

    /**
     * Validate and save. Creating records a 'created' entry; saving an
     * existing row records the before/after diff and calls it an extension
     * when only the deadline moved.
     */
    public function save(): void
    {
        $this->ensureSuperadmin();
        $this->buildWindow();
        $this->validate();

        $actor = Auth::user();

        if ($this->editingId !== null) {
            $schedule = OpcrfSchedule::findOrFail($this->editingId);
            $before = $schedule->snapshot();

            $schedule->update([
                'opcrf_year' => $this->year,
                'part_number' => $this->part_number,
                'start_datetime' => $this->window['start'],
                'end_datetime' => $this->window['end'],
                'description' => $this->description !== '' ? $this->description : null,
                'is_enabled' => $this->is_enabled,
            ]);

            OpcrfScheduleLog::record($actor, $schedule, $this->actionFor($before), $before, $schedule->fresh()->snapshot());

            $this->successMessage = $schedule->partLabel().' schedule saved — it applies immediately.';
        } else {
            $schedule = OpcrfSchedule::create([
                'opcrf_year' => $this->year,
                'part_number' => $this->part_number,
                'start_datetime' => $this->window['start'],
                'end_datetime' => $this->window['end'],
                'description' => $this->description !== '' ? $this->description : null,
                'is_enabled' => $this->is_enabled,
                'created_by' => $actor->id,
            ]);

            OpcrfScheduleLog::record($actor, $schedule, OpcrfScheduleLog::ACTION_CREATED, null, $schedule->snapshot());

            $this->successMessage = $schedule->partLabel().' schedule created.';
        }

        $this->showModal = false;
        $this->editingId = null;
        $this->resetValidation();
    }

    /**
     * What an edit counts as, for the audit trail: extending a deadline is
     * called out specifically because that is the change staff most need to
     * be able to see later.
     *
     * @param  array<string, mixed>  $before
     */
    private function actionFor(array $before): string
    {
        $schedule = OpcrfSchedule::find($this->editingId);

        if ($schedule === null) {
            return OpcrfScheduleLog::ACTION_UPDATED;
        }

        if ($before['is_enabled'] !== (bool) $schedule->is_enabled) {
            return $schedule->is_enabled
                ? OpcrfScheduleLog::ACTION_ENABLED
                : OpcrfScheduleLog::ACTION_DISABLED;
        }

        $startUnchanged = $before['start_datetime'] === $schedule->start_datetime?->toDateTimeString();
        $endUnchanged = $before['end_datetime'] === $schedule->end_datetime?->toDateTimeString();

        if ($startUnchanged && ! $endUnchanged) {
            return OpcrfScheduleLog::ACTION_EXTENDED;
        }

        return OpcrfScheduleLog::ACTION_UPDATED;
    }

    /**
     * Flip a schedule on or off without touching its dates — the manual
     * override the superadmin uses to close a Part that is inside its window.
     */
    public function toggle(int $scheduleId): void
    {
        $this->ensureSuperadmin();

        $schedule = OpcrfSchedule::findOrFail($scheduleId);
        $before = $schedule->snapshot();

        $schedule->update(['is_enabled' => ! $schedule->is_enabled]);

        OpcrfScheduleLog::record(
            Auth::user(),
            $schedule,
            $schedule->is_enabled ? OpcrfScheduleLog::ACTION_ENABLED : OpcrfScheduleLog::ACTION_DISABLED,
            $before,
            $schedule->fresh()->snapshot(),
        );

        $this->successMessage = $schedule->partLabel()
            .($schedule->is_enabled ? ' is now enabled.' : ' has been disabled.');
    }

    public function confirmDelete(int $scheduleId): void
    {
        $this->ensureSuperadmin();
        $this->confirmingDeleteId = $scheduleId;
    }

    public function cancelDelete(): void
    {
        $this->ensureSuperadmin();
        $this->confirmingDeleteId = null;
    }

    /**
     * Delete a schedule. The log entry survives the row (the foreign key is
     * null, not cascaded), so the record of why the Part went stays.
     */
    public function delete(int $scheduleId): void
    {
        $this->ensureSuperadmin();

        $schedule = OpcrfSchedule::findOrFail($scheduleId);
        $label = $schedule->partLabel();

        OpcrfScheduleLog::record(
            Auth::user(),
            $schedule,
            OpcrfScheduleLog::ACTION_DELETED,
            $schedule->snapshot(),
            null,
        );

        $schedule->delete();

        $this->confirmingDeleteId = null;
        $this->successMessage = $label.' schedule deleted.';
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function render()
    {
        $this->ensureSuperadmin();

        $year = $this->year ?: OpcrfAccess::currentYear();
        $schedules = OpcrfSchedule::forYear($year)->get();
        $now = now();

        // One row per Part, scheduled or not: the superadmin's table shows
        // the whole year so a missing Part is visible as "not scheduled"
        // rather than silently absent.
        $rows = collect(OpcrfSchedule::parts())->map(function (int $part) use ($schedules, $now) {
            $schedule = $schedules->firstWhere('part_number', $part);

            return [
                'part' => $part,
                'label' => 'Part '.$part,
                'schedule' => $schedule,
                'status' => $schedule?->statusAt($now) ?? OpcrfAccess::STATUS_NOT_SCHEDULED,
            ];
        });

        return view('livewire.opcrf.schedule-manager', [
            'rows' => $rows,
            'years' => OpcrfAccess::years(),
            'year' => $year,
            'logs' => OpcrfScheduleLog::query()
                ->where('opcrf_year', $year)
                ->latest('id')
                ->limit(15)
                ->get(),
            'canCreate' => $schedules->count() < count(OpcrfSchedule::parts()),
        ]);
    }
}

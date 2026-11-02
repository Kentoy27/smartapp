<?php

namespace App\Livewire;

use App\Models\School;
use App\Models\SchoolAllotmentAudit;
use App\Models\User;
use App\Support\SchoolDivision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * School Division Mapping — the administrator's page for assigning the
 * school division of financial records that predate the division columns.
 *
 * The migration that introduced `school_division` carried every existing row
 * over as Elementary rather than guessing which High School records belong to
 * Junior High and which to Senior High. This page is the explicit,
 * administrator-controlled mapping the plan calls for: the administrator
 * picks a school, the division the records currently read as, and the
 * division they actually belong to; the records move in one transaction.
 *
 * The move is conflict-aware. The unique keys include the division, so a
 * Junior High row may already exist for the same school and year — the page
 * reports every such conflict and leaves BOTH rows untouched rather than
 * merging or overwriting money. Nothing is inferred: there is no automatic
 * Junior High / Senior High assignment anywhere, only this page.
 *
 * Administrator-only, like the other Admin pages: the route guard and
 * mount() both enforce canManageUsersAndSchools(), and every action
 * re-checks it.
 */
#[Title('School Division Mapping — SmartApp')]
class SchoolDivisionMapper extends Component
{
    /**
     * The tables a mapping moves, with the columns that make up each one's
     * unique key — used to detect a conflict before writing.
     *
     * @var array<int, array{table: string, label: string, key: array<int, string>, key_label: string}>
     */
    private const TABLES = [
        [
            'table' => 'school_allotments',
            'label' => 'School Allotment',
            'key' => ['school_id', 'school_division', 'year'],
            'key_label' => 'CY :year',
        ],
        [
            'table' => 'monthly_expenditure_matrices',
            'label' => 'Monthly Expenditure Matrix',
            'key' => ['school_id', 'school_division', 'year', 'month'],
            'key_label' => 'CY :year, month :month',
        ],
        [
            'table' => 'wfp_plans',
            'label' => 'WFP Plan',
            'key' => ['school_id', 'school_division', 'year'],
            'key_label' => 'CY :year',
        ],
        [
            'table' => 'wfp_report_checks',
            'label' => 'WFP Report Check',
            'key' => ['school_id', 'school_division', 'year'],
            'key_label' => 'CY :year',
        ],
    ];

    /** The school whose records are mapped, '' until one is picked. */
    public string $school = '';

    /** The division the records currently read as. */
    public string $sourceDivision = SchoolDivision::ELEMENTARY;

    /** The division the records should belong to, '' until one is picked. */
    public string $targetDivision = '';

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    /** @var array<int, string> */
    public array $conflictNotes = [];

    public function mount(): void
    {
        $this->user();
    }

    /* -------------------------------------------------------------- guards */

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);

        return $user;
    }

    /* --------------------------------------------------------------- state */

    public function updatedSchool(): void
    {
        // A mapping belongs to one school: changing the school throws the
        // previous result away rather than letting it read as the new one's.
        $this->resetResult();

        if (! $this->validSchoolId()) {
            $this->school = '';
        }
    }

    public function updatedSourceDivision(): void
    {
        $this->resetResult();
    }

    public function updatedTargetDivision(): void
    {
        $this->resetResult();
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function clearError(): void
    {
        $this->errorMessage = null;
    }

    private function resetResult(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
        $this->conflictNotes = [];
    }

    /* ------------------------------------------------------------- mapping */

    /**
     * Move the selected school's records from the source division to the
     * target division. One transaction: it lands in full or not at all.
     */
    public function map(): void
    {
        $this->user();

        $this->resetResult();

        $school = $this->selectedSchool;

        if ($school === null) {
            $this->errorMessage = 'Choose the school whose records you are mapping.';

            return;
        }

        if (! SchoolDivision::isValidDivision($this->sourceDivision)
            || ! SchoolDivision::isValidDivision($this->targetDivision)) {
            $this->errorMessage = 'Choose a division the records are moving from and to.';

            return;
        }

        if ($this->sourceDivision === $this->targetDivision) {
            $this->errorMessage = 'The source and target divisions are the same — there is nothing to map.';

            return;
        }

        $source = $this->sourceDivision;
        $target = $this->targetDivision;
        $schoolId = (int) $school->id;

        $result = DB::transaction(function () use ($schoolId, $source, $target): array {
            $moved = [];
            $conflicts = [];
            $movedAllotmentYears = [];

            foreach (self::TABLES as $spec) {
                // A conflict is the SAME period on both sides: the division is
                // what is being changed, so it is never part of the key that
                // is compared.
                $period = array_values(array_diff($spec['key'], ['school_division']));

                $existing = DB::table($spec['table'])
                    ->where('school_id', $schoolId)
                    ->where('school_division', $target)
                    ->get($period)
                    ->map(fn ($row): string => self::keyString($row, $period))
                    ->all();

                $existing = array_fill_keys($existing, true);

                $rows = DB::table($spec['table'])
                    ->where('school_id', $schoolId)
                    ->where('school_division', $source)
                    ->get();

                $movedCount = 0;

                foreach ($rows as $row) {
                    $key = self::keyString($row, $period);

                    if (isset($existing[$key])) {
                        $conflicts[] = [
                            'table' => $spec['label'],
                            'note' => self::keyLabel($spec, $row),
                        ];

                        continue;
                    }

                    DB::table($spec['table'])
                        ->where('id', $row->id)
                        ->update(['school_division' => $target]);

                    if ($spec['table'] === 'school_allotments') {
                        $movedAllotmentYears[] = (int) $row->year;
                    }

                    $movedCount++;
                }

                $moved[$spec['table']] = $movedCount;
            }

            // The trail and the uploaded workbooks carry the division
            // directly; neither has a unique key that could conflict, so
            // every matching row moves.
            $moved['school_allotment_audits'] = DB::table('school_allotment_audits')
                ->where('school_id', $schoolId)
                ->where('school_division', $source)
                ->update(['school_division' => $target]);

            $userIds = DB::table('users')->where('school_id', $schoolId)->pluck('id')->all();

            $moved['wfp_submissions'] = $userIds === []
                ? 0
                : DB::table('wfp_submissions')
                    ->whereIn('user_id', $userIds)
                    ->where('school_division', $source)
                    ->update(['school_division' => $target]);

            // An allotment's trail records the mapping as its own event, so
            // the amount history still explains which division paid it.
            foreach (array_unique($movedAllotmentYears) as $year) {
                SchoolAllotmentAudit::record(
                    $schoolId,
                    (int) $year,
                    Auth::user(),
                    SchoolAllotmentAudit::ACTION_MAPPED,
                    null,
                    null,
                    SchoolAllotmentAudit::SOURCE_MAPPING,
                    $target,
                );
            }

            return ['moved' => $moved, 'conflicts' => $conflicts];
        });

        $total = array_sum($result['moved']);

        $this->conflictNotes = array_map(
            fn (array $conflict): string => $conflict['table'].' — '.$conflict['note'],
            $result['conflicts'],
        );

        $targetLabel = SchoolDivision::label($target);
        $sourceLabel = SchoolDivision::label($source);

        if ($total === 0 && $this->conflictNotes === []) {
            $this->successMessage = $school->name.' has no '.$sourceLabel.' records to map to '.$targetLabel.'.';

            return;
        }

        $this->successMessage = number_format($total).' record(s) moved — '.$school->name
            .' now holds them under '.$targetLabel.' instead of '.$sourceLabel.'.'
            .($this->conflictNotes !== []
                ? ' '.count($this->conflictNotes).' record(s) were left untouched because a '
                    .$targetLabel.' record already exists for the same period (listed below).'
                : '');
    }

    /* ----------------------------------------------------------- computed */

    /**
     * The school picker's list, by name.
     *
     * @return Collection<int, School>
     */
    #[Computed]
    public function schools(): Collection
    {
        return School::query()
            ->orderBy('name')
            ->get(['id', 'name', 'school_id']);
    }

    #[Computed]
    public function selectedSchool(): ?School
    {
        if (! $this->validSchoolId()) {
            return null;
        }

        return School::query()->find((int) $this->school);
    }

    /**
     * The divisions the picker offers — the three financial divisions.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function divisionOptions(): array
    {
        return array_map(
            fn (string $division): array => ['value' => $division, 'label' => SchoolDivision::label($division)],
            SchoolDivision::all(),
        );
    }

    /**
     * What a mapping would move for the selected school and source division:
     * the row count per table, and how many of each conflict with an existing
     * target-division row. Read-only — nothing is written by rendering.
     *
     * @return array<int, array{label: string, count: int, conflicts: int}>|null
     */
    #[Computed]
    public function preview(): ?array
    {
        $school = $this->selectedSchool;

        if ($school === null || ! SchoolDivision::isValidDivision($this->sourceDivision)) {
            return null;
        }

        $target = SchoolDivision::isValidDivision($this->targetDivision)
            ? $this->targetDivision
            : null;

        $rows = [];

        foreach (self::TABLES as $spec) {
            $query = DB::table($spec['table'])
                ->where('school_id', $school->id)
                ->where('school_division', $this->sourceDivision);

            $count = (clone $query)->count();
            $conflicts = 0;

            if ($target !== null && $target !== $this->sourceDivision) {
                $period = array_values(array_diff($spec['key'], ['school_division']));

                $existing = DB::table($spec['table'])
                    ->where('school_id', $school->id)
                    ->where('school_division', $target)
                    ->get($period)
                    ->map(fn ($row): string => self::keyString($row, $period))
                    ->all();

                $existing = array_fill_keys($existing, true);

                foreach ((clone $query)->get($period) as $row) {
                    if (isset($existing[self::keyString($row, $period)])) {
                        $conflicts++;
                    }
                }
            }

            $rows[] = [
                'label' => $spec['label'],
                'count' => $count,
                'conflicts' => $conflicts,
            ];
        }

        $userIds = DB::table('users')->where('school_id', $school->id)->pluck('id')->all();

        $rows[] = [
            'label' => 'School Allotment Audit',
            'count' => DB::table('school_allotment_audits')
                ->where('school_id', $school->id)
                ->where('school_division', $this->sourceDivision)
                ->count(),
            'conflicts' => 0,
        ];

        $rows[] = [
            'label' => 'WFP Upload',
            'count' => $userIds === []
                ? 0
                : DB::table('wfp_submissions')
                    ->whereIn('user_id', $userIds)
                    ->where('school_division', $this->sourceDivision)
                    ->count(),
            'conflicts' => 0,
        ];

        return $rows;
    }

    /* ------------------------------------------------------------- helpers */

    private function validSchoolId(): bool
    {
        return ctype_digit($this->school)
            && School::query()->whereKey((int) $this->school)->exists();
    }

    /**
     * @param  array<int, string>  $key
     */
    private static function keyString(object $row, array $key): string
    {
        return implode('|', array_map(static fn (string $column): string => (string) ($row->{$column} ?? ''), $key));
    }

    /**
     * @param  array{table: string, label: string, key: array<int, string>, key_label: string}  $spec
     */
    private static function keyLabel(array $spec, object $row): string
    {
        $label = $spec['key_label'];

        foreach (['year' => 'year', 'month' => 'month'] as $column => $placeholder) {
            $value = (string) ($row->{$column} ?? '');

            if ($value !== '') {
                $label = str_replace(':'.$placeholder, $value, $label);
            }
        }

        return $label;
    }

    public function render()
    {
        return view('livewire.schools.division-mapping');
    }
}

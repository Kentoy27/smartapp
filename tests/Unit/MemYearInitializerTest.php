<?php

namespace Tests\Unit;

use App\Models\District;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Support\MemYearInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemYearInitializerTest extends TestCase
{
    use RefreshDatabase;

    private function school(string $name = 'Test School'): School
    {
        $district = District::create(['name' => 'District '.$name]);

        return School::create(['district_id' => $district->id, 'name' => $name]);
    }

    private function user(?School $school = null): User
    {
        return User::factory()->create([
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school?->id,
        ]);
    }

    public function test_it_creates_the_twelve_months_as_blank_drafts(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $matrices = (new MemYearInitializer)->initialize($school->id, 2026, $user->id);

        $this->assertCount(12, $matrices);
        $this->assertSame(range(1, 12), $matrices->pluck('month')->all());

        foreach ($matrices as $matrix) {
            $this->assertSame(MonthlyExpenditureMatrix::STATUS_DRAFT, $matrix->status);
            $this->assertSame(1, $matrix->version);
            $this->assertSame($user->id, $matrix->created_by);
            $this->assertSame(0, $matrix->items()->count());
        }
    }

    public function test_it_is_idempotent(): void
    {
        $school = $this->school();

        $first = (new MemYearInitializer)->initialize($school->id, 2026);
        $ids = $first->pluck('id')->all();

        $second = (new MemYearInitializer)->initialize($school->id, 2026);

        $this->assertSame(12, MonthlyExpenditureMatrix::count());
        $this->assertSame($ids, $second->pluck('id')->all());
    }

    public function test_it_only_fills_missing_months_and_leaves_the_rest_untouched(): void
    {
        $school = $this->school();

        $july = MonthlyExpenditureMatrix::create([
            'school_id' => $school->id,
            'year' => 2026,
            'month' => 7,
            'status' => MonthlyExpenditureMatrix::STATUS_FINALIZED,
            'version' => 4,
        ]);
        $july->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Existing line',
        ]);

        (new MemYearInitializer)->initialize($school->id, 2026);

        $this->assertSame(12, MonthlyExpenditureMatrix::count());

        $july->refresh();
        $this->assertSame(MonthlyExpenditureMatrix::STATUS_FINALIZED, $july->status);
        $this->assertSame(4, $july->version);
        $this->assertSame(1, $july->items()->count());
    }

    public function test_it_never_touches_another_school(): void
    {
        $a = $this->school('School A');
        $b = $this->school('School B');

        (new MemYearInitializer)->initialize($a->id, 2026);

        $this->assertSame(12, MonthlyExpenditureMatrix::where('school_id', $a->id)->count());
        $this->assertSame(0, MonthlyExpenditureMatrix::where('school_id', $b->id)->count());
    }

    public function test_it_returns_the_months_in_calendar_order(): void
    {
        $school = $this->school();

        (new MemYearInitializer)->initialize($school->id, 2026, null);

        $matrices = (new MemYearInitializer)->initialize($school->id, 2026, null);

        $this->assertSame(range(1, 12), $matrices->pluck('month')->all());
    }

    public function test_the_total_for_a_school_and_year_never_exceeds_twelve(): void
    {
        $school = $this->school();

        foreach (range(1, 5) as $ignored) {
            (new MemYearInitializer)->initialize($school->id, 2026);
        }

        $this->assertSame(12, MonthlyExpenditureMatrix::where('school_id', $school->id)->where('year', 2026)->count());
    }

    public function test_the_school_year_month_triple_is_unique_in_the_database(): void
    {
        $school = $this->school();

        $row = [
            'school_id' => $school->id,
            'year' => 2026,
            'month' => 3,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
        ];

        MonthlyExpenditureMatrix::create($row);

        // A second raw insert of the same triple collides — this is the
        // guarantee that makes concurrent initialization safe.
        $this->expectException(\Illuminate\Database\QueryException::class);

        MonthlyExpenditureMatrix::create($row);
    }
}

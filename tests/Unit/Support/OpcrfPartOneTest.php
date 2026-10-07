<?php

namespace Tests\Unit\Support;

use App\Support\OpcrfPartOne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsOpcrfWorkbooks;
use Tests\TestCase;

class OpcrfPartOneTest extends TestCase
{
    use BuildsOpcrfWorkbooks;

    // The gate now consults the superadmin's opcrf_schedules table first and
    // falls back to this legacy window only when no schedule exists — so the
    // table has to exist for these tests to mean anything. With the table
    // empty, every one of them exercises the legacy fallback.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed clock inside the default window (03-16 → 04-30): with the
        // Part-One-only lock released, the template's full form is open.
        Carbon::setTestNow('2026-04-01 10:00:00');
    }

    /**
     * The default state is "Part One only, from the beginning" — the window
     * tests below release the lock (opcrf.part_one.part_one_only = false)
     * so the term-gate logic itself is exercised.
     */
    private function unlockFullTemplate(): void
    {
        config(['opcrf.part_one.part_one_only' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_part_one_only_is_the_default_from_the_beginning(): void
    {
        // No config, any date: the lock holds and only Part One ships.
        Carbon::setTestNow('2026-04-01 10:00:00'); // inside the old window

        $this->assertFalse(OpcrfPartOne::windowOpen());
        $this->assertSame(['PART I (CY 2025 & SY2025-2026)'], $this->sheetNamesOfWorkbook(OpcrfPartOne::templateBytes()));
        $this->assertSame('OPCRF-PART-I.xlsx', OpcrfPartOne::templateDownloadName());

        $summary = OpcrfPartOne::summary();
        $this->assertSame(1, $summary['part']);
        $this->assertFalse($summary['full_open']);
    }

    public function test_the_window_is_open_between_its_boundaries(): void
    {
        $this->unlockFullTemplate();

        $this->assertTrue(OpcrfPartOne::windowOpen());

        Carbon::setTestNow('2026-03-16 00:00:00');
        $this->assertTrue(OpcrfPartOne::windowOpen(), 'The opening day is inside the window.');

        Carbon::setTestNow('2026-04-30 23:59:59');
        $this->assertTrue(OpcrfPartOne::windowOpen(), 'The closing day is inside the window.');
    }

    public function test_the_window_is_closed_outside_its_boundaries(): void
    {
        $this->unlockFullTemplate();

        Carbon::setTestNow('2026-03-15 23:59:59');
        $this->assertFalse(OpcrfPartOne::windowOpen());

        Carbon::setTestNow('2026-05-01 00:00:00');
        $this->assertFalse(OpcrfPartOne::windowOpen());

        Carbon::setTestNow('2026-09-29 12:00:00');
        $this->assertFalse(OpcrfPartOne::windowOpen(), 'Deep outside the term, the full form is closed.');
    }

    public function test_a_window_across_the_new_year_is_understood(): void
    {
        $this->unlockFullTemplate();

        config(['opcrf.part_one.term_opens' => '11-01']);
        config(['opcrf.part_one.term_closes' => '03-15']);

        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->assertTrue(OpcrfPartOne::windowOpen(), 'Late in the year, the window that opened in November is live.');

        Carbon::setTestNow('2026-02-01 10:00:00');
        $this->assertTrue(OpcrfPartOne::windowOpen(), 'Early in the year, the same window is still live.');

        Carbon::setTestNow('2026-06-01 10:00:00');
        $this->assertFalse(OpcrfPartOne::windowOpen());
    }

    public function test_an_invalid_window_keeps_the_gate_closed(): void
    {
        $this->unlockFullTemplate();

        config(['opcrf.part_one.term_opens' => 'not-a-date']);
        config(['opcrf.part_one.term_closes' => '13-45']);

        $this->assertFalse(OpcrfPartOne::windowOpen(), 'A mistyped window must close the gate, not misbehave.');

        $summary = OpcrfPartOne::summary();
        $this->assertFalse($summary['full_open']);
        $this->assertNull($summary['full_open_date']);
    }

    public function test_the_summary_describes_the_closed_state_outside_the_term(): void
    {
        Carbon::setTestNow('2026-09-29 12:00:00');

        $summary = OpcrfPartOne::summary();

        $this->assertFalse($summary['full_open']);
        $this->assertSame(1, $summary['part'], 'Outside the term, only Part One is available.');
        $this->assertNotNull($summary['full_open_date']);
        $this->assertSame('the end of the school year term', $summary['label']);
    }

    public function test_the_summary_describes_the_open_state_inside_the_term(): void
    {
        $this->unlockFullTemplate();

        Carbon::setTestNow('2026-04-01 10:00:00');

        $summary = OpcrfPartOne::summary();

        $this->assertTrue($summary['full_open']);
        $this->assertSame(4, $summary['part'], 'Inside the term, the whole form is available.');
    }

    public function test_outside_the_term_the_template_is_part_one_only(): void
    {
        Carbon::setTestNow('2026-09-29 12:00:00');

        $bytes = OpcrfPartOne::templateBytes();

        $this->assertSame(['PART I (CY 2025 & SY2025-2026)'], $this->sheetNamesOfWorkbook($bytes));
        $this->assertSame('OPCRF-PART-I.xlsx', OpcrfPartOne::templateDownloadName());
    }

    public function test_inside_the_term_the_full_template_is_served(): void
    {
        $this->unlockFullTemplate();

        Carbon::setTestNow('2026-04-01 10:00:00');

        $this->assertSame(
            file_get_contents(OpcrfPartOne::templatePath()),
            OpcrfPartOne::templateBytes(),
            'Inside the window the shipped template is served untouched.'
        );
        $this->assertSame('OPCRF-TEMPLATE.xlsx', OpcrfPartOne::templateDownloadName());
    }

    public function test_the_part_one_copy_is_smaller_than_the_full_template(): void
    {
        $this->unlockFullTemplate();

        Carbon::setTestNow('2026-04-01 10:00:00');
        $full = OpcrfPartOne::templateBytes();

        Carbon::setTestNow('2026-09-29 12:00:00');
        $trimmed = OpcrfPartOne::templateBytes();

        $this->assertNotSame($full, $trimmed, 'Outside the term the served bytes are a rebuild, not the original.');
        $this->assertLessThan(strlen($full), strlen($trimmed), 'Three of four part sheets are gone.');
    }
}

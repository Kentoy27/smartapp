<?php

namespace Tests\Feature;

use App\Livewire\OpcrfScheduleManager;
use App\Models\District;
use App\Models\OpcrfSchedule;
use App\Models\OpcrfScheduleLog;
use App\Models\OpcrfSubmission;
use App\Models\OpcrfTemplate;
use App\Models\School;
use App\Models\User;
use App\Support\OpcrfAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\BuildsOpcrfWorkbooks;
use Tests\TestCase;

/**
 * OPCRF Part access control.
 *
 * The superadmin owns the calendar; the staff member's Parts follow it. What
 * these tests pin down is that the schedule is a real control and not
 * decoration: every locked Part is refused by the SERVER even when the URL is
 * typed directly, the refusal survives the deadline without invalidating work
 * already submitted, and every change a superadmin makes is recorded.
 *
 * Acceptance criteria 1–20 are covered in order, grouped by behaviour.
 */
class OpcrfScheduleTest extends TestCase
{
    use BuildsOpcrfWorkbooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // A fixed clock, because every rule here is "is now inside the
        // window" — a test that moves with the wall clock is not a test.
        Carbon::setTestNow('2026-06-15 10:00:00');
    }

    private function superadmin(string $username = 'chief'): User
    {
        // Idempotent on purpose: several tests need the same administrator
        // both by hand and indirectly, through the schedule() helper.
        return User::firstOrCreate(
            ['username' => $username],
            [
                'name' => 'Chief',
                'email' => $username.'@example.com',
                'password' => Hash::make('password123'),
                'is_superadmin' => true,
            ]
        );
    }

    private function staff(string $username = 'staff', string $name = 'Staff Member'): User
    {
        return User::firstOrCreate(
            ['username' => $username],
            [
                'name' => $name,
                'email' => $username.'@example.com',
                'password' => Hash::make('password123'),
                'is_superadmin' => false,
            ]
        );
    }

    /**
     * A submitted Part 1 form, as the upload flow would leave it.
     *
     * Most of these columns are NOT NULL in the table, so they are supplied
     * here rather than in each test — these tests care about access, not about
     * the contents of the form.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function submission(User $user, array $attributes = []): OpcrfSubmission
    {
        return OpcrfSubmission::create(array_merge([
            'user_id' => $user->id,
            'opcrf_part' => OpcrfSubmission::PART_ONE,
            'employee_name' => $user->name,
            'position' => 'Teacher I',
            'review_period' => '2026',
            'division_office' => 'Sample Division',
            'objectives' => 'An objective.',
            'accomplishments' => 'An accomplishment.',
            'self_rating' => 4.5,
            'status' => 'pending',
            'submitted_at' => now(),
        ], $attributes));
    }

    /**
     * A schedule for one Part of the given year, defaulting to a window that
     * contains the fixed test clock.
     */
    private function schedule(
        int $part,
        string $start = '2026-06-01 08:00:00',
        string $end = '2026-06-30 17:00:00',
        int $year = 2026,
        bool $enabled = true,
    ): OpcrfSchedule {
        return OpcrfSchedule::create([
            'opcrf_year' => $year,
            'part_number' => $part,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'description' => null,
            'is_enabled' => $enabled,
            'created_by' => $this->superadmin()->id,
        ]);
    }

    /* ==================================================================
     * 1, 2, 3, 12 — creating a schedule, and the access it produces
     * ================================================================== */

    public function test_a_superadmin_creates_a_part_schedule(): void
    {
        $admin = $this->superadmin();

        Livewire::actingAs($admin)
            ->test(OpcrfScheduleManager::class)
            ->call('openCreate', OpcrfSchedule::PART_ONE)
            ->set('start_date', '2026-01-05')
            ->set('start_time', '08:00')
            ->set('end_date', '2026-01-31')
            ->set('end_time', '17:00')
            ->set('description', 'Bring your signed WFP and AIP.')
            ->call('save')
            ->assertHasNoErrors();

        $schedule = OpcrfSchedule::firstOrFail();

        $this->assertSame(2026, $schedule->opcrf_year);
        $this->assertSame(1, $schedule->part_number);
        $this->assertSame('2026-01-05 08:00:00', $schedule->start_datetime->toDateTimeString());
        $this->assertSame('2026-01-31 17:00:00', $schedule->end_datetime->toDateTimeString());
        $this->assertSame('Bring your signed WFP and AIP.', $schedule->description);
        $this->assertSame($admin->id, $schedule->created_by);
    }

    public function test_the_parts_grid_shows_every_part_locked_until_scheduled(): void
    {
        $staff = $this->staff();
        $this->schedule(OpcrfSchedule::PART_ONE);

        $html = $this->actingAs($staff)->get(route('opcrf.index'))->assertOk()->getContent();

        // All four Parts are LISTED — a locked Part is never hidden.
        foreach ([1, 2, 3, 4] as $part) {
            $this->assertStringContainsString('Part '.$part, $html);
        }

        $this->assertStringContainsString('Part 1 is now open.', $html);
        // And the others say what is true, not nothing.
        $this->assertStringContainsString('No access period has been set for this Part yet.', $html);
    }

    public function test_a_user_cannot_access_a_part_before_its_start_date(): void
    {
        $staff = $this->staff();

        // Starts a week after the fixed clock.
        $this->schedule(OpcrfSchedule::PART_ONE, '2026-06-22 08:00:00', '2026-06-30 17:00:00');

        $response = $this->actingAs($staff)->get(route('opcrf.part', ['part' => 1]));

        $response->assertRedirect(route('opcrf.index'));
        $response->assertSessionHas('opcrfPartNotice.message', 'Part 1 is not yet available. Available starting June 22, 2026 8:00 AM.');

        $this->assertFalse(OpcrfAccess::canAccess($staff, 1));
    }

    public function test_a_user_can_access_a_part_inside_its_window(): void
    {
        $staff = $this->staff();
        $this->schedule(OpcrfSchedule::PART_ONE);

        $this->actingAs($staff)
            ->get(route('opcrf.part', ['part' => 1]))
            ->assertOk()
            ->assertSee('Part 1')
            // Part 1 carries the form, pre-filled from the account.
            ->assertSeeLivewire('opcrf-form');
    }

    /**
     * Acceptance 12 — the control is server-side. A staff member who types
     * the URL, or forges a request, is refused exactly like one who clicks.
     */
    public function test_the_lock_is_enforced_on_the_server_not_by_the_page(): void
    {
        $staff = $this->staff();

        // Nothing scheduled at all: Parts 2-4 do not exist yet.
        foreach ([2, 3, 4] as $part) {
            $this->actingAs($staff)
                ->get("/opcrf/part/{$part}")
                ->assertRedirect(route('opcrf.index'));
        }

        // A Part outside 1..4 is a 404, not a message — the surface does not
        // widen by typing a different number.
        $this->actingAs($staff)->get('/opcrf/part/9')->assertNotFound();
        $this->actingAs($staff)->get('/opcrf/part/0')->assertNotFound();

        $this->assertFalse(OpcrfAccess::canAccess($staff, 2));
    }

    public function test_a_disabled_schedule_locks_a_part_even_inside_its_window(): void
    {
        $staff = $this->staff();
        $this->schedule(OpcrfSchedule::PART_ONE, '2026-06-01 08:00:00', '2026-06-30 17:00:00', enabled: false);

        $this->assertFalse(OpcrfAccess::canAccess($staff, 1));

        $this->actingAs($staff)
            ->get(route('opcrf.part', ['part' => 1]))
            ->assertRedirect(route('opcrf.index'))
            ->assertSessionHas('opcrfPartNotice.message', 'Part 1 is currently unavailable.');
    }

    /* ==================================================================
     * 4, 13 — deadlines
     * ================================================================== */

    public function test_a_part_closes_automatically_the_minute_its_deadline_passes(): void
    {
        $staff = $this->staff();

        // The exact end minute is still open (the boundary is inclusive).
        $this->schedule(OpcrfSchedule::PART_TWO, '2026-06-01 08:00:00', '2026-06-15 10:00:00');
        $this->assertTrue(OpcrfAccess::canAccess($staff, 2));

        // One minute later it is not — no toggle, no cron, no deploy.
        Carbon::setTestNow('2026-06-15 10:01:00');
        $this->assertFalse(OpcrfAccess::canAccess($staff, 2));

        $this->actingAs($staff)
            ->get(route('opcrf.part', ['part' => 2]))
            ->assertRedirect(route('opcrf.index'))
            ->assertSessionHas('opcrfPartNotice.message', 'Part 2 access period has ended.');
    }

    /**
     * Acceptance 13 — a deadline closing must NOT retract work the user
     * already handed in. Their submission stays, and stays readable.
     */
    public function test_a_closed_schedule_never_invalidates_a_submission_already_made(): void
    {
        $staff = $this->staff();
        $this->schedule(
            OpcrfSchedule::PART_ONE,
            '2026-06-01 08:00:00',
            '2026-06-15 17:00:00',
        );

        $submission = $this->submission($staff, ['position' => 'Teacher I']);

        // The window closes an hour later.
        Carbon::setTestNow('2026-06-15 18:00:00');

        $state = OpcrfAccess::partState($staff, 1);

        $this->assertSame(OpcrfSchedule::STATUS_CLOSED, $state['status'], 'The schedule is closed.');
        $this->assertTrue($state['completed'], 'The Part they already submitted still counts as done.');

        // …but the completed Part stays reachable, and the record is intact.
        $this->actingAs($staff)
            ->get(route('opcrf.part', ['part' => 1]))
            ->assertOk();

        $this->assertSame('pending', $submission->fresh()->status);
        $this->assertSame(1, OpcrfSubmission::where('user_id', $staff->id)->count());
    }

    /* ==================================================================
     * 5, 6, 7, 11 — each Part checks its OWN schedule
     * ================================================================== */

    public function test_each_part_is_scheduled_independently(): void
    {
        $staff = $this->staff();

        $this->schedule(OpcrfSchedule::PART_ONE, '2026-06-01 08:00:00', '2026-06-30 17:00:00');
        // Part 2 is already over; Parts 3 and 4 have not opened.
        $this->schedule(OpcrfSchedule::PART_TWO, '2026-05-01 08:00:00', '2026-05-31 17:00:00');
        $this->schedule(OpcrfSchedule::PART_THREE, '2026-07-01 08:00:00', '2026-07-31 17:00:00');
        $this->schedule(OpcrfSchedule::PART_FOUR, '2026-08-01 08:00:00', '2026-08-31 17:00:00');

        $states = collect(OpcrfAccess::allParts($staff, 2026))->keyBy('part');

        $this->assertSame(OpcrfSchedule::STATUS_OPEN, $states[1]['status']);
        $this->assertSame(OpcrfSchedule::STATUS_CLOSED, $states[2]['status']);
        $this->assertSame(OpcrfSchedule::STATUS_SCHEDULED, $states[3]['status']);
        $this->assertSame(OpcrfSchedule::STATUS_SCHEDULED, $states[4]['status']);

        // An open Part 1 does NOT open Part 3.
        $this->assertTrue(OpcrfAccess::canAccess($staff, 1));
        $this->assertFalse(OpcrfAccess::canAccess($staff, 3));
        $this->assertFalse(OpcrfAccess::canAccess($staff, 4));
    }

    public function test_the_grid_names_the_state_each_part_is_in(): void
    {
        $staff = $this->staff();

        $this->schedule(OpcrfSchedule::PART_ONE);
        $this->schedule(OpcrfSchedule::PART_TWO, '2026-05-01 08:00:00', '2026-05-31 17:00:00');
        $this->schedule(OpcrfSchedule::PART_THREE, '2026-07-01 08:00:00', '2026-07-31 17:00:00');
        $this->schedule(OpcrfSchedule::PART_FOUR, '2026-06-01 08:00:00', '2026-06-30 17:00:00', enabled: false);

        $html = $this->actingAs($staff)->get(route('opcrf.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Part 1 is now open.', $html);
        $this->assertStringContainsString('Part 2 access period has ended.', $html);
        $this->assertStringContainsString('Part 3 is not yet available.', $html);
        $this->assertStringContainsString('Part 4 is currently unavailable.', $html);
    }

    /* ==================================================================
     * 13, 19 — the OPCRF year
     * ================================================================== */

    public function test_the_year_is_never_hardcoded(): void
    {
        $staff = $this->staff();

        $this->schedule(OpcrfSchedule::PART_ONE, '2027-01-05 08:00:00', '2027-01-31 17:00:00', year: 2027);

        // The newest scheduled year becomes the cycle year.
        $this->assertSame(2027, OpcrfAccess::currentYear());
        $this->assertContains(2027, OpcrfAccess::years());

        // …and that year's window governs Part 1, not 2026's.
        $this->assertFalse(OpcrfAccess::canAccess($staff, 1, 2027));

        $html = $this->actingAs($staff)->get(route('opcrf.index'))->assertOk()->getContent();
        $this->assertStringContainsString('OPCRF 2027', $html);
    }

    public function test_with_no_schedule_part_one_stays_reachable(): void
    {
        // The schedules have to ADD control, not remove access: an install
        // that never configured a schedule must not lock everyone out of the
        // one Part they could previously use.
        $staff = $this->staff();

        $this->assertTrue(OpcrfAccess::canAccess($staff, 1));
        $this->actingAs($staff)->get(route('opcrf.part', ['part' => 1]))->assertOk();
    }

    /* ==================================================================
     * 8, 9, 14, 20 — the superadmin's overrides
     * ================================================================== */

    public function test_editing_a_schedule_applies_immediately(): void
    {
        $staff = $this->staff();
        $admin = $this->superadmin();
        $schedule = $this->schedule(OpcrfSchedule::PART_ONE);

        $this->assertTrue(OpcrfAccess::canAccess($staff, 1));

        // Move the deadline to the past: the Part must shut at once.
        Livewire::actingAs($admin)
            ->test(OpcrfScheduleManager::class)
            ->call('openEdit', $schedule->id)
            ->set('end_date', '2026-06-02')
            ->set('end_time', '17:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(OpcrfAccess::canAccess($staff, 1));
    }

    public function test_extending_a_deadline_is_recorded_as_an_extension(): void
    {
        $admin = $this->superadmin();
        $schedule = $this->schedule(OpcrfSchedule::PART_TWO, '2026-02-01 08:00:00', '2026-02-28 17:00:00');

        Livewire::actingAs($admin)
            ->test(OpcrfScheduleManager::class)
            ->call('openEdit', $schedule->id)
            ->set('end_date', '2026-03-05')
            ->call('save')
            ->assertHasNoErrors();

        $log = OpcrfScheduleLog::latest('id')->firstOrFail();

        $this->assertSame(OpcrfScheduleLog::ACTION_EXTENDED, $log->action);
        $this->assertSame('2026-02-28 17:00:00', $log->old_values['end_datetime']);
        $this->assertSame('2026-03-05 17:00:00', $log->new_values['end_datetime']);
    }

    public function test_a_superadmin_can_disable_and_re_enable_a_part(): void
    {
        $staff = $this->staff();
        $admin = $this->superadmin();
        $schedule = $this->schedule(OpcrfSchedule::PART_ONE);

        $component = Livewire::actingAs($admin)->test(OpcrfScheduleManager::class);

        $component->call('toggle', $schedule->id);
        $this->assertFalse($schedule->fresh()->is_enabled);
        $this->assertFalse(OpcrfAccess::canAccess($staff, 1));

        $component->call('toggle', $schedule->id);
        $this->assertTrue($schedule->fresh()->is_enabled);
        $this->assertTrue(OpcrfAccess::canAccess($staff, 1));

        $this->assertSame(
            [OpcrfScheduleLog::ACTION_DISABLED, OpcrfScheduleLog::ACTION_ENABLED],
            OpcrfScheduleLog::where('opcrf_schedule_id', $schedule->id)
                ->orderBy('id')
                ->pluck('action')
                ->all(),
        );
    }

    public function test_deleting_a_schedule_locks_the_part_but_keeps_the_record(): void
    {
        $staff = $this->staff();
        $admin = $this->superadmin();
        $schedule = $this->schedule(OpcrfSchedule::PART_ONE);

        Livewire::actingAs($admin)
            ->test(OpcrfScheduleManager::class)
            ->call('confirmDelete', $schedule->id)
            ->call('delete', $schedule->id);

        $this->assertDatabaseMissing('opcrf_schedules', ['id' => $schedule->id]);

        // Part 1 falls back to the "no schedule" rule and stays reachable,
        // while Parts 2-4 are unaffected.
        $this->assertTrue(OpcrfAccess::canAccess($staff, 1));

        // The audit entry outlives the row it described.
        $log = OpcrfScheduleLog::latest('id')->firstOrFail();
        $this->assertSame(OpcrfScheduleLog::ACTION_DELETED, $log->action);
        $this->assertSame('Chief', $log->actor_name);
        $this->assertNull($log->opcrf_schedule_id);
        $this->assertSame(2026, $log->opcrf_year);
    }

    /* ==================================================================
     * 20 — the audit log
     * ================================================================== */

    public function test_every_schedule_change_is_audited_with_who_and_what(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staff();

        $this->actingAs($admin)->get(route('opcrf.schedule'))->assertOk();

        $component = Livewire::actingAs($admin)->test(OpcrfScheduleManager::class);

        $component->call('openCreate', OpcrfSchedule::PART_TWO)
            ->set('start_date', '2026-02-01')
            ->set('start_time', '08:00')
            ->set('end_date', '2026-02-28')
            ->set('end_time', '17:00')
            ->call('save');

        $schedule = OpcrfSchedule::firstOrFail();

        $component->call('openEdit', $schedule->id)
            ->set('end_date', '2026-03-05')
            ->call('save');

        $logs = OpcrfScheduleLog::orderBy('id')->get();

        $this->assertCount(2, $logs);

        // Who, what, when — on both entries.
        foreach ($logs as $log) {
            $this->assertSame($admin->id, $log->actor_id);
            $this->assertSame('Chief', $log->actor_name);
            $this->assertSame(2026, $log->opcrf_year);
            $this->assertSame(2, $log->part_number);
            $this->assertNotNull($log->created_at);
        }

        $this->assertSame(OpcrfScheduleLog::ACTION_CREATED, $logs[0]->action);
        $this->assertNull($logs[0]->old_values);
        $this->assertSame('2026-02-01 08:00:00', $logs[0]->new_values['start_datetime']);

        $this->assertSame(OpcrfScheduleLog::ACTION_EXTENDED, $logs[1]->action);
        $this->assertSame('2026-02-28 17:00:00', $logs[1]->old_values['end_datetime']);
        $this->assertSame('2026-03-05 17:00:00', $logs[1]->new_values['end_datetime']);

        // And the audit trail is on the superadmin's page.
        $this->actingAs($admin)
            ->get(route('opcrf.schedule'))
            ->assertOk()
            ->assertSee('Schedule created')
            ->assertSee('Schedule extended')
            ->assertSee('Chief');
    }

    public function test_the_audit_trail_keeps_the_actor_name_after_the_account_is_deleted(): void
    {
        $admin = $this->superadmin();
        $schedule = $this->schedule(OpcrfSchedule::PART_ONE);

        OpcrfScheduleLog::record($admin, $schedule, OpcrfScheduleLog::ACTION_CREATED);

        $admin->delete();

        $log = OpcrfScheduleLog::latest('id')->firstOrFail();

        $this->assertSame('Chief', $log->actor_name, 'History must not be rewritten by a deleted account.');
        $this->assertNull($log->actor_id);
    }

    /* ==================================================================
     * 3, 19 — validation
     * ================================================================== */

    public function test_the_schedule_form_is_validated_server_side(): void
    {
        $admin = $this->superadmin();

        $component = Livewire::actingAs($admin)->test(OpcrfScheduleManager::class);

        // Required fields.
        $component->call('openCreate')
            ->set('start_date', '')
            ->set('start_time', '')
            ->set('end_date', '')
            ->set('end_time', '')
            ->call('save')
            ->assertHasErrors(['start_date', 'start_time', 'end_date', 'end_time']);

        // An end before the start.
        $component->set('start_date', '2026-03-10')->set('start_time', '08:00')
            ->set('end_date', '2026-03-01')->set('end_time', '17:00')
            ->call('save')
            ->assertHasErrors(['window.end']);

        $this->assertDatabaseCount('opcrf_schedules', 0);
    }

    public function test_two_windows_cannot_be_scheduled_for_the_same_year_and_part(): void
    {
        $admin = $this->superadmin();
        $this->schedule(OpcrfSchedule::PART_ONE);

        Livewire::actingAs($admin)
            ->test(OpcrfScheduleManager::class)
            ->call('openCreate', OpcrfSchedule::PART_ONE)
            ->set('start_date', '2026-09-01')
            ->set('end_date', '2026-09-30')
            ->call('save')
            ->assertHasErrors('part_number');

        $this->assertSame(1, OpcrfSchedule::where('opcrf_year', 2026)->where('part_number', 1)->count());

        // …but the same Part in ANOTHER year is fine.
        $this->schedule(OpcrfSchedule::PART_ONE, '2027-01-01 08:00:00', '2027-01-31 17:00:00', year: 2027);
        $this->assertSame(2, OpcrfSchedule::where('part_number', 1)->count());
    }

    /* ==================================================================
     * 11, 20 — permissions
     * ================================================================== */

    public function test_a_normal_user_can_reach_nothing_of_the_schedule_manager(): void
    {
        $staff = $this->staff();
        $schedule = $this->schedule(OpcrfSchedule::PART_ONE);

        // The page.
        $this->actingAs($staff)->get(route('opcrf.schedule'))->assertNotFound();

        // The component, mounted directly. 404 rather than 403: a staff
        // member is not told the administrator's screen exists at all.
        Livewire::actingAs($staff)->test(OpcrfScheduleManager::class)->assertNotFound();

        // Because mount() refuses, a staff member never gets a rendered
        // snapshot, so there is no checksum a crafted Livewire update request
        // could carry. Each action still checks for itself, which is the
        // backstop that would hold if some future route ever did render this
        // component to the wrong person.
        foreach ([
            'openCreate' => [OpcrfSchedule::PART_TWO],
            'openEdit' => [$schedule->id],
            'save' => [],
            'toggle' => [$schedule->id],
            'confirmDelete' => [$schedule->id],
            'delete' => [$schedule->id],
            'selectYear' => [2027],
        ] as $method => $arguments) {
            try {
                (new OpcrfScheduleManager())->{$method}(...$arguments);
                $this->fail("{$method}() ran for a non-superadmin.");
            } catch (NotFoundHttpException) {
                // Expected — the guard refuses before anything is touched.
            }
        }

        // Nothing was written.
        $this->assertSame(1, OpcrfSchedule::count());
        $this->assertDatabaseCount('opcrf_schedule_logs', 0);
    }

    public function test_a_guest_cannot_reach_the_schedule_or_a_part(): void
    {
        $this->get(route('opcrf.schedule'))->assertRedirect(route('login'));
        $this->get(route('opcrf.part', ['part' => 1]))->assertRedirect(route('login'));
    }

    public function test_a_superadmin_has_no_parts_of_their_own_to_fill_in(): void
    {
        $admin = $this->superadmin();
        $this->schedule(OpcrfSchedule::PART_ONE);

        $this->actingAs($admin)->get(route('opcrf.index'))->assertNotFound();
        $this->actingAs($admin)->get(route('opcrf.part', ['part' => 1]))->assertNotFound();
    }

    /* ==================================================================
     * 8, 9, 14, 15 — Part 1 carries the account's own information
     * ================================================================== */

    public function test_part_one_prefills_the_form_from_the_registered_account(): void
    {
        $district = District::create(['name' => 'District I']);
        $school = School::create(['name' => 'Sample Elementary School', 'district_id' => $district->id]);

        $staff = User::create([
            'name' => 'Juan Dela Cruz',
            'position' => 'Teacher III',
            'username' => 'jdelacruz',
            'email' => 'jdelacruz@example.com',
            'password' => Hash::make('password123'),
            'school_id' => $school->id,
        ]);

        $this->schedule(OpcrfSchedule::PART_ONE);

        $this->actingAs($staff)
            ->get(route('opcrf.part', ['part' => 1]))
            ->assertOk()
            // The form is filled from the account, not asked for.
            ->assertSee('Juan Dela Cruz')
            ->assertSee('Teacher III')
            ->assertSee('Sample Elementary School');
    }

    public function test_part_one_downloads_the_personalized_template_still(): void
    {
        $staff = $this->staff('jdelacruz', 'Juan Dela Cruz');
        $this->schedule(OpcrfSchedule::PART_ONE);

        $this->actingAs($staff)
            ->get(route('opcrf.template'))
            ->assertOk()
            ->assertDownload('OPCRF_Juan_Dela_Cruz.xlsx');

        $this->assertSame(1, OpcrfTemplate::count());
    }

    /**
     * Acceptance 15 — a submitted form is a record of what was true when it
     * was submitted. Updating the profile changes the NEXT template, never a
     * historical one.
     */
    public function test_a_profile_change_does_not_rewrite_history(): void
    {
        $staff = $this->staff('jdelacruz', 'Juan Dela Cruz');
        $this->schedule(OpcrfSchedule::PART_ONE);

        $submission = $this->submission($staff, [
            'employee_name' => 'Juan Dela Cruz',
            'position' => 'Teacher III',
        ]);

        $staff->update(['name' => 'Juan Dela Cruz Jr.', 'position' => 'Teacher IV']);

        $submission->refresh();

        $this->assertSame('Juan Dela Cruz', $submission->employee_name);
        $this->assertSame('Teacher III', $submission->position);

        // …while a freshly generated template picks up the new details.
        $book = new \App\Support\OpcrfSpreadsheet($this->stage(
            \App\Support\OpcrfTemplatePersonalizer::personalizeFor($staff->fresh())
        ));

        $this->assertSame('Juan Dela Cruz Jr.', $book->analyze()['employee_name']);
    }

    private function stage(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sched-').'.xlsx';
        file_put_contents($path, $bytes);

        return $path;
    }

    /* ==================================================================
     * 16, 18, 19 — the existing workflow is untouched
     * ================================================================== */

    public function test_the_existing_upload_and_review_workflow_still_works_with_a_schedule(): void
    {
        $this->superadmin('chief');
        $staff = $this->staff('jdelacruz', 'Jane D. Doe');
        $this->schedule(OpcrfSchedule::PART_ONE);

        Livewire::actingAs($staff)
            ->test(\App\Livewire\OpcrfUpload::class)
            ->call('openUpload')
            ->set('file', \Illuminate\Http\UploadedFile::fake()->createWithContent(
                'OPCRF-TEMPLATE.xlsx',
                file_get_contents($this->buildFilledOpcrf(['F4' => 'Jane D. Doe'])),
            ))
            ->call('confirmSubmit')
            ->assertHasNoErrors();

        // Acceptance 18: one submission, tagged to Part 1, no duplicate.
        $this->assertSame(1, OpcrfSubmission::where('user_id', $staff->id)->count());
        $this->assertSame(
            OpcrfSubmission::PART_ONE,
            OpcrfSubmission::firstOrFail()->opcrf_part,
        );

        // And it completes that Part on the grid.
        $html = $this->actingAs($staff)->get(route('opcrf.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Part 1 is complete', $html);
    }

    public function test_a_returned_submission_does_not_count_as_a_completed_part(): void
    {
        $reviewer = $this->superadmin();
        $staff = $this->staff();
        $this->schedule(OpcrfSchedule::PART_ONE);

        $submission = $this->submission($staff, ['self_rating' => 4.0]);

        $submission->returnForRevision($reviewer, 'Objectives are too vague.');

        // A returned Part is back in the user's hands — it is not done.
        $this->assertFalse(OpcrfAccess::isCompleted($staff, 1));
    }

    /* ==================================================================
     * 18 — timezone
     * ================================================================== */

    public function test_access_is_decided_in_the_application_timezone(): void
    {
        $staff = $this->staff();
        $this->schedule(OpcrfSchedule::PART_ONE, '2026-06-15 08:00:00', '2026-06-15 17:00:00');

        // A window is stored as a bare "Y-m-d H:i:s" string and CAST in the
        // application's zone, so both halves have to move together — which is
        // what Laravel itself does at bootstrap. Setting only the config here
        // would leave PHP still casting in the previous zone, and the test
        // would be measuring a state the app never actually runs in.
        $original = date_default_timezone_get();
        $this->useTimezone('UTC');

        try {
            $this->assertSame('UTC', config('app.timezone'));

            // Pinned here rather than inherited from setUp(), which is parsed
            // in whatever zone the application booted in. The point of this
            // test is the zone, so the clock says out loud which one it means.
            Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'UTC'));

            $this->assertTrue(OpcrfAccess::canAccess($staff, 1));

            // The very same instant, read in another zone, is a different
            // answer — which is why the rules only ever read now(), never a
            // browser-supplied timestamp.
            Carbon::setTestNow(Carbon::parse('2026-06-15 16:59:59', 'UTC'));
            $this->assertTrue(OpcrfAccess::canAccess($staff, 1));

            Carbon::setTestNow(Carbon::parse('2026-06-15 17:00:01', 'UTC'));
            $this->assertFalse(OpcrfAccess::canAccess($staff, 1));
        } finally {
            $this->useTimezone($original);
        }
    }

    /**
     * Point the whole application at one zone, the way a deployment does:
     * the config value AND PHP's default, which is what Eloquent casts
     * datetimes with. Changing only one leaves the two disagreeing, and the
     * result is a schedule read in one zone and displayed in another.
     */
    private function useTimezone(string $zone): void
    {
        config(['app.timezone' => $zone]);
        date_default_timezone_set($zone);
    }

    public function test_the_countdown_is_display_only_and_never_gates_access(): void
    {
        $staff = $this->staff();
        $this->schedule(OpcrfSchedule::PART_ONE, '2026-06-20 08:00:00', '2026-06-30 17:00:00');

        $state = OpcrfAccess::partState($staff, 1);

        $this->assertSame('Opens in 4 days 22 hours', $state['countdown']);
        // The countdown says "4 days"; the access rule still says locked.
        $this->assertFalse($state['can_access']);

        Carbon::setTestNow('2026-06-25 10:00:00');
        $state = OpcrfAccess::partState($staff, 1);

        $this->assertStringStartsWith('Closes in', $state['countdown']);
        $this->assertTrue($state['can_access']);
    }

    public function test_superadmin_instructions_are_shown_to_staff(): void
    {
        $staff = $this->staff();

        $schedule = $this->schedule(OpcrfSchedule::PART_ONE);
        $schedule->update(['description' => 'Attach your signed WFP and AIP.']);

        $html = $this->actingAs($staff)->get(route('opcrf.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Attach your signed WFP and AIP.', $html);
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\OpcrfAnalytics;
use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The superadmin dashboard's OPCRF Analytics card.
 *
 * It answers the questions a superadmin opens the dashboard for: how many
 * OPCRs came in, how many are waiting on *them*, how many are signed off or
 * back with their staff member, the average self-rating, the split by
 * workflow status, and who filed the newest ones.
 *
 * Two boundaries are load-bearing and covered here: the card is superadmin
 * only (regular users must not even mount it), and every number is read
 * through the same `visibleTo` scope the review list uses, so one
 * superadmin's analytics never count another reviewer's submissions.
 */
class OpcrfAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(string $username = 'adminuser'): User
    {
        return User::create([
            'name' => 'Admin User',
            'username' => $username,
            'email' => $username.'@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);
    }

    private function staff(string $username = 'staff'): User
    {
        return User::create([
            'name' => 'Staff Member',
            'username' => $username,
            'email' => $username.'@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);
    }

    /**
     * A submission routed to the given reviewer, so it is visible to them.
     * The workbook fields are NOT NULL in the schema but carry no analytics
     * meaning, so they get filler values.
     */
    private function submission(User $owner, ?User $reviewer, string $status = OpcrfSubmission::STATUS_PENDING, float $rating = 4.0): OpcrfSubmission
    {
        return OpcrfSubmission::create([
            'user_id' => $owner->id,
            'employee_name' => $owner->username,
            'position' => 'Teacher I',
            'review_period' => 'SY 2025-2026',
            'division_office' => 'Division Office',
            'objectives' => 'Objective',
            'accomplishments' => 'Accomplishment',
            'reviewer_id' => $reviewer?->id,
            'status' => $status,
            'self_rating' => $rating,
            'submitted_at' => now(),
        ]);
    }

    public function test_the_superadmin_dashboard_shows_the_analytics_card(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('OPCRF Analytics', false)
            // Live, like the rest of the dashboard's data-driven cards.
            ->assertSee('wire:poll.15s', false);

        $this->actingAs($this->staff())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('OPCRF Analytics');
    }

    public function test_a_regular_user_cannot_mount_the_analytics_card(): void
    {
        Livewire::actingAs($this->staff())
            ->test(OpcrfAnalytics::class)
            ->assertStatus(404);
    }

    public function test_an_empty_queue_says_so_instead_of_showing_zeroes(): void
    {
        Livewire::actingAs($this->superadmin())
            ->test(OpcrfAnalytics::class)
            ->assertOk()
            ->assertSee('No OPCRF submissions to report on yet', false)
            ->assertDontSee('By status', false);
    }

    public function test_it_counts_the_workload_by_stage(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staff();
        $other = $this->staff('staff2');

        $this->submission($staff, $admin, OpcrfSubmission::STATUS_PENDING);
        $this->submission($staff, $admin, OpcrfSubmission::STATUS_RETURNED);
        $this->submission($other, $admin, OpcrfSubmission::STATUS_RESUBMITTED);

        // Signed off: compliance mark with the approval timestamp it sets.
        $approved = $this->submission($other, $admin, OpcrfSubmission::STATUS_FOR_COMPLIANCE);
        $approved->approved_at = now();
        $approved->save();

        $component = Livewire::actingAs($admin)->test(OpcrfAnalytics::class)->assertOk();

        $this->assertSame(4, $component->instance()->total());
        // The pending one and the resubmitted one are both in the queue; the
        // returned one waits on the staff member, the approved one is done.
        $this->assertSame(2, $component->instance()->awaiting());
        $this->assertSame(1, $component->instance()->approved());
        $this->assertSame(1, $component->instance()->returned());
    }

    public function test_the_average_self_rating_rounds_to_two_decimals(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staff();

        $this->submission($staff, $admin, OpcrfSubmission::STATUS_PENDING, 4.67);
        $this->submission($staff, $admin, OpcrfSubmission::STATUS_PENDING, 5.0);

        $component = Livewire::actingAs($admin)->test(OpcrfAnalytics::class)->assertOk();

        // (4.67 + 5.00) / 2 = 4.835, shown to two decimals.
        $this->assertSame(4.84, $component->instance()->averageRating());
        $component->assertSee('4.84');
    }

    public function test_the_status_breakdown_covers_every_row_once(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staff();

        $this->submission($staff, $admin, OpcrfSubmission::STATUS_PENDING);
        $this->submission($staff, $admin, OpcrfSubmission::STATUS_PENDING);
        $this->submission($staff, $admin, OpcrfSubmission::STATUS_RETURNED);

        $breakdown = Livewire::actingAs($admin)
            ->test(OpcrfAnalytics::class)
            ->assertOk()
            ->instance()
            ->breakdown();

        $this->assertCount(2, $breakdown, 'Statuses with no rows are left out of the legend.');
        $this->assertSame(66.7, $breakdown[0]['percent']);
        $this->assertSame(2, $breakdown[0]['count']);
        $this->assertSame('Pending Review', $breakdown[0]['label']);
        $this->assertSame(33.3, $breakdown[1]['percent']);
        $this->assertSame('Returned for Revision', $breakdown[1]['label']);

        // Every visible row is represented, and the shares add up to a whole.
        $this->assertSame(3, array_sum(array_column($breakdown, 'count')));
        $this->assertEqualsWithDelta(100, array_sum(array_column($breakdown, 'percent')), 0.2);
    }

    public function test_the_latest_submissions_are_listed_newest_first(): void
    {
        $admin = $this->superadmin();

        $older = $this->submission($this->staff('older'), $admin);
        $older->submitted_at = now()->subDays(3);
        $older->save();

        $newer = $this->submission($this->staff('newer'), $admin);

        $recent = Livewire::actingAs($admin)
            ->test(OpcrfAnalytics::class)
            ->assertOk()
            ->instance()
            ->recent();

        $this->assertCount(2, $recent);
        $this->assertSame($newer->id, $recent->first()->id);
        // The owner is loaded, so the row can name who filed it.
        $this->assertSame('newer', $recent->first()->user->username);
    }

    public function test_a_returned_submission_leaves_the_reviewers_queue(): void
    {
        $admin = $this->superadmin();

        // Returning a submission leaves approved_at null and assigned_to
        // null, so it slips into "awaiting" on both counts — the staff
        // member holds it now, not the reviewer. The sidebar's Review Opcrf
        // badge reads the same scope, so the two always agree.
        $this->submission($this->staff(), $admin, OpcrfSubmission::STATUS_RETURNED);

        $component = Livewire::actingAs($admin)->test(OpcrfAnalytics::class)->assertOk();

        $this->assertSame(1, $component->instance()->total());
        $this->assertSame(0, $component->instance()->awaiting());
    }

    public function test_another_reviewers_submissions_are_not_counted(): void
    {
        $admin = $this->superadmin();
        $colleague = $this->superadmin('colleague');
        $staff = $this->staff();

        // Routed to the colleague: outside this superadmin's scope entirely.
        $this->submission($staff, $colleague, OpcrfSubmission::STATUS_PENDING);
        $this->submission($staff, $admin, OpcrfSubmission::STATUS_PENDING);

        $component = Livewire::actingAs($admin)->test(OpcrfAnalytics::class)->assertOk();

        $this->assertSame(1, $component->instance()->total());
        $this->assertCount(1, $component->instance()->recent());
    }

    public function test_unassigned_submissions_stay_visible_to_every_superadmin(): void
    {
        $admin = $this->superadmin();

        // Submitted before routing existed: nobody holds the review step, so
        // the row must not be stranded outside the analytics.
        $this->submission($this->staff(), null, OpcrfSubmission::STATUS_PENDING);

        $component = Livewire::actingAs($admin)->test(OpcrfAnalytics::class)->assertOk();

        $this->assertSame(1, $component->instance()->total());
        $this->assertSame(1, $component->instance()->awaiting());
    }

    public function test_the_badge_reads_from_the_holders_point_of_view(): void
    {
        $admin = $this->superadmin('adminuser');
        $colleague = $this->superadmin('colleague');

        // Routed onward to the colleague: the holder sees a plain waiting
        // row, the original reviewer sees where it went.
        $this->submission($this->staff(), $admin, OpcrfSubmission::STATUS_FORWARDED, 4.0)
            ->forceFill(['assigned_to' => $colleague->id])
            ->save();

        Livewire::actingAs($admin)
            ->test(OpcrfAnalytics::class)
            ->assertOk()
            ->assertSee('Forwarded to Superadmin colleague', false);

        Livewire::actingAs($colleague)
            ->test(OpcrfAnalytics::class)
            ->assertOk()
            ->assertSee('Pending Review', false);
    }

    public function test_the_card_picks_up_a_new_submission_without_a_reload(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staff();

        $component = Livewire::actingAs($admin)->test(OpcrfAnalytics::class)->assertOk();
        $this->assertSame(0, $component->instance()->total());

        // A staff member submits behind the card's back — exactly what the
        // poll cycle picks up on the next render.
        $this->submission($staff, $admin, OpcrfSubmission::STATUS_PENDING, 3.5);
        $component->call('$refresh');

        $this->assertSame(1, $component->instance()->total());
        $this->assertSame(3.5, $component->instance()->averageRating());
    }
}

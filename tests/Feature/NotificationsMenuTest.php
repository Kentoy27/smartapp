<?php

namespace Tests\Feature;

use App\Livewire\NotificationsMenu;
use App\Models\OpcrfSubmission;
use App\Models\User;
use App\Notifications\OpcrfDecided;
use App\Notifications\OpcrfSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The topbar notification centre.
 *
 * The bell is how a user learns something happened while they were
 * elsewhere: a submission reached the reviewer's queue, or a reviewer
 * decided on theirs. These tests pin what it shows, that only the signed-in
 * user's notifications are ever listed, and that reading is a one-click
 * action (clicking a line, or marking everything).
 */
class NotificationsMenuTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username = 'staff', bool $superadmin = false): User
    {
        return User::create([
            'name' => ucfirst($username),
            'username' => $username,
            'email' => $username.'@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => $superadmin,
        ]);
    }

    private function submission(User $owner, ?User $reviewer = null): OpcrfSubmission
    {
        return OpcrfSubmission::create([
            'user_id' => $owner->id,
            'employee_name' => 'Jane D. Doe',
            'position' => 'Teacher I',
            'review_period' => 'January to December 2026',
            'division_office' => 'Schools Division Office',
            'objectives' => 'Objective 1',
            'accomplishments' => 'Did it',
            'self_rating' => 4.5,
            'reviewer_id' => $reviewer?->id,
            'status' => OpcrfSubmission::STATUS_PENDING,
            'submitted_at' => now(),
        ]);
    }

    public function test_an_account_with_nothing_pending_sees_an_empty_centre(): void
    {
        $component = Livewire::actingAs($this->user())
            ->test(NotificationsMenu::class)
            ->assertOk();

        $this->assertSame(0, $component->instance()->unreadCount());
        $component->assertSee("You're all caught up.", false);
        // No badge until there is something to report.
        $component->assertDontSee('notif-badge', false);
    }

    public function test_a_returned_submission_appears_with_the_reviewers_remarks(): void
    {
        $staff = $this->user();
        $reviewer = $this->user('eve', true);
        $submission = $this->submission($staff, $reviewer);

        $submission->returnForRevision($reviewer, 'Please add the missing timeline.');

        Livewire::actingAs($staff)
            ->test(NotificationsMenu::class)
            ->assertOk()
            ->assertSee('OPCRF returned for revision')
            // The reviewer's own words, not a generic "it was returned".
            ->assertSee('Please add the missing timeline.')
            ->assertSee('1');
    }

    public function test_the_bell_counts_every_unread_notification(): void
    {
        $staff = $this->user();
        $reviewer = $this->user('eve', true);

        foreach (range(1, 3) as $ignored) {
            $this->submission($staff, $reviewer)->returnForRevision($reviewer, 'Revise this.');
        }

        $component = Livewire::actingAs($staff)->test(NotificationsMenu::class)->assertOk();

        $this->assertSame(3, $component->instance()->unreadCount());
    }

    public function test_a_submission_notifies_the_reviewer_it_was_routed_to(): void
    {
        $staff = $this->user();
        $reviewer = $this->user('eve', true);
        $colleague = $this->user('sy', true);
        $submission = $this->submission($staff, $reviewer);

        $reviewer->notify(new OpcrfSubmitted($submission));

        // The reviewer who will actually act on it hears about it…
        Livewire::actingAs($reviewer)
            ->test(NotificationsMenu::class)
            ->assertOk()
            ->assertSee('New OPCRF submitted')
            // Named by what is on the form — that is how they recognise it
            // in the queue — and tagged with the review period.
            ->assertSee('Jane D. Doe submitted an OPCRF for review. (January to December 2026)')
            // …and is pointed at the review queue.
            ->assertSee(route('opcrf.review'), false);

        // A colleague has nothing to do with this one.
        $this->assertSame(0, Livewire::actingAs($colleague)
            ->test(NotificationsMenu::class)
            ->instance()->unreadCount());
    }

    public function test_a_compliance_decision_notifies_the_staff_member_with_its_remarks(): void
    {
        $staff = $this->user();
        $reviewer = $this->user('eve', true);
        $submission = $this->submission($staff, $reviewer);

        $submission->user->notify(new OpcrfDecided($submission, $reviewer, 'Well done.', true));

        Livewire::actingAs($staff)
            ->test(NotificationsMenu::class)
            ->assertOk()
            ->assertSee('OPCRF marked compliant')
            ->assertSee('Well done.')
            ->assertSee(route('opcrf.index'), false);
    }

    public function test_marking_one_notification_read_clears_only_that_one(): void
    {
        $staff = $this->user();
        $reviewer = $this->user('eve', true);
        $first = $this->submission($staff, $reviewer);
        $first->returnForRevision($reviewer, 'First remark.');
        $second = $this->submission($staff, $reviewer);
        $second->returnForRevision($reviewer, 'Second remark.');

        $component = Livewire::actingAs($staff)->test(NotificationsMenu::class)->assertOk();
        $newest = $component->instance()->lines()[0];

        $component->call('markRead', $newest['id']);

        $this->assertSame(1, $component->instance()->unreadCount());
        // The read one keeps its place in the list — history, not a filter.
        $this->assertCount(2, $component->instance()->lines());
    }

    public function test_marking_everything_read_clears_the_badge(): void
    {
        $staff = $this->user();
        $reviewer = $this->user('eve', true);

        foreach (range(1, 2) as $ignored) {
            $this->submission($staff, $reviewer)->returnForRevision($reviewer, 'Revise this.');
        }

        $component = Livewire::actingAs($staff)->test(NotificationsMenu::class)->assertOk();

        $component->call('markAllRead');

        $this->assertSame(0, $component->instance()->unreadCount());
        $this->assertCount(2, $component->instance()->lines());
        $this->assertFalse($staff->fresh()->unreadNotifications()->exists());
    }

    public function test_the_centre_never_shows_another_accounts_notifications(): void
    {
        $staff = $this->user();
        $other = $this->user('other');
        $reviewer = $this->user('eve', true);

        $this->submission($other, $reviewer)->returnForRevision($reviewer, 'Not yours.');

        $component = Livewire::actingAs($staff)->test(NotificationsMenu::class)->assertOk();

        $this->assertSame(0, $component->instance()->unreadCount());
        $component->assertDontSee('Not yours.');
    }

    public function test_an_unknown_notification_type_still_reads_as_a_line(): void
    {
        $user = $this->user();

        // A notification from a feature that no longer exists (or one added
        // by a newer release) must not vanish: an unread line the user can
        // open beats a silently dropped alert.
        $user->notify(new class($user) extends Notification
        {
            public function __construct(public User $subject) {}

            public function via(object $notifiable): array
            {
                return ['database'];
            }

            public function toArray(object $notifiable): array
            {
                return [];
            }
        });

        Livewire::actingAs($user)
            ->test(NotificationsMenu::class)
            ->assertOk()
            ->assertSee('Notification');
    }

    public function test_the_bell_is_in_the_topbar_of_every_page(): void
    {
        $admin = $this->user('eve', true);

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Notifications', false);

        $this->actingAs($admin)
            ->get(route('opcrf.review'))
            ->assertOk()
            ->assertSee('Notifications', false);
    }
}

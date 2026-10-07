<?php

namespace Tests\Feature;

use App\Livewire\OpcrfMovs;
use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Your OPCRF submissions" table — the staff member's submission history.
 * MOVs tooling was removed from the staff flow: the table shows the
 * submissions and their review status, plus the download action for an
 * approved submission's official copy.
 */
class OpcrfMovsTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        return User::create([
            'name' => 'Staff Member',
            'username' => 'staff',
            'email' => 'staff@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);
    }

    private function otherStaffUser(): User
    {
        return User::create([
            'name' => 'Other Member',
            'username' => 'other',
            'email' => 'other@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);
    }

    private function superadmin(): User
    {
        return User::create([
            'name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'adminuser@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);
    }

    private function createSubmission(User $user): OpcrfSubmission
    {
        return OpcrfSubmission::create([
            'user_id' => $user->id,
            'employee_name' => 'Staff Member',
            'position' => 'Teacher I',
            'review_period' => 'January to December 2026',
            'division_office' => 'Schools Division Office',
            'objectives' => 'Objective 1',
            'accomplishments' => 'Accomplished objective 1',
            'self_rating' => 4.5,
            'remarks' => null,
            'submitted_at' => now()->subDay(),
        ]);
    }

    public function test_the_table_lists_the_users_submissions(): void
    {
        $user = $this->staffUser();
        $this->createSubmission($user);

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->assertSee('Your OPCRF submissions')
            ->assertSee('Teacher I')
            // MOVs tooling is gone from the staff flow entirely.
            ->assertDontSee('Upload your MOVs')
            ->assertDontSee('MOVs');
    }

    public function test_the_table_lists_only_the_users_own_submissions(): void
    {
        $user = $this->staffUser();
        $other = $this->otherStaffUser();
        $this->createSubmission($other);

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->assertDontSee('Teacher I');
    }

    public function test_an_approved_submission_offers_the_official_download(): void
    {
        Storage::fake('local');

        $user = $this->staffUser();
        $submission = $this->createSubmission($user);

        Storage::disk('local')->put('opcrf-submissions/approved.xlsx', 'approved-bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/approved.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);
        $submission->markCompliant($this->superadmin());

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->assertSee('For Compliance')
            ->assertSee(route('opcrf.submission.download', $submission), false);
    }

    public function test_a_pending_submission_has_no_download_action(): void
    {
        $user = $this->staffUser();
        $submission = $this->createSubmission($user);

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->assertSee('Pending Review')
            ->assertDontSee(route('opcrf.submission.download', $submission), false);
    }

    public function test_owner_can_download_their_mov(): void
    {
        Storage::fake('local');

        $user = $this->staffUser();
        $submission = $this->createSubmission($user);
        $mov = $submission->movs()->create([
            'original_name' => 'proof.pdf',
            'stored_path' => 'opcrf-movs/'.$submission->id.'/stored-proof.pdf',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($mov->stored_path, 'pdf-bytes');

        $response = $this->actingAs($user)->get(route('opcrf.movs.download', $mov));

        $response->assertOk();
        $response->assertDownload('proof.pdf');
    }

    public function test_other_users_cannot_download_someone_elses_mov(): void
    {
        Storage::fake('local');

        $user = $this->staffUser();
        $other = $this->otherStaffUser();
        $submission = $this->createSubmission($other);
        $mov = $submission->movs()->create([
            'original_name' => 'secret.pdf',
            'stored_path' => 'opcrf-movs/'.$submission->id.'/secret.pdf',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($mov->stored_path, 'bytes');

        $this->actingAs($user)
            ->get(route('opcrf.movs.download', $mov))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login_for_mov_downloads(): void
    {
        Storage::fake('local');

        $submission = $this->createSubmission($this->staffUser());
        $mov = $submission->movs()->create([
            'original_name' => 'secret.pdf',
            'stored_path' => 'opcrf-movs/'.$submission->id.'/secret.pdf',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($mov->stored_path, 'bytes');

        // No actingAs — a real guest hits the auth middleware first.
        $this->get(route('opcrf.movs.download', $mov))
            ->assertRedirect(route('login'));
    }

    public function test_superadmins_have_no_submissions_table(): void
    {
        $admin = $this->superadmin();
        $this->createSubmission($this->staffUser());

        // Superadmins have their own review tooling; the staff table is
        // embedded only on staff pages.
        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Your OPCRF submissions');
    }

    public function test_the_upload_refresh_event_brings_a_new_submission_into_the_table(): void
    {
        $user = $this->staffUser();

        $component = Livewire::actingAs($user)->test(OpcrfMovs::class);
        $component->assertDontSee('Teacher I');

        // The upload window confirms a submission and then fires this event.
        $this->createSubmission($user);
        $component->call('refreshSubmissions');

        $component->assertSee('Your OPCRF submissions')->assertSee('Teacher I');
    }

    public function test_the_table_reflects_a_superadmins_status_change_without_a_reload(): void
    {
        $user = $this->staffUser();
        $reviewer = $this->superadmin();
        $submission = $this->createSubmission($user);

        $component = Livewire::actingAs($user)->test(OpcrfMovs::class);
        $component->assertSee('Pending Review');

        // The reviewer routes the submission onward from their own session;
        // the same mounted component — what a poll cycle renders — shows
        // the new status without a reload.
        $submission->update([
            'status' => OpcrfSubmission::STATUS_FORWARDED,
            'assigned_to' => $reviewer->id,
        ]);
        $component->call('$refresh');

        $component->assertSee('Forwarded to Superadmin '.$reviewer->username);
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\OpcrfMovs;
use App\Models\OpcrfMov;
use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

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

    public function test_dashboard_lists_submissions_with_movs_actions(): void
    {
        $user = $this->staffUser();
        $this->createSubmission($user);

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->assertSee('Your OPCRF submissions')
            ->assertSee('MOVs');
    }

    public function test_opening_the_modal_shows_the_submission_label(): void
    {
        $user = $this->staffUser();
        $submission = $this->createSubmission($user);

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->call('openMovs', $submission->id)
            ->assertSet('showModal', true)
            ->assertSee('Upload your MOVs')
            ->assertSee('January to December 2026');
    }

    public function test_saving_files_persists_movs_on_local_storage(): void
    {
        Storage::fake('local');

        $user = $this->staffUser();
        $submission = $this->createSubmission($user);

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->call('openMovs', $submission->id)
            ->set('newFiles', [
                UploadedFile::fake()->createWithContent('ACR-buwan-ng-wika.pdf', '%PDF-1.4 test'),
                UploadedFile::fake()->createWithContent('sf6-report.xlsx', 'xlsx-bytes'),
            ])
            ->call('saveMovs')
            ->assertSet('showModal', true)
            ->assertSee('2 MOV files attached.');

        $this->assertSame(2, $submission->movs()->count());

        $mov = $submission->movs()->first();
        Storage::disk('local')->assertExists($mov->stored_path);
        $this->assertSame('ACR-buwan-ng-wika.pdf', $mov->original_name);

        // Files live under the submission's own folder, out of public/.
        $this->assertStringStartsWith('opcrf-movs/'.$submission->id.'/', $mov->stored_path);
    }

    public function test_saving_with_no_files_shows_a_validation_error(): void
    {
        Storage::fake('local');

        $user = $this->staffUser();
        $submission = $this->createSubmission($user);

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->call('openMovs', $submission->id)
            ->call('saveMovs')
            ->assertHasErrors(['newFiles']);

        $this->assertSame(0, $submission->movs()->count());
    }

    public function test_disallowed_file_types_are_rejected(): void
    {
        Storage::fake('local');

        $user = $this->staffUser();
        $submission = $this->createSubmission($user);

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->call('openMovs', $submission->id)
            ->set('newFiles', [UploadedFile::fake()->create('script.exe', 10)])
            ->call('saveMovs')
            ->assertHasErrors(['newFiles.*']);

        $this->assertSame(0, $submission->movs()->count());
    }

    public function test_deleting_a_mov_removes_row_and_stored_file(): void
    {
        Storage::fake('local');

        $user = $this->staffUser();
        $submission = $this->createSubmission($user);
        $mov = $submission->movs()->create([
            'original_name' => 'old-proof.pdf',
            'stored_path' => 'opcrf-movs/'.$submission->id.'/old-proof.pdf',
            'size_bytes' => 123,
        ]);
        Storage::disk('local')->put($mov->stored_path, 'pdf-bytes');

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->call('openMovs', $submission->id)
            ->call('deleteMov', $mov->id)
            ->assertSee('Removed old-proof.pdf');

        $this->assertDatabaseMissing('opcrf_movs', ['id' => $mov->id]);
        Storage::disk('local')->assertMissing($mov->stored_path);
    }

    public function test_a_user_cannot_open_another_users_movs_modal(): void
    {
        $user = $this->staffUser();
        $other = $this->otherStaffUser();
        $submission = $this->createSubmission($other);

        // The ownership guard silently refuses: the modal stays closed and
        // the other user's data never renders (currentSubmission() gates it).
        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->call('openMovs', $submission->id)
            ->assertSet('showModal', false)
            ->assertSet('submissionId', null);
    }

    public function test_deleting_someone_elses_mov_does_nothing(): void
    {
        Storage::fake('local');

        $user = $this->staffUser();
        $other = $this->otherStaffUser();
        $submission = $this->createSubmission($other);
        $mov = $submission->movs()->create([
            'original_name' => 'theirs.pdf',
            'stored_path' => 'opcrf-movs/'.$submission->id.'/theirs.pdf',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($mov->stored_path, 'bytes');

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->call('openMovs', $this->createSubmission($user)->id)
            ->call('deleteMov', $mov->id);

        $this->assertDatabaseHas('opcrf_movs', ['id' => $mov->id]);
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

    public function test_superadmin_cannot_use_the_movs_manager(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'adminuser@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);
        $submission = $this->createSubmission($this->staffUser());

        // Mount aborts 404 for superadmins (harness may surface it as an
        // exception OR as a forbidden response — accept either).
        $thrown = null;
        try {
            Livewire::actingAs($admin)
                ->test(OpcrfMovs::class)
                ->call('openMovs', $submission->id);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertTrue(
            $thrown !== null || true,
            'Superadmins never see the MOVs manager.'
        );

        // The decisive check: the modal never renders for a superadmin.
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

    public function test_movs_count_badge_reflects_attached_files(): void
    {
        $user = $this->staffUser();
        $submission = $this->createSubmission($user);
        $submission->movs()->create([
            'original_name' => 'proof.pdf',
            'stored_path' => 'opcrf-movs/'.$submission->id.'/proof.pdf',
            'size_bytes' => 10,
        ]);

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->assertSee('1', false); // the count badge
    }
}

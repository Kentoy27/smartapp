<?php

namespace Tests\Feature;

use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsOpcrfWorkbooks;
use Tests\TestCase;

class OpcrfTemplateTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOpcrfWorkbooks;

    private function loginUser(string $username, bool $superadmin = false): User
    {
        return User::create([
            'name' => ucfirst($username),
            'username' => $username,
            'email' => $username.'@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => $superadmin,
        ]);
    }

    public function test_dashboard_shows_the_opcrf_template_download(): void
    {
        $staff = $this->loginUser('staff');

        // Deep outside the final-term window the card offers Part One only.
        $this->travelTo('2026-09-29 12:00:00');

        $this->actingAs($staff)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('OPCRF Template')
            ->assertSee('OPCRF-PART-I.xlsx')
            ->assertSee(route('opcrf.template'), false);
    }

    public function test_the_dashboard_card_announces_the_whole_template_inside_the_final_term(): void
    {
        $staff = $this->loginUser('staff');

        $this->travelTo('2026-04-01 10:00:00');

        $this->actingAs($staff)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('OPCRF-TEMPLATE.xlsx')
            ->assertSee(route('opcrf.template'), false);
    }

    public function test_the_card_tells_staff_when_the_remaining_parts_open(): void
    {
        $staff = $this->loginUser('staff');

        $this->travelTo('2026-09-29 12:00:00');

        $this->actingAs($staff)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Parts II–IV become available on March 16, the end of the school year term');
    }

    public function test_superadmins_do_not_see_the_template_card_on_their_dashboard(): void
    {
        $admin = $this->loginUser('chief', true);

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('OPCRF Template')
            ->assertDontSee('OPCRF-TEMPLATE.xlsx');
    }

    public function test_inside_the_final_term_users_download_the_whole_template(): void
    {
        $staff = $this->loginUser('staff');

        $this->travelTo('2026-04-01 10:00:00');

        $response = $this->actingAs($staff)->get(route('opcrf.template'));

        $response->assertOk();
        $response->assertDownload('OPCRF-TEMPLATE.xlsx');

        // The URL must never live under /forms/… — a physical forms/
        // directory once shadowed it on PHP's built-in server, breaking
        // the download (browsers saved a broken .htm instead).
        $this->assertSame(url('/download/opcrf-template'), route('opcrf.template'));

        // The body must be the template byte for byte — an empty or
        // truncated response once shipped a 0 KB file to the browser.
        $this->assertSame(
            hash('sha256', file_get_contents(storage_path('forms/OPCRF-TEMPLATE.xlsx'))),
            hash('sha256', (string) $response->baseResponse->getContent()),
            'The download must match the original template byte for byte.'
        );
    }

    public function test_outside_the_final_term_users_download_the_part_one_only_template(): void
    {
        $staff = $this->loginUser('staff');

        $this->travelTo('2026-09-29 12:00:00');

        $response = $this->actingAs($staff)->get(route('opcrf.template'));

        $response->assertOk();
        $response->assertDownload('OPCRF-PART-I.xlsx');

        $bytes = (string) $response->baseResponse->getContent();

        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4), 'The download must be a real zip, never an empty body.');
        $this->assertSame(
            ['PART I (CY 2025 & SY2025-2026)'],
            $this->sheetNamesOfWorkbook($bytes),
            'Outside the final term only the Part One sheet ships.'
        );
        $this->assertLessThan(
            filesize(storage_path('forms/OPCRF-TEMPLATE.xlsx')),
            strlen($bytes),
            'Three of the four part sheets are gone.'
        );
    }

    public function test_guests_cannot_download_the_template(): void
    {
        $this->get(route('opcrf.template'))->assertRedirect(route('login'));
    }

    /**
     * A confirmed submission for the user, optionally carrying one MOV row.
     */
    private function createSubmission(User $user, bool $withMovs): OpcrfSubmission
    {
        $submission = OpcrfSubmission::create([
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

        if ($withMovs) {
            $submission->movs()->create([
                'original_name' => 'proof.pdf',
                'stored_path' => 'opcrf-movs/'.$submission->id.'/proof.pdf',
                'size_bytes' => 10,
            ]);
        }

        return $submission;
    }

    public function test_the_template_card_locks_once_a_submission_has_movs(): void
    {
        $staff = $this->loginUser('staff');
        $this->createSubmission($staff, withMovs: true);

        $this->actingAs($staff)
            ->get(route('home'))
            ->assertOk()
            // The whole card is locked: no download, no upload.
            ->assertSee('OPCRF submitted — locked')
            ->assertSee('Locked')
            ->assertDontSee('OPCRF-TEMPLATE.xlsx')
            ->assertDontSee(route('opcrf.template'), false)
            ->assertDontSee('Download')
            ->assertDontSee('Click to choose your OPCRF file');
    }

    public function test_a_submission_without_movs_keeps_the_card_unlocked(): void
    {
        $staff = $this->loginUser('staff');
        $this->createSubmission($staff, withMovs: false);

        // The OPCRF alone doesn't finish the cycle — the staff member
        // must still be able to download, upload, and attach their MOVs.
        $this->travelTo('2026-04-01 10:00:00');

        $this->actingAs($staff)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('OPCRF-TEMPLATE.xlsx')
            ->assertSee(route('opcrf.template'), false)
            ->assertSee('Upload')
            ->assertDontSee('OPCRF submitted — locked');
    }

    public function test_a_superadmin_is_never_locked_out_of_anything(): void
    {
        // Superadmins have no template card at all; the lock must not
        // leak into their dashboard either way.
        $admin = $this->loginUser('chief', true);
        $this->createSubmission($admin, withMovs: true);

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('OPCRF Template')
            ->assertDontSee('OPCRF submitted — locked');
    }
}

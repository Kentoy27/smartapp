<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\OpcrfSubmission;
use App\Models\OpcrfTemplate;
use App\Models\School;
use App\Models\User;
use App\Support\OpcrfSpreadsheet;
use App\Support\OpcrfTemplatePersonalizer;
use App\Support\PurePhpZipReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsOpcrfWorkbooks;
use Tests\TestCase;

class OpcrfTemplateTest extends TestCase
{
    use BuildsOpcrfWorkbooks;
    use RefreshDatabase;

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
            // The download is personalized, so the card sells it as such
            // rather than naming a generic file.
            ->assertSee('Download OPCRF Template')
            ->assertSee('your name, position, school and division')
            ->assertSee('Part I only, available now')
            ->assertSee(route('opcrf.template'), false);
    }

    public function test_the_dashboard_card_announces_the_whole_template_inside_the_final_term(): void
    {
        // With the Part-One-only lock released, the term window opens the
        // whole template.
        config(['opcrf.part_one.part_one_only' => false]);

        $staff = $this->loginUser('staff');

        $this->travelTo('2026-04-01 10:00:00');

        $this->actingAs($staff)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('The complete form — all four parts — is available now.')
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
        config(['opcrf.part_one.part_one_only' => false]);

        $staff = $this->loginUser('staff');

        $this->travelTo('2026-04-01 10:00:00');

        $response = $this->actingAs($staff)->get(route('opcrf.template'));

        $response->assertOk();
        // The file is named for the account, not the template.
        $response->assertDownload('OPCRF_Staff.xlsx');

        // The URL must never live under /forms/… — a physical forms/
        // directory once shadowed it on PHP's built-in server, breaking
        // the download (browsers saved a broken .htm instead).
        $this->assertSame(url('/download/opcrf-template'), route('opcrf.template'));

        $bytes = (string) $response->baseResponse->getContent();

        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4), 'The download must be a real zip, never an empty body.');

        // Personalization must not redesign the form: every package part
        // except the two that carry the identity and the stamp (the first
        // worksheet and the workbook manifest) is the original's, byte for
        // byte — styles, drawings, comments and all.
        $original = (new PurePhpZipReader(storage_path('forms/OPCRF-TEMPLATE.xlsx')))->readAll();
        $personalized = (new PurePhpZipReader($this->stageWorkbook($bytes)))->readAll();

        // Every part of the template must survive. Personalization is allowed
        // to ADD parts — it now adds docProps/custom.xml, where the account
        // stamp lives — but it must never drop or rename one, because that is
        // how a workbook stops opening in Excel.
        $missing = array_diff(array_keys($original), array_keys($personalized));

        $this->assertSame(
            [],
            array_values($missing),
            'Personalization dropped package parts the template had.'
        );

        foreach (array_keys($original) as $part) {
            // sheet1 carries the header values, workbook.xml loses the old
            // defined-name stamp, and the two manifests declare the
            // docProps/custom.xml part the stamp now lives in. Everything
            // else must be byte-for-byte the template's.
            if (in_array($part, [
                'xl/worksheets/sheet1.xml',
                'xl/workbook.xml',
                '[Content_Types].xml',
                '_rels/.rels',
            ], true)) {
                continue;
            }

            $this->assertSame(
                $original[$part],
                $personalized[$part] ?? null,
                "Package part {$part} was altered by personalization."
            );
        }

        // The worksheet keeps its merges and formulas: the form the staff
        // member fills in is the real one.
        $sheet = $personalized['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('<mergeCells', $sheet);
        $this->assertSame(
            substr_count($original['xl/worksheets/sheet1.xml'], '<f'),
            substr_count($sheet, '<f'),
            'Personalization must not disturb the template\'s formulas.'
        );
    }

    public function test_the_downloaded_template_carries_the_accounts_own_identity(): void
    {
        $district = District::create(['name' => 'District of San Juan']);
        $school = School::create(['name' => 'ABC Elementary School', 'district_id' => $district->id]);

        $staff = User::create([
            'name' => 'Juan Dela Cruz',
            'position' => 'Teacher III',
            'username' => 'jdelacruz',
            'email' => 'jdelacruz@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);

        $this->travelTo('2026-09-29 12:00:00');

        $response = $this->actingAs($staff)->get(route('opcrf.template'));
        $response->assertOk();
        $response->assertDownload('OPCRF_Juan_Dela_Cruz.xlsx');

        $book = new OpcrfSpreadsheet($this->stageWorkbook((string) $response->baseResponse->getContent()));
        $header = $book->analyze();

        // Read back out of the workbook with the app's own analyzer: the
        // name is the account's registered name, not anything the file
        // supplied.
        $this->assertSame('Juan Dela Cruz', $header['employee_name']);
        $this->assertSame('Teacher III', $header['position']);
        $this->assertSame('ABC Elementary School — District of San Juan', $header['division_office']);

        // The evaluator block on the right is left alone — it belongs to
        // the reviewer, not the employee.
        $this->assertStringNotContainsString(
            'Juan Dela Cruz',
            json_encode($book->evaluatorCells(), JSON_THROW_ON_ERROR)
        );
    }

    public function test_the_download_is_recorded_against_the_account(): void
    {
        $staff = $this->loginUser('staff');

        $this->travelTo('2026-09-29 12:00:00');

        $this->actingAs($staff)->get(route('opcrf.template'))->assertOk();
        $this->actingAs($staff)->get(route('opcrf.template'))->assertOk();

        $this->assertSame(2, OpcrfTemplate::where('user_id', $staff->id)->count());
        $this->assertSame('OPCRF_Staff.xlsx', OpcrfTemplate::latestFor($staff)?->filename);
        $this->assertSame((string) config('opcrf.template.version'), OpcrfTemplate::latestFor($staff)?->version);
        // The stamp travels inside the workbook, invisible in the form.
        $stamp = OpcrfTemplatePersonalizer::readStamp($this->latestDownloadPathFor($staff));

        $this->assertNotNull($stamp, 'The personalized workbook must carry its account stamp.');
        $this->assertSame((string) $staff->id, $stamp['user_id']);
        $this->assertSame((string) config('opcrf.template.version'), $stamp['version']);
    }

    public function test_superadmins_cannot_download_a_personalized_template(): void
    {
        // A superadmin has no OPCRF of their own; their downloads serve a
        // submission's archived workbook instead.
        $admin = $this->loginUser('chief', true);

        $this->actingAs($admin)->get(route('opcrf.template'))->assertNotFound();
    }

    public function test_outside_the_final_term_users_download_the_part_one_only_template(): void
    {
        $staff = $this->loginUser('staff');

        $this->travelTo('2026-09-29 12:00:00');

        $response = $this->actingAs($staff)->get(route('opcrf.template'));

        $response->assertOk();
        $response->assertDownload('OPCRF_Staff.xlsx');

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

    /**
     * Write raw workbook bytes to a temp file the reader can open.
     */
    private function stageWorkbook(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'opcrf-dl-').'.xlsx';
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * The bytes the account most recently downloaded, regenerated the same
     * way the route does and staged where the reader can open them.
     */
    private function latestDownloadPathFor(User $user): string
    {
        return $this->stageWorkbook(OpcrfTemplatePersonalizer::personalizeFor($user));
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
            // The whole card is locked: no download, no upload. The checks
            // are scoped to the OPCRF card's own download (file name and
            // route): a page-wide "no Download" check predates the WFP card,
            // which legitimately shows its own Download button for School
            // Heads — this staff account is one.
            ->assertSee('OPCRF submitted — locked')
            ->assertSee('Locked')
            ->assertDontSee('OPCRF-TEMPLATE.xlsx')
            ->assertDontSee(route('opcrf.template'), false)
            ->assertDontSee('Click to choose your OPCRF file');
    }

    public function test_a_submission_locks_the_card_even_without_movs(): void
    {
        $staff = $this->loginUser('staff');
        $this->createSubmission($staff, withMovs: false);

        // MOVs no longer gate the cycle: the confirmed OPCRF alone locks
        // the card, MOVs attached or not.
        $this->travelTo('2026-04-01 10:00:00');

        $this->actingAs($staff)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('OPCRF submitted — locked')
            ->assertDontSee('Click to choose your OPCRF file');
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

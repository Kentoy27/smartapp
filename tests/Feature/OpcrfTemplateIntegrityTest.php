<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OpcrfPartOne;
use App\Support\OpcrfTemplatePersonalizer;
use App\Support\PurePhpZipWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

/**
 * The generated template has to be a file Excel will actually open.
 *
 * These tests work on the REAL workbook in storage/forms, not the small
 * synthetic ones the rest of the suite builds. That distinction is the whole
 * point: the synthetic workbooks never carried a <definedNames> block, so
 * writing the account stamp into one looked fine everywhere else while
 * producing a file Excel refused to open.
 *
 * The stamp used to be a workbook defined name holding a bare literal
 * ("OPCRF:56:2026.1"). A defined name must resolve to a cell reference or a
 * formula, so Excel rejected the download with "We found a problem with some
 * content". It now lives in docProps/custom.xml, which is what custom
 * document properties exist for.
 *
 * The old defined name is still READ, because workbooks already handed to
 * staff carry it and refusing those would be a regression of its own.
 */
class OpcrfTemplateIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name = 'Juan Dela Cruz'): User
    {
        return User::create([
            'name' => $name,
            'username' => 'jdelacruz',
            'email' => 'jdelacruz@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);
    }

    private function stage(string $bytes, string $prefix = 'opcrf-'): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix).'.xlsx';
        file_put_contents($path, $bytes);

        return $path;
    }

    /** @return array<string, string> */
    private function parts(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);

        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $parts[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }

        $zip->close();

        return $parts;
    }

    /** The real template with the old invalid defined-name stamp put back. */
    private function legacyTemplate(string $userId): string
    {
        $path = $this->stage(OpcrfPartOne::templateBytes(), 'opcrf-legacy-');
        $parts = $this->parts($path);
        $version = (string) config('opcrf.template.version', '2026.1');

        $parts['xl/workbook.xml'] = str_replace(
            '</definedNames>',
            '<definedName name="_OPCRF_TEMPLATE">OPCRF:'.$userId.':'.$version.'</definedName></definedNames>',
            $parts['xl/workbook.xml']
        );

        file_put_contents($path, PurePhpZipWriter::create($parts));

        return $path;
    }

    public function test_the_generated_template_carries_no_defined_name_stamp(): void
    {
        $user = $this->user();
        $path = $this->stage(OpcrfTemplatePersonalizer::personalizeFor($user));

        $workbook = $this->parts($path)['xl/workbook.xml'];

        $this->assertStringNotContainsString(
            '_OPCRF_TEMPLATE',
            $workbook,
            'The stamp must not be a defined name — Excel rejects a defined name whose value is a literal.'
        );

        // The workbook's own legitimate defined name must survive untouched.
        $this->assertStringContainsString('_xlnm.Print_Area', $workbook);
    }

    public function test_the_stamp_is_a_custom_document_property_and_reads_back(): void
    {
        $user = $this->user();
        $path = $this->stage(OpcrfTemplatePersonalizer::personalizeFor($user));
        $parts = $this->parts($path);

        $this->assertArrayHasKey(
            'docProps/custom.xml',
            $parts,
            'The stamp lives in docProps/custom.xml, which has to be a declared part.'
        );
        $this->assertStringContainsString('OPCRF:'.$user->id.':', $parts['docProps/custom.xml']);

        // A part Excel cannot resolve is a part Excel complains about.
        $this->assertStringContainsString(
            '/docProps/custom.xml',
            $parts['[Content_Types].xml'],
            'custom.xml needs its content-type override.'
        );
        $this->assertStringContainsString(
            'docProps/custom.xml',
            $parts['_rels/.rels'],
            'custom.xml needs a package relationship.'
        );

        $this->assertSame(
            ['user_id' => (string) $user->id, 'version' => (string) config('opcrf.template.version', '2026.1')],
            OpcrfTemplatePersonalizer::readStamp($path)
        );
    }

    public function test_a_workbook_stamped_the_old_way_is_still_accepted(): void
    {
        $user = $this->user();

        $this->assertSame(
            ['user_id' => (string) $user->id, 'version' => (string) config('opcrf.template.version', '2026.1')],
            OpcrfTemplatePersonalizer::readStamp($this->legacyTemplate((string) $user->id)),
            'A workbook already downloaded must still be recognised.'
        );
    }

    public function test_repersonalizing_repairs_a_legacy_defined_name(): void
    {
        $user = $this->user();
        $legacy = $this->legacyTemplate((string) $user->id);

        $this->assertStringContainsString(
            '_OPCRF_TEMPLATE',
            $this->parts($legacy)['xl/workbook.xml'],
            'The fixture really does carry the old stamp.'
        );

        $repaired = $this->stage(OpcrfTemplatePersonalizer::personalize(
            (string) file_get_contents($legacy),
            OpcrfTemplatePersonalizer::identityFor($user),
            $user->id,
            (string) config('opcrf.template.version', '2026.1')
        ));

        $this->assertStringNotContainsString(
            '_OPCRF_TEMPLATE',
            $this->parts($repaired)['xl/workbook.xml'],
            'Re-downloading repairs the file rather than leaving the invalid name behind.'
        );
    }

    public function test_an_account_with_no_name_is_flagged_as_an_account_problem_not_a_bad_file(): void
    {
        $nameless = User::create([
            'name' => null,
            'username' => 'anon',
            'email' => 'anon@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);

        $path = $this->stage(OpcrfTemplatePersonalizer::personalizeFor($nameless));
        $check = OpcrfTemplatePersonalizer::verify($path, $nameless);

        $this->assertSame(
            OpcrfTemplatePersonalizer::ACCOUNT_UNNAMED,
            $check['status'],
            'The blame is the account, not the file.'
        );
        $this->assertStringContainsString('account has no name', $check['message']);
        $this->assertArrayNotHasKey(
            'blocking',
            $check,
            'Nothing in the check can refuse an upload any more — the result is context only.'
        );
    }

    /**
     * The invariant behind that: verify() reports, it never refuses.
     *
     * Every signal it can raise — a foreign template stamp, a name that
     * belongs to somebody else, a file with no readable name at all — comes
     * back as a status and a message, and nothing more. There is no return
     * shape in which a caller is told to stop.
     */
    public function test_the_upload_check_has_no_way_to_refuse(): void
    {
        $user = $this->user();

        $cases = [
            'a matching name' => $this->stage(OpcrfTemplatePersonalizer::personalizeFor($user)),
            'a foreign stamp' => $this->stage(
                OpcrfTemplatePersonalizer::personalizeFor(User::create([
                    'name' => 'Maria Santos',
                    'username' => 'msantos',
                    'email' => 'msantos@example.com',
                    'password' => Hash::make('password123'),
                    'is_superadmin' => false,
                ]))
            ),
        ];

        foreach ($cases as $label => $path) {
            $check = OpcrfTemplatePersonalizer::verify($path, $user);

            $this->assertEqualsCanonicalizing(
                ['status', 'message', 'name', 'expected', 'version'],
                array_keys($check),
                "{$label}: the result carries information and nothing that could gate on."
            );
            $this->assertNotSame('', $check['message'], "{$label}: every outcome is explained.");
        }
    }

    /**
     * Excel refuses a workbook whose zip entries carry an impossible
     * MS-DOS date. The writer used to stamp every part "month 0, day 0",
     * which Excel reports as a corrupt file and offers to repair — silently
     * dropping parts on the way in. The shipped template stamps
     * 1980-01-01 for exactly this reason, and so must we.
     */
    public function test_every_part_is_stamped_with_a_real_ms_dos_date(): void
    {
        $user = $this->user();
        $bytes = OpcrfTemplatePersonalizer::personalizeFor($user);
        $path = $this->stage($bytes);

        $offset = 0;
        $entries = 0;

        while ($offset < strlen($bytes) && substr($bytes, $offset, 4) === "PK\x03\x04") {
            // Local file header: 4-byte signature, then version(2) flags(2)
            // method(2) time(2) date(2) crc(4) csize(4) usize(4)
            // namelen(2) extralen(2) name… payload.
            $header = unpack(
                'vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnamelen/vextralen',
                substr($bytes, $offset + 4, 26)
            );

            $name = substr($bytes, $offset + 30, $header['namelen']);
            $month = ($header['date'] >> 5) & 0xF;
            $day = $header['date'] & 0x1F;
            $year = ($header['date'] >> 9) + 1980;

            $this->assertGreaterThanOrEqual(1, $month, "{$name} is stamped with month 0.");
            $this->assertLessThanOrEqual(12, $month, "{$name} is stamped with an impossible month.");
            $this->assertGreaterThanOrEqual(1, $day, "{$name} is stamped with day 0.");
            $this->assertLessThanOrEqual(31, $day, "{$name} is stamped with an impossible day.");
            $this->assertGreaterThanOrEqual(1980, $year, "{$name} predates the MS-DOS epoch.");

            $offset += 30 + $header['namelen'] + $header['extralen'] + $header['compressed'];
            $entries++;
        }

        $this->assertSame(21, $entries, 'Every package part is walked by this test.');

        // The same assertion from the file's own central directory, so the
        // check covers what a reader sees and not just what we wrote first.
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame($entries, $zip->numFiles);
        $zip->close();
    }

    public function test_the_generated_workbook_is_a_package_with_nothing_dangling(): void
    {
        $user = $this->user();
        $path = $this->stage(OpcrfTemplatePersonalizer::personalizeFor($user));

        // CHECKCONS is the strict structural check.
        $zip = new ZipArchive;
        $this->assertTrue(
            $zip->open($path, ZipArchive::CHECKCONS),
            'The generated workbook must satisfy the strict zip consistency check.'
        );

        // Every declared override must point at a part that exists.
        preg_match_all('/PartName="\/([^"]+)"/', (string) $zip->getFromName('[Content_Types].xml'), $declared);

        foreach ($declared[1] as $part) {
            $this->assertNotFalse(
                $zip->locateName($part),
                "[Content_Types].xml declares /{$part}, which is not in the package."
            );
        }

        // …and every relationship target must resolve too.
        preg_match_all('/Target="([^"]+)"/', (string) $zip->getFromName('_rels/.rels'), $targets);

        foreach ($targets[1] as $target) {
            if (str_starts_with($target, 'http')) {
                continue;
            }

            $this->assertNotFalse(
                $zip->locateName($target),
                "_rels/.rels points at {$target}, which is not in the package."
            );
        }

        $zip->close();
    }
}

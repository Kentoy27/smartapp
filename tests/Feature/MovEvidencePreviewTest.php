<?php

namespace Tests\Feature;

use App\Models\MovCategory;
use App\Models\MovPart;
use App\Models\MovRequirement;
use App\Models\User;
use App\Models\UserMov;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use ZipArchive;

class MovEvidencePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_superadmin_can_preview_spreadsheet_and_word_evidence_in_browser(): void
    {
        $admin = $this->user('admin', true);
        $owner = $this->user('owner');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Budget Plan');
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Approved budget');
        $spreadsheet->getActiveSheet()->setCellValue('B1', '125000');
        $xlsxPath = tempnam(sys_get_temp_dir(), 'mov-preview-').'.xlsx';
        (new Xlsx($spreadsheet))->save($xlsxPath);
        $spreadsheet->disconnectWorksheets();
        $xlsx = $this->mov($owner, 'plan.xlsx', (string) file_get_contents($xlsxPath));
        unlink($xlsxPath);

        $docxPath = tempnam(sys_get_temp_dir(), 'mov-preview-').'.docx';
        $docx = new ZipArchive;
        $this->assertTrue($docx->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $docx->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Signed WFP agreement</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Plan year 2027</w:t></w:r></w:p></w:tc></w:tr></w:tbl><w:sectPr/></w:body></w:document>'
        );
        $docx->close();
        $word = $this->mov($owner, 'agreement.docx', (string) file_get_contents($docxPath));
        unlink($docxPath);

        $this->actingAs($admin)
            ->get(route('mov.preview', $xlsx))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('Budget Plan')
            ->assertSee('Approved budget')
            ->assertSee('125000');

        $this->actingAs($admin)
            ->get(route('mov.preview', $word))
            ->assertOk()
            ->assertSee('Signed WFP agreement')
            ->assertSee('Plan year 2027');
    }

    public function test_pdf_is_previewed_inline_and_files_remain_private(): void
    {
        $admin = $this->user('admin', true);
        $owner = $this->user('owner');
        $otherUser = $this->user('other');
        $pdf = $this->mov($owner, 'approved-plan.pdf', "%PDF-1.4\nPreview PDF content");

        $this->actingAs($admin)
            ->get(route('mov.preview', $pdf))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename=approved-plan.pdf')
            ->assertStreamedContent("%PDF-1.4\nPreview PDF content");

        $this->actingAs($otherUser)
            ->get(route('mov.preview', $pdf))
            ->assertForbidden();
    }

    private function user(string $username, bool $superadmin = false): User
    {
        return User::create([
            'name' => ucfirst($username),
            'username' => $username,
            'email' => $username.'@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => $superadmin,
        ]);
    }

    private function mov(User $owner, string $filename, string $bytes): UserMov
    {
        $part = MovPart::firstOrCreate(['name' => 'PART 1'], ['part_order' => 1]);
        $category = MovCategory::firstOrCreate(
            ['mov_part_id' => $part->id, 'name' => 'Preview'],
            ['category_order' => 1]
        );
        $requirement = MovRequirement::firstOrCreate(
            ['mov_category_id' => $category->id, 'mov_number' => 98],
            ['title' => 'Preview evidence', 'description' => 'Evidence used for preview tests.']
        );
        $path = 'mov-uploads/'.$owner->id.'/'.$filename;
        Storage::disk('local')->put($path, $bytes);

        return UserMov::create([
            'user_id' => $owner->id,
            'mov_requirement_id' => $requirement->id,
            'original_name' => $filename,
            'stored_path' => $path,
            'mime_type' => match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
                'pdf' => 'application/pdf',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                default => 'application/octet-stream',
            },
            'size_bytes' => strlen($bytes),
            'status' => UserMov::STATUS_UPLOADED,
            'uploaded_at' => now(),
        ]);
    }
}

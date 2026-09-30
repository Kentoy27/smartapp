<?php

namespace Tests\Unit\Support;

use App\Support\OpcrfWorkbookTrim;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Concerns\BuildsOpcrfWorkbooks;
use ZipArchive;

class OpcrfWorkbookTrimTest extends TestCase
{
    use BuildsOpcrfWorkbooks;

    /** The tab name the shipped template's first part carries. */
    private const PART_I = 'PART I (CY 2025 & SY2025-2026)';

    public function test_it_keeps_only_the_requested_part_tab(): void
    {
        $trimmed = OpcrfWorkbookTrim::keepPart($this->buildMultiPartOpcrf(), 'PART I');

        $this->assertSame([self::PART_I], $this->sheetNamesOfWorkbook($trimmed));
    }

    public function test_the_trimmed_archive_is_a_valid_zip(): void
    {
        $bytes = OpcrfWorkbookTrim::keepPart($this->buildMultiPartOpcrf(), 'PART I');
        $path = tempnam(sys_get_temp_dir(), 'opcrf-trim-').'.xlsx';
        file_put_contents($path, $bytes);

        $zip = new ZipArchive();

        $this->assertTrue($zip->open($path) === true, 'The rebuilt workbook must be a valid zip archive.');
        $this->assertNotSame(false, $zip->getFromName('xl/workbook.xml'));
        $zip->close();
    }

    public function test_dropped_sheets_disappear_from_the_package(): void
    {
        $bytes = OpcrfWorkbookTrim::keepPart($this->buildMultiPartOpcrf(), 'PART I');
        $path = tempnam(sys_get_temp_dir(), 'opcrf-trim-').'.xlsx';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $zip->open($path);

        // The removed tabs and their per-sheet parts are gone…
        $this->assertFalse($zip->locateName('xl/worksheets/sheet2.xml') !== false);
        $this->assertFalse($zip->locateName('xl/worksheets/sheet3.xml') !== false);
        $this->assertFalse($zip->locateName('xl/worksheets/sheet4.xml') !== false);

        // …with nothing left referencing them: rels, content types, titles.
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $this->assertStringNotContainsString('sheet2.xml', $rels);
        $this->assertStringNotContainsString('sheet3.xml', $rels);
        $this->assertStringNotContainsString('sheet4.xml', $rels);

        $contentTypes = $zip->getFromName('[Content_Types].xml');
        $this->assertStringNotContainsString('/xl/worksheets/sheet2.xml', $contentTypes);
        $this->assertStringNotContainsString('/xl/worksheets/sheet3.xml', $contentTypes);
        $this->assertStringNotContainsString('/xl/worksheets/sheet4.xml', $contentTypes);

        $zip->close();
    }

    public function test_only_the_kept_sheets_printer_settings_survive(): void
    {
        $bytes = OpcrfWorkbookTrim::keepPart($this->buildMultiPartOpcrf(), 'PART I');
        $path = tempnam(sys_get_temp_dir(), 'opcrf-trim-').'.xlsx';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $zip->open($path);

        $this->assertNotFalse($zip->locateName('xl/printerSettings/printerSettings1.bin') !== false, 'Part I keeps its printer settings.');
        $this->assertFalse($zip->locateName('xl/printerSettings/printerSettings2.bin') !== false, 'Part II does not.');
        $this->assertFalse($zip->locateName('xl/printerSettings/printerSettings3.bin') !== false, 'Part III does not.');
        $this->assertFalse($zip->locateName('xl/printerSettings/printerSettings4.bin') !== false, 'Part IV does not.');
        $zip->close();
    }

    public function test_the_calc_chain_is_dropped(): void
    {
        // It indexes formula cells by sheet position, so it would describe
        // sheets that no longer exist; Excel rebuilds it on open anyway.
        $bytes = OpcrfWorkbookTrim::keepPart($this->buildMultiPartOpcrf(), 'PART I');
        $path = tempnam(sys_get_temp_dir(), 'opcrf-trim-').'.xlsx';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $zip->open($path);

        $this->assertFalse($zip->locateName('xl/calcChain.xml') !== false);

        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $this->assertStringNotContainsString('calcChain', $rels);
        $zip->close();
    }

    public function test_the_print_area_stays_scoped_to_the_surviving_sheet(): void
    {
        // The name is scoped to sheet position 0 (Part I), which survives —
        // it must remain present and re-scoped to the new position of its
        // sheet (still 0).
        $bytes = OpcrfWorkbookTrim::keepPart($this->buildMultiPartOpcrf(), 'PART I');
        $path = tempnam(sys_get_temp_dir(), 'opcrf-trim-').'.xlsx';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $zip->open($path);
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        $this->assertStringContainsString('localSheetId="0"', $workbook);
        $this->assertStringContainsString('PART I (CY 2025', $workbook);
    }

    public function test_a_defined_name_scoped_to_a_dropped_sheet_is_removed(): void
    {
        // Keeping PART IV instead: the print area is scoped to position 0
        // (Part I), which is dropped — the name must go away with its sheet,
        // never silently re-scope onto the surviving one.
        $bytes = OpcrfWorkbookTrim::keepPart($this->buildMultiPartOpcrf(), 'PART IV');

        $workbook = $this->workbookXmlOf($bytes);

        $this->assertSame(['PART IV'], $this->sheetNamesOfWorkbook($bytes));
        // The name itself is gone (the empty <definedNames> container is
        // valid and stays).
        $this->assertStringNotContainsString('_xlnm.Print_Area', $workbook, 'A name scoped to the dropped sheet goes away.');
    }

    public function test_docprops_titles_match_the_rebuilt_workbook(): void
    {
        $bytes = OpcrfWorkbookTrim::keepPart($this->buildMultiPartOpcrf(), 'PART I');
        $path = tempnam(sys_get_temp_dir(), 'opcrf-trim-').'.xlsx';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $zip->open($path);
        $appXml = $zip->getFromName('docProps/app.xml');
        $zip->close();

        // The surviving tab and its named range stay; the dropped ones go.
        // (Titles live inside XML, so the needle is compared in escaped form.)
        $this->assertStringContainsString(htmlspecialchars(self::PART_I, ENT_XML1), $appXml);
        $this->assertStringContainsString('!Print_Area', $appXml);
        $this->assertStringNotContainsString('PART II', $appXml);
        $this->assertStringNotContainsString('PART III', $appXml);
        $this->assertStringNotContainsString('PART IV', $appXml);

        // Counts: 1 worksheet + 1 named range → 2 titles, and the
        // HeadingPairs counts follow.
        $this->assertSame(1, preg_match('/<TitlesOfParts><vt:vector size="2"/', $appXml));
        $this->assertSame(1, preg_match('/<vt:lpstr>Worksheets<\/vt:lpstr><\/vt:variant><vt:variant><vt:i4>1<\/vt:i4>/', $appXml));
    }

    public function test_a_workbook_without_the_requested_tab_is_returned_unchanged(): void
    {
        // Single-sheet fixtures and older forms have no part tabs — the
        // trimmer must leave them exactly as it found them.
        $original = $this->buildFilledOpcrf();
        $originalBytes = file_get_contents($original);

        $bytes = OpcrfWorkbookTrim::keepPart($original, 'PART I');

        $this->assertSame($originalBytes, $bytes);
        $this->assertSame(['OPCRF'], $this->sheetNamesOfWorkbook($bytes));
    }

    public function test_the_real_shipped_template_trims_to_part_i(): void
    {
        // Unit tests run without the app container, so storage_path() is
        // unavailable — the repo-relative path is used instead.
        $template = dirname(__DIR__, 3).'/storage/forms/OPCRF-TEMPLATE.xlsx';

        if (! is_file($template)) {
            $this->markTestSkipped('The shipped OPCRF-TEMPLATE.xlsx is not present.');
        }

        $bytes = OpcrfWorkbookTrim::keepPart($template, 'PART I');

        $this->assertSame([self::PART_I], $this->sheetNamesOfWorkbook($bytes));

        // The app's own analyzer must read the rebuilt workbook — the Part I
        // tab's three sub-parts and signer block all still parse.
        $path = tempnam(sys_get_temp_dir(), 'opcrf-real-').'.xlsx';
        file_put_contents($path, $bytes);
        $sheet = new \App\Support\OpcrfSpreadsheet($path);

        $this->assertSame('OFFICE PERFORMANCE COMMITMENT AND REVIEW FORM (OPCRF) ver.Feb2025', $sheet->title());

        $this->assertSame(['I-A', 'I-B', 'I-C'], array_column($sheet->parts(), 'key'));
        $this->assertCount(3, $sheet->signers());
    }

    public function test_a_non_workbook_file_cannot_be_trimmed(): void
    {
        $garbage = tempnam(sys_get_temp_dir(), 'opcrf-garbage-').'.xlsx';
        file_put_contents($garbage, 'definitely not a zip archive');

        $this->expectException(RuntimeException::class);

        OpcrfWorkbookTrim::keepPart($garbage, 'PART I');
    }

    public function test_an_unreadable_file_throws(): void
    {
        $this->expectException(RuntimeException::class);

        OpcrfWorkbookTrim::keepPart(__DIR__.'/does-not-exist.xlsx', 'PART I');
    }

    private function workbookXmlOf(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'opcrf-wb-').'.xlsx';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $zip->open($path);
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        return $workbook;
    }
}

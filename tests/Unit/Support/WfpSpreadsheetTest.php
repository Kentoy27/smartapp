<?php

namespace Tests\Unit\Support;

use App\Support\WfpSpreadsheet;
use ZipArchive;
use Tests\TestCase;

class WfpSpreadsheetTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_analyzes_and_returns_all_workbook_worksheets(): void
    {
        $path = $this->buildWorkbook([
            'Overview' => [
                'A1' => 'Work and Financial Plan',
                'A3' => 'Objectives',
            ],
            'Activities' => [
                'A1' => 'Programs, Projects, & Activities',
                'B1' => 'Major Outputs',
                'A2' => 'Reading recovery',
                'B2' => 'Improved reading level',
            ],
            'Budget' => [
                'A1' => 'Financial Target',
                'A2' => 'CY 2026',
            ],
        ]);

        $spreadsheet = new WfpSpreadsheet($path);
        $spreadsheet->validateStructure();
        $review = $spreadsheet->reviewData();

        $this->assertCount(3, $review['sheet_data']);
        $this->assertSame(
            ['Overview', 'Activities', 'Budget'],
            array_column($review['sheet_data'], 'name'),
        );
        $this->assertSame('Reading recovery', $review['sheet_data'][1]['rows'][1]['cells'][1]);
        $this->assertSame(3, $review['analysis']['sheet_count']);
        $this->assertSame(6, $review['analysis']['row_count']);
        $this->assertTrue($review['validation']['passed']);
        $this->assertSame('CY 2026', $spreadsheet->detectSchoolYear());
    }

    /**
     * Build a minimal workbook whose declared sheet order differs from its
     * relationship IDs, ensuring sheet names are resolved from workbook.xml.
     *
     * @param  array<string, array<string, string>>  $sheets
     */
    private function buildWorkbook(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wfp-review-').'.xlsx';
        $this->temporaryFiles[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $sheetXml = [];
        $sheetTags = [];
        $relationshipTags = [];
        $contentOverrides = '';

        foreach ($sheets as $name => $cells) {
            $number = count($sheetXml) + 1;
            $rows = [];

            foreach ($cells as $reference => $value) {
                preg_match('/^([A-Z]+)(\d+)$/', $reference, $match);
                $rowNumber = (int) $match[2];
                $column = $match[1];
                $text = htmlspecialchars($value, ENT_XML1);
                $rows[$rowNumber][] = '<c r="'.$reference.'" t="inlineStr"><is><t>'.$text.'</t></is></c>';
            }

            ksort($rows);
            $rowsXml = '';

            foreach ($rows as $rowNumber => $rowCells) {
                $rowsXml .= '<row r="'.$rowNumber.'">'.implode('', $rowCells).'</row>';
            }

            $sheetXml[$name] = '<?xml version="1.0" encoding="UTF-8"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetData>'.$rowsXml.'</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/review'.$number.'.xml', $sheetXml[$name]);

            $relationshipId = 'rId'.$number;
            $sheetTags[] = '<sheet name="'.htmlspecialchars($name, ENT_XML1).'" sheetId="'.$number.'" r:id="'.$relationshipId.'"/>';
            $relationshipTags[] = '<Relationship Id="'.$relationshipId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/review'.$number.'.xml"/>';
            $contentOverrides .= '<Override PartName="/xl/worksheets/review'.$number.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        $workbook = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.implode('', $sheetTags).'</sheets></workbook>';
        $relationships = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', $relationshipTags).'</Relationships>';
        $contentTypes = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$contentOverrides.'</Types>';

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $relationships);
        $zip->close();

        return $path;
    }
}

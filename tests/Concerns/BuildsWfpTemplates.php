<?php

namespace Tests\Concerns;

use App\Support\PurePhpZipWriter;
use App\Support\WfpTemplateFiller;

/**
 * Builds synthetic WFP workbooks shaped like the official template: a title
 * row, a header row carrying the recognised column labels, a band of blank
 * styled data rows, a TOTAL row whose `SUM(...)` formulas span that band, a
 * `<dimension>` and a merge outside the data band.
 *
 * Built with the pure-PHP zip writer, so the fixtures need no zip extension —
 * the same writer the export uses.
 */
trait BuildsWfpTemplates
{
    /**
     * The header labels, laid out in columns B..M, matching config/wfp.php.
     *
     * @var array<string, string>
     */
    private array $wfpHeaderLabels = [
        'B' => 'Objectives',
        'C' => 'Programs, Projects, & Activities',
        'D' => 'Major Outputs',
        'E' => 'Performance Indicator',
        'F' => 'Physical Target',
        'G' => 'Unit',
        'H' => 'Financial Target',
        'I' => 'Account Title',
        'J' => 'Object Code',
        'K' => 'Source of Fund',
        'L' => 'Organizational Classification',
        'M' => 'MOVs',
    ];

    /**
     * @param  int  $dataRows  how many blank styled data rows the table has
     * @param  array<string, string>|null  $labels  header labels (null = the standard set; [] = none)
     * @return string the workbook bytes
     */
    private function wfpTemplateBytes(int $dataRows = 3, ?array $labels = null): string
    {
        $labels ??= $this->wfpHeaderLabels;
        $headerRow = 3;
        $firstData = $headerRow + 1;
        $totalRow = $firstData + $dataRows;
        $columns = array_keys($this->wfpHeaderLabels);
        $lastCol = end($columns);

        $rows = '';

        // Title row (merged A1:M1, outside the data band).
        $rows .= '<row r="1"><c r="A1" s="1" t="inlineStr"><is><t xml:space="preserve">WORK AND FINANCIAL PLAN</t></is></c></row>';
        $rows .= '<row r="2"/>';

        // Header row.
        $cells = '';
        foreach ($labels as $col => $label) {
            $cells .= '<c r="'.$col.$headerRow.'" s="2" t="inlineStr"><is><t xml:space="preserve">'
                .htmlspecialchars($label, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                .'</t></is></c>';
        }
        $rows .= '<row r="'.$headerRow.'">'.$cells.'</row>';

        // Blank styled data rows: a styled-but-empty cell in every column.
        for ($r = $firstData; $r < $totalRow; $r++) {
            $cells = '';
            foreach ($columns as $col) {
                $cells .= '<c r="'.$col.$r.'" s="5"/>';
            }
            $rows .= '<row r="'.$r.'">'.$cells.'</row>';
        }

        // TOTAL row: a label plus SUM formulas over the data band.
        $totalCells = '<c r="A'.$totalRow.'" s="1" t="inlineStr"><is><t xml:space="preserve">TOTAL</t></is></c>';
        $totalCells .= '<c r="F'.$totalRow.'" s="6"><f>SUM(F'.$firstData.':F'.($totalRow - 1).')</f><v>0</v></c>';
        $totalCells .= '<c r="H'.$totalRow.'" s="6"><f>SUM(H'.$firstData.':H'.($totalRow - 1).')</f><v>0</v></c>';
        $rows .= '<row r="'.$totalRow.'">'.$totalCells.'</row>';

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<dimension ref="A1:'.$lastCol.$totalRow.'"/>'
            .'<cols><col min="2" max="2" width="30" customWidth="1"/></cols>'
            .'<sheetData>'.$rows.'</sheetData>'
            .'<mergeCells count="1"><mergeCell ref="A1:M1"/></mergeCells>'
            .'</worksheet>';

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="WFP" sheetId="1" r:id="rId1"/></sheets>'
                .'</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'</Relationships>',
            'xl/worksheets/sheet1.xml' => $sheetXml,
        ];

        return PurePhpZipWriter::create($parts);
    }

    /**
     * The worksheet XML of a workbook given as bytes.
     */
    private function wfpSheetXml(string $bytes): string
    {
        $parts = WfpTemplateFiller::readParts($bytes);

        return $parts['xl/worksheets/sheet1.xml'] ?? '';
    }

    /**
     * A cell's text (inline string or cached numeric value), from sheet XML.
     */
    private function wfpCellText(string $sheetXml, string $ref): ?string
    {
        $pattern = '/<c\b(?=[^>]*\br="'.preg_quote($ref, '/').'")[^>]*\/>|<c\b(?=[^>]*\br="'.preg_quote($ref, '/').'")[^>]*>.*?<\/c>/s';

        if (preg_match($pattern, $sheetXml, $m) !== 1) {
            return null;
        }

        if (preg_match('/<is>\s*<t[^>]*>(.*?)<\/t>/s', $m[0], $t) === 1) {
            return html_entity_decode($t[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }

        if (preg_match('/<v>(.*?)<\/v>/s', $m[0], $v) === 1) {
            return $v[1];
        }

        return '';
    }

    /**
     * A cell's style id from sheet XML, or null when it carries none.
     */
    private function wfpCellStyle(string $sheetXml, string $ref): ?string
    {
        $pattern = '/<c\b(?=[^>]*\br="'.preg_quote($ref, '/').'")[^>]*\/>|<c\b(?=[^>]*\br="'.preg_quote($ref, '/').'")[^>]*>.*?<\/c>/s';

        if (preg_match($pattern, $sheetXml, $m) !== 1) {
            return null;
        }

        return preg_match('/\bs="(\d+)"/', $m[0], $s) === 1 ? $s[1] : null;
    }

    /**
     * The formula of a cell, or null when it has none.
     */
    private function wfpCellFormula(string $sheetXml, string $ref): ?string
    {
        $pattern = '/<c\b(?=[^>]*\br="'.preg_quote($ref, '/').'")[^>]*>.*?<\/c>/s';

        if (preg_match($pattern, $sheetXml, $m) !== 1) {
            return null;
        }

        return preg_match('/<f[^>]*>(.*?)<\/f>/s', $m[0], $f) === 1 ? $f[1] : null;
    }
}

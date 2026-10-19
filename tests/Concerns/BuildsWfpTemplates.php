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
     * A month-shaped workbook laid out like the official DepEd template: a
     * three-row merged header block (parent headings, then the financial band
     * with a paired Object Code column per month, then the physical band), a
     * blank styled data band, and a TOTAL row.
     *
     * Physical months live in C..E, financial months in G/I/K with their
     * Object Code columns in H/J/L, and SOURCE OF FUND in M.
     *
     * @return string the workbook bytes
     */
    private function wfpMonthlyTemplateBytes(int $dataRows = 3): string
    {
        $headerRow = 3;
        $financialSubRow = 4;
        $physicalSubRow = 5;
        $firstData = 6;
        $totalRow = $firstData + $dataRows;

        $monthNames = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar'];
        $financialCols = [1 => 'G', 2 => 'I', 3 => 'K'];
        $codeCols = [1 => 'H', 2 => 'J', 3 => 'L'];
        $physicalCols = [1 => 'C', 2 => 'D', 3 => 'E'];

        $cell = static function (string $ref, string $text, int $style = 2): string {
            return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'
                .htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                .'</t></is></c>';
        };

        $rows = '<row r="1">'.$cell('A1', 'WORK AND FINANCIAL PLAN', 1).'</row>';
        $rows .= '<row r="2"/>';

        // Parent headings.
        $rows .= '<row r="'.$headerRow.'">'
            .$cell('A'.$headerRow, 'Objectives')
            .$cell('B'.$headerRow, 'Programs, Projects, & Activities')
            .$cell('C'.$headerRow, 'Physical Target (2024)')
            .$cell('G'.$headerRow, 'Financial Target (2025)')
            .$cell('M'.$headerRow, 'SOURCE OF FUND')
            .'</row>';

        // Financial band: month, then its paired Object Code column.
        $financial = '';
        foreach ($monthNames as $number => $name) {
            $financial .= $cell($financialCols[$number].$financialSubRow, $name)
                .$cell($codeCols[$number].$financialSubRow, 'Object Code');
        }
        $rows .= '<row r="'.$financialSubRow.'">'.$financial.'</row>';

        // Physical band.
        $physical = '';
        foreach ($monthNames as $number => $name) {
            $physical .= $cell($physicalCols[$number].$physicalSubRow, $name);
        }
        $rows .= '<row r="'.$physicalSubRow.'">'.$physical.'</row>';

        // Blank styled data band.
        $bandCols = array_merge(array_values($physicalCols), array_values($financialCols), array_values($codeCols), ['A', 'B', 'M']);
        sort($bandCols);

        for ($r = $firstData; $r < $totalRow; $r++) {
            $cells = '';
            foreach ($bandCols as $col) {
                // The last template row carries a row-total formula, exactly
                // like the official template's per-line sums — the filler must
                // preserve it rather than blank or overwrite the cell.
                $cells .= ($r === $totalRow - 1 && $col === 'K')
                    ? '<c r="K'.$r.'" s="6"><f>SUM(K'.$firstData.':K'.($r - 1).')</f><v>0</v></c>'
                    : '<c r="'.$col.$r.'" s="5"/>';
            }
            $rows .= '<row r="'.$r.'">'.$cells.'</row>';
        }

        // TOTAL row: SUM formulas spanning both month bands.
        $totalCells = '<c r="A'.$totalRow.'" s="1" t="inlineStr"><is><t xml:space="preserve">TOTAL</t></is></c>';
        $totalCells .= '<c r="C'.$totalRow.'" s="6"><f>SUM(C'.$firstData.':C'.($totalRow - 1).')</f><v>0</v></c>';
        $totalCells .= '<c r="G'.$totalRow.'" s="6"><f>SUM(G'.$firstData.':G'.($totalRow - 1).')</f><v>0</v></c>';
        $rows .= '<row r="'.$totalRow.'">'.$totalCells.'</row>';

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<dimension ref="A1:M'.$totalRow.'"/>'
            .'<sheetData>'.$rows.'</sheetData>'
            .'<mergeCells count="3"><mergeCell ref="A1:M1"/><mergeCell ref="C'.$headerRow.':E'.$headerRow.'"/><mergeCell ref="G'.$headerRow.':L'.$headerRow.'"/></mergeCells>'
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
     * The shared strings of the last workbook read, so a cell that stores a
     * string by index (as Excel-written workbooks do) still reads as text.
     *
     * @var array<int, string>
     */
    private array $wfpSharedStrings = [];

    /**
     * The worksheet XML of a workbook given as bytes.
     */
    private function wfpSheetXml(string $bytes): string
    {
        $parts = WfpTemplateFiller::readParts($bytes);
        $this->wfpSharedStrings = $this->wfpParseSharedStrings($parts['xl/sharedStrings.xml'] ?? '');

        return $parts['xl/worksheets/sheet1.xml'] ?? '';
    }

    /**
     * @return array<int, string>
     */
    private function wfpParseSharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        $strings = [];
        preg_match_all('/<si>(.*?)<\/si>/s', $xml, $entries);

        foreach ($entries[1] as $entry) {
            preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $entry, $runs);
            $strings[] = html_entity_decode(implode('', $runs[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }

        return $strings;
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
            // A shared-string cell stores the index, not the text.
            return str_contains($m[0], 't="s"')
                ? ($this->wfpSharedStrings[(int) $v[1]] ?? $v[1])
                : $v[1];
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

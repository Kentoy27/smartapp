<?php

namespace Tests\Concerns;

use App\Support\PurePhpZipWriter;

/**
 * Builds a synthetic multi-sheet Monthly Expenditure Matrix workbook: one
 * worksheet per month ("JAN".."DEC"), each with a title row, a header row
 * carrying the recognised labels, grey section-banner rows (a solid
 * FFD9D9D9 fill) and unfilled data rows — plus the "uacs MOOE partial" tab.
 *
 * Built with the pure-PHP zip writer, so the fixture needs no zip extension,
 * and it exercises the fill-aware reader without committing a binary file.
 */
trait BuildsMemWorkbooks
{
    /**
     * The header labels, laid out in columns A..H.
     *
     * @var array<string, string>
     */
    private array $memHeaderLabels = [
        'A' => 'Objectives',
        'B' => 'Project/Activity/Expenditure',
        'C' => 'Kind',
        'D' => 'Quantity',
        'E' => 'Unit',
        'F' => 'Unit Cost',
        'G' => 'Account Title',
        'H' => 'Account Code',
    ];

    /**
     * The data fields, by their column letter.
     *
     * @var array<string, string>
     */
    private array $memColumns = [
        'A' => 'objective',
        'B' => 'activity_description',
        'C' => 'resource_kind',
        'D' => 'quantity',
        'E' => 'unit',
        'F' => 'unit_cost',
        'G' => 'account_title',
        'H' => 'account_code',
    ];

    /**
     * The same workbook with its header spread over TWO rows, exactly as the
     * School Head's own file lays it out: the parent labels on one row and the
     * "Resources Needed" sub-labels on the row beneath. This is the layout a
     * single-row header scan gets wrong.
     *
     * @var array<string, string>
     */
    private array $memSplitParentLabels = [
        'A' => 'Objectives',
        'B' => 'Project/Activity/Expenditure',
        'C' => 'Resources Needed',
        'G' => 'Total Estimated Cost',
        'H' => 'Account Title',
        'I' => 'Account Code',
    ];

    /**
     * The "Resources Needed" sub-labels of the two-row layout (row 4).
     *
     * @var array<string, string>
     */
    private array $memSplitSubLabels = [
        'C' => 'Kind',
        'D' => 'Quantity',
        'E' => 'Unit',
        'F' => 'Unit Cost',
    ];

    /**
     * The data fields of the two-row layout, by column letter. Note that the
     * parent labels push Account Title and Account Code one column right of
     * where the single-row layout keeps them.
     *
     * @var array<string, string>
     */
    private array $memSplitColumns = [
        'A' => 'objective',
        'B' => 'activity_description',
        'C' => 'resource_kind',
        'D' => 'quantity',
        'E' => 'unit',
        'F' => 'unit_cost',
        'G' => 'calculated_total',
        'H' => 'account_title',
        'I' => 'account_code',
    ];

    /**
     * Build a workbook from a map of sheet name => rows. Each row is either a
     * field => value map, or `['__section__' => 'Operations-Related Expenses']`
     * for a grey banner row.
     *
     * Pass `$splitHeader = true` for the two-row header layout of a real
     * workbook (see {@see $memSplitParentLabels}).
     *
     * @param  array<string, array<int, array<string, string>>>  $sheets
     */
    private function memWorkbookBytes(array $sheets, bool $splitHeader = false): string
    {
        $names = array_keys($sheets);
        $sheetParts = [];
        $overrides = '';
        $rels = '';
        $sheetTags = '';

        foreach ($names as $i => $name) {
            $index = $i + 1;
            $rid = 'rId'.$index;

            $sheetParts["xl/worksheets/sheet{$index}.xml"] = $this->memSheetXml($sheets[$name], $splitHeader);
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $rels .= '<Relationship Id="'.$rid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
            $sheetTags .= '<sheet name="'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="'.$index.'" r:id="'.$rid.'"/>';
        }

        $rels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .$overrides
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets>'.$sheetTags.'</sheets>'
                .'</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .$rels
                .'</Relationships>',
            'xl/styles.xml' => $this->memStylesXml(),
        ];

        $parts = array_merge($parts, $sheetParts);

        return PurePhpZipWriter::create($parts);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function memSheetXml(array $rows, bool $splitHeader = false): string
    {
        $xml = $this->memRow(1, ['A' => ['2026 MONTHLY EXPENDITURE MATRIX', 1]]);

        if ($splitHeader) {
            $xml .= $this->memRow(3, array_map(fn (string $label): array => [$label, 1], $this->memSplitParentLabels));
            $xml .= $this->memRow(4, array_map(fn (string $label): array => [$label, 1], $this->memSplitSubLabels));
        } else {
            $xml .= $this->memRow(3, array_map(fn (string $label): array => [$label, 1], $this->memHeaderLabels));
        }

        $headerRow = $splitHeader ? 4 : 3;
        $dataColumns = $splitHeader ? $this->memSplitColumns : $this->memColumns;
        $bannerColumns = $splitHeader ? ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] : array_keys($this->memHeaderLabels);

        $rowNumber = $headerRow;

        foreach ($rows as $row) {
            $rowNumber++;

            if (isset($row['__section__'])) {
                $cells = [];

                foreach ($bannerColumns as $col) {
                    $cells[$col] = ['', 2];
                }

                $cells['A'] = [(string) $row['__section__'], 2];
                $xml .= $this->memRow($rowNumber, $cells);

                continue;
            }

            $cells = [];

            foreach ($dataColumns as $col => $field) {
                if (array_key_exists($field, $row)) {
                    $cells[$col] = [(string) $row[$field], 0];
                }
            }

            $xml .= $this->memRow($rowNumber, $cells);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$xml.'</sheetData>'
            .'</worksheet>';
    }

    /**
     * @param  array<string, array{0: string, 1: int}>  $cells  col => [value, style]
     */
    private function memRow(int $rowNumber, array $cells): string
    {
        $xml = '';

        foreach ($cells as $col => [$value, $style]) {
            $ref = $col.$rowNumber;

            $xml .= $value === ''
                ? '<c r="'.$ref.'" s="'.$style.'"/>'
                : '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'
                    .htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                    .'</t></is></c>';
        }

        return '<row r="'.$rowNumber.'">'.$xml.'</row>';
    }

    /**
     * Two fonts and the grey solid fill the section banners use (style 2).
     */
    private function memStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2">'
            .'<font><sz val="10"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="10"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="3">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'</cellXfs>'
            .'</styleSheet>';
    }
}

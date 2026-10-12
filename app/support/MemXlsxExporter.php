<?php

namespace App\Support;

use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\User;

/**
 * The Monthly Expenditure Matrix as the printed DepEd form: one .xlsx sheet
 * with the school/division header, the lines grouped under Operations- and
 * Project-Related Expenses with their subtotals, the monthly TOTAL, and the
 * Prepared / Checked / Approved signature block.
 *
 * The workbook is BUILT FROM SCRATCH — a MEM has no archived file to patch
 * (unlike a WFP, which rewrites the official template). It is a minimal but
 * complete OOXML package assembled through PurePhpZipWriter, because the
 * `zip` PHP extension is disabled on XAMPP's web-server PHP: inline strings
 * (never a sharedStrings table), builtin number format 4 ("#,##0.00") for
 * money, and a handful of cell styles. Numbers are written as numbers, so
 * the subtotals are real values a reviewer can sum, not text.
 *
 * Every value in the file comes from the lines as they stand NOW (quantity ×
 * unit cost, recomputed here exactly as the editor and the WFP generator do),
 * never from a stored total that might predate the last edit.
 */
final class MemXlsxExporter
{
    /* Cell styles, as indexes into the cellXfs below. */
    private const S_TITLE = 1;      // bold 12pt, centred

    private const S_HEAD_LINE = 2;  // centred header line (Republic/Department…)

    private const S_HEAD_BOLD = 3;  // centred + bold (school name)

    private const S_PLAIN_LEFT = 4; // left, no border

    private const S_TH = 5;         // bordered, bold, centred, wrapped

    private const S_BANNER = 6;     // bordered, bold, gray fill (section name)

    private const S_TEXT = 7;       // bordered, wrapped, top-left

    private const S_NUM = 8;        // bordered, "#,##0.00", right

    private const S_UNIT = 9;       // bordered, centred (unit)

    private const S_SUB_LABEL = 10; // bordered, bold, right, gray fill

    private const S_SUB_VALUE = 11; // bordered, bold, money, gray fill

    private const S_TOT_LABEL = 12; // bordered, bold, right

    private const S_TOT_VALUE = 13; // bordered, bold, money

    private const S_LABEL = 14;     // bold, no border ("Prepared by:")

    private const S_PLAIN = 15;     // plain, no border

    /** Build the workbook's bytes. */
    public static function bytes(MonthlyExpenditureMatrix $matrix, ?User $exporter = null): string
    {
        $school = $matrix->school;
        $district = $school?->district;

        $merges = [];
        $rows = [];
        $row = 0;

        $addRow = function (string $cellsXml) use (&$rows, &$row): void {
            $row++;
            $rows[$row] = $cellsXml;
        };

        /* ------------------------------------------------------- header */

        // The DepEd letterhead. The division line falls back to a generic
        // one: the app stores the district, not the division office, and a
        // form with no division line reads worse than a generic one.
        $addRow(self::headerLine($row + 1, 'REPUBLIC OF THE PHILIPPINES', $merges));
        $addRow(self::headerLine($row + 1, 'DEPARTMENT OF EDUCATION', $merges));
        $addRow(self::headerLine($row + 1, $district?->name ?: 'Schools Division Office', $merges));
        $addRow(self::headerCell($row + 1, $school?->name ?: 'School', self::S_HEAD_BOLD, $merges));
        $addRow(self::headerCell($row + 1, $matrix->year.' MONTHLY EXPENDITURE MATRIX', self::S_TITLE, $merges));
        $addRow(self::headerCell(
            $row + 1,
            'MONTH: '.strtoupper((string) $matrix->periodLabel()),
            self::S_PLAIN_LEFT,
            $merges
        ));

        /* ------------------------------------------------- column headers */

        // Row 1 of the two-tier header: the merged tops. Each also spans the
        // sub-header row, so "Resources needed" sits over Kind/Quantity/Unit/
        // Unit Cost exactly as the printed form does.
        $headerRow = $row + 1;
        $subRow = $headerRow + 1;

        $addRow(
            self::cell($headerRow, 'A', self::S_TH, 'Objectives')
            .self::cell($headerRow, 'B', self::S_TH, 'Project/Activity/Expenditure')
            .self::cell($headerRow, 'C', self::S_TH, 'Resources needed')
            .self::cell($headerRow, 'G', self::S_TH, 'Total Estimated Cost')
            .self::cell($headerRow, 'H', self::S_TH, 'Account Title')
            .self::cell($headerRow, 'I', self::S_TH, 'Account Code')
        );

        $merges[] = 'A'.$headerRow.':A'.$subRow;
        $merges[] = 'B'.$headerRow.':B'.$subRow;
        $merges[] = 'C'.$headerRow.':F'.$headerRow;
        $merges[] = 'G'.$headerRow.':G'.$subRow;
        $merges[] = 'H'.$headerRow.':H'.$subRow;
        $merges[] = 'I'.$headerRow.':I'.$subRow;

        $addRow(
            self::cell($subRow, 'C', self::S_TH, 'Kind')
            .self::cell($subRow, 'D', self::S_TH, 'Quantity')
            .self::cell($subRow, 'E', self::S_TH, 'Unit')
            .self::cell($subRow, 'F', self::S_TH, 'Unit Cost')
        );

        /* ------------------------------------------------------- sections */

        $operationsTotal = 0.0;
        $projectTotal = 0.0;

        foreach ([
            MonthlyExpenditureMatrix::SECTION_OPERATIONS => 'Operations-Related Expenses',
            MonthlyExpenditureMatrix::SECTION_PROJECT => 'Project-Related Expenses',
        ] as $section => $banner) {
            $sectionTotal = 0.0;

            $addRow(self::cell($row + 1, 'A', self::S_BANNER, $banner));
            $merges[] = 'A'.$row.':I'.$row;

            $items = $matrix->items()
                ->where('section', $section)
                ->get()
                ->sortBy(fn (MonthlyExpenditureItem $item): int => (int) $item->position);

            foreach ($items as $item) {
                $quantity = $item->quantity !== null ? (float) $item->quantity : null;
                $unitCost = $item->unit_cost !== null ? (float) $item->unit_cost : null;
                $total = round(($quantity ?? 0.0) * ($unitCost ?? 0.0), 2);
                $sectionTotal += $total;

                $r = $row + 1;

                $addRow(
                    self::cell($r, 'A', self::S_TEXT, (string) ($item->objective ?? ''))
                    .self::cell($r, 'B', self::S_TEXT, (string) $item->activity_description)
                    .self::cell($r, 'C', self::S_TEXT, (string) ($item->resource_kind ?? ''))
                    .self::numericCell($r, 'D', $quantity)
                    .self::cell($r, 'E', self::S_UNIT, (string) ($item->unit ?? ''))
                    .self::numericCell($r, 'F', $unitCost)
                    .self::numericCell($r, 'G', $total)
                    .self::cell($r, 'H', self::S_TEXT, (string) ($item->account_title ?? ''))
                    .self::cell($r, 'I', self::S_TEXT, (string) ($item->account_code ?? ''))
                );
            }

            // A section with no lines still shows its banner and one empty
            // line, so the form reads as the month's matrix rather than as a
            // section that was forgotten.
            if ($items->isEmpty()) {
                $r = $row + 1;

                $addRow(
                    self::cell($r, 'A', self::S_TEXT, '')
                    .self::cell($r, 'B', self::S_TEXT, '')
                    .self::cell($r, 'C', self::S_TEXT, '')
                    .self::numericCell($r, 'D', null)
                    .self::cell($r, 'E', self::S_UNIT, '')
                    .self::numericCell($r, 'F', null)
                    .self::numericCell($r, 'G', null)
                    .self::cell($r, 'H', self::S_TEXT, '')
                    .self::cell($r, 'I', self::S_TEXT, '')
                );
            }

            $sectionTotal = round($sectionTotal, 2);
            $r = $row + 1;

            $addRow(
                self::cell($r, 'A', self::S_SUB_LABEL, 'Subtotal')
                .self::numericCell($r, 'G', $sectionTotal, self::S_SUB_VALUE)
                .self::blankCell($r, 'H', self::S_SUB_VALUE)
                .self::blankCell($r, 'I', self::S_SUB_VALUE)
            );
            $merges[] = 'A'.$r.':F'.$r;

            if ($section === MonthlyExpenditureMatrix::SECTION_OPERATIONS) {
                $operationsTotal = $sectionTotal;
            } else {
                $projectTotal = $sectionTotal;
            }
        }

        /* ---------------------------------------------------------- total */

        $r = $row + 1;
        $grandTotal = round($operationsTotal + $projectTotal, 2);

        $addRow(
            self::cell($r, 'A', self::S_TOT_LABEL, 'TOTAL')
            .self::numericCell($r, 'G', $grandTotal, self::S_TOT_VALUE)
            .self::blankCell($r, 'H', self::S_TOT_VALUE)
            .self::blankCell($r, 'I', self::S_TOT_VALUE)
        );
        $merges[] = 'A'.$r.':F'.$r;

        /* ---------------------------------------------------- signatures */

        $addRow('');
        $addRow('');
        $addRow('');

        $r = $row + 1;

        $addRow(
            self::cell($r, 'A', self::S_LABEL, 'Prepared by:')
            .self::cell($r, 'E', self::S_LABEL, 'Checked by the Completeness / Certifying')
            .self::cell($r, 'H', self::S_LABEL, 'Approved by:')
        );

        $r = $row + 1;

        $addRow(self::cell($r, 'E', self::S_PLAIN, 'Availability of Account Titles and Codes:'));

        $r = $row + 1;

        $addRow(
            self::cell($r, 'A', self::S_PLAIN, $exporter?->name ?? '')
            .self::cell($r, 'F', self::S_PLAIN, '_________________')
            .self::cell($r, 'G', self::S_PLAIN, 'Bookkeeper')
            .self::cell($r, 'H', self::S_PLAIN, '_________________')
        );

        $r = $row + 1;

        $addRow(
            self::cell($r, 'A', self::S_PLAIN, 'School Principal I')
            .self::cell($r, 'G', self::S_PLAIN, 'Budget Officer')
            .self::cell($r, 'H', self::S_PLAIN, 'Schools Division Superintendent')
        );

        /* ------------------------------------------------------- package */

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<cols>'
            .'<col min="1" max="1" width="32" customWidth="1"/>'
            .'<col min="2" max="2" width="38" customWidth="1"/>'
            .'<col min="3" max="3" width="15" customWidth="1"/>'
            .'<col min="4" max="4" width="10" customWidth="1"/>'
            .'<col min="5" max="5" width="9" customWidth="1"/>'
            .'<col min="6" max="6" width="12" customWidth="1"/>'
            .'<col min="7" max="7" width="17" customWidth="1"/>'
            .'<col min="8" max="8" width="26" customWidth="1"/>'
            .'<col min="9" max="9" width="16" customWidth="1"/>'
            .'</cols>'
            .'<sheetData>';

        foreach ($rows as $number => $cellsXml) {
            $sheetXml .= '<row r="'.$number.'">'.$cellsXml.'</row>';
        }

        $sheetXml .= '</sheetData>';

        if ($merges !== []) {
            $sheetXml .= '<mergeCells count="'.count($merges).'">';

            foreach ($merges as $merge) {
                $sheetXml .= '<mergeCell ref="'.$merge.'"/>';
            }

            $sheetXml .= '</mergeCells>';
        }

        // Landscape, fit to one page wide: the form is wider than it is tall,
        // and a printout that spills a lone Account Code column onto page two
        // is the sort of thing that gets retyped by hand.
        $sheetXml .= '<pageMargins left="0.5" right="0.5" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            .'<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            .'</worksheet>';

        return PurePhpZipWriter::create([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',

            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',

            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets>'
                .'<sheet name="MEM" sheetId="1" r:id="rId1"/>'
                .'</sheets>'
                .'</workbook>',

            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',

            'xl/styles.xml' => self::stylesXml(),

            'xl/worksheets/sheet1.xml' => $sheetXml,
        ]);
    }

    /** The download's file name: sortable by year and month. */
    public static function downloadName(MonthlyExpenditureMatrix $matrix): string
    {
        return sprintf(
            'monthly-expenditure-matrix-%d-%02d.xlsx',
            (int) $matrix->year,
            (int) $matrix->month
        );
    }

    /* ------------------------------------------------------- cell builders */

    /** A centred header line merged across the whole table. */
    private static function headerLine(int $rowNumber, string $value, array &$merges): string
    {
        $merges[] = 'A'.$rowNumber.':I'.$rowNumber;

        return self::cell($rowNumber, 'A', self::S_HEAD_LINE, $value);
    }

    /** A centred header cell merged across the whole table. */
    private static function headerCell(int $rowNumber, string $value, int $style, array &$merges): string
    {
        $merges[] = 'A'.$rowNumber.':I'.$rowNumber;

        return self::cell($rowNumber, 'A', $style, $value);
    }

    private static function cell(int $rowNumber, string $column, int $style, string $value): string
    {
        $ref = $column.$rowNumber;

        if ($value === '') {
            return self::blankCell($rowNumber, $column, $style);
        }

        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr">'
            .'<is><t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1).'</t></is>'
            .'</c>';
    }

    /** A money/quantity cell: a real number, formatted by its style. */
    private static function numericCell(int $rowNumber, string $column, ?float $value, int $style = self::S_NUM): string
    {
        if ($value === null) {
            return self::blankCell($rowNumber, $column, $style);
        }

        return '<c r="'.$column.$rowNumber.'" s="'.$style.'"><v>'.$value.'</v></c>';
    }

    /** A styled but empty cell — it still paints its border and fill. */
    private static function blankCell(int $rowNumber, string $column, int $style): string
    {
        return '<c r="'.$column.$rowNumber.'" s="'.$style.'"/>';
    }

    /* ------------------------------------------------------------- styles */

    /**
     * Three fonts, one gray fill, one thin border, and the cell styles the
     * form needs. Money uses builtin numFmtId 4 ("#,##0.00") — no custom
     * numFmts section, so the file opens the same in Excel and WPS.
     */
    private static function stylesXml(): string
    {
        $font = static fn (string $extra = ''): string => '<font>'.$extra.'<sz val="10"/><name val="Calibri"/></font>';

        $fonts = '<fonts count="3">'
            .$font()
            .$font('<b/>')
            .'<font><b/><sz val="12"/><name val="Calibri"/></font>'
            .'</fonts>';

        $fills = '<fills count="3">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>';

        $thin = '<color indexed="64"/>';

        $borders = '<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border>'
            .'<left style="thin">'.$thin.'</left>'
            .'<right style="thin">'.$thin.'</right>'
            .'<top style="thin">'.$thin.'</top>'
            .'<bottom style="thin">'.$thin.'</bottom>'
            .'<diagonal/>'
            .'</border>'
            .'</borders>';

        // The alignment is a CHILD element of <xf>, so it goes after the
        // attributes — never concatenated into the tag itself.
        $xf = static fn (array $attrs, string $alignment = ''): string => '<xf '.implode(' ', $attrs).' xfId="0">'.$alignment.'</xf>';

        $cellXfs = '<cellXfs count="16">'
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="0"']) // 0 default
            .$xf(['numFmtId="0"', 'fontId="2"', 'fillId="0"', 'borderId="0"', 'applyFont="1"', 'applyAlignment="1"'], '<alignment horizontal="center" vertical="center"/>') // 1 title
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="0"', 'applyAlignment="1"'], '<alignment horizontal="center" vertical="center"/>') // 2 head line
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="0"', 'borderId="0"', 'applyFont="1"', 'applyAlignment="1"'], '<alignment horizontal="center" vertical="center"/>') // 3 head bold
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="0"', 'applyAlignment="1"'], '<alignment horizontal="left" vertical="center"/>') // 4 plain left
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="0"', 'borderId="1"', 'applyFont="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="center" vertical="center" wrapText="1"/>') // 5 th
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="2"', 'borderId="1"', 'applyFont="1"', 'applyFill="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="left" vertical="center"/>') // 6 banner
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="left" vertical="top" wrapText="1"/>') // 7 text
            .$xf(['numFmtId="4"', 'fontId="0"', 'fillId="0"', 'borderId="1"', 'applyNumberFormat="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="right" vertical="center"/>') // 8 num
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="center" vertical="center"/>') // 9 unit
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="2"', 'borderId="1"', 'applyFont="1"', 'applyFill="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="right" vertical="center"/>') // 10 subtotal label
            .$xf(['numFmtId="4"', 'fontId="1"', 'fillId="2"', 'borderId="1"', 'applyNumberFormat="1"', 'applyFont="1"', 'applyFill="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="right" vertical="center"/>') // 11 subtotal value
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="0"', 'borderId="1"', 'applyFont="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="right" vertical="center"/>') // 12 total label
            .$xf(['numFmtId="4"', 'fontId="1"', 'fillId="0"', 'borderId="1"', 'applyNumberFormat="1"', 'applyFont="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="right" vertical="center"/>') // 13 total value
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="0"', 'borderId="0"', 'applyFont="1"', 'applyAlignment="1"'], '<alignment horizontal="left" vertical="center"/>') // 14 label
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="0"', 'applyAlignment="1"'], '<alignment horizontal="left" vertical="center"/>') // 15 plain
            .'</cellXfs>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .$fonts
            .$fills
            .$borders
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .$cellXfs
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}

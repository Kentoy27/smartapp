<?php

namespace App\Support;

use App\Models\School;
use App\Models\User;

/**
 * The annual MOOE allotment report as an .xlsx: the same form the page shows —
 * the DepEd letterhead, the MONTH / BUDGET column beside the year's lines
 * rolled up per account code with their shares, and the signature block.
 *
 * The workbook is BUILT FROM SCRATCH through PurePhpZipWriter, exactly as the
 * MEM exporter is, because the `zip` PHP extension is disabled on XAMPP's
 * web-server PHP: inline strings (no sharedStrings table), builtin number
 * format 4 ("#,##0.00") for money, one custom format for the percentages, and
 * a handful of cell styles. Amounts are written as real NUMBERS, so a reviewer
 * can sum them; the percentages are written as fractions under a "0.00%"
 * format, so they are real percentages too.
 *
 * The columns are AUTO-FIT. Every column is measured against what was actually
 * written in it — the widest title, the longest account code, "1,018,000.00" —
 * and its width is set from that, so nothing arrives truncated and nothing
 * needs the reader to drag a column edge. Each column carries a floor (a
 * column narrower than its heading reads as a rendering fault) and a ceiling
 * (one runaway Account Title must not push the form off the page); the Account
 * Title column wraps inside its width, as the printed form does.
 *
 * Every figure comes from WfpAllotment — the same source the page reads — so
 * the download is the report that was on screen.
 */
final class WfpAllotmentXlsxExporter
{
    /* Cell styles, as indexes into the cellXfs below. */
    private const S_HEAD_LINE = 1;   // centred header line (Republic/Department…)

    private const S_HEAD_BOLD = 2;   // centred + bold (school name)

    private const S_TITLE = 3;       // bold 12pt, centred

    private const S_TH = 4;          // bordered, bold, centred, wrapped

    private const S_LEFT = 5;        // bordered, left (month, account code)

    private const S_MONEY = 6;       // bordered, "#,##0.00", right

    private const S_MONEY_BOLD = 7;  // bordered, bold, money

    private const S_TEXT = 8;        // bordered, wrapped, top-left

    private const S_PCT = 9;         // bordered, "0.00%", right

    private const S_PCT_BOLD = 10;   // bordered, bold, percent

    private const S_TOTAL = 11;      // bordered, bold, right

    private const S_LABEL = 12;      // small, no border, wrapped (signatory label)

    private const S_LABEL_BOLD = 13; // bold, no border (prepared-by name)

    private const S_PLAIN = 14;      // small, no border (roles, signature lines)

    private const S_NUMBER = 15;     // bordered, centred (the No. column)

    /** The report's grid: months on the left, the year's lines on the right. */
    private const COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

    private const HEADER_ROW = 7;

    /** The gap between the two tables, in column widths. */
    private const SPACER_WIDTH = 2.6;

    /** Nothing narrower than this, nothing wider — per column. */
    private const MIN_WIDTHS = ['A' => 6.0, 'B' => 8.0, 'C' => self::SPACER_WIDTH, 'D' => 5.0, 'E' => 22.0, 'F' => 9.0, 'G' => 9.0, 'H' => 8.0];

    private const MAX_WIDTHS = ['A' => 40.0, 'B' => 20.0, 'C' => self::SPACER_WIDTH, 'D' => 10.0, 'E' => 46.0, 'F' => 20.0, 'G' => 20.0, 'H' => 14.0];

    /** Build the workbook's bytes. */
    public static function bytes(int $schoolId, int $year, ?User $preparedBy = null): string
    {
        $school = School::query()->with('district')->find($schoolId);
        $months = WfpAllotment::months($schoolId, $year);
        $lines = WfpAllotment::lineItems($schoolId, $year);
        $total = WfpAllotment::total($lines);

        /** @var array<int, array<string, array{style: int, kind: string, value: string|float|null}>> $cells */
        $cells = [];
        /** @var array<int, string> $merges */
        $merges = [];
        /** @var array<string, float> $measured */
        $measured = [];

        $text = function (int $row, string $column, int $style, string $value, bool $measure = true) use (&$cells, &$measured): void {
            $cells[$row][$column] = ['style' => $style, 'kind' => 'text', 'value' => $value];

            if ($measure) {
                $measured[$column] = max($measured[$column] ?? 0.0, (float) mb_strlen($value));
            }
        };

        $money = function (int $row, string $column, int $style, float $value, bool $measure = true) use (&$cells, &$measured): void {
            $cells[$row][$column] = ['style' => $style, 'kind' => 'number', 'value' => $value];

            if ($measure) {
                $measured[$column] = max($measured[$column] ?? 0.0, (float) mb_strlen(number_format($value, 2)));
            }
        };

        // The share is stored as the fraction the "0.00%" format multiplies
        // back up (5.21 → 0.0521), so the cell is a percentage Excel can use.
        $percent = function (int $row, string $column, int $style, float $share, bool $measure = true) use (&$cells, &$measured): void {
            $cells[$row][$column] = ['style' => $style, 'kind' => 'number', 'value' => round($share / 100, 6)];

            if ($measure) {
                $measured[$column] = max($measured[$column] ?? 0.0, (float) mb_strlen(number_format($share, 2).'%'));
            }
        };

        $blank = function (int $row, string $column, int $style) use (&$cells): void {
            $cells[$row][$column] = ['style' => $style, 'kind' => 'blank', 'value' => null];
        };

        // A merged line is measured against the whole width it spans, never
        // against one column: a full-width title would otherwise stretch the
        // first column to the width of the sheet.
        $merge = function (string $from, string $to, int $row) use (&$merges): void {
            $merges[] = $from.$row.':'.$to.$row;
        };

        /* ---------------------------------------------------------- header */

        // The DepEd letterhead. The app stores the district and the school, not
        // the region or the street address, so the division line falls back to
        // a generic one — a form with no division line reads worse than a
        // generic one.
        $row = 0;

        $text(++$row, 'A', self::S_HEAD_LINE, 'REPUBLIC OF THE PHILIPPINES', false);
        $merge('A', 'H', $row);

        $text(++$row, 'A', self::S_HEAD_LINE, 'DEPARTMENT OF EDUCATION', false);
        $merge('A', 'H', $row);

        $text(++$row, 'A', self::S_HEAD_LINE, $school?->district?->name ?: 'Schools Division Office', false);
        $merge('A', 'H', $row);

        $text(++$row, 'A', self::S_HEAD_BOLD, mb_strtoupper($school?->name ?: 'School'), false);
        $merge('A', 'H', $row);

        $text(++$row, 'A', self::S_TITLE, sprintf(
            '%d ANNUAL MOOE ALLOTMENT ALLOCATED PER LINE ITEM OR OBJECT OF EXPENDITURE',
            $year
        ), false);
        $merge('A', 'H', $row);

        /* ---------------------------------------------- the two table heads */

        $text(self::HEADER_ROW, 'A', self::S_TH, 'MONTH');
        $text(self::HEADER_ROW, 'B', self::S_TH, 'BUDGET');
        $text(self::HEADER_ROW, 'D', self::S_TH, 'No.');
        $text(self::HEADER_ROW, 'E', self::S_TH, 'Account Title');
        $text(self::HEADER_ROW, 'F', self::S_TH, 'Account Code');
        $text(self::HEADER_ROW, 'G', self::S_TH, 'Total Amount');
        $text(self::HEADER_ROW, 'H', self::S_TH, '%');

        /* --------------------------------------------------- the month column */

        $monthRow = self::HEADER_ROW;

        foreach ($months as $month) {
            $monthRow++;

            $text($monthRow, 'A', self::S_LEFT, mb_strtoupper($month['label']));
            $money($monthRow, 'B', self::S_MONEY, (float) $month['budget']);
        }

        $monthsTotalRow = ++$monthRow;
        $text($monthsTotalRow, 'A', self::S_TOTAL, 'TOTAL');
        $money($monthsTotalRow, 'B', self::S_MONEY_BOLD, $total);

        /* ---------------------------------------------------- the line items */

        $lineRow = self::HEADER_ROW;

        foreach ($lines as $index => $line) {
            $lineRow++;

            $text($lineRow, 'D', self::S_NUMBER, (string) ($index + 1));
            $text($lineRow, 'E', self::S_TEXT, $line['title']);
            $text($lineRow, 'F', self::S_LEFT, (string) ($line['code'] ?? ''));
            $money($lineRow, 'G', self::S_MONEY, (float) $line['total']);
            $percent($lineRow, 'H', self::S_PCT, (float) $line['percent']);
        }

        // A year with no lines yet still gets the form's own total row (0.00):
        // an empty report reads as a report of nothing, not as a broken file.
        $grandTotalRow = $lineRow + 1;

        $text($grandTotalRow, 'D', self::S_LEFT, 'Grand Total', false);
        $merge('D', 'E', $grandTotalRow);
        $blank($grandTotalRow, 'F', self::S_LEFT);
        $money($grandTotalRow, 'G', self::S_MONEY_BOLD, $total);
        $percent($grandTotalRow, 'H', self::S_PCT_BOLD, $total > 0.0 ? 100.0 : 0.0);

        /* -------------------------------------------------------- signatures */

        // Two clear rows under the longer of the two tables, so the form has
        // its signing space whether the month column or the line items run
        // longer.
        $signatureRow = max($monthsTotalRow, $grandTotalRow) + 2;

        $text($signatureRow, 'A', self::S_LABEL, 'Prepared by:', false);
        $text($signatureRow, 'E', self::S_LABEL, 'Checked as to the Correctness of Account Titles and Codes:', false);
        $text($signatureRow, 'G', self::S_LABEL, 'Certifying Availability of Allotment:', false);
        $text($signatureRow, 'H', self::S_LABEL, 'Approved:', false);

        $signatureRow += 2;

        $text($signatureRow, 'A', self::S_LABEL_BOLD, mb_strtoupper($preparedBy?->name ?: (string) $preparedBy?->username), false);
        $text($signatureRow, 'E', self::S_PLAIN, '_________________', false);
        $text($signatureRow, 'G', self::S_PLAIN, '_________________', false);
        $text($signatureRow, 'H', self::S_PLAIN, '_________________', false);

        $signatureRow++;

        $text($signatureRow, 'A', self::S_PLAIN, $preparedBy?->position ?: 'School Principal I', false);
        $text($signatureRow, 'E', self::S_PLAIN, 'Senior Bookkeeper', false);
        $text($signatureRow, 'G', self::S_PLAIN, 'Budget Officer III', false);
        $text($signatureRow, 'H', self::S_PLAIN, 'Schools Division Superintendent', false);

        /* ----------------------------------------------------------- package */

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
                .'<sheet name="Line Item Allotment" sheetId="1" r:id="rId1"/>'
                .'</sheets>'
                .'</workbook>',

            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',

            'xl/styles.xml' => self::stylesXml(),

            'xl/worksheets/sheet1.xml' => self::sheetXml($cells, $merges, $measured),
        ]);
    }

    /** The download's file name: one report per school per year. */
    public static function downloadName(int $year): string
    {
        return sprintf('per-line-item-expenditure-%d.xlsx', $year);
    }

    /* ------------------------------------------------------------ worksheet */

    /**
     * The worksheet XML: the grid, then the column widths measured from it.
     *
     * @param  array<int, array<string, array{style: int, kind: string, value: string|float|null}>>  $cells
     * @param  array<int, string>  $merges
     * @param  array<string, float>  $measured
     */
    private static function sheetXml(array $cells, array $merges, array $measured): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .self::colsXml($measured)
            .'<sheetData>';

        ksort($cells);

        foreach ($cells as $number => $rowCells) {
            // Single-letter columns sort alphabetically into A…H, which is the
            // order a worksheet requires.
            ksort($rowCells);

            $rowXml = '';

            foreach ($rowCells as $column => $cell) {
                $rowXml .= self::cellXml($column.$number, $cell['style'], $cell['kind'], $cell['value']);
            }

            $xml .= '<row r="'.$number.'">'.$rowXml.'</row>';
        }

        $xml .= '</sheetData>';

        if ($merges !== []) {
            $xml .= '<mergeCells count="'.count($merges).'">';

            foreach ($merges as $merge) {
                $xml .= '<mergeCell ref="'.$merge.'"/>';
            }

            $xml .= '</mergeCells>';
        }

        // Landscape, fit to one page wide: the report is wider than it is tall,
        // and a printout that spills the % column onto page two is the sort of
        // thing that gets retyped by hand.
        return $xml
            .'<pageMargins left="0.5" right="0.5" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            .'<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            .'</worksheet>';
    }

    /**
     * The column widths, measured from the cells written above: content width
     * plus the padding Excel leaves around it, inside each column's floor and
     * ceiling. `bestFit` is set alongside `customWidth` so a reader who asks
     * Excel to auto-fit again gets the same answer.
     *
     * @param  array<string, float>  $measured
     */
    private static function colsXml(array $measured): string
    {
        $xml = '<cols>';
        $index = 0;

        foreach (self::COLUMNS as $column) {
            $index++;

            $width = $column === 'C'
                ? self::SPACER_WIDTH
                : round(min(
                    max(($measured[$column] ?? 0.0) * 1.08 + 2.4, self::MIN_WIDTHS[$column]),
                    self::MAX_WIDTHS[$column]
                ), 2);

            $xml .= '<col min="'.$index.'" max="'.$index.'" width="'.$width.'" customWidth="1" bestFit="1"/>';
        }

        return $xml.'</cols>';
    }

    /**
     * One cell: text as an inline string, money and shares as real numbers
     * formatted by the cell's style, blanks styled but empty so their borders
     * still paint.
     */
    private static function cellXml(string $ref, int $style, string $kind, string|float|null $value): string
    {
        if ($kind === 'blank' || $value === null || $value === '') {
            return '<c r="'.$ref.'" s="'.$style.'"/>';
        }

        if ($kind === 'number') {
            return '<c r="'.$ref.'" s="'.$style.'"><v>'.$value.'</v></c>';
        }

        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr">'
            .'<is><t xml:space="preserve">'.htmlspecialchars((string) $value, ENT_XML1).'</t></is>'
            .'</c>';
    }

    /* --------------------------------------------------------------- styles */

    /**
     * Four fonts (plain 10pt, bold 10pt, bold 12pt, small 9pt), one thin
     * border, one custom number format for the shares. Money uses builtin
     * numFmtId 4 ("#,##0.00"), so the file opens the same in Excel and WPS.
     */
    private static function stylesXml(): string
    {
        $font = static fn (string $extra = ''): string => '<font>'.$extra.'<sz val="10"/><name val="Calibri"/></font>';

        $fonts = '<fonts count="4">'
            .$font()
            .$font('<b/>')
            .'<font><b/><sz val="12"/><name val="Calibri"/></font>'
            .$font('<sz val="9"/>')
            .'</fonts>';

        $fills = '<fills count="2">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
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

        $center = '<alignment horizontal="center" vertical="center"/>';
        $centerWrap = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
        $left = '<alignment horizontal="left" vertical="center"/>';
        $leftWrap = '<alignment horizontal="left" vertical="center" wrapText="1"/>';
        $textWrap = '<alignment horizontal="left" vertical="top" wrapText="1"/>';
        $right = '<alignment horizontal="right" vertical="center"/>';

        $bordered = ['fillId="0"', 'borderId="1"', 'applyBorder="1"'];

        $cellXfs = '<cellXfs count="16">'
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="0"']) // 0 default
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="0"', 'applyAlignment="1"'], $center) // 1 head line
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="0"', 'borderId="0"', 'applyFont="1"', 'applyAlignment="1"'], $center) // 2 head bold
            // The title wraps: on a school with nothing to report the columns
            // sit at their floors, and a single line would be clipped rather
            // than spill.
            .$xf(['numFmtId="0"', 'fontId="2"', 'fillId="0"', 'borderId="0"', 'applyFont="1"', 'applyAlignment="1"'], $centerWrap) // 3 title
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="0"', 'borderId="1"', 'applyFont="1"', 'applyBorder="1"', 'applyAlignment="1"'], $centerWrap) // 4 th
            .$xf(['numFmtId="0"', 'fontId="0"', ...$bordered, 'applyAlignment="1"'], $left) // 5 left
            .$xf(['numFmtId="4"', 'fontId="0"', ...$bordered, 'applyNumberFormat="1"', 'applyAlignment="1"'], $right) // 6 money
            .$xf(['numFmtId="4"', 'fontId="1"', ...$bordered, 'applyNumberFormat="1"', 'applyFont="1"', 'applyAlignment="1"'], $right) // 7 money bold
            .$xf(['numFmtId="0"', 'fontId="0"', ...$bordered, 'applyAlignment="1"'], $textWrap) // 8 text
            .$xf(['numFmtId="164"', 'fontId="0"', ...$bordered, 'applyNumberFormat="1"', 'applyAlignment="1"'], $right) // 9 percent
            .$xf(['numFmtId="164"', 'fontId="1"', ...$bordered, 'applyNumberFormat="1"', 'applyFont="1"', 'applyAlignment="1"'], $right) // 10 percent bold
            .$xf(['numFmtId="0"', 'fontId="1"', ...$bordered, 'applyFont="1"', 'applyAlignment="1"'], $right) // 11 total label
            .$xf(['numFmtId="0"', 'fontId="3"', 'fillId="0"', 'borderId="0"', 'applyFont="1"', 'applyAlignment="1"'], $leftWrap) // 12 label
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="0"', 'borderId="0"', 'applyFont="1"', 'applyAlignment="1"'], $left) // 13 label bold
            .$xf(['numFmtId="0"', 'fontId="3"', 'fillId="0"', 'borderId="0"', 'applyFont="1"', 'applyAlignment="1"'], $left) // 14 plain small
            .$xf(['numFmtId="0"', 'fontId="0"', ...$bordered, 'applyAlignment="1"'], $center) // 15 number
            .'</cellXfs>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="0.00%"/></numFmts>'
            .$fonts
            .$fills
            .$borders
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .$cellXfs
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}

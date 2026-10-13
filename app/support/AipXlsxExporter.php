<?php

namespace App\Support;

use App\Models\AipActivity;
use App\Models\AipPlan;
use App\Models\AipProject;
use App\Models\User;

/**
 * The Annual Implementation Plan as the printed DepEd form: one .xlsx sheet
 * laid out as Annex 10 of the enhanced SIP Guidebook (DepEd Order No. 44,
 * s. 2015).
 *
 * The eight columns are the official form's own:
 *
 *   SCHOOL IMPROVEMENT PROJECT TITLE · PROJECT OBJECTIVE · OUTPUT FOR THE
 *   YEAR · ACTIVITIES · PERSON(S) RESPONSIBLE · SCHEDULE/VENUE · BUDGET PER
 *   ACTIVITY · BUDGET SOURCE
 *
 * Each project's title/objective/output span its activity rows (one merged
 * block per project), exactly as the form is filled in by hand, and the
 * plan's budget total and its per-source breakdown close the table before the
 * Prepared / Reviewed / Approved signature block.
 *
 * The workbook is BUILT FROM SCRATCH — the AIP is structured data with no
 * uploaded file to patch — through PurePhpZipWriter, because the `zip` PHP
 * extension is disabled on XAMPP's web-server PHP. Inline strings (never a
 * sharedStrings table), builtin number format 4 ("#,##0.00") for money.
 *
 * Every value comes from the plan's lines as they stand NOW, never from a
 * stored total that might predate the last edit.
 */
final class AipXlsxExporter
{
    private const S_TITLE = 1;      // bold 12pt, centred

    private const S_HEAD_LINE = 2;  // centred header line (Republic/Department…)

    private const S_HEAD_BOLD = 3;  // centred + bold (school name)

    private const S_TH = 4;         // bordered, bold, centred, wrapped

    private const S_TEXT = 5;       // bordered, wrapped, top-left

    private const S_MONEY = 6;      // bordered, "#,##0.00", right

    private const S_PROJECT = 7;    // bordered, bold, wrapped, top-left

    private const S_TOT_LABEL = 8;  // bordered, bold, right

    private const S_TOT_VALUE = 9;  // bordered, bold, money

    private const S_LABEL = 10;     // bold, no border ("Prepared by:")

    private const S_PLAIN = 11;     // plain, no border

    private const S_CENTER = 12;    // bordered, centred

    /** Build the workbook's bytes. */
    public static function bytes(AipPlan $plan, ?User $exporter = null): string
    {
        $school = $plan->school;
        $district = $school?->district;

        $merges = [];
        $rows = [];
        $row = 0;

        $addRow = function (string $cellsXml) use (&$rows, &$row): void {
            $row++;
            $rows[$row] = $cellsXml;
        };

        $mergeRow = function (int $rowNumber, string $left, string $right, int $style, string $value) use (&$merges): string {
            $merges[] = $left.$rowNumber.':'.$right.$rowNumber;

            return self::cell($rowNumber, $left, $style, $value);
        };

        /* ------------------------------------------------------- header */

        $addRow($mergeRow($row + 1, 'A', 'H', self::S_HEAD_LINE, 'REPUBLIC OF THE PHILIPPINES'));
        $addRow($mergeRow($row + 1, 'A', 'H', self::S_HEAD_LINE, 'DEPARTMENT OF EDUCATION'));
        $addRow($mergeRow($row + 1, 'A', 'H', self::S_HEAD_LINE, $district?->name ?: 'Schools Division Office'));
        $addRow($mergeRow($row + 1, 'A', 'H', self::S_HEAD_BOLD, $school?->name ?: 'School'));
        $addRow($mergeRow($row + 1, 'A', 'H', self::S_TITLE, 'ANNUAL IMPLEMENTATION PLAN'));
        $addRow($mergeRow($row + 1, 'A', 'H', self::S_HEAD_LINE, 'School Year '.$plan->fiscalYearLabel()));

        if (trim((string) $plan->title) !== '') {
            $addRow($mergeRow($row + 1, 'A', 'H', self::S_HEAD_LINE, (string) $plan->title));
        }

        /* ------------------------------------------------ column headers */

        $headerRow = $row + 1;

        $addRow(
            self::cell($headerRow, 'A', self::S_TH, 'SCHOOL IMPROVEMENT PROJECT TITLE')
            .self::cell($headerRow, 'B', self::S_TH, 'PROJECT OBJECTIVE')
            .self::cell($headerRow, 'C', self::S_TH, 'OUTPUT FOR THE YEAR')
            .self::cell($headerRow, 'D', self::S_TH, 'ACTIVITIES')
            .self::cell($headerRow, 'E', self::S_TH, 'PERSON(S) RESPONSIBLE')
            .self::cell($headerRow, 'F', self::S_TH, 'SCHEDULE/VENUE')
            .self::cell($headerRow, 'G', self::S_TH, 'BUDGET PER ACTIVITY')
            .self::cell($headerRow, 'H', self::S_TH, 'BUDGET SOURCE')
        );

        /* ---------------------------------------------------------- rows */

        $projects = $plan->projects()->with('activities')->get();

        if ($projects->isEmpty()) {
            $emptyRow = $row + 1;

            $addRow(
                self::cell($emptyRow, 'A', self::S_TEXT, '(No projects encoded yet.)')
                .self::blankCell($emptyRow, 'B', self::S_TEXT)
                .self::blankCell($emptyRow, 'C', self::S_TEXT)
                .self::blankCell($emptyRow, 'D', self::S_TEXT)
                .self::blankCell($emptyRow, 'E', self::S_TEXT)
                .self::blankCell($emptyRow, 'F', self::S_TEXT)
                .self::blankCell($emptyRow, 'G', self::S_MONEY)
                .self::blankCell($emptyRow, 'H', self::S_TEXT)
            );
            $merges[] = 'A'.$emptyRow.':C'.$emptyRow;
        }

        foreach ($projects as $project) {
            /** @var AipProject $project */
            $activities = $project->activities;

            // A project with no activities still shows its project block and
            // one empty activity line, so the form reads as the plan rather
            // than as a project somebody forgot to finish.
            $lines = $activities->isEmpty() ? [null] : $activities->all();
            $firstRow = $row + 1;
            $lastRow = $row + count($lines);

            foreach ($lines as $index => $activity) {
                /** @var AipActivity|null $activity */
                $r = $row + 1;

                // The project's own cells are only written on the first line;
                // the block below merges them down over its activities.
                $cells = $index === 0
                    ? self::cell($r, 'A', self::S_PROJECT, (string) $project->title)
                        .self::cell($r, 'B', self::S_TEXT, (string) ($project->objective ?? ''))
                        .self::cell($r, 'C', self::S_TEXT, (string) ($project->output_for_year ?? ''))
                    : self::blankCell($r, 'A', self::S_PROJECT)
                        .self::blankCell($r, 'B', self::S_TEXT)
                        .self::blankCell($r, 'C', self::S_TEXT);

                $cells .= self::cell($r, 'D', self::S_TEXT, $activity !== null ? (string) $activity->activity : '')
                    .self::cell($r, 'E', self::S_TEXT, (string) ($activity?->person_responsible ?? ''))
                    .self::cell($r, 'F', self::S_TEXT, $activity !== null ? $activity->scheduleLabel() : '')
                    .(($activity !== null && $activity->budget !== null)
                        ? self::numericCell($r, 'G', (float) $activity->budget, self::S_MONEY)
                        : self::blankCell($r, 'G', self::S_MONEY))
                    .self::cell($r, 'H', self::S_TEXT, (string) ($activity?->budget_source ?? ''));

                $addRow($cells);
            }

            // Merge the project's three columns down over its activity rows.
            if ($lastRow > $firstRow) {
                $merges[] = 'A'.$firstRow.':A'.$lastRow;
                $merges[] = 'B'.$firstRow.':B'.$lastRow;
                $merges[] = 'C'.$firstRow.':C'.$lastRow;
            }
        }

        /* --------------------------------------------------------- totals */

        $r = $row + 1;
        $addRow(
            self::cell($r, 'A', self::S_TOT_LABEL, 'TOTAL BUDGET')
            .self::blankCell($r, 'B', self::S_TOT_LABEL)
            .self::blankCell($r, 'C', self::S_TOT_LABEL)
            .self::blankCell($r, 'D', self::S_TOT_LABEL)
            .self::blankCell($r, 'E', self::S_TOT_LABEL)
            .self::blankCell($r, 'F', self::S_TOT_LABEL)
            .self::numericCell($r, 'G', $plan->annualBudgetTotal(), self::S_TOT_VALUE)
            .self::blankCell($r, 'H', self::S_TOT_LABEL)
        );
        $merges[] = 'A'.$r.':F'.$r;

        // The per-source breakdown closes the table: a reviewer needs to see
        // how much is MOOE, how much SEF, and so on.
        foreach ($plan->budgetBySource() as $source => $total) {
            $r = $row + 1;
            $addRow(
                self::cell($r, 'A', self::S_PLAIN, '')
                .self::blankCell($r, 'B', self::S_PLAIN)
                .self::blankCell($r, 'C', self::S_PLAIN)
                .self::blankCell($r, 'D', self::S_PLAIN)
                .self::blankCell($r, 'E', self::S_PLAIN)
                .self::cell($r, 'F', self::S_TOT_LABEL, 'Subtotal — '.$source)
                .self::numericCell($r, 'G', (float) $total, self::S_TOT_VALUE)
                .self::blankCell($r, 'H', self::S_PLAIN)
            );
        }

        /* ----------------------------------------------------- signatures */

        $addRow('');
        $addRow('');
        $addRow('');

        $r = $row + 1;
        $addRow(
            self::cell($r, 'A', self::S_LABEL, 'Prepared by:')
            .self::cell($r, 'D', self::S_LABEL, 'Reviewed by:')
            .self::cell($r, 'G', self::S_LABEL, 'Approved by:')
        );

        $r = $row + 1;
        $addRow(
            self::cell($r, 'A', self::S_PLAIN, '')
            .self::cell($r, 'D', self::S_PLAIN, '')
            .self::cell($r, 'G', self::S_PLAIN, '')
        );

        $r = $row + 1;
        $addRow(
            self::cell($r, 'A', self::S_PLAIN, $exporter?->name ?? '')
            .self::cell($r, 'D', self::S_PLAIN, $plan->approvedBy?->name ?? '')
            .self::cell($r, 'G', self::S_PLAIN, '')
        );

        $r = $row + 1;
        $addRow(
            self::cell($r, 'A', self::S_PLAIN, 'School Head')
            .self::cell($r, 'D', self::S_PLAIN, 'Schools Division Office')
            .self::cell($r, 'G', self::S_PLAIN, 'Schools Division Superintendent')
        );

        /* -------------------------------------------------------- package */

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<cols>'
            .'<col min="1" max="1" width="30" customWidth="1"/>'
            .'<col min="2" max="2" width="32" customWidth="1"/>'
            .'<col min="3" max="3" width="26" customWidth="1"/>'
            .'<col min="4" max="4" width="34" customWidth="1"/>'
            .'<col min="5" max="5" width="22" customWidth="1"/>'
            .'<col min="6" max="6" width="22" customWidth="1"/>'
            .'<col min="7" max="7" width="16" customWidth="1"/>'
            .'<col min="8" max="8" width="18" customWidth="1"/>'
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

        // Landscape, fit to one page wide: the form is wider than it is tall.
        $sheetXml .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
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
                .'<sheet name="AIP" sheetId="1" r:id="rId1"/>'
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

    /** The export's file name. */
    public static function downloadName(AipPlan $plan): string
    {
        return self::safeName($plan->exportBaseName()).'.xlsx';
    }

    /** A file name safe for the Content-Disposition header. */
    public static function safeName(string $name): string
    {
        $name = preg_replace('#[\\\\/:*?"<>|]+#u', ' ', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';

        return mb_substr(trim($name), 0, 120) ?: 'Annual Implementation Plan';
    }

    /* ------------------------------------------------------- cell builders */

    private static function cell(int $rowNumber, string $column, int $style, string $value): string
    {
        if ($value === '') {
            return self::blankCell($rowNumber, $column, $style);
        }

        return '<c r="'.$column.$rowNumber.'" s="'.$style.'" t="inlineStr">'
            .'<is><t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1).'</t></is>'
            .'</c>';
    }

    private static function numericCell(int $rowNumber, string $column, ?float $value, int $style = self::S_MONEY): string
    {
        if ($value === null) {
            return self::blankCell($rowNumber, $column, $style);
        }

        return '<c r="'.$column.$rowNumber.'" s="'.$style.'"><v>'.$value.'</v></c>';
    }

    private static function blankCell(int $rowNumber, string $column, int $style): string
    {
        return '<c r="'.$column.$rowNumber.'" s="'.$style.'"/>';
    }

    /* ------------------------------------------------------------- styles */

    private static function stylesXml(): string
    {
        $font = static fn (string $extra = ''): string => '<font>'.$extra.'<sz val="10"/><name val="Calibri"/></font>';

        $fonts = '<fonts count="3">'
            .$font()
            .$font('<b/>')
            .'<font><b/><sz val="12"/><name val="Calibri"/></font>'
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

        $xf = static fn (array $attrs, string $alignment = ''): string => '<xf '.implode(' ', $attrs).' xfId="0">'.$alignment.'</xf>';

        $cellXfs = '<cellXfs count="13">'
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="0"']) // 0 default
            .$xf(['numFmtId="0"', 'fontId="2"', 'fillId="0"', 'borderId="0"', 'applyFont="1"', 'applyAlignment="1"'], '<alignment horizontal="center" vertical="center"/>') // 1 title
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="0"', 'applyAlignment="1"'], '<alignment horizontal="center" vertical="center"/>') // 2 head line
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="0"', 'borderId="0"', 'applyFont="1"', 'applyAlignment="1"'], '<alignment horizontal="center" vertical="center"/>') // 3 head bold
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="0"', 'borderId="1"', 'applyFont="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="center" vertical="center" wrapText="1"/>') // 4 th
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="left" vertical="top" wrapText="1"/>') // 5 text
            .$xf(['numFmtId="4"', 'fontId="0"', 'fillId="0"', 'borderId="1"', 'applyNumberFormat="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="right" vertical="center"/>') // 6 money
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="0"', 'borderId="1"', 'applyFont="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="left" vertical="top" wrapText="1"/>') // 7 project
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="0"', 'borderId="1"', 'applyFont="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="right" vertical="center"/>') // 8 total label
            .$xf(['numFmtId="4"', 'fontId="1"', 'fillId="0"', 'borderId="1"', 'applyNumberFormat="1"', 'applyFont="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="right" vertical="center"/>') // 9 total value
            .$xf(['numFmtId="0"', 'fontId="1"', 'fillId="0"', 'borderId="0"', 'applyFont="1"', 'applyAlignment="1"'], '<alignment horizontal="left" vertical="center"/>') // 10 label
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="0"', 'applyAlignment="1"'], '<alignment horizontal="left" vertical="center"/>') // 11 plain
            .$xf(['numFmtId="0"', 'fontId="0"', 'fillId="0"', 'borderId="1"', 'applyBorder="1"', 'applyAlignment="1"'], '<alignment horizontal="center" vertical="center"/>') // 12 center
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

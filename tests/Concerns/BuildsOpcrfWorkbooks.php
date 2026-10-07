<?php

namespace Tests\Concerns;

use ZipArchive;

/**
 * Builds minimal but valid .xlsx workbooks (inline strings + numeric
 * cells) so the OPCRF analyzer and upload flow can be exercised without
 * a real Excel install. Cell values are given as ["B4" => "text",
 * "T16" => 5] — ints become numeric cells.
 */
trait BuildsOpcrfWorkbooks
{
    /**
     * @param  array<string, string|int>  $cells
     * @return string path to the built workbook
     */
    private function buildOpcrfWorkbook(array $cells): string
    {
        return $this->buildOpcrfWorkbookWithSheets(['OPCRF' => $cells]);
    }

    /**
     * A multi-tab workbook: each entry is a tab name → cell map, built
     * into one package in the order given (rel-based manifest, so the
     * analyzer must resolve tabs the way it does for real files).
     *
     * Optional per-sheet options (keyed by tab name):
     *   - 'hidden_rows'    => int[]  rows emitted with hidden="1"
     *   - 'hidden_columns' => int[]  1-based column indexes hidden via <cols>
     *
     * @param  array<string, array<string, string|int>>  $sheets
     * @param  array<string, array{hidden_rows?: array<int, int>, hidden_columns?: array<int, int>}>  $sheetOptions
     * @return string path to the built workbook
     */
    private function buildOpcrfWorkbookWithSheets(array $sheets, array $sheetOptions = []): string
    {
        $names = array_keys($sheets);
        $sheetOverrides = '';
        $relsEntries = '';
        $contentOverrides = '';
        $path = tempnam(sys_get_temp_dir(), 'opcrf-test-').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($names as $index => $name) {
            $number = $index + 1;
            $cells = $sheets[$name];
            $byRow = [];

            foreach ($cells as $ref => $value) {
                preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
                $byRow[(int) $m[2]][$m[1]] = $value;
            }

            $rowsXml = '';
            $hiddenRows = $sheetOptions[$name]['hidden_rows'] ?? [];

            foreach ($byRow as $rowNumber => $rowCells) {
                $cellsXml = '';

                foreach ($rowCells as $column => $value) {
                    if (is_int($value)) {
                        $cellsXml .= '<c r="'.$column.$rowNumber.'"><v>'.$value.'</v></c>';
                    } else {
                        $escaped = htmlspecialchars($value, ENT_XML1);
                        $cellsXml .= '<c r="'.$column.$rowNumber.'" t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>';
                    }
                }

                $rowsXml .= '<row r="'.$rowNumber.'"'
                    .(in_array($rowNumber, $hiddenRows, true) ? ' hidden="1"' : '')
                    .'>'.$cellsXml.'</row>';
            }

            $colsXml = '';

            foreach ($sheetOptions[$name]['hidden_columns'] ?? [] as $columnIndex) {
                $colsXml .= '<col min="'.$columnIndex.'" max="'.$columnIndex.'" width="9" hidden="1"/>';
            }

            $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .($colsXml !== '' ? '<cols>'.$colsXml.'</cols>' : '')
                .'<sheetData>'.$rowsXml.'</sheetData></worksheet>';

            $escapedName = htmlspecialchars($name, ENT_XML1);
            $sheetOverrides .= '<sheet name="'.$escapedName.'" sheetId="'.$number.'" r:id="rId'.$number.'"/>';
            $relsEntries .= '<Relationship Id="rId'.$number.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$number.'.xml"/>';
            $contentOverrides .= '<Override PartName="/xl/worksheets/sheet'.$number.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';

            $zip->addFromString('xl/worksheets/sheet'.$number.'.xml', $sheetXml);
        }

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetOverrides.'</sheets></workbook>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relsEntries
            .'</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$contentOverrides
            .'</Types>';

        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $relsXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->close();

        return $path;
    }

    /**
     * A realistic filled-in OPCRF workbook matching the real template's
     * layout: header labels in B4:B7 with the staff member's values typed
     * into the left value band (F, merged F..M in the real template), the
     * evaluator block on the right (labels in N, values merged O..V) —
     * which the untouched template even ships with the evaluator's own
     * name pre-filled in O4 — objectives in F, accomplishments in S,
     * ratings in T, criteria labels in L.
     *
     * @param  array<string, string|int>  $overrides
     * @return string path to the built workbook
     */
    private function buildFilledOpcrf(array $overrides = []): string
    {
        $cells = [
            'B4' => 'Name of Employee:',
            'F4' => 'Jane D. Doe',
            'B5' => 'Position/Designation: ',
            'F5' => 'Teacher I',
            'B6' => 'Review Period:   ',
            'F6' => 'January to December 2026',
            'B7' => 'Strand/Bureau/Center/Service/Region/Division:  ',
            'F7' => 'Schools Division Office',
            // Evaluator block on the right (as in the shipped template).
            'O4' => 'EVA M. DOLLOSA RN',
            'N5' => 'Position:',
            'O5' => 'OIC-Asst. Schools Division Superintendent',
            'N6' => 'Approving Authority:',
            'O6' => 'FERDINAND S. SY PhD, CESO VI',
            'N7' => 'Date of Review:',
            // Objective block 1 (rows 16–18): F merged on the first row,
            // criteria labels (L) and ratings (T) on every criteria row.
            'F16' => 'Objective 1: Improved learner outcomes',
            'L16' => 'Quality',
            'S16' => 'Raised MPS by 5 points',
            'T16' => 5,
            'L17' => 'Efficiency',
            'T17' => 4,
            'L18' => 'Timeliness',
            'T18' => 5,
            // Objective block 2 (row 19+): merged F on its first row.
            'F19' => 'Objective 2: Conducted action research',
            'L19' => 'Quality',
            'S19' => 'Completed one action research',
            'T19' => 4,
        ];

        return $this->buildOpcrfWorkbook(array_merge($cells, $overrides));
    }

    /**
     * A workbook shaped like the real template's full body: the three
     * parts (I-A at banner row 10, I-B at 79, I-C at 114), each with an
     * objective block, a "Part … Total Score" row, and the signer block
     * (RATEE / RATER / APPROVING AUTHORITY) at the end. Part I-C keeps
     * its Timeline in column J, and the part tables' "Objectives"
     * header cells (F81/F117) are present to prove they never read as
     * objectives.
     *
     * @param  array<string, string|int>  $overrides
     * @return string path to the built workbook
     */
    private function buildThreePartOpcrf(array $overrides = []): string
    {
        $cells = [
            // ----- HEADER BLOCK (rows 2-7, as in the real template) -----
            // Present because every personalized OPCRF carries it: the
            // uploaded workbook's Name of Employee cell is what the upload
            // gate reads, so a fixture without one is rejected outright.
            'B2' => 'OFFICE PERFORMANCE COMMITMENT AND REVIEW FORM (OPCRF)',
            'B4' => 'Name of Employee:',
            'F4' => 'Jane D. Doe',
            'B5' => 'Position/Designation:',
            'F5' => 'Teacher I',
            'B6' => 'Review Period:',
            'F6' => 'January to December 2026',
            'B7' => 'Strand/Bureau/Center/Service/Region/Division:',
            'F7' => 'Schools Division Office',
            // ----- PART I-A (banner row 10, entries, total row 76) -----
            'B10' => "PART I-A: COMMITMENT TO ORGANIZATIONAL OUTCOMES (60%)\nPart I-A. Commitment to Organizational Outcomes shall capture office commitments.",
            // Full planning band of the first objective (as in the real
            // template): KRA, attribution column, weight, target, scale,
            // MOVs and per-block average.
            'B16' => 'Education Human Resource Development Program',
            'D16' => 'BEDP Pillar 1: Access',
            'F16' => 'Objective 1: Improved learner outcomes',
            'H16' => 'January to December 2026',
            'I16' => 0.35,
            'J16' => 2,
            'K16' => 'AIP activities aligned with the approved WFP',
            'M16' => 'Met all 5 indicators based on the established standards',
            'Q16' => 'Only 1 out of 5 indicators is met',
            'R16' => 'Approved WFP and Annual Implementation Plan',
            'L16' => 'Quality',
            'S16' => 'Raised MPS by 5 points',
            'T16' => 5,
            'U16' => 4.67,
            'L17' => 'Efficiency',
            'T17' => 4,
            'L18' => 'Timeliness',
            'T18' => 5,
            // A second objective in the SAME part, with its own KRA —
            // the block must split there, not swallow it.
            'B19' => 'School Leadership and Administration',
            'F19' => 'Objective 2: Implemented the School Improvement Plan',
            'H19' => 'June 2025 to March 2026',
            'I19' => 0.05,
            'L19' => 'Quality',
            'S19' => 'SIP implemented across all grade levels',
            'T19' => 4,
            'B76' => 'Part I-A Total Score',
            'V76' => 2, // numeric total, as Excel caches it

            // ----- PART I-B (banner row 79, entries, total row 112) -----
            'B79' => "PART I-B: INNOVATING AND INTERVENING ACCOMPLISHMENTS (20%)\nPart I-B. Innovating and Intervening Accomplishments shall capture innovation.",
            'F81' => 'Objectives', // part table's header band cell
            'F83' => 'Objective: Conducted innovations and interventions',
            'H83' => 'June 2025 to March 2026',
            'L83' => 'Quality',
            'S83' => 'Innovation documents approved',
            'T83' => 4,
            'B112' => 'Part I-B Total Score',
            'V112' => 1,

            // ----- PART I-C (banner row 114, entries, total row 146) -----
            'B114' => "PART I-C: ORGANIZATIONAL EFFECTIVENESS (15%)\nPart I-C. Organizational Effectiveness shall capture accomplishments.",
            'F117' => 'Objectives', // part table's header band cell
            'F119' => 'Objective: Utilized budget allocation',
            'J119' => 'Within the rating period', // I-C keeps Timeline in J
            'K119' => 0, // numeric weight — must not read as a timeline
            'L119' => 'Quality',
            'S119' => 'Liquidation reports submitted',
            'T119' => 5,
            'B146' => 'Part I-C Total Score',
            'V146' => 1,

            // ----- Signer block (rows 151–153) -----
            'D151' => 0, // stray computed cell — never a name
            'I151' => 'EVA M. DOLLOSA RN',
            'N151' => 'FERDINAND S. SY PhD, CESO VI',
            'D153' => 'RATEE',
            'I153' => 'RATER',
            'N153' => 'APPROVING AUTHORITY',
        ];

        return $this->buildOpcrfWorkbook(array_merge($cells, $overrides));
    }

    /**
     * The complete four-tab workbook: PART I (the three-part objectives
     * sheet) plus the PART II competency tab, the PART III rating summary
     * and the PART IV improvement plans — with ratings filled in, so the
     * per-tab parsers can be asserted end to end.
     *
     * @return string path to the built workbook
     */
    private function buildFullOpcrfWorkbook(): string
    {
        return $this->buildOpcrfWorkbookWithSheets([
            'PART I (CY 2025 & SY2025-2026)' => $this->threePartCells(),
            'PART II' => [
                'B3' => "PART II-A:  LEADERSHIP COMPETENCIES (2.5%)\nPart II-A. Leadership Competencies shall capture leadership.",
                'B4' => 'Competencies',
                'C4' => 'Behavioural Indicators',
                'B6' => 'Leading People',
                'C6' => '1. Uses basic persuasion techniques in a discussion.',
                'I6' => 4,
                'G6' => 'Persuaded the division during the budget hearing.',
                'C7' => '2. Persuades, convinces or influences others.',
                'I7' => 5,
                'G7' => '',
                // The tab's own rating legend, printed beside the table.
                'L4' => 'DepEd Competencies Scale',
                'L5' => 'Numerical Rating',
                'M5' => 'Adjectival Rating',
                'N5' => 'Definition',
                'L6' => 5,
                'M6' => 'Role Model',
                'N6' => 'Behavioral indicator is consistently exhibited and is worthy of emulation.',
                'L7' => 4,
                'M7' => 'Consistently Demonstrated',
                'N7' => 'Behavioral indicator is constantly shown.',
                'L8' => 1,
                'M8' => 'Rarely Demonstrated',
                'N8' => 'Behavioral indicator is seldom shown.',
                'B11' => 'People Performance Management',
                'C11' => '1. Makes specific changes in the performance management system.',
                'B16' => 'People Development',
                'C16' => '1. Improves the skills and effectiveness of individuals.',
                'B21' => 'Part II-A Total Score: Weighted Average (Average x 0.025)',
                'J21' => 4.5,
                'B22' => "PART II-B:  CORE BEHAVIOURAL COMPETENCIES (2.5%)\nPart II-B. Core Behavioral Competencies shall capture behavior.",
                'B25' => 'Self-Management',
                'C25' => '1. Sets personal goals and direction, needs and development.',
                'B55' => 'Part II-B Total Score: Weighted Average (Average x 0.025)',
                'J55' => 4.0,
                'C68' => 'RATEE',
                'G66' => 'EVA M. DOLLOSA RN',
                'G68' => 'RATER',
            ],
            'PART III' => [
                'C2' => 'PART III: SUMMARY OF RATINGS',
                'C5' => 'Final Performance Components',
                'E5' => 'Weight Allocation',
                'F5' => 'Obtained Score',
                'C7' => 'PART I',
                'D7' => 'A.  Commitment to Organizational Outcomes',
                'E7' => 0.6,
                'F7' => 4.2,
                'D8' => 'B.  Innovating and Intervening Accomplishments',
                'E8' => 0.2,
                'F8' => 3.8,
                'D9' => 'C. Organizational Effectiveness',
                'E9' => 0.15,
                'F9' => 4.5,
                'C10' => 'PART II',
                'D10' => 'A.  Leadership Competencies',
                'E10' => '2.5% (0.125)',
                'F10' => 4.1,
                'D11' => 'B.  Core Behavioural Competencies',
                'E11' => '2.5% (0.125)',
                'F11' => 4.0,
                'C17' => 'Name of Employee: JUAN DELA CRUZ',
                'F17' => 'Name of Superior: EVA M. DOLLOSA RN',
                // The agreement block's signature and date lines.
                'C19' => 'Signature:',
                'C21' => 'Date: 2026-03-31',
                'F19' => 'Signature: EVA M. DOLLOSA RN',
                'F21' => 'Date:',
                // The overall score and the RPMS rating beside it, plus the
                // rating table that explains both.
                'G7' => 4.25,
                'H7' => 'Very Satisfactory',
                'M6' => 'Range',
                'N6' => 'Numerical Rating',
                'O6' => 'Adjectival Rating',
                'M7' => '4.500-5.000',
                'N7' => 5,
                'O7' => 'Outstanding',
                'M8' => '3.500-4.499',
                'N8' => 4,
                'O8' => 'Very Satisfactory',
            ],
            'PART IV' => [
                'C4' => 'PART IV: IMPROVEMENT AND DEVELOPMENT PLANS',
                'C6' => 'Part IV-A: Office Improvement Plan',
                'E7' => 'Action Plan',
                'C8' => "Gap Analysis  \n(SWOT)",
                'D8' => 'Improvement Area',
                'E8' => 'General Objective',
                'G8' => 'Recommended Improvement Intervention',
                'I8' => 'Timeline',
                'J8' => 'Resources Needed',
                'C10' => 'Weak ICT infrastructure',
                'D10' => 'Learning resources',
                'E10' => 'Digitize learning materials',
                'G10' => 'Procure tablets and offline content',
                'I10' => 'June 2026',
                'J10' => 'MOOE funds',
                'C13' => 'Feedback:',
                'C14' => 'Plans are achievable within the rating period.',
                'C15' => 'Part IV-B: Individual Development Plan',
                'E16' => 'Action Plan',
                'C17' => 'Strengths',
                'D17' => 'Improvement Needs',
                'E17' => 'Learning Objective',
                'G17' => 'Recommended Developmental Intervention',
                'I17' => 'Timeline',
                'J17' => 'Resources Needed',
                'C19' => 'Strong classroom management',
                'D19' => 'Research writing',
                'E19' => 'Complete one action research',
                'G19' => 'Attend writeshop',
                'I19' => 'March 2027',
                'J19' => 'Division training fund',
                'F25' => 'EVA M. DOLLOSA RN',
                'C26' => 'RATEE',
                'F26' => 'RATER',
            ],
        ]);
    }

    /**
     * The PART I cells of buildThreePartOpcrf, as an array (the
     * multi-tab fixture reuses them for its first tab).
     *
     * @return array<string, string|int>
     */
    private function threePartCells(): array
    {
        return [
            // ----- HEADER BLOCK -----
            // The personalized template's identity cells. The upload gate
            // reads F4 to confirm the form belongs to the signed-in
            // account, so every multi-tab fixture needs it.
            'B2' => 'OFFICE PERFORMANCE COMMITMENT AND REVIEW FORM (OPCRF)',
            'B4' => 'Name of Employee:',
            'F4' => 'Jane D. Doe',
            'B5' => 'Position/Designation:',
            'F5' => 'Teacher I',
            // ----- PART I-A -----
            'B10' => "PART I-A: COMMITMENT TO ORGANIZATIONAL OUTCOMES (60%)\nPart I-A. Commitment to Organizational Outcomes shall capture office commitments.",
            'F16' => 'Objective 1: Improved learner outcomes',
            'H16' => 'January to December 2026',
            'L16' => 'Quality',
            'S16' => 'Raised MPS by 5 points',
            'T16' => 5,
            'L17' => 'Efficiency',
            'T17' => 4,
            'L18' => 'Timeliness',
            'T18' => 5,
            'B76' => 'Part I-A Total Score',
            'V76' => 2,
            'B79' => "PART I-B: INNOVATING AND INTERVENING ACCOMPLISHMENTS (20%)\nPart I-B. Innovating and Intervening Accomplishments shall capture innovation.",
            'F83' => 'Objective: Conducted innovations and interventions',
            'H83' => 'June 2025 to March 2026',
            'L83' => 'Quality',
            'S83' => 'Innovation documents approved',
            'T83' => 4,
            'B112' => 'Part I-B Total Score',
            'V112' => 1,
            'B114' => "PART I-C: ORGANIZATIONAL EFFECTIVENESS (15%)\nPart I-C. Organizational Effectiveness shall capture accomplishments.",
            'F119' => 'Objective: Utilized budget allocation',
            'J119' => 'Within the rating period',
            'L119' => 'Quality',
            'S119' => 'Liquidation reports submitted',
            'T119' => 5,
            'B146' => 'Part I-C Total Score',
            'V146' => 1,
            'D153' => 'RATEE',
            'I151' => 'EVA M. DOLLOSA RN',
            'N151' => 'FERDINAND S. SY PhD, CESO VI',
            'I153' => 'RATER',
            'N153' => 'APPROVING AUTHORITY',
        ];
    }

    /**
     * A workbook shaped like the official OPCRF-TEMPLATE.xlsx package: one
     * part per tab — "PART I (CY 2025 & SY2025-2026)", "PART II", "PART III",
     * "PART IV" — with the workbook-level relationships, the Part I print
     * area defined name (localSheetId is a *position*), the calcChain
     * relationship, and docProps/app.xml titles a real Excel file carries,
     * so the part-trimming surgery can be exercised against the same
     * structures the shipped template has.
     *
     * Per-tab cell values come as ['PART II' => ['B2' => 'text']]; a tab
     * given as a bare string key keeps its default content. Each tab's
     * sheet part is referenced by Target="worksheets/sheetN.xml" from
     * xl/_rels/workbook.xml.rels, exactly like the real file.
     *
     * @param  array<string, array<string, string|int>>  $tabs
     * @return string path to the built workbook
     */
    private function buildMultiPartOpcrf(array $tabs = []): string
    {
        $names = [
            'PART I (CY 2025 & SY2025-2026)',
            'PART II',
            'PART III',
            'PART IV',
        ];

        $defaults = [
            'B2' => 'OFFICE PERFORMANCE COMMITMENT AND REVIEW FORM (OPCRF)',
            'B4' => 'Name of Employee:',
            // The personalized header: the upload gate reads this cell to
            // confirm the form belongs to the signed-in account.
            'F4' => 'Jane D. Doe',
        ];

        $bySheets = [];
        $sheetRels = '';
        $overrides = '';
        $titles = '';

        foreach ($names as $index => $name) {
            $number = $index + 1;
            $rid = 'rId'.$number;
            $cells = array_merge($defaults, $tabs[$name] ?? []);

            $byRow = [];

            foreach ($cells as $ref => $value) {
                preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
                $byRow[(int) $m[2]][$m[1]] = $value;
            }

            $rowsXml = '';

            foreach ($byRow as $rowNumber => $rowCells) {
                $cellsXml = '';

                foreach ($rowCells as $column => $value) {
                    if (is_int($value)) {
                        $cellsXml .= '<c r="'.$column.$rowNumber.'"><v>'.$value.'</v></c>';
                    } else {
                        $escaped = htmlspecialchars($value, ENT_XML1);
                        $cellsXml .= '<c r="'.$column.$rowNumber.'" t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>';
                    }
                }

                $rowsXml .= '<row r="'.$rowNumber.'">'.$cellsXml.'</row>';
            }

            $bySheets[$name] = '<sheetData>'.$rowsXml.'</sheetData>'
                .'<pageSetup r:id="rId'.($number + 20).'"/>'; // per-sheet printer rel

            $sheetRels .= '<Relationship Id="'.$rid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$number.'.xml"/>';

            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$number.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $overrides .= '<Override PartName="/xl/printerSettings/printerSettings'.$number.'.bin" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.printerSettings"/>';

            $titles .= '<vt:lpstr>'.htmlspecialchars($name, ENT_XML1).'</vt:lpstr>';
        }

        // A workbook-scoped relationship (calcChain) sits at a rid beyond the
        // sheets, plus a theme entry, matching the real template's rels shape.
        $sheetRels .= '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>';
        $sheetRels .= '<Relationship Id="rId12" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/calcChain" Target="calcChain.xml"/>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'
            .'<sheet name="'.htmlspecialchars($names[0], ENT_XML1).'" sheetId="1" r:id="rId1"/>'
            .'<sheet name="PART II" sheetId="2" r:id="rId2"/>'
            .'<sheet name="PART III" sheetId="3" r:id="rId3"/>'
            .'<sheet name="PART IV" sheetId="4" r:id="rId4"/>'
            .'</sheets>'
            // The print area is scoped to the FIRST sheet: localSheetId is a
            // position, so keeping sheet1 leaves it at 0, dropping it must
            // remove the name (or renumber whichever sheet survives).
            .'<definedNames><definedName name="_xlnm.Print_Area" localSheetId="0">\''.htmlspecialchars($names[0], ENT_XML1).'\'!$B$1:$V$156</definedName></definedNames>'
            .'<calcPr calcId="191029"/></workbook>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$sheetRels
            .'</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="bin" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.printerSettings"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'<Override PartName="/xl/calcChain.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.calcChain+xml"/>'
            .'</Types>';

        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            .'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<HeadingPairs><vt:vector size="4" baseType="variant">'
            .'<vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>4</vt:i4></vt:variant>'
            .'<vt:variant><vt:lpstr>Named Ranges</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant>'
            .'</vt:vector></HeadingPairs>'
            .'<TitlesOfParts><vt:vector size="5" baseType="lpstr">'
            .$titles
            .'<vt:lpstr>\''.htmlspecialchars($names[0], ENT_XML1).'\'!Print_Area</vt:lpstr>'
            .'</vt:vector></TitlesOfParts>'
            .'</Properties>';

        $path = tempnam(sys_get_temp_dir(), 'opcrf-multi-').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $relsXml);
        $zip->addFromString('docProps/app.xml', $appXml);
        $zip->addFromString('docProps/core.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/"/>');
        $zip->addFromString('xl/calcChain.xml', '<calcChain/>');
        $zip->addFromString('xl/theme/theme1.xml', '<theme/>');

        foreach ($names as $index => $name) {
            $number = $index + 1;

            $zip->addFromString('xl/worksheets/sheet'.$number.'.xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .$bySheets[$name]
                .'</worksheet>');

            $zip->addFromString('xl/printerSettings/printerSettings'.$number.'.bin', 'printer-'.$number);

            $zip->addFromString('xl/worksheets/_rels/sheet'.$number.'.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/printerSettings" Target="../printerSettings/printerSettings'.$number.'.bin"/>'
                .'</Relationships>');
        }

        $zip->close();

        return $path;
    }

    /**
     * The sheet tab names of a workbook given as bytes (or a path), in
     * tab order — read straight out of xl/workbook.xml.
     *
     * @return array<int, string>
     */
    private function sheetNamesOfWorkbook(string $bytesOrPath): array
    {
        $path = $bytesOrPath;

        if (! is_file($path)) {
            $path = tempnam(sys_get_temp_dir(), 'opcrf-inspect-').'.xlsx';
            file_put_contents($path, $bytesOrPath);
        }

        $zip = new ZipArchive;
        $zip->open($path);

        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        preg_match_all('/<sheet\b[^>]*\bname="([^"]*)"/', $workbook, $m);

        return array_map(
            fn (string $name): string => html_entity_decode($name, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $m[1]
        );
    }
}

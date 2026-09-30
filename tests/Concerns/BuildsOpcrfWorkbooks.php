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

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$rowsXml.'</sheetData></worksheet>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="OPCRF" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';

        $path = tempnam(sys_get_temp_dir(), 'opcrf-test-').'.xlsx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $relsXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
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
            // ----- PART I-A (banner row 10, entries, total row 76) -----
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
        $zip = new ZipArchive();
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

        $zip = new ZipArchive();
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

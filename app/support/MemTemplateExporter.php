<?php

namespace App\Support;

/**
 * The MEM authoring template, delivered as a real .xlsx instead of a CSV:
 * every column gets an explicit width so it opens auto-fitted in Excel, and
 * the account code is stamped as text (@) so a value like 5020301000 is not
 * mangled into scientific notation (5.02E+09).
 *
 * The OOXML package is assembled by {@see MemXlsxExporter::package()}, the
 * same proven builder the matrix export uses, so the workbook opens cleanly
 * in Excel without repair. Style indexes match {@see MemXlsxExporter}:
 * 5 = header, 7 = body, 17 = account code (numFmtId 49, text).
 */
final class MemTemplateExporter
{
    /** Header label → print width, in the order the CSV template used. */
    private const COLUMNS = [
        'Section' => 12,
        'Objective' => 24,
        'Activity Description' => 30,
        'Resource Kind' => 16,
        'Quantity' => 12,
        'Unit' => 10,
        'Unit Cost' => 12,
        'Account Title' => 22,
        'Account Code' => 16,
        'Funding Source' => 14,
        'Organizational Classification' => 26,
        'Activity MOVs' => 14,
    ];

    /** One worked example row, matching the template's instructions. */
    private const EXAMPLE = [
        'operations',
        'Improved learner outcomes',
        'Procure office supplies',
        'MOOE',
        '10',
        'ream',
        '250.00',
        'Office Supplies Expenses',
        '5020301000',
        'MOOE',
        'School',
        'PO and RIS',
    ];

    // Style indexes as defined by MemXlsxExporter::stylesXml().
    private const S_HEADER = 5; // bordered, bold, centred, wrapped
    private const S_BODY = 7;   // bordered, wrapped, left-aligned
    private const S_CODE = 17;  // numFmtId 49 (@), text — account code

    public static function bytes(): string
    {
        // NOTE: wrapped as a list of sheets — package() iterates $sheets as int-keyed rows,
        // so passing the sheet data directly ('name'/'xml' keys) yields "string + int".
        return MemXlsxExporter::package([[
            'name' => 'MEM Template',
            'xml' => self::sheet(),
        ]]);
    }

    public static function downloadName(): string
    {
        return 'mem-template.xlsx';
    }

    private static function sheet(): string
    {
        $header = array_keys(self::COLUMNS);
        $accountCodeIndex = array_search('Account Code', $header, true);

        $cells = '<row r="1">'.self::headerRow($header).'</row>';
        $data = '';
        $col = 0;

        foreach ($header as $index => $label) {
            $col++;
            $style = ($index === $accountCodeIndex) ? self::S_CODE : self::S_BODY;
            $data .= self::cell(self::colLetter($col), 2, $style, (string) self::EXAMPLE[$index]);
        }

        $cells .= '<row r="2">'.$data.'</row>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="A1:L2"/>'
            .'<sheetViews><sheetView workbookViewId="0">'
            .'<selection activeCell="A2" sqref="A2"/>'
            .'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .self::colsXml()
            .'<sheetData>'.$cells.'</sheetData>'
            .'</worksheet>';
    }

    private static function headerRow(array $header): string
    {
        $xml = '';
        $col = 0;

        foreach ($header as $label) {
            $col++;
            $xml .= self::cell(self::colLetter($col), 1, self::S_HEADER, $label);
        }

        return $xml;
    }

    private static function cell(string $column, int $row, int $style, string $value): string
    {
        $ref = $column.$row;

        if ($value === '') {
            return '<c r="'.$ref.'" s="'.$style.'"/>';
        }

        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr">'
            .'<is><t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1).'</t></is>'
            .'</c>';
    }

    private static function colLetter(int $index): string
    {
        $letter = '';

        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = (int) ($index / 26);
        }

        return $letter;
    }

    private static function colsXml(): string
    {
        $xml = '<cols>';
        $index = 0;

        foreach (self::COLUMNS as $width) {
            $index++;
            $xml .= '<col min="'.$index.'" max="'.$index.'" width="'.$width.'" customWidth="1"/>';
        }

        return $xml.'</cols>';
    }
}

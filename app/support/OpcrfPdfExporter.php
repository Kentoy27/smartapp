<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

/** Renders one OPCRF worksheet as a PDF attachment. */
class OpcrfPdfExporter
{
    public static function fromWorkbook(string $workbookBytes, string $partKeyword): string
    {
        $workbookPath = tempnam(sys_get_temp_dir(), 'opcrf-pdf-xlsx-');

        if ($workbookPath === false) {
            throw new RuntimeException('Unable to allocate temporary files for OPCRF PDF conversion.');
        }

        $pdfPath = tempnam(sys_get_temp_dir(), 'opcrf-pdf-output-');

        if ($pdfPath === false) {
            unlink($workbookPath);

            throw new RuntimeException('Unable to allocate temporary files for OPCRF PDF conversion.');
        }

        $spreadsheet = null;

        try {
            if (file_put_contents($workbookPath, $workbookBytes) === false) {
                throw new RuntimeException('Unable to stage the OPCRF workbook for PDF conversion.');
            }

            $spreadsheet = IOFactory::createReader('Xlsx')->load($workbookPath);
            $partIndex = self::partSheetIndex($spreadsheet, $partKeyword);

            $writer = IOFactory::createWriter($spreadsheet, 'Mpdf');
            $writer->setSheetIndex($partIndex);
            $writer->save($pdfPath);

            $pdfBytes = file_get_contents($pdfPath);

            if (! is_string($pdfBytes) || ! str_starts_with($pdfBytes, '%PDF-')) {
                throw new RuntimeException('The OPCRF PDF renderer returned an invalid document.');
            }

            return $pdfBytes;
        } finally {
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }

            if (is_file($workbookPath)) {
                unlink($workbookPath);
            }

            if (is_file($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

    private static function partSheetIndex(Spreadsheet $spreadsheet, string $partKeyword): int
    {
        $pattern = '/\b'.preg_quote($partKeyword, '/').'\b/iu';

        foreach ($spreadsheet->getWorksheetIterator() as $index => $sheet) {
            if (preg_match($pattern, $sheet->getTitle()) === 1) {
                return $index;
            }
        }

        throw new RuntimeException('The OPCRF workbook does not contain the requested Part I sheet.');
    }
}

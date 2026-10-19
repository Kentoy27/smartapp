<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Carries saved Part I cell values into the official full OPCRF workbook
 * without replacing the template's formatting, formulas, or other tabs.
 */
final class OpcrfWorkbookProgress
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    public static function mergePartOne(
        string $templateBytes,
        string $savedWorkbookPath,
        bool $replaceEmptyCells = false
    ): string
    {
        $stagedTemplate = tempnam(sys_get_temp_dir(), 'opcrf-full-');

        if ($stagedTemplate === false || file_put_contents($stagedTemplate, $templateBytes) === false) {
            throw new RuntimeException('The complete OPCRF template could not be prepared.');
        }

        try {
            $template = new OpcrfSpreadsheet($stagedTemplate);
            $saved = new OpcrfSpreadsheet($savedWorkbookPath);
            $savedCells = $saved->firstSheetCells();
            $templateCells = $template->firstSheetCells();
            $changedValues = [];

            if ($replaceEmptyCells) {
                $references = array_unique(array_merge(
                    array_keys($templateCells),
                    array_keys($savedCells)
                ));

                foreach ($references as $reference) {
                    $value = $savedCells[$reference] ?? '';

                    if (($templateCells[$reference] ?? '') !== $value) {
                        $changedValues[$reference] = $value;
                    }
                }
            } else {
                foreach ($savedCells as $reference => $value) {
                    if ($value !== '' && ($templateCells[$reference] ?? '') !== $value) {
                        $changedValues[$reference] = $value;
                    }
                }
            }

            if ($changedValues === []) {
                return $templateBytes;
            }

            $parts = (new PurePhpZipReader($stagedTemplate))->readAll();
            $templatePath = $template->firstSheetPath();
            $savedPath = $saved->firstSheetPath();
            $templateXml = $parts[$templatePath] ?? null;
            $savedArchivePath = $savedWorkbookPath;
            $savedParts = (new PurePhpZipReader($savedArchivePath))->readAll();
            $savedXml = $savedParts[$savedPath] ?? null;

            if (! is_string($templateXml) || ! is_string($savedXml)) {
                throw new RuntimeException('The saved OPCRF Part I worksheet could not be read.');
            }

            $templateDocument = self::loadWorksheet($templateXml);
            $savedDocument = self::loadWorksheet($savedXml);
            $savedCellsByReference = self::worksheetCells($savedDocument);
            $templateCellsByReference = self::worksheetCells($templateDocument);

            foreach ($changedValues as $reference => $value) {
                $sourceCell = $savedCellsByReference[$reference] ?? null;
                $targetCell = $templateCellsByReference[$reference] ?? null;

                if ($targetCell !== null && self::hasFormula($targetCell)) {
                    continue;
                }

                if ($sourceCell === null) {
                    if ($replaceEmptyCells && $targetCell !== null) {
                        self::clearCellValue($targetCell);
                    }

                    continue;
                }

                if (self::hasFormula($sourceCell)) {
                    continue;
                }

                $sourceType = $sourceCell->getAttribute('t');

                if (in_array($sourceType, ['s', 'inlineStr', 'str'], true)) {
                    $targetCell = self::prepareTargetCell($templateDocument, $reference, $targetCell, 'inlineStr');
                    $inlineString = $templateDocument->createElementNS(self::MAIN_NS, 'is');
                    $text = $templateDocument->createElementNS(self::MAIN_NS, 't');
                    $text->setAttributeNS(self::XML_NS, 'xml:space', 'preserve');
                    $text->appendChild($templateDocument->createTextNode($value));
                    $inlineString->appendChild($text);
                    $targetCell->appendChild($inlineString);

                    continue;
                }

                $rawValue = self::firstChildText($sourceCell, 'v');

                if ($rawValue === null || ! is_numeric($rawValue)) {
                    continue;
                }

                $targetCell = self::prepareTargetCell($templateDocument, $reference, $targetCell, $sourceType);
                $targetCell->appendChild($templateDocument->createElementNS(self::MAIN_NS, 'v', $rawValue));
            }

            $parts[$templatePath] = (string) $templateDocument->saveXML();

            return PurePhpZipWriter::create($parts);
        } finally {
            if (is_file($stagedTemplate)) {
                unlink($stagedTemplate);
            }
        }
    }

    /**
     * Restore the saved Part I fields on the regenerated template. When no
     * uploaded workbook exists, map the legacy saved form fields into the
     * first matching objective rows instead.
     *
     * @param  array{employee_name?: string, position?: string, review_period?: string, division_office?: string, objectives?: string, accomplishments?: string, self_rating?: int|float|string|null}  $submission
     */
    public static function mergeSubmissionData(
        string $templateBytes,
        array $submission,
        bool $includeSummaryFields
    ): string {
        $cellValues = [];
        $headerCells = [
            'employee_name' => 'F4',
            'position' => 'F5',
            'review_period' => 'F6',
            'division_office' => 'F7',
        ];

        foreach ($headerCells as $field => $reference) {
            $value = trim((string) ($submission[$field] ?? ''));

            if ($value !== '') {
                $cellValues[$reference] = $value;
            }
        }

        if ($includeSummaryFields) {
            $stagedTemplate = tempnam(sys_get_temp_dir(), 'opcrf-fields-');

            if ($stagedTemplate === false || file_put_contents($stagedTemplate, $templateBytes) === false) {
                throw new RuntimeException('The OPCRF template could not be prepared for saved Part I data.');
            }

            try {
                $workbook = new OpcrfSpreadsheet($stagedTemplate);
                $entries = [];

                foreach ($workbook->parts() as $part) {
                    array_push($entries, ...$part['entries']);
                }

                $objectiveRows = array_column($entries, 'row');
                $objectives = self::textLines((string) ($submission['objectives'] ?? ''));
                $accomplishments = self::textLines((string) ($submission['accomplishments'] ?? ''));

                self::mapLinesToRows($cellValues, $objectives, $objectiveRows, 'F');
                self::mapLinesToRows($cellValues, $accomplishments, $objectiveRows, 'S');

                $rating = $submission['self_rating'] ?? null;

                if (is_numeric($rating) && (float) $rating > 0 && $objectiveRows !== []) {
                    $cellValues['T'.$objectiveRows[0]] = (float) $rating;
                }
            } finally {
                if (is_file($stagedTemplate)) {
                    unlink($stagedTemplate);
                }
            }
        }

        return $cellValues === []
            ? $templateBytes
            : self::writeCellValues($templateBytes, $cellValues);
    }

    /**
     * @param  array<string, string|int|float>  $values
     */
    private static function writeCellValues(string $templateBytes, array $values): string
    {
        $stagedTemplate = tempnam(sys_get_temp_dir(), 'opcrf-values-');

        if ($stagedTemplate === false || file_put_contents($stagedTemplate, $templateBytes) === false) {
            throw new RuntimeException('The OPCRF template could not be prepared for saved Part I data.');
        }

        try {
            $workbook = new OpcrfSpreadsheet($stagedTemplate);
            $parts = (new PurePhpZipReader($stagedTemplate))->readAll();
            $sheetPath = $workbook->firstSheetPath();
            $sheetXml = $parts[$sheetPath] ?? null;

            if (! is_string($sheetXml)) {
                throw new RuntimeException('The OPCRF template Part I worksheet could not be read.');
            }

            $document = self::loadWorksheet($sheetXml);
            $existingCells = self::worksheetCells($document);

            foreach ($values as $reference => $value) {
                $cell = $existingCells[$reference] ?? null;

                if ($cell !== null && self::hasFormula($cell)) {
                    continue;
                }

                $type = is_int($value) || is_float($value) ? '' : 'inlineStr';
                $cell = self::prepareTargetCell($document, $reference, $cell, $type);

                if ($type === 'inlineStr') {
                    $inlineString = $document->createElementNS(self::MAIN_NS, 'is');
                    $text = $document->createElementNS(self::MAIN_NS, 't');
                    $text->setAttributeNS(self::XML_NS, 'xml:space', 'preserve');
                    $text->appendChild($document->createTextNode((string) $value));
                    $inlineString->appendChild($text);
                    $cell->appendChild($inlineString);
                } else {
                    $cell->appendChild($document->createElementNS(self::MAIN_NS, 'v', (string) $value));
                }
            }

            $parts[$sheetPath] = (string) $document->saveXML();

            return PurePhpZipWriter::create($parts);
        } finally {
            if (is_file($stagedTemplate)) {
                unlink($stagedTemplate);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private static function textLines(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\R/u', trim($value)) ?: []),
            fn (string $line): bool => $line !== ''
        ));
    }

    /**
     * @param  array<string, string|int|float>  $cellValues
     * @param  array<int, string>  $lines
     * @param  array<int, int>  $rows
     */
    private static function mapLinesToRows(
        array &$cellValues,
        array $lines,
        array $rows,
        string $column
    ): void {
        foreach ($lines as $index => $line) {
            if (! isset($rows[$index])) {
                if ($rows !== []) {
                    $lastRow = end($rows);
                    $lastReference = $column.$lastRow;
                    $cellValues[$lastReference] = trim((string) ($cellValues[$lastReference] ?? '')."\n".$line);
                }

                continue;
            }

            $cellValues[$column.$rows[$index]] = $line;
        }
    }

    private static function loadWorksheet(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        if (! $document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('An OPCRF worksheet could not be read.');
        }

        return $document;
    }

    /**
     * @return array<string, DOMElement>
     */
    private static function worksheetCells(DOMDocument $document): array
    {
        $cells = [];

        foreach ($document->getElementsByTagNameNS(self::MAIN_NS, 'c') as $cell) {
            if ($cell instanceof DOMElement) {
                $reference = $cell->getAttribute('r');

                if ($reference !== '') {
                    $cells[$reference] = $cell;
                }
            }
        }

        return $cells;
    }

    private static function hasFormula(DOMElement $cell): bool
    {
        foreach ($cell->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'f') {
                return true;
            }
        }

        return false;
    }

    private static function clearCellValue(DOMElement $cell): void
    {
        foreach (iterator_to_array($cell->childNodes) as $child) {
            $cell->removeChild($child);
        }

        $cell->removeAttribute('t');
    }

    private static function firstChildText(DOMElement $cell, string $name): ?string
    {
        foreach ($cell->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child->textContent;
            }
        }

        return null;
    }

    private static function prepareTargetCell(
        DOMDocument $document,
        string $reference,
        ?DOMElement $cell,
        string $type
    ): DOMElement {
        if ($cell === null) {
            $cell = self::insertCell($document, $reference);
        }

        foreach (iterator_to_array($cell->childNodes) as $child) {
            $cell->removeChild($child);
        }

        if ($type === '' || $type === 'n') {
            $cell->removeAttribute('t');
        } else {
            $cell->setAttribute('t', $type);
        }

        return $cell;
    }

    private static function insertCell(DOMDocument $document, string $reference): DOMElement
    {
        if (! preg_match('/^([A-Z]+)(\d+)$/', $reference, $matches)) {
            throw new RuntimeException('The saved OPCRF contains an invalid cell reference.');
        }

        $sheetData = $document->getElementsByTagNameNS(self::MAIN_NS, 'sheetData')->item(0);

        if (! $sheetData instanceof DOMElement) {
            throw new RuntimeException('The OPCRF template has no worksheet data section.');
        }

        $rowNumber = (int) $matches[2];
        $row = null;

        foreach ($sheetData->getElementsByTagNameNS(self::MAIN_NS, 'row') as $candidate) {
            if ($candidate instanceof DOMElement && (int) $candidate->getAttribute('r') === $rowNumber) {
                $row = $candidate;

                break;
            }
        }

        if ($row === null) {
            $row = $document->createElementNS(self::MAIN_NS, 'row');
            $row->setAttribute('r', (string) $rowNumber);

            $insertBefore = null;
            foreach ($sheetData->childNodes as $candidate) {
                if ($candidate instanceof DOMElement
                    && $candidate->localName === 'row'
                    && (int) $candidate->getAttribute('r') > $rowNumber) {
                    $insertBefore = $candidate;

                    break;
                }
            }

            $sheetData->insertBefore($row, $insertBefore);
        }

        $cell = $document->createElementNS(self::MAIN_NS, 'c');
        $cell->setAttribute('r', $reference);
        $columnNumber = self::columnNumber($matches[1]);
        $insertBefore = null;

        foreach ($row->childNodes as $candidate) {
            if (! $candidate instanceof DOMElement || $candidate->localName !== 'c') {
                continue;
            }

            if (preg_match('/^([A-Z]+)/', $candidate->getAttribute('r'), $candidateColumn)
                && self::columnNumber($candidateColumn[1]) > $columnNumber) {
                $insertBefore = $candidate;

                break;
            }
        }

        $row->insertBefore($cell, $insertBefore);

        return $cell;
    }

    private static function columnNumber(string $column): int
    {
        $number = 0;

        foreach (str_split($column) as $letter) {
            $number = $number * 26 + ord($letter) - ord('A') + 1;
        }

        return $number;
    }
}

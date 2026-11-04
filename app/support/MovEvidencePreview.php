<?php

namespace App\Support;

use App\Models\UserMov;
use DOMDocument;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

final class MovEvidencePreview
{
    private const MAX_ROWS = 500;

    private const MAX_COLUMNS = 40;

    /**
     * The safe, display-ready content for an uploaded MOV document.
     *
     * @return array{kind: string, file_name: string, sheets?: array<int, array{name: string, rows: array<int, array<int, string|null>>, truncated: bool}>, paragraphs?: array<int, string>, tables?: array<int, array<int, array<int, string>>>, slides?: array<int, array{name: string, paragraphs: array<int, string>}>, text?: string, message?: string}
     */
    public static function for(UserMov $mov): array
    {
        $extension = strtolower(pathinfo($mov->downloadName(), PATHINFO_EXTENSION));
        $path = \Illuminate\Support\Facades\Storage::disk(config('mov.disk', 'local'))
            ->path($mov->stored_path);

        return match ($extension) {
            'xlsx', 'xls' => self::spreadsheet($path, $mov->downloadName()),
            'docx' => self::wordDocument($path, $mov->downloadName()),
            'pptx' => self::presentation($path, $mov->downloadName()),
            'csv' => self::csv($path, $mov->downloadName()),
            'txt' => self::text($path, $mov->downloadName()),
            'doc', 'ppt' => self::unsupported($mov->downloadName(), 'This legacy Office format cannot be rendered safely in the browser.'),
            default => self::unsupported($mov->downloadName(), 'This file type does not have an in-browser preview.'),
        };
    }

    private static function spreadsheet(string $path, string $fileName): array
    {
        if (! class_exists(\ZipArchive::class) && strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'xlsx') {
            return self::unsupported($fileName, 'Spreadsheet preview is unavailable on this server.');
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (\PhpOffice\PhpSpreadsheet\Exception|RuntimeException $exception) {
            return self::unsupported($fileName, 'This spreadsheet could not be opened for preview: '.$exception->getMessage());
        }

        $sheets = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $rowCount = min($worksheet->getHighestDataRow(), self::MAX_ROWS);
            $columnCount = min(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($worksheet->getHighestDataColumn()),
                self::MAX_COLUMNS
            );
            $rows = [];

            for ($row = 1; $row <= $rowCount; $row++) {
                $cells = [];

                for ($column = 1; $column <= $columnCount; $column++) {
                    $value = $worksheet->getCell([$column, $row])->getFormattedValue();
                    $cells[] = is_scalar($value) ? (string) $value : '';
                }

                $rows[] = $cells;
            }

            $sheets[] = [
                'name' => $worksheet->getTitle(),
                'rows' => $rows,
                'truncated' => $worksheet->getHighestDataRow() > self::MAX_ROWS
                    || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($worksheet->getHighestDataColumn()) > self::MAX_COLUMNS,
            ];
        }

        $spreadsheet->disconnectWorksheets();

        return ['kind' => 'spreadsheet', 'file_name' => $fileName, 'sheets' => $sheets];
    }

    private static function wordDocument(string $path, string $fileName): array
    {
        try {
            $parts = self::packageParts($path);
        } catch (RuntimeException) {
            return self::unsupported($fileName, 'The Word document package could not be opened.');
        }

        $xml = $parts['word/document.xml'] ?? null;

        if ($xml === null) {
            return self::unsupported($fileName, 'The Word document does not contain a readable document body.');
        }

        $document = self::xmlDocument($xml);

        if ($document === null) {
            return self::unsupported($fileName, 'The Word document content could not be read.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $body = $xpath->query('/w:document/w:body/*');
        $paragraphs = [];
        $tables = [];

        if ($body !== false) {
            foreach ($body as $block) {
                if ($block->localName === 'p') {
                    $paragraph = self::wordParagraph($xpath, $block);

                    if ($paragraph !== '') {
                        $paragraphs[] = $paragraph;
                    }

                    continue;
                }

                if ($block->localName !== 'tbl') {
                    continue;
                }

                $tableRows = [];
                $rows = $xpath->query('./w:tr', $block);

                if ($rows !== false) {
                    foreach ($rows as $row) {
                        $cells = [];
                        $columns = $xpath->query('./w:tc', $row);

                        if ($columns !== false) {
                            foreach ($columns as $cell) {
                                $cells[] = self::wordParagraphs($xpath, $cell);
                            }
                        }

                        $tableRows[] = $cells;
                    }
                }

                $tables[] = $tableRows;
            }
        }

        return ['kind' => 'word', 'file_name' => $fileName, 'paragraphs' => $paragraphs, 'tables' => $tables];
    }

    private static function presentation(string $path, string $fileName): array
    {
        try {
            $parts = self::packageParts($path);
        } catch (RuntimeException) {
            return self::unsupported($fileName, 'The presentation package could not be opened.');
        }

        $slideNames = array_filter(
            array_keys($parts),
            static fn (string $name): bool => preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1
        );
        usort($slideNames, 'strnatcmp');
        $slides = [];

        foreach ($slideNames as $index => $slideName) {
            $document = self::xmlDocument($parts[$slideName]);

            if ($document === null) {
                return self::unsupported($fileName, 'A slide in this presentation could not be read.');
            }

            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $paragraphs = [];
            $nodes = $xpath->query('//a:p');

            if ($nodes !== false) {
                foreach ($nodes as $node) {
                    $text = trim($node->textContent);

                    if ($text !== '') {
                        $paragraphs[] = $text;
                    }
                }
            }

            $slides[] = ['name' => 'Slide '.($index + 1), 'paragraphs' => $paragraphs];
        }

        if ($slides === []) {
            return self::unsupported($fileName, 'The presentation does not contain readable slides.');
        }

        return ['kind' => 'presentation', 'file_name' => $fileName, 'slides' => $slides];
    }

    private static function csv(string $path, string $fileName): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return self::unsupported($fileName, 'The CSV file could not be opened for preview.');
        }

        $rows = [];

        while (($row = fgetcsv($handle)) !== false && count($rows) < self::MAX_ROWS) {
            $rows[] = array_slice(array_map(
                static fn ($value): string => (string) ($value ?? ''),
                $row
            ), 0, self::MAX_COLUMNS);
        }

        $truncated = ! feof($handle);
        fclose($handle);

        return [
            'kind' => 'spreadsheet',
            'file_name' => $fileName,
            'sheets' => [['name' => 'CSV', 'rows' => $rows, 'truncated' => $truncated]],
        ];
    }

    private static function text(string $path, string $fileName): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return self::unsupported($fileName, 'The text file could not be opened for preview.');
        }

        return ['kind' => 'text', 'file_name' => $fileName, 'text' => mb_convert_encoding($contents, 'UTF-8', 'UTF-8')];
    }

    /**
     * @return array<string, string>
     */
    private static function packageParts(string $path): array
    {
        return (new PurePhpZipReader($path))->readAll();
    }

    private static function xmlDocument(string $xml): ?DOMDocument
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = @$document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        return $loaded ? $document : null;
    }

    private static function wordParagraph(DOMXPath $xpath, \DOMNode $paragraph): string
    {
        $text = '';
        $nodes = $xpath->query('.//w:t | .//w:tab | .//w:br', $paragraph);

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $text .= match ($node->localName) {
                    'tab' => "\t",
                    'br' => "\n",
                    default => $node->textContent,
                };
            }
        }

        return trim($text);
    }

    private static function wordParagraphs(DOMXPath $xpath, \DOMNode $cell): string
    {
        $paragraphs = [];
        $nodes = $xpath->query('./w:p', $cell);

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $text = self::wordParagraph($xpath, $node);

                if ($text !== '') {
                    $paragraphs[] = $text;
                }
            }
        }

        return implode("\n", $paragraphs);
    }

    private static function unsupported(string $fileName, string $message): array
    {
        return ['kind' => 'unsupported', 'file_name' => $fileName, 'message' => $message];
    }
}

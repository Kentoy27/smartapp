<?php

namespace App\Support;

use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Reads the FY AIP Word template into the editable, row-based AIP form.
 */
final class AipTemplateImporter
{
    private const MAX_DOCUMENT_XML_BYTES = 10_000_000;

    private const MAX_ROWS = 500;

    /**
     * The online form starts with the same five pillar sections as the
     * supplied Word template, including its initial SO 1.1 placeholder.
     *
     * @return array<int, array<string, string>>
     */
    public static function blankRows(): array
    {
        return array_map(
            static fn (string $pillar, int $index): array => [
                'pillar' => $pillar,
                'strategic_objective' => $index === 0 ? 'SO 1.1' : '',
                'strategy' => '',
                'ppa' => '',
                'resources' => '',
                'budget' => '',
                'persons_responsible' => '',
                'timeline' => '',
            ],
            config('aip.pillars', []),
            array_keys(config('aip.pillars', [])),
        );
    }

    /**
     * @return array{detected_year: int|null, rows: array<int, array<string, string>>}
     */
    public static function import(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('The uploaded AIP template could not be found.');
        }

        $fileSize = filesize($path);

        if ($fileSize === false || $fileSize > ((int) config('aip.max_kb', 10240) * 1024)) {
            throw new RuntimeException('The AIP template is too large to import.');
        }

        $parts = (new PurePhpZipReader($path))->part('word/document.xml', self::MAX_DOCUMENT_XML_BYTES);

        if ($parts === null || $parts === '') {
            throw new RuntimeException('The uploaded Word file does not contain a readable document.');
        }

        $document = self::xmlDocument($parts);

        if ($document === null) {
            throw new RuntimeException('The uploaded Word document could not be read.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $body = $xpath->query('/w:document/w:body/*');
        $plainText = '';
        $tables = [];

        if ($body !== false) {
            foreach ($body as $block) {
                if ($block->localName === 'tbl') {
                    $tables[] = $block;
                } elseif ($block->localName === 'p') {
                    $plainText .= ' '.self::wordText($xpath, $block);
                }
            }
        }

        $targetTable = null;
        $headerIndex = null;

        foreach ($tables as $table) {
            $rows = $xpath->query('./w:tr', $table);

            if ($rows === false) {
                continue;
            }

            foreach ($rows as $index => $row) {
                $cells = self::rowCells($xpath, $row);
                $header = mb_strtolower(implode(' ', $cells));

                if (str_contains($header, 'strategic objective')
                    && str_contains($header, 'updated programs')
                    && str_contains($header, 'indicative timeline')) {
                    $targetTable = $table;
                    $headerIndex = $index;

                    break 2;
                }
            }
        }

        if ($targetTable === null || $headerIndex === null) {
            throw new RuntimeException('The file does not contain the expected AIP table columns.');
        }

        $rows = $xpath->query('./w:tr', $targetTable);
        $pillar = '';
        $objective = '';
        $entries = [];

        if ($rows !== false) {
            foreach ($rows as $index => $row) {
                if ($index <= $headerIndex) {
                    continue;
                }

                $cells = array_pad(array_slice(self::rowCells($xpath, $row), 0, 7), 7, '');
                $first = trim($cells[0]);

                if (preg_match('/^Pillar\s+\d+\s*:/iu', $first) === 1
                    || mb_strtoupper($first) === 'ENABLING MECHANISMS') {
                    $pillar = $first;
                    $objective = '';

                    continue;
                }

                if ($first !== '') {
                    $objective = $first;
                }

                $remaining = array_slice($cells, 1);
                $hasContent = $first !== '' || count(array_filter(
                    $remaining,
                    static fn (string $cell): bool => trim($cell) !== '',
                )) > 0;

                if (! $hasContent && $pillar === '') {
                    continue;
                }

                $entries[] = [
                    'pillar' => $pillar,
                    'strategic_objective' => $objective,
                    'strategy' => $cells[1],
                    'ppa' => $cells[2],
                    'resources' => $cells[3],
                    'budget' => $cells[4],
                    'persons_responsible' => $cells[5],
                    'timeline' => $cells[6],
                ];

                if (count($entries) > self::MAX_ROWS) {
                    throw new RuntimeException('The AIP document has more than 500 data rows. Split it into a smaller number of rows before importing.');
                }
            }
        }

        preg_match('/\bFY\s*(20\d{2})\b/i', $plainText, $yearMatch);

        return [
            'detected_year' => isset($yearMatch[1]) ? (int) $yearMatch[1] : null,
            'rows' => $entries,
        ];
    }

    private static function xmlDocument(string $xml): ?DOMDocument
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        return $loaded ? $document : null;
    }

    /**
     * @return array<int, string>
     */
    private static function rowCells(DOMXPath $xpath, DOMNode $row): array
    {
        $cells = $xpath->query('./w:tc', $row);

        if ($cells === false) {
            return [];
        }

        $values = [];

        foreach ($cells as $cell) {
            $values[] = self::wordText($xpath, $cell);
        }

        return $values;
    }

    private static function wordText(DOMXPath $xpath, DOMNode $node): string
    {
        $content = '';
        $textNodes = $xpath->query('.//w:t | .//w:tab | .//w:br', $node);

        if ($textNodes !== false) {
            foreach ($textNodes as $textNode) {
                $content .= match ($textNode->localName) {
                    'tab' => "\t",
                    'br' => "\n",
                    default => $textNode->textContent,
                };
            }
        }

        return trim(preg_replace('/[ \t]+/u', ' ', $content) ?? $content);
    }
}

<?php

namespace App\Support;

use RuntimeException;

/**
 * Reads a bulk-import CSV into associative rows. Shared by the WFP lookup and
 * activity-template importers so header detection, BOM handling, positional
 * fallback and the row cap behave identically for both.
 *
 * A header row is recognised when at least two of its cells match a known
 * column label (after lower-casing and stripping punctuation/spaces); a file
 * without one is read positionally in the order the column spec declares.
 * Nothing here writes or validates values — that is the importer's job.
 */
class WfpCsvReader
{
    /**
     * @param  array<string, array<string, mixed>>  $columns  field => spec (label, aliases, …)
     * @return array<int, array{line: int, data: array<string, mixed>}>
     */
    public static function read(string $path, array $columns, int $maxRows): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        $rows = [];
        $headerFields = null;
        $line = 0;
        $dataRows = 0;

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;

            // The first cell of the first row may carry a UTF-8 BOM.
            if ($line === 1 && isset($cells[0])) {
                $cells[0] = self::stripBom((string) $cells[0]);
            }

            if (self::isBlankRow($cells)) {
                continue;
            }

            if ($headerFields === null) {
                $detected = self::detectHeader($cells, $columns);

                if ($detected !== null) {
                    $headerFields = $detected;

                    continue; // the header row is not data
                }

                // No recognisable header: the file is positional.
                $headerFields = array_keys($columns);
            }

            $dataRows++;

            if ($dataRows > $maxRows) {
                fclose($handle);

                throw new RuntimeException(
                    "The file has more than {$maxRows} data rows. Split it into smaller files."
                );
            }

            $assoc = [];

            foreach ($headerFields as $index => $field) {
                if ($field !== null && array_key_exists($index, $cells)) {
                    $assoc[$field] = $cells[$index];
                }
            }

            $rows[] = ['line' => $line, 'data' => $assoc];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Parse a Yes/No-style status value.
     *
     * @return array{valid: bool, value: bool}  valid=false when the value is
     *         neither a recognised spelling nor empty (empty means "active").
     */
    public static function parseBoolean(string $value): array
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || in_array($normalized, ['yes', 'y', '1', 'true', 'active', 'enabled', 'on'], true)) {
            return ['valid' => true, 'value' => true];
        }

        if (in_array($normalized, ['no', 'n', '0', 'false', 'inactive', 'disabled', 'off'], true)) {
            return ['valid' => true, 'value' => false];
        }

        return ['valid' => false, 'value' => true];
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, array<string, mixed>>  $columns
     * @return array<int, string|null>|null  position => field, or null if this is not a header row
     */
    private static function detectHeader(array $cells, array $columns): ?array
    {
        $fields = [];
        $matches = 0;

        foreach ($cells as $index => $cell) {
            $field = self::fieldFor(self::normalizeHeader((string) $cell), $columns);
            $fields[$index] = $field;

            if ($field !== null) {
                $matches++;
            }
        }

        // Two or more recognised column names: a real header. A single stray
        // match is far more likely to be a data value than a header row.
        return $matches >= 2 ? $fields : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $columns
     */
    private static function fieldFor(string $normalized, array $columns): ?string
    {
        if ($normalized === '') {
            return null;
        }

        foreach ($columns as $field => $spec) {
            foreach ((array) ($spec['aliases'] ?? []) as $alias) {
                if ($normalized === $alias) {
                    return $field;
                }
            }
        }

        return null;
    }

    public static function normalizeHeader(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private static function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}

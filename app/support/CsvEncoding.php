<?php

namespace App\Support;

/**
 * Normalises CSV cell values to UTF-8 at the import boundary.
 *
 * Spreadsheet software on Windows commonly saves "CSV (Comma delimited)" in
 * the machine's ANSI code page (Windows-1252). Those bytes are not valid
 * UTF-8, and a single one reaching a Livewire component's public state makes
 * the update response fail with "Malformed UTF-8 characters, possibly
 * incorrectly encoded" before the preview can even be shown. They would also
 * be rejected by the database's utf8mb4 columns on commit.
 */
final class CsvEncoding
{
    /**
     * Return the value as UTF-8, converting Windows-1252 bytes when needed.
     *
     * Valid UTF-8 is returned untouched: the conversion is only a fallback for
     * files that carry bytes a UTF-8 reader cannot represent.
     */
    public static function toUtf8(?string $value): ?string
    {
        if ($value === null || $value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /**
     * @param  array<int, string|null>  $cells
     * @return array<int, string|null>
     */
    public static function cellsToUtf8(array $cells): array
    {
        return array_map(static fn (?string $cell): ?string => self::toUtf8($cell), $cells);
    }
}

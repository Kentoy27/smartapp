<?php

namespace App\Support;

use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Parses and validates bulk Monthly Expenditure Matrix rows (a CSV file or an
 * array) — the MEM mirror of AccountClassificationImporter.
 *
 * Two-phase, exactly like the Account Classifications importer: `analyze*()`
 * validates every row and writes nothing, returning the valid rows plus a
 * per-row error report; `commit()` then inserts the validated rows inside a
 * transaction. `calculated_total` is NEVER taken from the file — it is
 * recomputed server-side from quantity × unit cost.
 *
 * The target matrix fixes the month and year, so a `month`/`year` column in the
 * file is validated against it: a line can never be written into the wrong
 * month.
 */
class MemImporter
{
    /**
     * Header spellings the importer recognises, keyed by field. Compared after
     * normalisation (lower-cased, punctuation/space removed).
     *
     * @var array<string, array<int, string>>
     */
    public const HEADER_ALIASES = [
        'section' => ['section', 'category'],
        'objective' => ['objective', 'objectives'],
        'activity_description' => ['activitydescription', 'activity', 'activities', 'programactivity', 'programprojectactivity', 'programsprojectsactivities', 'projectactivityexpenditure', 'projectactivity', 'description'],
        'resource_kind' => ['resourcekind', 'resource', 'kind'],
        'quantity' => ['quantity', 'qty'],
        'unit' => ['unit', 'units', 'uom'],
        'unit_cost' => ['unitcost', 'unitprice', 'price', 'costperunit'],
        'calculated_total' => ['calculatedtotal', 'total', 'amount', 'totalcost', 'totalestimatedcost'],
        'account_title' => ['accounttitle', 'title'],
        'account_code' => ['accountcode', 'objectcode', 'code', 'uacscode'],
        'funding_source' => ['fundingsource', 'sourceoffund', 'sourceoffunds', 'fund'],
        'organizational_classification' => ['organizationalclassification', 'orgclassification', 'organizationaloutput'],
        'activity_movs' => ['activitymovs', 'movs', 'meansofverification', 'mov'],
        'month' => ['month'],
        'year' => ['year', 'cy'],
    ];

    /** Header fields strong enough to prove the first row is a header. */
    private const STRONG_HEADERS = ['activity_description', 'section', 'quantity', 'unit_cost'];

    /** Column order assumed when the file has no recognisable header row. */
    private const POSITIONAL_FIELDS = [
        'section',
        'objective',
        'activity_description',
        'resource_kind',
        'quantity',
        'unit',
        'unit_cost',
        'account_title',
        'account_code',
        'funding_source',
        'organizational_classification',
        'activity_movs',
    ];

    /** The columns carried onto a stored line (month/year are the matrix's). */
    public const DATA_FIELDS = [
        'section',
        'objective',
        'activity_description',
        'resource_kind',
        'quantity',
        'unit',
        'unit_cost',
        'account_title',
        'account_code',
        'funding_source',
        'organizational_classification',
        'activity_movs',
    ];

    /**
     * @var array<string, string>
     */
    private const REQUIRED_LABELS = [
        'section' => 'Section',
        'activity_description' => 'Activity Description',
    ];

    /**
     * Read and validate a CSV file against its target matrix. Nothing is written.
     *
     * @return array{rows: array<int, array<string, mixed>>, valid: array<int, array<string, mixed>>, validCount: int, invalidCount: int}
     */
    public static function analyzeCsv(string $path, ?MonthlyExpenditureMatrix $matrix = null): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        $raw = [];
        $headerFields = null;
        $line = 0;

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;

            if ($line === 1 && isset($cells[0])) {
                $cells[0] = self::stripBom((string) $cells[0]);
            }

            if (self::isBlankRow($cells)) {
                continue;
            }

            if ($headerFields === null) {
                $detected = self::detectHeader($cells);

                if ($detected !== null) {
                    $headerFields = $detected;

                    continue;
                }

                $headerFields = self::POSITIONAL_FIELDS;
            }

            $assoc = [];

            foreach ($headerFields as $index => $field) {
                if ($field !== null && array_key_exists($index, $cells)) {
                    $assoc[$field] = $cells[$index];
                }
            }

            $raw[] = ['line' => $line, 'data' => $assoc];
        }

        fclose($handle);

        return self::analyzeRows($raw, $matrix);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, valid: array<int, array<string, mixed>>, validCount: int, invalidCount: int}
     */
    public static function analyzeRows(array $rows, ?MonthlyExpenditureMatrix $matrix = null): array
    {
        $analysed = [];
        $validCount = 0;
        $sections = array_keys((array) config('mem.sections', ['operations' => 'Operations', 'project' => 'Project']));

        foreach ($rows as $row) {
            $line = (int) ($row['line'] ?? 0);
            $data = self::normalizeRow((array) ($row['data'] ?? []));

            $values = [
                'section' => self::normalizeSection((string) ($data['section'] ?? '')),
                'objective' => trim((string) ($data['objective'] ?? '')),
                'activity_description' => trim((string) ($data['activity_description'] ?? '')),
                'resource_kind' => trim((string) ($data['resource_kind'] ?? '')),
                'quantity' => trim((string) ($data['quantity'] ?? '')),
                'unit' => trim((string) ($data['unit'] ?? '')),
                'unit_cost' => trim((string) ($data['unit_cost'] ?? '')),
                'account_title' => trim((string) ($data['account_title'] ?? '')),
                'account_code' => trim((string) ($data['account_code'] ?? '')),
                'funding_source' => trim((string) ($data['funding_source'] ?? '')),
                'organizational_classification' => trim((string) ($data['organizational_classification'] ?? '')),
                'activity_movs' => trim((string) ($data['activity_movs'] ?? '')),
            ];

            $errors = self::rowErrors($values, $data, $matrix, $sections);

            $valid = $errors === [];
            $validCount += $valid ? 1 : 0;

            // Numbers become floats; blanks become null; the total is left to
            // the server to recompute.
            $stored = [
                'section' => $values['section'],
                'objective' => $values['objective'] !== '' ? $values['objective'] : null,
                'activity_description' => $values['activity_description'],
                'resource_kind' => $values['resource_kind'] !== '' ? $values['resource_kind'] : null,
                'quantity' => $values['quantity'] !== '' ? (float) $values['quantity'] : null,
                'unit' => $values['unit'] !== '' ? $values['unit'] : null,
                'unit_cost' => $values['unit_cost'] !== '' ? (float) $values['unit_cost'] : null,
                'account_title' => $values['account_title'] !== '' ? $values['account_title'] : null,
                'account_code' => $values['account_code'] !== '' ? $values['account_code'] : null,
                'funding_source' => $values['funding_source'] !== '' ? $values['funding_source'] : null,
                'organizational_classification' => $values['organizational_classification'] !== '' ? $values['organizational_classification'] : null,
                'activity_movs' => $values['activity_movs'] !== '' ? $values['activity_movs'] : null,
            ];

            $analysed[] = [
                'line' => $line,
                'data' => $stored,
                'valid' => $valid,
                'errors' => $errors,
            ];
        }

        $valid = array_values(array_map(
            static fn (array $row) => $row['data'],
            array_filter($analysed, static fn (array $row) => $row['valid'])
        ));

        return [
            'rows' => $analysed,
            'valid' => $valid,
            'validCount' => $validCount,
            'invalidCount' => count($analysed) - $validCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $data  the raw normalised row (for month/year)
     * @param  array<int, string>  $sections
     * @return array<int, string>
     */
    private static function rowErrors(array $values, array $data, ?MonthlyExpenditureMatrix $matrix, array $sections): array
    {
        $errors = [];

        if ($values['section'] === '' || ! in_array($values['section'], $sections, true)) {
            $errors[] = 'Section must be one of: '.implode(', ', $sections);
        }

        if ($values['activity_description'] === '') {
            $errors[] = 'Activity Description is required';
        }

        foreach (['activity_description' => 2000, 'objective' => 2000, 'resource_kind' => 255, 'unit' => 64, 'account_title' => 255, 'account_code' => 64, 'funding_source' => 255, 'organizational_classification' => 255, 'activity_movs' => 2000] as $field => $limit) {
            if (mb_strlen($values[$field]) > $limit) {
                $errors[] = self::label($field)." may not be longer than {$limit} characters";
            }
        }

        foreach (['quantity' => 'Quantity', 'unit_cost' => 'Unit Cost'] as $field => $label) {
            if ($values[$field] !== '' && ! is_numeric($values[$field])) {
                $errors[] = $label.' must be a number';
            } elseif ($values[$field] !== '' && (float) $values[$field] < 0) {
                $errors[] = $label.' may not be negative';
            }
        }

        // The target matrix fixes the period; a month/year column must agree.
        if ($matrix !== null) {
            $month = trim((string) ($data['month'] ?? ''));

            if ($month !== '') {
                $parsed = self::parseMonth($month);

                if ($parsed === null) {
                    $errors[] = 'Month is not a recognised month';
                } elseif ($parsed !== (int) $matrix->month) {
                    $errors[] = 'Month does not match this matrix ('.($matrix->monthLabel()).')';
                }
            }

            $year = trim((string) ($data['year'] ?? ''));

            if ($year !== '') {
                $digits = preg_replace('/\D/', '', $year) ?? '';

                if ($digits === '' || (int) $digits !== (int) $matrix->year) {
                    $errors[] = 'Year does not match this matrix ('.$matrix->year.')';
                }
            }
        }

        return $errors;
    }

    /**
     * Insert the validated rows at the end of the matrix. The total is
     * recomputed here (never trusted from the file).
     *
     * @param  array<int, array<string, mixed>>  $validRows
     * @return array{imported: int}
     */
    public static function commit(array $validRows, MonthlyExpenditureMatrix $matrix, ?User $user = null): array
    {
        return DB::transaction(function () use ($validRows, $matrix, $user) {
            $position = (int) ($matrix->items()->max('position') ?? 0);
            $imported = 0;

            foreach ($validRows as $row) {
                $position++;

                $item = new MonthlyExpenditureItem([
                    'matrix_id' => $matrix->id,
                    'position' => $position,
                    ...array_intersect_key($row, array_flip(self::DATA_FIELDS)),
                ]);
                $item->syncTotal();
                $item->save();

                $imported++;
            }

            $matrix->updated_by = $user?->id;
            $matrix->save();
            $matrix->bumpVersion();

            return ['imported' => $imported];
        });
    }

    /**
     * Map a header row's cells to fields, or null when the row does not look
     * like a header (fewer than two strong headings). Public so the workbook
     * importer detects its monthly sheets' headers the same way a CSV is.
     *
     * @param  array<int, mixed>  $cells  a row's cells, keyed by column index
     * @return array<int, string|null>|null
     */
    public static function detectHeader(array $cells): ?array
    {
        $fields = self::headerFields($cells);
        $matches = 0;

        foreach ($fields as $field) {
            if (in_array($field, self::STRONG_HEADERS, true)) {
                $matches++;
            }
        }

        return $matches >= 2 ? $fields : null;
    }

    /**
     * The field every cell maps to, keyed by the cell's own index — the raw
     * mapping behind {@see detectHeader()}, without its "is this a header at
     * all" gate. A cell that names no known field maps to null.
     *
     * Exposed because a real workbook may spread its header over MORE THAN ONE
     * row (the parent labels on one row, their sub-labels — "Kind",
     * "Quantity" — on the next), so the caller has to merge the whole header
     * block instead of accepting a single row that happens to look right.
     *
     * @param  array<int, string>  $cells
     * @return array<int, string|null>
     */
    public static function headerFields(array $cells): array
    {
        $fields = [];

        foreach ($cells as $index => $cell) {
            $fields[$index] = self::fieldFor(self::normalizeHeader((string) $cell));
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $data = [];

        foreach ($row as $key => $value) {
            $field = self::fieldFor(self::normalizeHeader((string) $key));

            if ($field !== null && ! array_key_exists($field, $data)) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    public static function fieldFor(string $normalized): ?string
    {
        foreach (self::HEADER_ALIASES as $field => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $field;
            }
        }

        return null;
    }

    public static function normalizeHeader(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }

    public static function normalizeSection(string $value): string
    {
        return preg_replace('/[^a-z]/', '', strtolower(trim($value))) ?? '';
    }

    public static function parseMonth(string $value): ?int
    {
        if (is_numeric($value)) {
            $number = (int) $value;

            return $number >= 1 && $number <= 12 ? $number : null;
        }

        $needle = self::normalizeHeader($value);

        foreach ((array) config('mem.months', []) as $number => $name) {
            if (str_starts_with(self::normalizeHeader((string) $name), $needle) && $needle !== '') {
                return (int) $number;
            }
        }

        return null;
    }

    private static function label(string $field): string
    {
        return match ($field) {
            'activity_description' => 'Activity Description',
            'resource_kind' => 'Resource Kind',
            'account_title' => 'Account Title',
            'account_code' => 'Account Code',
            'funding_source' => 'Funding Source',
            'organizational_classification' => 'Organizational Classification',
            'activity_movs' => 'Activity MOVs',
            default => ucfirst($field),
        };
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

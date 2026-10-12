<?php

namespace App\Support;

use App\Models\AccountClassification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Parses and validates bulk Account Classification data (CSV or an array of
 * rows) so the admin UI and the API enforce exactly the same rules.
 *
 * The flow is deliberately two-phase: `analyze*()` validates every row and
 * writes nothing, returning the valid rows plus a per-row error report;
 * `commit()` then inserts the validated rows. Nothing is written until the
 * whole file has been read and checked, and an invalid row can never reach the
 * table. Codes are only trimmed by the importer — never reformatted — so what
 * the file carried is what gets stored.
 */
class AccountClassificationImporter
{
    /** Upload size ceiling (KB) for an import file. */
    public const MAX_SIZE_KB = 5120;

    /** Accepted upload extensions. */
    public const EXTENSIONS = 'csv,txt';

    /**
     * Header spellings the importer recognises, keyed by field. Compared after
     * normalisation (lower-cased, punctuation/space removed).
     *
     * @var array<string, array<int, string>>
     */
    private const HEADER_ALIASES = [
        'account_title' => ['accounttitle', 'title', 'account'],
        'rca_code' => ['rcacode', 'rca'],
        'uacs_sub_object_code' => ['uacssubobjectcode', 'subobjectcode', 'subobject'],
        'uacs_object_code' => ['uacsobjectcode', 'objectcode', 'object'],
        'covering_ppas' => ['coveringppas', 'ppas', 'covering'],
    ];

    /** Header fields that are strong enough to prove the first row is a header. */
    private const STRONG_HEADERS = ['account_title', 'rca_code', 'uacs_sub_object_code', 'uacs_object_code'];

    /** Column order assumed when the file has no recognisable header row. */
    private const POSITIONAL_FIELDS = [
        'account_title',
        'rca_code',
        'uacs_sub_object_code',
        'uacs_object_code',
        'covering_ppas',
    ];

    /**
     * @var array<string, string>
     */
    private const REQUIRED_LABELS = [
        'account_title' => 'Account Title',
        'rca_code' => 'RCA Code',
        'uacs_sub_object_code' => 'UACS Sub-Object Code',
        'uacs_object_code' => 'UACS Object Code',
    ];

    /**
     * Read and validate a CSV file. Nothing is written here.
     *
     * @return array{rows: array<int, array<string, mixed>>, valid: array<int, array<string, mixed>>, validCount: int, invalidCount: int}
     */
    public static function analyzeCsv(string $path): array
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

            // The first cell of the first row may carry a UTF-8 BOM.
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

                    continue; // the header row is not data
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

        return self::analyzeRows($raw);
    }

    /**
     * Validate rows already in associative form (the API's `rows` array). Keys
     * may be field names or header labels; both are normalised.
     *
     * @param  array<int, array<string, mixed>>  $rows  each: ['line' => int, 'data' => array]
     * @return array{rows: array<int, array<string, mixed>>, valid: array<int, array<string, mixed>>, validCount: int, invalidCount: int}
     */
    public static function analyzeRows(array $rows): array
    {
        $analysed = [];
        $seen = [];
        $validCount = 0;

        foreach ($rows as $row) {
            $line = (int) ($row['line'] ?? 0);
            $data = self::normalizeRow((array) ($row['data'] ?? []));

            $values = [
                'account_title' => trim((string) ($data['account_title'] ?? '')),
                'rca_code' => trim((string) ($data['rca_code'] ?? '')),
                'uacs_sub_object_code' => trim((string) ($data['uacs_sub_object_code'] ?? '')),
                'uacs_object_code' => trim((string) ($data['uacs_object_code'] ?? '')),
                'covering_ppas' => trim((string) ($data['covering_ppas'] ?? '')),
            ];

            $errors = [];

            foreach (self::REQUIRED_LABELS as $field => $label) {
                if ($values[$field] === '') {
                    $errors[] = $label.' is required';
                }
            }

            foreach (['account_title' => 255, 'rca_code' => 32, 'uacs_sub_object_code' => 32, 'uacs_object_code' => 32, 'covering_ppas' => 255] as $field => $limit) {
                if (mb_strlen($values[$field]) > $limit) {
                    $label = self::REQUIRED_LABELS[$field] ?? 'Covering PPAS';
                    $errors[] = $label." may not be longer than {$limit} characters";
                }
            }

            $identity = mb_strtolower(implode('|', [
                $values['account_title'],
                $values['rca_code'],
                $values['uacs_sub_object_code'],
                $values['uacs_object_code'],
            ]));

            if ($errors === []) {
                if (isset($seen[$identity])) {
                    $errors[] = 'Duplicate of this file’s row '.$seen[$identity];
                } else {
                    $seen[$identity] = $line;
                }
            }

            if ($errors === [] && AccountClassification::duplicateExists($values)) {
                $errors[] = 'Already exists as an active classification';
            }

            $valid = $errors === [];
            $validCount += $valid ? 1 : 0;

            $values['covering_ppas'] = $values['covering_ppas'] !== '' ? $values['covering_ppas'] : null;

            $analysed[] = [
                'line' => $line,
                'data' => $values,
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
     * Insert the validated rows. Re-checks duplicates against the database so a
     * row that became a duplicate since the analysis is skipped, not fatal.
     *
     * @param  array<int, array<string, mixed>>  $validRows
     * @return array{imported: int, skipped: int}
     */
    public static function commit(array $validRows, ?User $user): array
    {
        return DB::transaction(function () use ($validRows, $user) {
            $imported = 0;
            $skipped = 0;

            foreach ($validRows as $row) {
                if (AccountClassification::duplicateExists($row)) {
                    $skipped++;

                    continue;
                }

                $classification = AccountClassification::create([
                    ...$row,
                    'is_active' => true,
                ]);

                $classification->recordAudit(AccountClassification::AUDIT_CREATE, $user, null, $classification->auditSnapshot());
                $imported++;
            }

            return ['imported' => $imported, 'skipped' => $skipped];
        });
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return array<int, string|null>|null  position => field, or null if this is not a header row
     */
    private static function detectHeader(array $cells): ?array
    {
        $fields = [];
        $matches = 0;

        foreach ($cells as $index => $cell) {
            $field = self::fieldFor(self::normalizeHeader((string) $cell));
            $fields[$index] = $field;

            if (in_array($field, self::STRONG_HEADERS, true)) {
                $matches++;
            }
        }

        // Two or more recognised column names (e.g. "Account Title" + "RCA
        // Code"): a real header. A single stray match is far more likely to be
        // a data value than a header row.
        return $matches >= 2 ? $fields : null;
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

    private static function fieldFor(string $normalized): ?string
    {
        foreach (self::HEADER_ALIASES as $field => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $field;
            }
        }

        return null;
    }

    private static function normalizeHeader(string $value): string
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

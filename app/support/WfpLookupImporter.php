<?php

namespace App\Support;

use App\Models\Concerns\HasLookupBehavior;
use App\Models\User;
use App\Models\WfpImportLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Parses and validates bulk WFP lookup records (CSV), one category at a time,
 * with the same two-phase flow the Account Classifications importer uses:
 * `analyze*()` validates every row and writes nothing, returning the planned
 * action per row plus a row-level error report; `commit()` then writes the
 * rows inside a transaction and records the import.
 *
 * The import mode decides what happens to a row that matches an existing
 * record (matched by code when the row carries one, by name otherwise — never
 * by name when a code is available):
 * - `insert` — new rows are added; a match is skipped with a reason.
 * - `update` — matches are updated; a row with no match is skipped.
 * - `upsert` — matches are updated, everything else is inserted.
 *
 * Codes are only trimmed, never reformatted, so leading zeroes survive.
 */
class WfpLookupImporter
{
    /**
     * Read and validate a CSV file for one category. Nothing is written.
     *
     * @return array<string, mixed>
     */
    public static function analyzeCsv(string $path, string $category, string $mode): array
    {
        $spec = self::category($category);

        $rows = WfpCsvReader::read(
            $path,
            $spec['columns'],
            (int) config('wfp_lookups.import.max_rows', 2000)
        );

        return self::analyzeRows($rows, $category, $mode);
    }

    /**
     * Validate rows already in associative form.
     *
     * @param  array<int, array{line?: int, data?: array<string, mixed>}>  $rows
     * @return array<string, mixed>
     */
    public static function analyzeRows(array $rows, string $category, string $mode): array
    {
        $spec = self::category($category);
        $model = $spec['model'];
        $columns = $spec['columns'];
        $mode = self::normalizeMode($mode);

        $analysed = [];
        $seen = [];
        $counts = ['insert' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];

        foreach ($rows as $row) {
            $line = (int) ($row['line'] ?? 0);
            $data = (array) ($row['data'] ?? []);
            $values = [];
            $errors = [];

            foreach ($columns as $field => $columnSpec) {
                $values[$field] = trim((string) ($data[$field] ?? ''));
            }

            foreach ($columns as $field => $columnSpec) {
                if (($columnSpec['required'] ?? false) && $values[$field] === '') {
                    $errors[] = $columnSpec['label'].' is required';
                }

                if (! empty($columnSpec['boolean'])) {
                    $parsed = WfpCsvReader::parseBoolean($values[$field]);

                    if (! $parsed['valid']) {
                        $errors[] = $columnSpec['label'].' must be Yes or No';
                    } else {
                        $values[$field] = $parsed['value'];
                    }
                } elseif ($values[$field] !== '' && mb_strlen($values[$field]) > ($columnSpec['max'] ?? 255)) {
                    $errors[] = $columnSpec['label'].' may not be longer than '.$columnSpec['max'].' characters';
                }
            }

            // Duplicates within the file are caught by the same match key the
            // commit uses, so two rows cannot silently become one record.
            $matchKey = self::matchKey($values);

            if ($errors === []) {
                if (isset($seen[$matchKey])) {
                    $errors[] = 'Duplicate of this file’s row '.$seen[$matchKey];
                } else {
                    $seen[$matchKey] = $line;
                }
            }

            $action = 'insert';
            $warnings = [];

            if ($errors === []) {
                $exists = $model::findMatch(
                    (string) ($values['code'] ?? ''),
                    (string) ($values['name'] ?? '')
                ) !== null;

                $action = match (true) {
                    $mode === WfpImportLog::MODE_INSERT && $exists => 'skip',
                    $mode === WfpImportLog::MODE_UPDATE && ! $exists => 'skip',
                    $exists => 'update',
                    default => 'insert',
                };

                if ($action === 'skip') {
                    $warnings[] = $mode === WfpImportLog::MODE_INSERT
                        ? 'Already exists — skipped (insert only)'
                        : 'No existing record matches — skipped (update only)';
                }
            } else {
                $action = 'error';
            }

            $counts[$action]++;

            $analysed[] = [
                'line' => $line,
                'data' => $values,
                'valid' => $errors === [],
                'action' => $action,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        return [
            'rows' => $analysed,
            'validCount' => $counts['insert'] + $counts['update'],
            'invalidCount' => $counts['error'],
            'skippedCount' => $counts['skip'],
            'insertCount' => $counts['insert'],
            'updateCount' => $counts['update'],
            'totalCount' => count($analysed),
        ];
    }

    /**
     * Write the analysed rows inside a transaction and record the import.
     * Rows are re-checked against the database at commit time (a record may
     * have been created since the preview), so a row that became a duplicate
     * is skipped, not fatal — and any database failure rolls the whole import
     * back rather than leaving a partially imported catalogue.
     *
     * @param  array<string, mixed>  $analysis  the output of analyzeRows()/analyzeCsv()
     * @return array{inserted: int, updated: int, skipped: int, failed: int, log: WfpImportLog}
     */
    public static function commit(array $analysis, string $category, string $mode, ?User $user, ?string $filename = null): array
    {
        $spec = self::category($category);
        $model = $spec['model'];
        $mode = self::normalizeMode($mode);

        return DB::transaction(function () use ($analysis, $category, $mode, $user, $filename, $model, $spec): array {
            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $failed = 0;
            $errorRows = [];

            foreach ((array) ($analysis['rows'] ?? []) as $row) {
                $action = (string) ($row['action'] ?? 'error');

                if ($action === 'error' || ($row['valid'] ?? false) === false) {
                    $failed++;
                    $errorRows[] = [
                        'line' => (int) ($row['line'] ?? 0),
                        'result' => 'Failed',
                        'errors' => (array) ($row['errors'] ?? []),
                        'data' => (array) ($row['data'] ?? []),
                    ];

                    continue;
                }

                if ($action === 'skip') {
                    $skipped++;
                    $errorRows[] = [
                        'line' => (int) ($row['line'] ?? 0),
                        'result' => 'Skipped',
                        'errors' => (array) ($row['warnings'] ?? []),
                        'data' => (array) ($row['data'] ?? []),
                    ];

                    continue;
                }

                $values = (array) ($row['data'] ?? []);
                $attributes = self::attributes($values, $spec['columns']);

                $match = $model::findMatch(
                    (string) ($values['code'] ?? ''),
                    (string) ($values['name'] ?? '')
                );

                if ($match !== null) {
                    if ($mode === WfpImportLog::MODE_INSERT) {
                        $skipped++;
                        $errorRows[] = [
                            'line' => (int) ($row['line'] ?? 0),
                            'result' => 'Skipped',
                            'errors' => ['Already exists — skipped (insert only)'],
                            'data' => $values,
                        ];

                        continue;
                    }

                    $before = $match->auditSnapshot();

                    $match->fill($attributes);
                    $match->updated_by = $user?->id;
                    $match->save();

                    $match->recordLookupAudit($model::AUDIT_UPDATE, $user, $before, $match->auditSnapshot());
                    $updated++;

                    continue;
                }

                if ($mode === WfpImportLog::MODE_UPDATE) {
                    $skipped++;
                    $errorRows[] = [
                        'line' => (int) ($row['line'] ?? 0),
                        'result' => 'Skipped',
                        'errors' => ['No existing record matches — skipped (update only)'],
                        'data' => $values,
                    ];

                    continue;
                }

                $created = $model::create([
                    ...$attributes,
                    'created_by' => $user?->id,
                    'updated_by' => $user?->id,
                ]);

                $created->recordLookupAudit($model::AUDIT_CREATE, $user, null, $created->auditSnapshot());
                $inserted++;
            }

            $log = WfpImportLog::create([
                'user_id' => $user?->id,
                'category' => $category,
                'mode' => $mode,
                'filename' => $filename,
                'total_rows' => count((array) ($analysis['rows'] ?? [])),
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
                'errors' => $errorRows === [] ? null : $errorRows,
            ]);

            return [
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
                'log' => $log,
            ];
        });
    }

    /**
     * The category spec, or a clear failure for an unknown slug.
     *
     * @return array<string, mixed>
     */
    public static function category(string $category): array
    {
        $spec = config('wfp_lookups.categories.'.$category);

        if (! is_array($spec) || ! isset($spec['model'])) {
            throw new RuntimeException("Unknown WFP lookup category: {$category}");
        }

        return $spec;
    }

    public static function normalizeMode(string $mode): string
    {
        if (! in_array($mode, [WfpImportLog::MODE_INSERT, WfpImportLog::MODE_UPDATE, WfpImportLog::MODE_UPSERT], true)) {
            throw new RuntimeException("Unknown import mode: {$mode}");
        }

        return $mode;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    private static function attributes(array $values, array $columns): array
    {
        $attributes = [];

        foreach ($columns as $field => $columnSpec) {
            $value = $values[$field] ?? '';

            if (! empty($columnSpec['boolean'])) {
                $attributes[$field] = (bool) $value;

                continue;
            }

            $attributes[$field] = $value === '' ? null : (string) $value;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function matchKey(array $values): string
    {
        $code = mb_strtolower(trim((string) ($values['code'] ?? '')));

        if ($code !== '') {
            return 'code:'.$code;
        }

        return 'name:'.mb_strtolower(trim((string) ($values['name'] ?? '')));
    }
}

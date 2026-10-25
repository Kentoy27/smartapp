<?php

namespace App\Support;

use App\Models\AccountClassification;
use App\Models\Concerns\HasLookupBehavior;
use App\Models\User;
use App\Models\WfpImportLog;
use App\Models\WfpLineTemplate;
use App\Models\WfpOrgClassification;
use App\Models\WfpFundingSource;
use App\Models\WfpResourceKind;
use App\Models\WfpUnit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Parses and validates bulk Activity / Expenditure Templates (CSV) with the
 * same two-phase flow as the lookup importer: `analyze*()` validates every
 * row and writes nothing, `commit()` writes inside a transaction and records
 * the import.
 *
 * Related records are resolved by stable code first, then by name, among
 * ACTIVE rows only — a new template can never be attached to a retired unit
 * or funding source. An unknown reference is a row error naming the column
 * and the value, never a silent null. The account column is matched against
 * the RCA / UACS master data by object code (or RCA code) first, then by
 * account title.
 *
 * Templates are matched for update by their normalized name AND line type —
 * the same identity duplicate detection uses.
 */
class WfpLineTemplateImporter
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const COLUMNS = [
        'name' => ['label' => 'Template Name', 'aliases' => ['templatename', 'template', 'name'], 'required' => true, 'max' => 255],
        'line_type' => ['label' => 'Line Type', 'aliases' => ['linetype', 'type', 'section', 'category'], 'required' => false, 'max' => 40],
        'description' => ['label' => 'Description', 'aliases' => ['description', 'remarks', 'notes'], 'required' => false, 'max' => 500],
        'objective' => ['label' => 'Objective', 'aliases' => ['objective', 'objectives'], 'required' => false, 'max' => 2000],
        'activity_expenditure' => ['label' => 'Project/Activity/Expenditure', 'aliases' => ['projectactivityexpenditure', 'activityexpenditure', 'programprojectactivity', 'programactivity', 'projectactivity', 'activity', 'activities'], 'required' => true, 'max' => 2000],
        'resource_kind' => ['label' => 'Resource Kind', 'aliases' => ['resourcekind', 'resource', 'kind'], 'required' => false, 'max' => 255],
        'suggested_quantity' => ['label' => 'Suggested Quantity', 'aliases' => ['suggestedquantity', 'quantity', 'qty'], 'required' => false, 'max' => 32],
        'unit' => ['label' => 'Unit', 'aliases' => ['unit', 'units', 'uom'], 'required' => false, 'max' => 255],
        'suggested_unit_cost' => ['label' => 'Suggested Unit Cost', 'aliases' => ['suggestedunitcost', 'unitcost', 'unitprice', 'price'], 'required' => false, 'max' => 32],
        'account_code' => ['label' => 'Account Code', 'aliases' => ['accountcode', 'objectcode', 'uacscode', 'code'], 'required' => false, 'max' => 64],
        'funding_source' => ['label' => 'Funding Source', 'aliases' => ['fundingsource', 'sourceoffund', 'sourceoffunds', 'fund'], 'required' => false, 'max' => 255],
        'org_classification' => ['label' => 'Organizational Classification', 'aliases' => ['organizationalclassification', 'orgclassification', 'organizationaloutput'], 'required' => false, 'max' => 255],
        'major_output' => ['label' => 'Major Output', 'aliases' => ['majoroutput', 'majoroutputs'], 'required' => false, 'max' => 2000],
        'performance_indicator' => ['label' => 'Performance Indicator', 'aliases' => ['performanceindicator', 'performanceindicators'], 'required' => false, 'max' => 2000],
        'activity_movs' => ['label' => 'Activity MOVs', 'aliases' => ['activitymovs', 'movs', 'meansofverification', 'mov'], 'required' => false, 'max' => 2000],
        'tags' => ['label' => 'Tags', 'aliases' => ['tags', 'tag'], 'required' => false, 'max' => 255],
        'is_active' => ['label' => 'Active', 'aliases' => ['active', 'isactive', 'status', 'enabled'], 'required' => false, 'max' => 8, 'boolean' => true],
    ];

    /** The column order the blank template and the export write. */
    public const COLUMN_ORDER = [
        'name',
        'line_type',
        'description',
        'objective',
        'activity_expenditure',
        'resource_kind',
        'suggested_quantity',
        'unit',
        'suggested_unit_cost',
        'account_code',
        'funding_source',
        'org_classification',
        'major_output',
        'performance_indicator',
        'activity_movs',
        'tags',
        'is_active',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function columns(): array
    {
        return self::COLUMNS;
    }

    /**
     * Read and validate a CSV file. Nothing is written.
     *
     * @return array<string, mixed>
     */
    public static function analyzeCsv(string $path, string $mode): array
    {
        $rows = WfpCsvReader::read(
            $path,
            self::COLUMNS,
            (int) config('wfp_lookups.import.max_rows', 2000)
        );

        return self::analyzeRows($rows, $mode);
    }

    /**
     * Validate rows already in associative form.
     *
     * @param  array<int, array{line?: int, data?: array<string, mixed>}>  $rows
     * @return array<string, mixed>
     */
    public static function analyzeRows(array $rows, string $mode): array
    {
        $mode = WfpLookupImporter::normalizeMode($mode);

        $analysed = [];
        $seen = [];
        $counts = ['insert' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];

        foreach ($rows as $row) {
            $line = (int) ($row['line'] ?? 0);
            $data = (array) ($row['data'] ?? []);
            $values = [];
            $errors = [];

            foreach (self::COLUMNS as $field => $columnSpec) {
                $values[$field] = trim((string) ($data[$field] ?? ''));
            }

            foreach (self::COLUMNS as $field => $columnSpec) {
                if (($columnSpec['required'] ?? false) && $values[$field] === '') {
                    $errors[] = $columnSpec['label'].' is required';
                } elseif ($values[$field] !== '' && mb_strlen($values[$field]) > ($columnSpec['max'] ?? 255)) {
                    $errors[] = $columnSpec['label'].' may not be longer than '.$columnSpec['max'].' characters';
                }
            }

            $resolved = [
                'line_type' => WfpLineTemplate::LINE_TYPE_OPERATIONS,
                'resource_kind_id' => null,
                'unit_id' => null,
                'account_classification_id' => null,
                'funding_source_id' => null,
                'org_classification_id' => null,
                'suggested_quantity' => null,
                'suggested_unit_cost' => null,
                'is_active' => true,
            ];

            // Line type: blank means operations; anything else must be a
            // recognised spelling of the two line types.
            if ($values['line_type'] !== '') {
                $lineType = self::resolveLineType($values['line_type']);

                if ($lineType === null) {
                    $errors[] = 'Line Type must be Operations or Project';
                } else {
                    $resolved['line_type'] = $lineType;
                }
            }

            // Suggested numbers: blank stays blank; anything else must be a
            // non-negative number.
            foreach (['suggested_quantity' => 'Suggested Quantity', 'suggested_unit_cost' => 'Suggested Unit Cost'] as $field => $label) {
                if ($values[$field] === '') {
                    continue;
                }

                if (! is_numeric($values[$field]) || (float) $values[$field] < 0) {
                    $errors[] = $label.' must be a number of zero or more';

                    continue;
                }

                $resolved[$field] = round((float) $values[$field], 2);
            }

            $active = WfpCsvReader::parseBoolean($values['is_active']);

            if (! $active['valid']) {
                $errors[] = 'Active must be Yes or No';
            } else {
                $resolved['is_active'] = $active['value'];
            }

            // Related records: resolved by code first, then by name, among
            // active rows only.
            $resolved['resource_kind_id'] = self::resolveReference($values['resource_kind'], WfpResourceKind::class, 'Resource Kind', $errors);
            $resolved['unit_id'] = self::resolveReference($values['unit'], WfpUnit::class, 'Unit', $errors);
            $resolved['funding_source_id'] = self::resolveReference($values['funding_source'], WfpFundingSource::class, 'Funding Source', $errors);
            $resolved['org_classification_id'] = self::resolveReference($values['org_classification'], WfpOrgClassification::class, 'Organizational Classification', $errors);

            if ($values['account_code'] !== '') {
                $classification = self::resolveAccount($values['account_code']);

                if ($classification === null) {
                    $errors[] = 'Account Code “'.$values['account_code'].'” was not found among the account classifications';
                } elseif (! $classification->is_active) {
                    $errors[] = 'Account Code “'.$values['account_code'].'” matches an inactive account classification';
                } else {
                    $resolved['account_classification_id'] = $classification->id;
                }
            }

            // Duplicates within the file share the identity the update match
            // uses: normalized name + line type.
            $matchKey = self::matchKey($values['name'], (string) $resolved['line_type']);

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
                $exists = self::findTemplate($values['name'], (string) $resolved['line_type']) !== null;

                $action = match (true) {
                    $mode === WfpImportLog::MODE_INSERT && $exists => 'skip',
                    $mode === WfpImportLog::MODE_UPDATE && ! $exists => 'skip',
                    $exists => 'update',
                    default => 'insert',
                };

                if ($action === 'skip') {
                    $warnings[] = $mode === WfpImportLog::MODE_INSERT
                        ? 'A template with this name and line type already exists — skipped (insert only)'
                        : 'No existing template matches this name and line type — skipped (update only)';
                }
            } else {
                $action = 'error';
            }

            $counts[$action]++;

            $analysed[] = [
                'line' => $line,
                'data' => $values,
                'resolved' => $resolved,
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
     *
     * @param  array<string, mixed>  $analysis
     * @return array{inserted: int, updated: int, skipped: int, failed: int, log: WfpImportLog}
     */
    public static function commit(array $analysis, string $mode, ?User $user, ?string $filename = null): array
    {
        $mode = WfpLookupImporter::normalizeMode($mode);

        return DB::transaction(function () use ($analysis, $mode, $user, $filename): array {
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
                $resolved = (array) ($row['resolved'] ?? []);
                $attributes = self::attributes($values, $resolved);

                $match = self::findTemplate($values['name'], (string) ($resolved['line_type'] ?? WfpLineTemplate::LINE_TYPE_OPERATIONS));

                if ($match !== null) {
                    if ($mode === WfpImportLog::MODE_INSERT) {
                        $skipped++;
                        $errorRows[] = [
                            'line' => (int) ($row['line'] ?? 0),
                            'result' => 'Skipped',
                            'errors' => ['A template with this name and line type already exists — skipped (insert only)'],
                            'data' => $values,
                        ];

                        continue;
                    }

                    $before = $match->auditSnapshot();

                    $match->fill($attributes);
                    $match->updated_by = $user?->id;
                    $match->save();

                    $match->recordLookupAudit(WfpLineTemplate::AUDIT_UPDATE, $user, $before, $match->auditSnapshot());
                    $updated++;

                    continue;
                }

                if ($mode === WfpImportLog::MODE_UPDATE) {
                    $skipped++;
                    $errorRows[] = [
                        'line' => (int) ($row['line'] ?? 0),
                        'result' => 'Skipped',
                        'errors' => ['No existing template matches this name and line type — skipped (update only)'],
                        'data' => $values,
                    ];

                    continue;
                }

                $created = WfpLineTemplate::create([
                    ...$attributes,
                    'created_by' => $user?->id,
                    'updated_by' => $user?->id,
                ]);

                $created->recordLookupAudit(WfpLineTemplate::AUDIT_CREATE, $user, null, $created->auditSnapshot());
                $inserted++;
            }

            $log = WfpImportLog::create([
                'user_id' => $user?->id,
                'category' => 'line_templates',
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
     * The template an import row updates, matched by normalized name + line
     * type — the same identity duplicate detection uses.
     */
    public static function findTemplate(string $name, string $lineType): ?WfpLineTemplate
    {
        return WfpLineTemplate::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
            ->where('line_type', $lineType)
            ->orderBy('id')
            ->first();
    }

    private static function resolveLineType(string $value): ?string
    {
        $normalized = WfpCsvReader::normalizeHeader($value);

        if (in_array($normalized, ['operations', 'operation', 'operationsrelatedexpenditures', 'operationsrelated', 'opex', 'ops'], true)) {
            return WfpLineTemplate::LINE_TYPE_OPERATIONS;
        }

        if (in_array($normalized, ['project', 'projects', 'projectrelatedexpenditures', 'projectrelated'], true)) {
            return WfpLineTemplate::LINE_TYPE_PROJECT;
        }

        return null;
    }

    /**
     * Resolve one reference column to a live lookup row. Unknown values push
     * an error onto $errors (passed by reference) and resolve to null.
     *
     * @param  class-string  $model
     * @param  array<int, string>  $errors
     */
    private static function resolveReference(string $value, string $model, string $label, array &$errors): ?int
    {
        if (trim($value) === '') {
            return null;
        }

        $match = $model::resolveActive($value);

        if ($match === null) {
            $errors[] = $label.' “'.$value.'” was not found among the active records';

            return null;
        }

        return (int) $match->id;
    }

    /**
     * Resolve the account column against the RCA / UACS master data: an exact
     * object code or RCA code first, then the account title. (The sub-object
     * code is deliberately not matched — it is shared by many accounts.)
     */
    private static function resolveAccount(string $value): ?AccountClassification
    {
        $needle = trim($value);

        $exact = AccountClassification::query()
            ->where('uacs_object_code', $needle)
            ->orWhere('rca_code', $needle)
            ->orderBy('id')
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        return AccountClassification::query()
            ->whereRaw('LOWER(TRIM(account_title)) = ?', [mb_strtolower($needle)])
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private static function attributes(array $values, array $resolved): array
    {
        $nullIfBlank = static fn (string $value): ?string => $value === '' ? null : $value;

        return [
            'name' => $values['name'],
            'description' => $nullIfBlank((string) $values['description']),
            'line_type' => $resolved['line_type'] ?? WfpLineTemplate::LINE_TYPE_OPERATIONS,
            'objective' => $nullIfBlank((string) $values['objective']),
            'activity_expenditure' => $values['activity_expenditure'],
            'resource_kind_id' => $resolved['resource_kind_id'] ?? null,
            'unit_id' => $resolved['unit_id'] ?? null,
            'suggested_quantity' => $resolved['suggested_quantity'] ?? null,
            'suggested_unit_cost' => $resolved['suggested_unit_cost'] ?? null,
            'account_classification_id' => $resolved['account_classification_id'] ?? null,
            'funding_source_id' => $resolved['funding_source_id'] ?? null,
            'org_classification_id' => $resolved['org_classification_id'] ?? null,
            'major_output' => $nullIfBlank((string) $values['major_output']),
            'performance_indicator' => $nullIfBlank((string) $values['performance_indicator']),
            'activity_movs' => $nullIfBlank((string) $values['activity_movs']),
            'tags' => $nullIfBlank((string) $values['tags']),
            'is_active' => (bool) ($resolved['is_active'] ?? true),
        ];
    }

    private static function matchKey(string $name, string $lineType): string
    {
        return mb_strtolower(trim($name)).'|'.$lineType;
    }
}

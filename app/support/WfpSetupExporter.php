<?php

namespace App\Support;

use App\Models\WfpLineTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the CSV downloads of the WFP Setup module: each lookup category's
 * export and blank import template, and the activity templates' export and
 * blank import template.
 *
 * Exports write every row — active and retired — with the status in its own
 * column, so an administrator can keep a working copy, edit it and re-import
 * it: the import resolves the same columns it exports. Related records are
 * exported as their stable code where they have one (falling back to the
 * name), which is exactly how the template importer resolves them back.
 *
 * The escaping is done here, by hand, rather than through fputcsv: the file
 * is assembled as one string and handed to the response as bytes (the same
 * shape every other export in this app uses), and quoting a value that
 * contains a comma, quote or newline is three lines of code.
 */
class WfpSetupExporter
{
    /**
     * Every row of one lookup category, as a CSV with a header row.
     */
    public static function lookupCsv(string $category): string
    {
        $spec = WfpLookupImporter::category($category);
        $model = $spec['model'];
        $columns = $spec['columns'];

        $lines = [self::line(array_map(
            static fn (array $columnSpec): string => (string) $columnSpec['label'],
            $columns
        ))];

        foreach ($model::query()->orderBy('name')->orderBy('id')->get() as $record) {
            $cells = [];

            foreach ($columns as $field => $columnSpec) {
                $value = $record->{$field};

                $cells[] = ! empty($columnSpec['boolean'])
                    ? ($value ? 'Yes' : 'No')
                    : (string) ($value ?? '');
            }

            $lines[] = self::line($cells);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The blank import template for one lookup category: the header row plus
     * one worked example the administrator deletes before filling their own.
     */
    public static function lookupTemplateCsv(string $category): string
    {
        $spec = WfpLookupImporter::category($category);
        $columns = $spec['columns'];

        $header = array_map(
            static fn (array $columnSpec): string => (string) $columnSpec['label'],
            $columns
        );

        $sample = array_map(
            static fn (mixed $value): string => (string) $value,
            (array) ($spec['sample'] ?? [])
        );

        return self::line($header)."\n".self::line($sample)."\n";
    }

    /**
     * Every activity template, as a CSV the template importer can read back.
     */
    public static function templateCsv(): string
    {
        $columns = WfpLineTemplateImporter::columns();
        $order = WfpLineTemplateImporter::COLUMN_ORDER;

        $lines = [self::line(array_map(
            static fn (string $field): string => (string) $columns[$field]['label'],
            $order
        ))];

        $templates = WfpLineTemplate::query()
            ->with(['resourceKind', 'unit', 'accountClassification', 'fundingSource', 'orgClassification'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        foreach ($templates as $template) {
            $lines[] = self::line(self::templateRow($template, $order));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The blank activity-template import template: header row plus one worked
     * example (which the importer would accept as a real row — the
     * administrator deletes it before filling their own).
     */
    public static function templateTemplateCsv(): string
    {
        $columns = WfpLineTemplateImporter::columns();
        $order = WfpLineTemplateImporter::COLUMN_ORDER;

        $header = array_map(
            static fn (string $field): string => (string) $columns[$field]['label'],
            $order
        );

        $sample = [
            'Procure office supplies',
            'operations',
            'Delete this example row before filling your own.',
            'Improved learner outcomes',
            'Procure office supplies',
            'MOOE',
            '10',
            'ream',
            '250.00',
            '5020301000',
            'MOOE',
            'School',
            'Supplies procured and distributed',
            'Number of reams procured',
            'PO and RIS',
            'supplies, mooe',
            'Yes',
        ];

        return self::line($header)."\n".self::line($sample)."\n";
    }

    /**
     * @param  array<int, string>  $order
     * @return array<int, string>
     */
    private static function templateRow(WfpLineTemplate $template, array $order): array
    {
        $cells = [];

        foreach ($order as $field) {
            $cells[] = match ($field) {
                'name' => (string) $template->name,
                'line_type' => (string) $template->line_type,
                'description' => (string) ($template->description ?? ''),
                'objective' => (string) ($template->objective ?? ''),
                'activity_expenditure' => (string) $template->activity_expenditure,
                'resource_kind' => self::referenceValue($template->resourceKind),
                'suggested_quantity' => $template->suggested_quantity !== null ? (string) (float) $template->suggested_quantity : '',
                'unit' => self::referenceValue($template->unit),
                'suggested_unit_cost' => $template->suggested_unit_cost !== null ? (string) (float) $template->suggested_unit_cost : '',
                'account_code' => (string) ($template->accountClassification?->uacs_object_code ?? ''),
                'funding_source' => self::referenceValue($template->fundingSource),
                'org_classification' => self::referenceValue($template->orgClassification),
                'major_output' => (string) ($template->major_output ?? ''),
                'performance_indicator' => (string) ($template->performance_indicator ?? ''),
                'activity_movs' => (string) ($template->activity_movs ?? ''),
                'tags' => (string) ($template->tags ?? ''),
                'is_active' => $template->is_active ? 'Yes' : 'No',
                default => '',
            };
        }

        return $cells;
    }

    /**
     * A related record as the import resolves it back: its code when it has
     * one, otherwise its name.
     */
    private static function referenceValue(?Model $record): string
    {
        if ($record === null) {
            return '';
        }

        $code = trim((string) ($record->code ?? ''));

        return $code !== '' ? $code : (string) $record->name;
    }

    /**
     * The downloadable error report for a committed import: one row per
     * failed or skipped import row, with the line number, the result, the
     * reasons and the values that caused them.
     *
     * @param  array<int, array{line?: int, result?: string, errors?: array<int, string>, data?: array<string, mixed>>}  $errorRows
     */
    public static function errorReportCsv(array $errorRows): string
    {
        $lines = [self::line(['Row', 'Result', 'Reason(s)', 'Values'])];

        foreach ($errorRows as $errorRow) {
            $data = (array) ($errorRow['data'] ?? []);
            $values = [];

            foreach ($data as $key => $value) {
                if ($value === '') {
                    continue;
                }

                $values[] = $key.'='.(string) $value;
            }

            $lines[] = self::line([
                (string) (($errorRow['line'] ?? 0)),
                (string) ($errorRow['result'] ?? 'Failed'),
                implode('; ', (array) ($errorRow['errors'] ?? [])),
                implode('; ', $values),
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<int, string>  $cells
     */
    private static function line(array $cells): string
    {
        return implode(',', array_map(self::cell(...), $cells));
    }

    private static function cell(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[",\r\n]/', $value) === 1) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}

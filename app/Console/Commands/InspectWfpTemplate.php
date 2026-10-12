<?php

namespace App\Console\Commands;

use App\Support\WfpTemplate;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Reads the shipped WFP-Template.xlsx and prints the table layout the export
 * relies on: which sheet the table is on, its header row, the resolved column
 * letters (and month columns when enabled), the blank data band and the TOTAL
 * row.
 *
 * Run it once against the real template; any column the scan could not resolve
 * can be pinned in config/wfp.php ('account_code' => ['column' => 'F']). It is
 * read-only and changes nothing.
 */
class InspectWfpTemplate extends Command
{
    protected $signature = 'wfp:inspect-template';

    protected $description = 'Inspect the WFP template layout (sheet, header row, resolved columns, data band)';

    public function handle(): int
    {
        if (! WfpTemplate::exists()) {
            $this->components->error('No template found at '.WfpTemplate::templatePath().'.');

            return self::FAILURE;
        }

        try {
            $layout = WfpTemplate::inspect();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Template: '.WfpTemplate::templatePath());
        $this->line('  Sheet:        '.$layout['sheet_name'].'  ('.$layout['sheet_path'].')');
        $this->line('  Header row:   '.($layout['header_row'] ?? '— not found —'));
        $this->line('  Data band:    '.($layout['data_start_row'] ?? '—').' … '.(
            $layout['data_rows'] === [] ? '—' : max($layout['data_rows'])
        ).'  ('.count($layout['data_rows']).' blank row(s))');
        $this->line('  TOTAL row:    '.($layout['total_row'] ?? '— none —'));

        $this->newLine();
        $this->line('  Columns:');

        foreach ((array) config('wfp.template.columns', []) as $field => $spec) {
            $letter = $layout['columns'][$field] ?? null;
            $pinned = $spec['column'] ?? null;

            $this->line(sprintf(
                '    %-32s %s%s',
                $field,
                $letter ?? '— NOT FOUND —',
                $letter !== null && $pinned !== null ? ' (pinned)' : ''
            ));
        }

        if ($layout['months'] !== []) {
            $this->newLine();
            $this->line('  Month columns:');

            foreach ($layout['months'] as $number => $letter) {
                $this->line(sprintf('    %-3d %s', $number, $letter));
            }
        } elseif ((bool) config('wfp.template.months.enabled', false)) {
            $this->newLine();
            $this->components->warn('Month columns are enabled but none were found in the header row.');
        }

        return self::SUCCESS;
    }
}

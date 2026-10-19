<?php

namespace App\Console\Commands;

use App\Support\MemWorkbookImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Imports a single Monthly Expenditure Matrix workbook — a .xlsx whose
 * worksheets are the calendar months (JAN..DEC) — into the twelve stored
 * matrices for a school and year.
 *
 * `--dry-run` (or nothing committed) prints the per-month preview and writes
 * nothing, which is how the parser is tuned against the real file: run it,
 * check the row counts and month totals against the workbook, then run again
 * without --dry-run to commit.
 *
 * The commit REPLACES each month's lines, so a re-import is idempotent. The
 * school id and year are required: the workbook names no school, and a line
 * can never be written into the wrong school or year.
 */
class ImportMemWorkbook extends Command
{
    protected $signature = 'wfp:import-matrix
        {path : The .xlsx workbook (absolute, or relative to the project root)}
        {--school= : The school id the matrices belong to}
        {--year= : The planning year (defaults to the current year)}
        {--dry-run : Only print the preview; write nothing}';

    protected $description = 'Import a multi-sheet Monthly Expenditure Matrix workbook (JAN..DEC) into a school\'s matrices';

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (! File::isFile($path)) {
            $candidate = base_path($path);

            if (File::isFile($candidate)) {
                $path = $candidate;
            }
        }

        if (! File::isFile($path)) {
            $this->components->error('No file at '.$this->argument('path').'.');

            return self::FAILURE;
        }

        $school = (int) $this->option('school');

        if ($school <= 0) {
            $this->components->error('A --school=<id> is required: the workbook names no school.');

            return self::FAILURE;
        }

        $year = (int) ($this->option('year') ?: now()->year);

        try {
            $analysis = MemWorkbookImporter::analyzeWorkbook($path, $school, $year);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Workbook: '.$path);
        $this->line('  School: '.$school.'   Year: CY '.$year);
        $this->newLine();

        if ($analysis['months'] === []) {
            $this->components->warn('No monthly sheets (JAN…DEC) were found — nothing to import.');

            foreach ($analysis['warnings'] as $warning) {
                $this->line('    '.$warning);
            }

            return self::FAILURE;
        }

        $this->line('  Month      lines   ready   skipped        total');

        foreach ($analysis['months'] as $month) {
            $this->line(sprintf(
                '    %-8s %6d %7d %9d %12s',
                $month['name'],
                count($month['rows']),
                $month['validCount'],
                $month['invalidCount'],
                number_format($month['total'], 2),
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            '  %d ready to import, %d to skip, across %d month(s).',
            $analysis['validCount'],
            $analysis['invalidCount'],
            count($analysis['months']),
        ));

        foreach ($analysis['warnings'] as $warning) {
            $this->line('    ! '.$warning);
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->components->info('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        if ($analysis['validCount'] === 0) {
            $this->components->error('There are no valid rows to import.');

            return self::FAILURE;
        }

        $result = MemWorkbookImporter::commit($analysis['valid'], $school, $year, null);

        $this->newLine();
        $this->components->info(sprintf(
            'Imported %d line(s) into %d month(s) for CY %d.',
            $result['imported'],
            $result['months'],
            $year,
        ));

        return self::SUCCESS;
    }
}

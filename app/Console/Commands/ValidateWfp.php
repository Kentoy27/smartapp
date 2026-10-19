<?php

namespace App\Console\Commands;

use App\Models\WfpPlan;
use App\Support\WfpValidator;
use Illuminate\Console\Command;

/**
 * Prints the validation report for a generated WFP: whether each month's
 * total matches the source Monthly Expenditure Matrix to the centavo, whether
 * the grand totals agree, which account codes the master list does not know,
 * which rows are still missing their Major Output / Performance Indicator, and
 * which activities read as near-duplicates.
 *
 * Read-only: it changes nothing. The same report is shown on the plan editor.
 */
class ValidateWfp extends Command
{
    protected $signature = 'wfp:validate {plan : The WFP plan id}';

    protected $description = 'Validate a generated WFP against its Monthly Expenditure Matrix and the account-code master list';

    public function handle(): int
    {
        $plan = WfpPlan::find((int) $this->argument('plan'));

        if ($plan === null) {
            $this->components->error('No WFP plan with that id.');

            return self::FAILURE;
        }

        $report = WfpValidator::report($plan);

        $this->components->info('WFP '.$plan->fiscalYearLabel().' — plan #'.$plan->id);
        $this->newLine();

        $this->line('  Month totals (matrix vs plan):');

        foreach ($report['months'] as $month) {
            $flag = $month['matches'] ? 'ok' : 'MISMATCH';

            $this->line(sprintf(
                '    %-10s %14s %14s   %s',
                $month['label'],
                number_format($month['matrix'], 2),
                number_format($month['plan'], 2),
                $flag,
            ));
        }

        $grand = $report['grand'];
        $this->newLine();
        $this->line(sprintf(
            '  Grand total:   matrix %s   plan %s   %s',
            number_format($grand['matrix'], 2),
            number_format($grand['plan'], 2),
            $grand['matches'] ? 'ok' : 'MISMATCH',
        ));

        $this->newLine();

        if ($report['unknownCodes'] === []) {
            $this->line('  Account codes: all known to the master list.');
        } else {
            $this->components->warn('Account codes not on the master list:');

            foreach ($report['unknownCodes'] as $row) {
                $this->line(sprintf('    %-14s %d line(s)', $row['code'], $row['count']));
            }
        }

        $missing = $report['missing'];
        $this->line(sprintf(
            '  Missing enrichment: %d row(s) without a Major Output, %d without a Performance Indicator.',
            $missing['major_output'],
            $missing['performance_indicator'],
        ));

        if ($report['nearDuplicates'] !== []) {
            $this->newLine();
            $this->components->warn('Near-duplicate activities (warn only):');

            foreach ($report['nearDuplicates'] as $pair) {
                $this->line(sprintf('    “%s” ~ “%s” (distance %d)', $pair['a'], $pair['b'], $pair['distance']));
            }
        }

        $this->newLine();

        if ($report['passed']) {
            $this->components->info('Passed: totals reconcile and every account code is known.');

            return self::SUCCESS;
        }

        $this->components->error('Issues found — see the month totals and the report above.');

        return self::FAILURE;
    }
}

<?php

namespace App\Support;

use App\Models\AccountClassification;
use App\Models\User;
use RuntimeException;

/**
 * Builds the "Generate and Download WFP" workbook: the official WFP template
 * with one row per account code from the live `account_classifications` master
 * list, in UACS order.
 *
 * Only the Account Title and Object Code are written into each row — every
 * other column (Objectives, Programs/Activities, Major Outputs, Performance
 * Indicator, targets, Source of Fund, Org Classification, MOVs) is left blank
 * for the School Head to complete. The template's headings, merges, styles,
 * widths and TOTAL formula are preserved (the patching is done by
 * WfpTemplateFiller), and the shipped template file is never modified.
 */
final class WfpAccountCodeGenerator
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * The rows written into the workbook, one per active classification.
     *
     * Rows are ordered by UACS object code. The codes are fixed-width, so a
     * plain string sort is numeric order and stays portable across databases.
     * Only `account_title` and `account_code` are emitted: WfpTemplateFiller
     * writes just the keys a row carries and preserves every untouched cell,
     * so the remaining columns stay blank.
     *
     * @return array<int, array{account_title: string, account_code: string}>
     *
     * @throws RuntimeException when there is nothing to write, a row is
     *                          incomplete, or two titles share one code
     */
    public static function rows(): array
    {
        $classifications = AccountClassification::query()
            ->active()
            ->orderBy('uacs_object_code')
            ->orderBy('account_title')
            ->get();

        if ($classifications->isEmpty()) {
            throw new RuntimeException(
                'No account codes are available to generate a WFP. Import the Account Classifications list (Admin → Account Classifications) first.'
            );
        }

        $rows = [];
        $seen = [];

        foreach ($classifications as $classification) {
            $code = trim((string) $classification->uacs_object_code);
            $title = trim((string) $classification->account_title);

            if ($code === '' || $title === '') {
                throw new RuntimeException(
                    'An account classification is missing its Account Title or Object Code, so the WFP cannot be generated. Correct it in Admin → Account Classifications and try again.'
                );
            }

            if (isset($seen[$code])) {
                throw new RuntimeException(
                    'Two account classifications share the object code '.$code.' ("'.$seen[$code].'" and "'.$title.'"). Give each a unique Object Code in Admin → Account Classifications, then generate again.'
                );
            }

            $seen[$code] = $title;
            $rows[] = ['account_title' => $title, 'account_code' => $code];
        }

        return $rows;
    }

    /**
     * The generated workbook bytes: the official template patched with the
     * account-code rows.
     */
    public static function bytes(): string
    {
        return WfpTemplateFiller::fill(
            WfpTemplate::bytes(),
            self::rows(),
            WfpTemplate::inspect(),
        );
    }

    /**
     * The download's file name: `WFP_{year}_{School}.xlsx`. The school segment
     * is dropped (no dangling underscore) when the account has no school.
     */
    public static function downloadName(?User $user, ?int $year = null): string
    {
        $year ??= now()->year;

        $school = self::slugSegment((string) ($user?->school?->name ?? ''));

        return $school === ''
            ? 'WFP_'.$year.'.xlsx'
            : 'WFP_'.$year.'_'.$school.'.xlsx';
    }

    /**
     * How many active codes would be written — a caption only, never validated.
     */
    public static function activeCount(): int
    {
        return AccountClassification::query()->active()->count();
    }

    /** Sanitise one filename segment: non-alphanumerics → `_`, edges trimmed. */
    private static function slugSegment(string $value): string
    {
        return trim(preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?? '', '_');
    }
}

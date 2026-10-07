<?php

/*
|--------------------------------------------------------------------------
| OPCRF part availability (school-year terms)
|--------------------------------------------------------------------------
|
| The official template ships one part per tab — "PART I (CY 2025 &
| SY2025-2026)", "PART II", "PART III", "PART IV" — so "only part one" means
| rebuilding the workbook with the PART I tab as its only worksheet: the
| other parts are not merely hidden, they are not there.
|
| Every staff account gets the term-gated download:
|
|   - outside the school year's final term window the OPCRF Template card
|     serves a Part-I-only workbook,
|
|   - inside the window it serves the complete template (all four parts).
|
| The window is a recurring MM-DD pair so it re-opens on the same dates
| every year without a code change. When "closes" falls before "opens" the
| window is understood to run across the new year.
|
| Superadmins bypass the gate entirely: their Review Opcrf download always
| serves the submission's archived workbook as stored.
|
*/

return [

    'part_one' => [

        /*
         * The sheet the trimmed workbook keeps, matched against the tab name
         * as a whole word: "PART I" matches "PART I (CY 2025 & SY2025-2026)"
         * but never "PART II" or "PART IV".
         */
        'sheet' => env('OPCRF_PART_ONE_SHEET', 'PART I'),

        /*
         * Part One only, from the beginning: when true (the default) every
         * staff download is the template rebuilt with just the PART I sheet
         * — the full four-part workbook never opens on a schedule. Set the
         * env var to false to bring the term window below back into play.
         */
        'part_one_only' => env('OPCRF_PART_ONE_ONLY', true),

        /*
         * The window the full template opens in, as recurring month-day
         * pairs (MM-DD) — only consulted when part_one_only is false.
         */
        'term_opens' => env('OPCRF_TERM_OPENS', '03-16'),
        'term_closes' => env('OPCRF_TERM_CLOSES', '04-30'),

        // How the window is described to the staff member on the card.
        'term_label' => env('OPCRF_TERM_LABEL', 'the end of the school year term'),

    ],

    /*
    |----------------------------------------------------------------------
    | Automatic review routing
    |----------------------------------------------------------------------
    |
    | Every staff submission is routed to this superadmin automatically —
    | the staff member is never asked to pick a recipient. The value is
    | the account's **username** (resolved fresh on each submit, so the
    | id can differ per environment); when no account by that name exists
    | (or the env var is unset), the first superadmin by username receives
    | the submission instead, so a record is never stranded unroutable.
    |
    */

    'review_route' => env('OPCRF_REVIEW_ROUTE', 'Eve'),

    /*
    |----------------------------------------------------------------------
    | Onward routing after a compliance mark
    |----------------------------------------------------------------------
    |
    | When the reviewing superadmin marks a submission compliant/approved,
    | it is routed onward to this superadmin (by **username**) so the chain
    | keeps moving — SY in this deployment. The reviewer themself is never
    | the target; when no account by that name exists (or it IS the
    | reviewer), the first other superadmin by username receives it, and
    | with no other superadmin at all the compliance mark simply stands
    | alone.
    |
    */

    'next_reviewer' => env('OPCRF_NEXT_REVIEWER', 'SY'),

    /*
    |----------------------------------------------------------------------
    | OPCRF year and Part access
    |----------------------------------------------------------------------
    |
    | `year` is the DEFAULT cycle year, used when no schedule exists yet so
    | the superadmin's calendar starts somewhere. Once schedules are created
    | the newest scheduled year takes over (OpcrfAccess::currentYear()), so
    | this is a floor, not a hardcode — rolling the calendar forward is a
    | scheduling action, not a deploy.
    |
    | Which Parts a staff member may open, and when, is decided by the
    | superadmin in the opcrf_schedules table (App\Support\OpcrfAccess). The
    | `part_one` block below is the FALLBACK for installations with no
    | schedules yet.
    |
    */

    'year' => (int) env('OPCRF_YEAR', (int) now()->format('Y')),

    /*
    |----------------------------------------------------------------------
    | Personalized template
    |----------------------------------------------------------------------
    |
    | The template a staff member downloads is generated for them: their
    | registered name, position, school and division are written into the
    | header block of the real workbook, which is otherwise untouched. When
    | that file comes back, the name inside it is checked against the
    | account before the submission is accepted — see
    | App\Support\OpcrfTemplatePersonalizer.
    |
    | `version` is stamped into every download as a workbook defined name
    | (invisible in the form) and recorded per download, so a submission
    | can always be traced to the revision of the form it was answered on.
    | Bump it when the shipped template changes.
    |
    */

    'template' => [

        'version' => env('OPCRF_TEMPLATE_VERSION', '2026.1'),

        /*
         * The upload limit for a completed OPCRF, in kilobytes — 10 MB.
         * Enforced server-side on the upload itself.
         */
        'max_kb' => (int) env('OPCRF_TEMPLATE_MAX_KB', 10240),

    ],

];

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
         * The window the full template opens in, as recurring month-day
         * pairs (MM-DD).
         */
        'term_opens' => env('OPCRF_TERM_OPENS', '03-16'),
        'term_closes' => env('OPCRF_TERM_CLOSES', '04-30'),

        // How the window is described to the staff member on the card.
        'term_label' => env('OPCRF_TERM_LABEL', 'the end of the school year term'),

    ],

];

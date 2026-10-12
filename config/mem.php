<?php

/*
|--------------------------------------------------------------------------
| Monthly Expenditure Matrix (MEM) → WFP mapping
|--------------------------------------------------------------------------
|
| The MEM is the source of truth a Work and Financial Plan is generated from.
| This file holds the small amount of policy the mapping needs: the valid
| sections a line may belong to, and which WFP fields count as "needs review"
| when they end up empty.
|
| `wfp_flag_on_generation` lists the WFP-only fields — the ones the MEM never
| carries, so they are ALWAYS empty the first time a plan is generated — and
| `wfp_flag_when_empty` lists the carried fields that flag only when the MEM
| left them blank. Together they are the flaggable set: a field that is empty
| is flagged, and filling it in the editor clears its flag.
|
*/

return [

    /*
     * A line belongs to one of these sections. The stored value is the key;
     * the label is what the editor shows.
     */
    'sections' => [
        'operations' => 'Operations',
        'project' => 'Project',
    ],

    /*
     * The calendar months, 1-based, as the editor and CSV template write them.
     */
    'months' => [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ],

    /*
     * WFP-only fields: never carried from the MEM, so always flagged on the
     * first generation of a plan.
     */
    'wfp_flag_on_generation' => [
        'major_output',
        'performance_indicator',
    ],

    /*
     * Carried fields that flag only when the MEM left them empty (or the MEM
     * has no physical quantity to map).
     */
    'wfp_flag_when_empty' => [
        'physical_target',
        'funding_source',
        'organizational_classification',
        'activity_movs',
    ],

    /*
     * Bulk CSV import limits, mirroring the Account Classifications importer.
     */
    'import' => [
        'max_size_kb' => (int) env('MEM_IMPORT_MAX_KB', 5120),
        'extensions' => 'csv,txt',
    ],

];

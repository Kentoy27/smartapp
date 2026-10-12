<?php

return [

    /*
     * The shipped template's file name inside storage/forms/. The physical
     * file keeps a template-y name; `file_name` is what the card shows and
     * what the download is called.
     *
     * The default casing matches the file as shipped ("WFP-Template.xlsx").
     * The previous default ("WFP-TEMPLATE.xlsx") only resolved on a
     * case-insensitive filesystem (Windows), so a Linux deploy would not find
     * the template at all.
     */
    'template_file' => env('WFP_TEMPLATE_FILE', 'WFP-Template.xlsx'),

    'file_name' => env('WFP_FILE_NAME', 'WFP.xlsx'),

    // The card's short description.
    'description' => env(
        'WFP_DESCRIPTION',
        'Work and Financial Plan template for the school. Download the official template, complete the required financial and activity information, then upload the completed file.'
    ),

    // Upload size ceiling (KB).
    'max_size_kb' => (int) env('WFP_MAX_SIZE_KB', 10240),

    /*
     * Structure checks for an uploaded workbook. Matching is tolerant
     * (case/punctuation/whitespace-insensitive), so a completed copy of the
     * official template is accepted even after harmless edits.
     *
     * Every `required_keywords` phrase must appear somewhere in the sheet;
     * at least `min_sections` of the `section_keywords` must appear too.
     */
    'required_keywords' => [
        'work and financial plan',
    ],

    'section_keywords' => [
        'objectives',
        'programs, projects, & activities',
        'major outputs',
        'performance indicator',
        'physical target',
        'financial target',
        'source of fund',
        'object code',
    ],

    'min_sections' => 3,

    /*
     * ----------------------------------------------------------------------
     * Generated-WFP export: patching the official template
     * ----------------------------------------------------------------------
     *
     * The generated WFP is produced by patching the shipped WFP-Template.xlsx
     * itself — its headings, merges, styles, print area and TOTAL formulas are
     * preserved, and only the blank data rows are filled in. This block tells
     * WfpTemplateFiller where the template's table lives.
     *
     * Matching is tolerant (case/punctuation/whitespace-insensitive). Each
     * column may carry a `column` letter to PIN it, which is the escape hatch
     * when the automatic header scan cannot resolve one — run
     * `php artisan wfp:inspect-template` and copy the resolved letters here.
     */
    'template' => [

        // Stamped onto a plan at generation ("2026.1"), for traceability.
        'version' => env('WFP_TEMPLATE_VERSION', '2026.1'),

        // The worksheet the table is on, matched as a keyword against the tab
        // name. Falls back to the first sheet when no tab matches.
        'sheet' => env('WFP_TEMPLATE_SHEET', 'WFP'),

        // How many top rows to scan for the header row.
        'header_scan_rows' => (int) env('WFP_TEMPLATE_HEADER_SCAN_ROWS', 30),

        // Whether to also clone per-row merged ranges when rows are inserted
        // past the template's blank band. Off by default: cloning merges is
        // cosmetic and risks corrupting the merge table.
        'clone_row_merges' => (bool) env('WFP_TEMPLATE_CLONE_ROW_MERGES', false),

        /*
         * The table columns, keyed by WFP field. `labels` are the header
         * spellings recognised for the field; `column` (optional) pins the
         * letter and skips the header scan for that field.
         */
        'columns' => [
            'objective' => ['labels' => ['objectives', 'objective']],
            'program_activity' => ['labels' => ['programs projects and activities', 'programs projects & activities', 'program/activity', 'program activity', 'activities', 'programs']],
            'major_output' => ['labels' => ['major outputs', 'major output']],
            'performance_indicator' => ['labels' => ['performance indicator', 'performance indicators']],
            'physical_target' => ['labels' => ['physical target', 'physical targets', 'quantity', 'qty']],
            'physical_unit' => ['labels' => ['unit', 'units', 'uom']],
            'financial_target' => ['labels' => ['financial target', 'financial targets', 'amount', 'budget']],
            'account_title' => ['labels' => ['account title', 'account titles']],
            'account_code' => ['labels' => ['object code', 'account code', 'uacs code']],
            'funding_source' => ['labels' => ['source of fund', 'source of funds', 'funding source']],
            'organizational_classification' => ['labels' => ['organizational classification', 'org classification', 'organizational output']],
            'activity_movs' => ['labels' => ['movs', 'means of verification', 'activity movs']],
            'section' => ['labels' => ['section', 'category']],
        ],

        /*
         * When the template is monthly-shaped (a column per month), each line's
         * financial target is written into its own month's column instead of
         * being grouped into one annual row. Off for the annual-shaped
         * template; turn on after locating the month columns.
         */
        'months' => [
            'enabled' => (bool) env('WFP_TEMPLATE_MONTHS', false),
            'labels' => [
                1 => ['january', 'jan'],
                2 => ['february', 'feb'],
                3 => ['march', 'mar'],
                4 => ['april', 'apr'],
                5 => ['may'],
                6 => ['june', 'jun'],
                7 => ['july', 'jul'],
                8 => ['august', 'aug'],
                9 => ['september', 'sep', 'sept'],
                10 => ['october', 'oct'],
                11 => ['november', 'nov'],
                12 => ['december', 'dec'],
            ],
        ],

    ],

];

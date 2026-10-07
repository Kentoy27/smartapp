<?php

return [

    /*
     * The shipped template's file name inside storage/forms/. The physical
     * file keeps a template-y name; `file_name` is what the card shows and
     * what the download is called.
     */
    'template_file' => env('WFP_TEMPLATE_FILE', 'WFP-TEMPLATE.xlsx'),

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

];

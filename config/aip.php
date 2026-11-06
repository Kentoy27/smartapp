<?php

/*
|--------------------------------------------------------------------------
| Annual Implementation Plan (AIP)
|--------------------------------------------------------------------------
|
| AIP records support both the legacy project/activity form and the current
| FY matrix template. The FY form follows the supplied Annual Implementation
| Plan document: SIP pillars and objectives, strategy, PPAs, resources,
| budget, responsible persons, timeline, purpose, and approval blocks.
|
| The eight-column legacy form remains available for existing records.
| New online and DOCX-imported plans use the seven-column FY template below.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Who may author and who may review
    |----------------------------------------------------------------------
    |
    | An AIP belongs to a SCHOOL, so its author is the School Head (SH) of
    | that school — the same gate the WFP module uses (User::canAccessAip()).
    | The AIP review chain is configured by username, independently of the
    | OPCRF route: the Viewer reviews first, then the superadmins in order.
    |
    */

    // The upload/export ceiling for a submitted AIP document, in kilobytes.
    'max_kb' => (int) env('AIP_MAX_KB', 10240),

    'review_route' => env('AIP_REVIEW_ROUTE', 'Rebonie'),

    'next_reviewer' => env('AIP_NEXT_REVIEWER', 'Eve'),

    'final_reviewer' => env('AIP_FINAL_REVIEWER', 'SY'),

    /*
    |----------------------------------------------------------------------
    | Means of Verification integration
    |----------------------------------------------------------------------
    |
    | The approved AIP is evidence for a MOV requirement — the checklist's
    | "Approved Work and Financial Plan (WFP) and Annual Implementation Plan
    | (AIP)". Rather than hardcode a requirement id (ids differ per
    | environment), the requirement is located by a keyword in its title, so
    | a sync that renumbers MOVs cannot detach the feature.
    |
    */

    'mov_keyword' => env('AIP_MOV_KEYWORD', 'Annual Implementation Plan'),

    /*
    |----------------------------------------------------------------------
    | Export
    |----------------------------------------------------------------------
    |
    | The file name the .xlsx export is offered under. `%s` is the school
    | year (e.g. "2026").
    |
    */

    'export_name' => env('AIP_EXPORT_NAME', 'Annual Implementation Plan %s.xlsx'),

    /*
    |----------------------------------------------------------------------
    | Budget sources
    |----------------------------------------------------------------------
    |
    | The suggestions the budget-source field offers. A free-text value is
    | still accepted (schools add local funds), so this is a convenience,
    | never a whitelist.
    |
    */

    'budget_sources' => [
        'MOOE',
        'SEF',
        'School Canteen',
        'PTA / GPTA',
        'LGU / Barangay',
        'Donation',
        'General Fund',
        'Other',
    ],

    /*
    | The newer annual form groups implementation rows under these SIP pillars.
    */
    'pillars' => [
        'Pillar 1: ACCESS',
        'Pillar 2: EQUITY',
        'Pillar 3: QUALITY',
        'Pillar 4: RESILIENCE & WELL-BEING',
        'ENABLING MECHANISMS',
    ],

    'template' => [
        'purpose' => 'Purpose: This matrix is used to summarize the adjustments made to the SIP 2023–2028 for FY :year based on school needs, accomplishments, and contextual changes.',
        'reviewed_by' => [
            'name' => 'REBONIE F. EMBOLTORIO PhD',
            'role' => 'Senior Education program Specialist',
        ],
        'recommending_approval' => [
            'name' => 'EVA M. DOLLOSA RN',
            'role' => 'Assistant Schools Division Superintendent',
        ],
        'approved_by' => [
            'name' => 'FERDINAND S. SY PhD, CESO VI',
            'role' => 'Schools Division Superintendent',
        ],
    ],

];

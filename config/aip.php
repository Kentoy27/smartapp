<?php

/*
|--------------------------------------------------------------------------
| Annual Implementation Plan (AIP)
|--------------------------------------------------------------------------
|
| The AIP is the school's one-year operational breakdown of its School
| Improvement Plan (SIP): every School Improvement Project is listed with
| its objective and the output for the year, and each project's activities
| carry who is responsible, when/where, the budget per activity and the
| budget source.
|
| The column set follows the official form — "Annex 10: Annual
| Implementation Plan Template" of the enhanced SIP Guidebook (DepEd Order
| No. 44, s. 2015):
|
|   SCHOOL IMPROVEMENT PROJECT TITLE · PROJECT OBJECTIVE ·
|   OUTPUT FOR THE YEAR · ACTIVITIES · PERSON(S) RESPONSIBLE ·
|   SCHEDULE/VENUE · BUDGET PER ACTIVITY · BUDGET SOURCE
|
| The module deliberately does NOT invent extra official columns: the
| fields above are the form. Everything else the module stores (status,
| versions, reviewer, remarks) is workflow, not part of the printed form.
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
    | The reviewer is an administrative account (superadmin/administrator),
    | matching the OPCRF review workflow.
    |
    */

    // The upload/export ceiling for a submitted AIP document, in kilobytes.
    'max_kb' => (int) env('AIP_MAX_KB', 10240),

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

];

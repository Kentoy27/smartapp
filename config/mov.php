<?php

/*
|--------------------------------------------------------------------------
| MOV checklist structure
|--------------------------------------------------------------------------
|
| The Means of Verification checklist the app uploads against, in the shape
| it is written: PART → Category (A/B/C) → KRA → MOV. This file is the SOURCE of
| that catalogue — the tables are filled from it by `php artisan mov:sync`,
| which is idempotent (it updates titles, orders and the required flag on
| what it finds, adds what is new, and leaves anything removed here alone
| so nobody's uploads are destroyed by a sync).
|
| Each MOV carries the KRA printed beside it in the official OPCRF template.
| `mov:sync` copies that label to the requirement row without changing its
| identity, so uploads remain attached to the same MOV.
|
| Part 1 → A carries the full set of sixteen MOVs from the official
| template's Part I-A Means of Verification column, in the order the form
| lists them. MOV 7's sub-items (Research Committee Validation Form,
| Certificate of Presentation/Publication, SIP/AIP Integration Report,
| Mentoring/Coaching Documentation) belong to that one study, so they sit in
| its description rather than becoming MOVs of their own.
|
| Part 1 → B carries the four innovation/performance MOVs. MOV 1's four
| documents (a–d) follow the same rule as MOV 7: they are one verification,
| so they are listed in its description for staff to upload against, not
| numbered as MOVs the checklist does not have.
|
| Part 1 → C carries the three client-satisfaction and accountability MOVs.
| Where a MOV is really several documents that evidence one thing (the
| Charter, the ratings and the ICT systems; the CSM results, the tickets and
| the replies), they are one MOV with the parts named, not three numbers the
| checklist does not have.
|
| Part 1 is the whole checklist for now. Parts 2 and 3 were dropped: the
| forms they described were never filled in, and carrying them as empty
| headings only made the page look unfinished. They are RETIRED rather than
| deleted (is_active = false), so the rows — and anything uploaded against
| them — survive; this file simply stops listing them.
|
| NOTE: because mov:sync only adds and updates by default, removing a part
| here needs `php artisan mov:sync --retire-missing` for it to disappear
| from the page. A plain `mov:sync` would leave it active and still visible.
|
*/

return [

    'parts' => [
        [
            'name' => 'PART 1',
            'categories' => [
                [
                    'name' => 'A',
                    'movs' => [
                        [
                            'mov_number' => 1,
                            'kra_label' => 'KRA 1: School Leadership and Administration',
                            'title' => 'Approved Work and Financial Plan (WFP) and Annual Implementation Plan (AIP)',
                            'description' => 'The approved WFP and the AIP it is broken down into, as one signed set.',
                        ],
                        [
                            'mov_number' => 2,
                            'kra_label' => 'KRA 1: School Leadership and Administration',
                            'title' => 'Approved ISP / Approved MISAR',
                            'description' => 'The approved Indicator Success Plan, or the MISAR it replaced.',
                        ],
                        [
                            'mov_number' => 3,
                            'kra_label' => 'KRA 1: School Leadership and Administration',
                            'title' => 'Executed and duly signed Memoranda of Agreement (MOA), Deeds of Donation, and Partnership Agreements',
                            'description' => 'Signed copies of every agreement the office relies on for the year.',
                        ],
                        [
                            'mov_number' => 4,
                            'kra_label' => 'KRA 2: Teaching and Learning Delivery',
                            'title' => 'Report on Promotion Rate (School Form 6)',
                            'description' => 'The completed School Form 6 covering the promotion rate.',
                        ],
                        [
                            'mov_number' => 5,
                            'kra_label' => 'KRA 2: Teaching and Learning Delivery',
                            'title' => 'Report on Graduation Rate (School Form 6) stamped received and signed by appropriate signatories',
                            'description' => 'The completed School Form 6 covering the graduation rate, received and signed by the appropriate signatories.',
                        ],
                        [
                            'mov_number' => 6,
                            'kra_label' => 'KRA 2: Teaching and Learning Delivery',
                            'title' => 'Reports on MPS with Analysis',
                            'description' => 'The MPS reports, each with the analysis that goes with it.',
                        ],
                        [
                            'mov_number' => 7,
                            'kra_label' => 'KRA 2: Teaching and Learning Delivery',
                            'title' => 'Approved Research Proposal and Final Report',
                            'description' => 'The approved proposal and the final report, together with whichever of these the study produced: Division/School Research Committee Validation Form, Certificate of Presentation/Publication, SIP/AIP Integration or Utilization Report, Mentoring/Coaching Documentation.',
                        ],
                        [
                            'mov_number' => 8,
                            'kra_label' => 'KRA 3: Learner Formation and Development',
                            'title' => 'WINs Three-Star Approach Report',
                            'description' => 'The report on the WINs Three-Star Approach for the year.',
                        ],
                        [
                            'mov_number' => 9,
                            'kra_label' => 'KRA 3: Learner Formation and Development',
                            'title' => 'ACR of the School Programs and Activities',
                            'description' => 'The activity completion reports for the year’s programs and activities — Buwan ng Wika, Intramurals, NSED, Nutrition Month, Scilympics, and the rest.',
                        ],
                        [
                            'mov_number' => 10,
                            'kra_label' => 'KRA 3: Learner Formation and Development',
                            'title' => 'Contingency Plan and WeeLMat Monitoring Tool',
                            'description' => 'The contingency plan, and the monitoring tool recording how it was carried out.',
                        ],
                        [
                            'mov_number' => 11,
                            'kra_label' => 'KRA 3: Learner Formation and Development',
                            'title' => 'Child-Friendly School System (CFSS) Report',
                            'description' => 'The report on the school’s Child-Friendly School System for the year.',
                        ],
                        [
                            'mov_number' => 12,
                            'kra_label' => 'KRA 4: School Operations and Management',
                            'title' => 'Approved LAC Plan / Approved INSET Training Design, Attendance Sheet, and Activity Completion Reports (ACR)',
                            'description' => 'The professional-development set: the approved LAC plan or INSET training design, the attendance sheet, and the activity completion reports received and approved by the appropriate signatories.',
                        ],
                        [
                            'mov_number' => 13,
                            'kra_label' => 'KRA 4: School Operations and Management',
                            'title' => 'Summary of Ratings eIPCRF Summary',
                            'description' => 'The summary of ratings drawn from the eIPCRF.',
                        ],
                        [
                            'mov_number' => 14,
                            'kra_label' => 'KRA 4: School Operations and Management',
                            'title' => 'Report on Absences',
                            'description' => 'The report on teacher and personnel absences.',
                        ],
                        [
                            'mov_number' => 15,
                            'kra_label' => 'KRA 4: School Operations and Management',
                            'title' => 'Class and Teachers Program',
                            'description' => 'The class and teachers program for the year, signed by the appropriate signatories.',
                        ],
                        [
                            'mov_number' => 16,
                            'kra_label' => 'KRA 4: School Operations and Management',
                            'title' => 'SPIRPA - ACRs',
                            'description' => 'The activity completion reports from the School Program Implementation Review and Adjustment (SPIRPA).',
                        ],
                    ],
                ],
                [
                    'name' => 'B',
                    'movs' => [
                        [
                            'mov_number' => 1,
                            'kra_label' => 'KRA 1: School Leadership and Administration',
                            'title' => 'Innovation/Intervention documents',
                            // The four papers are one means of verification,
                            // not four separate MOVs — they document the same
                            // intervention end to end, so they sit in the
                            // description (as MOV 7 does) rather than taking
                            // numbers the checklist does not have.
                            'description' => 'Upload pictures of all four documents, labelled a to d: (a) Innovation/Intervention Plan, (b) Implementation Paper, (c) Accomplishment Report, (d) Certification of the Utilization or adoption.',
                        ],
                        [
                            'mov_number' => 2,
                            'kra_label' => 'KRA 2: Teaching and Learning Delivery',
                            'title' => 'ARAL Action Plan and Accomplishment Report',
                            'description' => 'The ARAL Action Plan together with the Accomplishment Report showing how the year was carried out.',
                        ],
                        [
                            'mov_number' => 3,
                            'kra_label' => 'KRA 3: Learner Formation and Development',
                            'title' => 'Report on Bullying, PEACE Action Plan, Accomplishment Report, and School Compliance Report',
                            'description' => 'All four papers: the Report on Bullying, the PEACE Action Plan, the Accomplishment Report, and the School Compliance Report.',
                        ],
                        [
                            'mov_number' => 4,
                            'kra_label' => 'KRA 4: School Operations and Management',
                            'title' => 'School Innovation Paper',
                            'description' => 'The School Innovation Paper with its attachments: the school memo, the criteria, the program, a sample of awards, and the ACR.',
                        ],
                    ],
                ],
                [
                    'name' => 'C',
                    'movs' => [
                        [
                            'mov_number' => 1,
                            'kra_label' => 'KRA 1: Financial Stewardship',
                            'title' => 'Liquidation Reports stamped received by the Accounting Unit',
                            'description' => 'Pictures of the liquidation reports, each bearing the stamp showing it was received by the Accounting Unit.',
                        ],
                        [
                            'mov_number' => 2,
                            'kra_label' => 'KRA 2: Process Improvement',
                            'title' => "Citizen's Charter, CSM Ratings, ICT-enabled systems developed",
                            'description' => "Three items in one: the Citizen's Charter, the CSM ratings, and the ICT-enabled systems the office developed.",
                        ],
                        [
                            'mov_number' => 3,
                            'kra_label' => 'KRA 3: Client Satisfaction',
                            'title' => 'CSM Results, 8888 Tickets, Answer to 8888 complaints',
                            'description' => 'The CSM results, the 8888 tickets, and the answers given to the 8888 complaints.',
                        ],
                    ],
                ],
            ],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Accepted uploads
    |----------------------------------------------------------------------
    |
    | MOV evidence may be a photo or a source document. Keep this list to
    | common non-executable formats used for plans, reports and certificates.
    |
    | Extensions are checked twice on upload: `mimes:` compares the file's
    | real MIME type against this list, and `extensions:` compares the name
    | the browser reported. Renaming a .exe to .jpg satisfies neither.
    |
    | A MOV accepts any number of files across repeat and batch uploads. The
    | cap is per file, not per MOV or upload batch.
    |
    */

    'allowed_extensions' => [
        'jpg', 'jpeg', 'png', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt',
    ],

    'max_kb' => 25600,

    /*
    | Where the files live on the local disk, relative to storage/app. Not
    | public: every download goes through a route that checks ownership.
    */
    'disk' => 'local',

    'directory' => 'mov-uploads',

];

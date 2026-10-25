<?php

use App\Models\WfpFundingSource;
use App\Models\WfpOrgClassification;
use App\Models\WfpResourceKind;
use App\Models\WfpUnit;

/*
|--------------------------------------------------------------------------
| WFP Lookup Library — administrator-managed master data
|--------------------------------------------------------------------------
|
| One entry per lookup category the WFP Setup module maintains. The entry
| drives everything about the category: its administrator CRUD screen, its
| bulk CSV import (labels, header aliases, required flags, length limits,
| the worked example row), and its CSV export. Adding a category later is a
| migration plus one entry here.
|
| Account titles / codes / object codes are deliberately absent: that master
| data already lives in `account_classifications` (the RCA / UACS reference
| table) and is maintained on its own page. Line types (operations / project)
| are fixed by the form, not administrable.
|
| `columns` is the single source of truth for a category's editable fields:
| the key is the database column, `label` is what the administrator sees,
| `aliases` are the header spellings the importer recognises (compared after
| lower-casing and stripping punctuation/spaces), and `boolean` marks the
| active/inactive column. Codes are only trimmed by the importer — never
| reformatted — so leading zeroes survive.
|
*/

return [

    /*
     * Bulk import limits for every lookup category.
     */
    'import' => [
        'max_size_kb' => (int) env('WFP_LOOKUP_IMPORT_MAX_KB', 5120),
        'extensions' => 'csv,txt',

        // A ceiling on data rows per import file. Bounds both the parse and
        // what the error report can hold.
        'max_rows' => (int) env('WFP_LOOKUP_IMPORT_MAX_ROWS', 2000),
    ],

    /*
     * The categories, in the order the WFP Setup module lists them.
     */
    'categories' => [

        'funding-sources' => [
            'model' => WfpFundingSource::class,
            'label' => 'Funding Sources',
            'singular' => 'Funding Source',
            'blurb' => 'The fund sources a WFP line may be charged to, such as MOOE or locally approved funds.',
            'columns' => [
                'code' => ['label' => 'Code', 'aliases' => ['code', 'fundingcode', 'fundsourcecode'], 'required' => false, 'max' => 64],
                'name' => ['label' => 'Name', 'aliases' => ['name', 'fundingsource', 'sourceoffund', 'sourceoffunds', 'fund'], 'required' => true, 'max' => 255],
                'description' => ['label' => 'Description', 'aliases' => ['description', 'remarks', 'notes'], 'required' => false, 'max' => 500],
                'is_active' => ['label' => 'Active', 'aliases' => ['active', 'isactive', 'status', 'enabled'], 'required' => false, 'max' => 8, 'boolean' => true],
            ],
            'sample' => ['MOOE', 'Maintenance and Other Operating Expenses', 'Regular school fund', 'Yes'],
        ],

        'units' => [
            'model' => WfpUnit::class,
            'label' => 'Units of Measure',
            'singular' => 'Unit of Measure',
            'blurb' => 'How quantities are counted on a line — ream, piece, set, box, lot and the like.',
            'columns' => [
                'code' => ['label' => 'Code', 'aliases' => ['code', 'unitcode'], 'required' => false, 'max' => 64],
                'name' => ['label' => 'Name', 'aliases' => ['name', 'unit', 'unitname', 'uom'], 'required' => true, 'max' => 255],
                'abbreviation' => ['label' => 'Abbreviation', 'aliases' => ['abbreviation', 'abbr', 'shortname', 'short'], 'required' => false, 'max' => 32],
                'is_active' => ['label' => 'Active', 'aliases' => ['active', 'isactive', 'status', 'enabled'], 'required' => false, 'max' => 8, 'boolean' => true],
            ],
            'sample' => ['REAM', 'Ream', 'rm', 'Yes'],
        ],

        'resource-kinds' => [
            'model' => WfpResourceKind::class,
            'label' => 'Resource Kinds',
            'singular' => 'Resource Kind',
            'blurb' => 'The kinds of resources a line may name, such as MOOE or other locally approved resource types.',
            'columns' => [
                'code' => ['label' => 'Code', 'aliases' => ['code', 'kindcode'], 'required' => false, 'max' => 64],
                'name' => ['label' => 'Name', 'aliases' => ['name', 'resourcekind', 'resource', 'kind'], 'required' => true, 'max' => 255],
                'description' => ['label' => 'Description', 'aliases' => ['description', 'remarks', 'notes'], 'required' => false, 'max' => 500],
                'is_active' => ['label' => 'Active', 'aliases' => ['active', 'isactive', 'status', 'enabled'], 'required' => false, 'max' => 8, 'boolean' => true],
            ],
            'sample' => ['MOOE', 'MOOE', 'Maintenance and Other Operating Expenses', 'Yes'],
        ],

        'org-classifications' => [
            'model' => WfpOrgClassification::class,
            'label' => 'Organizational Classifications',
            'singular' => 'Organizational Classification',
            'blurb' => 'The organizational level a line belongs to, such as School, District or Division.',
            'columns' => [
                'code' => ['label' => 'Code', 'aliases' => ['code', 'orgcode'], 'required' => false, 'max' => 64],
                'name' => ['label' => 'Name', 'aliases' => ['name', 'organizationalclassification', 'orgclassification', 'organizationaloutput', 'classification'], 'required' => true, 'max' => 255],
                'description' => ['label' => 'Description', 'aliases' => ['description', 'remarks', 'notes'], 'required' => false, 'max' => 500],
                'is_active' => ['label' => 'Active', 'aliases' => ['active', 'isactive', 'status', 'enabled'], 'required' => false, 'max' => 8, 'boolean' => true],
            ],
            'sample' => ['SCHOOL', 'School', 'The school itself', 'Yes'],
        ],

    ],

];

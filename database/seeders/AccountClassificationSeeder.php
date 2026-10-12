<?php

namespace Database\Seeders;

use App\Models\AccountClassification;
use Illuminate\Database\Seeder;

/**
 * Seeds a small, verified sample of Account Classification reference data.
 *
 * The seed is deliberately minimal: the plan's reference document lists many
 * account titles, but only the Office Supplies Expenses row carries a fully
 * specified RCA / UACS / PPAS combination. Only values that could be verified
 * are seeded here — the rest of the reference list should be added through the
 * Account Classifications module (or a future bulk import) after it is checked
 * against the organization's official accounting reference. Seeding invented
 * codes would be worse than an incomplete list.
 *
 * Idempotent: re-running it will not create duplicates.
 */
class AccountClassificationSeeder extends Seeder
{
    /**
     * @var array<int, array<string, string>>
     */
    private const REFERENCE = [
        [
            'account_title' => 'Office Supplies Expenses',
            'rca_code' => '50203010',
            'uacs_sub_object_code' => '00',
            'uacs_object_code' => '5020301000',
            'covering_ppas' => 'PPAS 1',
        ],
    ];

    public function run(): void
    {
        foreach (self::REFERENCE as $row) {
            if (AccountClassification::duplicateExists($row)) {
                continue;
            }

            AccountClassification::create([...$row, 'is_active' => true]);
        }
    }
}

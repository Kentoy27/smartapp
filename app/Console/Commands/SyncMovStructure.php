<?php

namespace App\Console\Commands;

use App\Models\MovCategory;
use App\Models\MovPart;
use App\Models\MovRequirement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills the MOV checklist tables from config/mov.php.
 *
 * Deliberately additive and non-destructive:
 *
 *   - a part/category/MOV named in the config is created if missing, and
 *     otherwise updated in place (title, description, order, required flag) —
 *     so a reworded requirement keeps its id, and therefore keeps everyone's
 *     uploads;
 *   - anything REMOVED from the config is left alone and merely retired
 *     (is_active = false). Deleting a requirement would cascade away the
 *     documents attached to it, which is never what "I tidied the config"
 *     meant.
 *
 * Re-running it is always safe: the command is how the checklist changes
 * (add MOV 5, add Part 4, correct a title) without touching a migration or
 * a view.
 */
class SyncMovStructure extends Command
{
    protected $signature = 'mov:sync
                            {--retire-missing : Also retire parts/categories/MOVs that the config no longer lists (they stay in the database, hidden) }';

    protected $description = 'Sync the MOV checklist (parts, categories, requirements) from config/mov.php';

    public function handle(): int
    {
        $parts = (array) config('mov.parts', []);

        if ($parts === []) {
            $this->components->error('config/mov.php lists no parts — nothing to sync.');

            return self::FAILURE;
        }

        $seenParts = [];
        $seenCategories = [];
        $seenRequirements = [];
        $touched = ['parts' => 0, 'categories' => 0, 'requirements' => 0];

        DB::transaction(function () use ($parts, &$seenParts, &$seenCategories, &$seenRequirements, &$touched): void {
            foreach (array_values($parts) as $partIndex => $part) {
                $partName = trim((string) ($part['name'] ?? ''));

                if ($partName === '') {
                    continue;
                }

                $partModel = MovPart::updateOrCreate(
                    ['name' => $partName],
                    ['part_order' => $partIndex + 1, 'is_active' => true],
                );
                $seenParts[] = $partModel->id;
                $touched['parts']++;

                foreach (array_values((array) ($part['categories'] ?? [])) as $categoryIndex => $category) {
                    $categoryName = trim((string) ($category['name'] ?? ''));

                    if ($categoryName === '') {
                        continue;
                    }

                    $categoryModel = MovCategory::updateOrCreate(
                        ['mov_part_id' => $partModel->id, 'name' => $categoryName],
                        ['category_order' => $categoryIndex + 1, 'is_active' => true],
                    );
                    $seenCategories[] = $categoryModel->id;
                    $touched['categories']++;

                    foreach (array_values((array) ($category['movs'] ?? [])) as $movIndex => $mov) {
                        $movNumber = (int) ($mov['mov_number'] ?? 0);

                        if ($movNumber <= 0) {
                            continue;
                        }

                        $requirement = MovRequirement::updateOrCreate(
                            ['mov_category_id' => $categoryModel->id, 'mov_number' => $movNumber],
                            [
                                'title' => trim((string) ($mov['title'] ?? '')) !== ''
                                    ? trim((string) $mov['title'])
                                    : 'Untitled MOV',
                                'description' => ($mov['description'] ?? null) !== null
                                    ? (string) $mov['description']
                                    : null,
                                'is_required' => (bool) ($mov['is_required'] ?? true),
                                'display_order' => $mov['display_order'] ?? $movIndex + 1,
                                'is_active' => true,
                            ],
                        );
                        $seenRequirements[] = $requirement->id;
                        $touched['requirements']++;
                    }
                }
            }
        });

        $retired = ['parts' => 0, 'categories' => 0, 'requirements' => 0];

        // Only when asked: leaving is_active alone keeps a requirement that
        // was merely dropped from the config visible and editable, instead of
        // silently disappearing from under the people who filled it in.
        if ($this->option('retire-missing')) {
            $retired['parts'] = MovPart::whereNotIn('id', $seenParts ?: [0])->update(['is_active' => false]);
            $retired['categories'] = MovCategory::whereNotIn('id', $seenCategories ?: [0])->update(['is_active' => false]);
            $retired['requirements'] = MovRequirement::whereNotIn('id', $seenRequirements ?: [0])->update(['is_active' => false]);
        }

        $this->components->info(sprintf(
            'MOV checklist synced: %d part(s), %d category(ies), %d requirement(s).',
            $touched['parts'],
            $touched['categories'],
            $touched['requirements'],
        ));

        if ($this->option('retire-missing')) {
            $this->components->info(sprintf(
                'Retired (hidden, uploads kept): %d part(s), %d category(ies), %d requirement(s).',
                $retired['parts'],
                $retired['categories'],
                $retired['requirements'],
            ));
        }

        return self::SUCCESS;
    }
}

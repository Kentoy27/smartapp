<?php

namespace Tests\Unit;

use App\Support\MemDuplicateChecker;
use Tests\TestCase;

class MemDuplicateCheckerTest extends TestCase
{
    // ------------------------------------------------------------ normalize

    public function test_normalisation_makes_case_spacing_and_punctuation_irrelevant(): void
    {
        $expected = 'procure office supplies';

        foreach ([
            'Procure office supplies',
            ' procure office supplies ',
            'PROCURE  OFFICE  SUPPLIES',
            "Procure\toffice\nsupplies",
            'Procure — office supplies',
            '“Procure office supplies.”',
            'Procure-office supplies',
        ] as $variant) {
            $this->assertSame($expected, MemDuplicateChecker::normalize($variant), $variant);
        }
    }

    public function test_normalisation_keeps_letters_and_digits_in_any_script(): void
    {
        $this->assertSame('peñaflor q1 2027', MemDuplicateChecker::normalize('  Peñaflor, Q1 2027!  '));
        $this->assertSame('', MemDuplicateChecker::normalize(null));
        $this->assertSame('', MemDuplicateChecker::normalize('   ...   '));
    }

    // ----------------------------------------------------------- similarity

    public function test_word_form_variants_score_as_similar(): void
    {
        // The plan's own examples of likely variations.
        foreach ([
            ['Procure office supplies', 'Procurement of office supplies'],
            ['Procure office supplies', 'Purchase office supply materials'],
            ['Procure office supplies', 'Purchase office supplies for Q2'],
        ] as [$a, $b]) {
            $score = MemDuplicateChecker::similarity(
                MemDuplicateChecker::normalize($a),
                MemDuplicateChecker::normalize($b),
            );

            $this->assertGreaterThanOrEqual(
                MemDuplicateChecker::DEFAULT_SIMILARITY_THRESHOLD,
                $score,
                $a.' ~ '.$b,
            );
        }
    }

    public function test_small_typos_score_as_similar(): void
    {
        $score = MemDuplicateChecker::similarity(
            MemDuplicateChecker::normalize('Procure office supplies'),
            MemDuplicateChecker::normalize('Procure offce supplies'),
        );

        $this->assertGreaterThanOrEqual(MemDuplicateChecker::DEFAULT_SIMILARITY_THRESHOLD, $score);
    }

    public function test_unrelated_descriptions_do_not_score_as_similar(): void
    {
        foreach ([
            ['Procure office supplies', 'Repair the school roof'],
            ['Procure office supplies', 'Conduct reading camp'],
            ['Procure office supplies', 'Pay the electricity bill'],
            ['Conduct reading camp', 'Conduct reading assessment'],
        ] as [$a, $b]) {
            $score = MemDuplicateChecker::similarity(
                MemDuplicateChecker::normalize($a),
                MemDuplicateChecker::normalize($b),
            );

            $this->assertLessThan(
                MemDuplicateChecker::DEFAULT_SIMILARITY_THRESHOLD,
                $score,
                $a.' ~ '.$b,
            );
        }
    }

    public function test_identical_text_scores_one(): void
    {
        $this->assertSame(1.0, MemDuplicateChecker::similarity('procure office supplies', 'procure office supplies'));
        $this->assertSame(0.0, MemDuplicateChecker::similarity('', 'procure office supplies'));
    }
}

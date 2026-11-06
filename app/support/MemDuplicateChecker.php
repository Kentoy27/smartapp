<?php

namespace App\Support;

use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use Illuminate\Database\Eloquent\Collection;

/**
 * The duplicate checker behind the Add-a-line form: "does a line with this
 * description already stand in THIS monthly matrix?"
 *
 * The matrix is the whole boundary. A line in another month — or another
 * school, or another division — is never a match: the same activity may
 * legitimately recur every month, so the search runs against one matrix id
 * and nothing else. Matching has two levels:
 *
 *  - an EXACT normalized match (case, spacing and punctuation made
 *    irrelevant), which is strong enough for the save gate to ask for an
 *    explicit "Add separately anyway" confirmation, and
 *  - a conservative SIMILAR match (word-form variations), which only ever
 *    suggests — it never blocks a save.
 *
 * Descriptions are compared in PHP over the current matrix's own lines: a
 * matrix holds tens of rows, so a plain full scan is honest and needs no
 * database-specific search index.
 */
class MemDuplicateChecker
{
    public const DEFAULT_MIN_QUERY_LENGTH = 3;

    /**
     * How alike two descriptions must be to count as "similar". Deliberately
     * conservative — a suggestion the user did not need costs more attention
     * than one that never appears.
     */
    public const DEFAULT_SIMILARITY_THRESHOLD = 0.7;

    public const DEFAULT_LIMIT = 5;

    /**
     * Words that name the same act of buying, mapped to one canonical token
     * before comparison — the plan's own example pairs "Procure office
     * supplies" with "Purchase office supply materials", and no amount of
     * string distance makes those two verbs look alike. Small on purpose:
     * only vocabulary this domain genuinely uses interchangeably.
     *
     * @var array<string, string>
     */
    private const SYNONYMS = [
        'procure' => 'procure',
        'procurement' => 'procure',
        'purchase' => 'procure',
        'purchases' => 'procure',
        'purchased' => 'procure',
        'purchasing' => 'procure',
        'buy' => 'procure',
        'acquire' => 'procure',
        'acquisition' => 'procure',
    ];

    /** The longest description the checker will compare (the field's own cap). */
    public const MAX_QUERY_LENGTH = 2000;

    /** Only the columns the suggestion panel and the warning read. */
    private const COLUMNS = [
        'id', 'section', 'objective', 'activity_description', 'resource_kind',
        'quantity', 'unit', 'unit_cost', 'calculated_total', 'account_title',
        'account_code', 'funding_source', 'organizational_classification',
        'activity_movs', 'major_output', 'performance_indicator', 'position',
    ];

    public static function minQueryLength(): int
    {
        return max(1, (int) config('mem.duplicates.min_query_length', self::DEFAULT_MIN_QUERY_LENGTH));
    }

    public static function similarityThreshold(): float
    {
        return (float) config('mem.duplicates.similarity_threshold', self::DEFAULT_SIMILARITY_THRESHOLD);
    }

    public static function limit(): int
    {
        return max(1, (int) config('mem.duplicates.limit', self::DEFAULT_LIMIT));
    }

    /**
     * The comparison form of a description: lowercased, with every run of
     * punctuation, quotes, dashes, line breaks or repeated spaces collapsed
     * to a single space and the ends trimmed. The stored description is never
     * changed — this exists only for the comparison.
     */
    public static function normalize(?string $text): string
    {
        $text = (string) $text;

        // Everything that is not a letter or a digit (in any script) becomes
        // one space, so "Procure — office supplies." and "procure  office
        // supplies" compare equal.
        $collapsed = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text, 'UTF-8'));

        return trim($collapsed ?? '');
    }

    /**
     * The matches for a description being typed, inside ONE monthly matrix,
     * exact matches first. An empty result means "nothing found" for a query
     * that was actually long enough — the caller shows that differently from
     * a failed request.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function find(
        MonthlyExpenditureMatrix $matrix,
        ?string $query,
        ?int $excludeItemId = null,
        ?int $limit = null,
    ): array {
        $normalized = self::normalize($query);

        if (mb_strlen($normalized) < self::minQueryLength()) {
            return [];
        }

        $exact = [];
        $similar = [];

        foreach (self::candidates($matrix, $excludeItemId) as $item) {
            $candidate = self::normalize($item->activity_description);

            if ($candidate === '') {
                continue;
            }

            if ($candidate === $normalized) {
                $exact[] = $item;

                continue;
            }

            $score = self::similarity($normalized, $candidate);

            if ($score >= self::similarityThreshold()) {
                $similar[] = ['score' => $score, 'item' => $item];
            }
        }

        usort($similar, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $matches = [];

        foreach ($exact as $item) {
            $matches[] = self::payload($item, 'exact');
        }

        foreach ($similar as $entry) {
            $matches[] = self::payload($entry['item'], 'similar');
        }

        return array_slice($matches, 0, $limit ?? self::limit());
    }

    /**
     * The first line in the matrix whose description is an exact normalized
     * match — the save gate's check. Null when none stands.
     */
    public static function exactMatch(
        MonthlyExpenditureMatrix $matrix,
        ?string $description,
        ?int $excludeItemId = null,
    ): ?MonthlyExpenditureItem {
        $normalized = self::normalize($description);

        if ($normalized === '') {
            return null;
        }

        foreach (self::candidates($matrix, $excludeItemId) as $item) {
            if (self::normalize($item->activity_description) === $normalized) {
                return $item;
            }
        }

        return null;
    }

    /**
     * How alike two NORMALIZED descriptions are, 0..1. Two independent
     * signals, whichever is stronger:
     *
     *  - character-level similarity (Levenshtein), which catches small
     *    typos and edits, and
     *  - word-level similarity (a dice coefficient over lightly stemmed
     *    words), which catches reordering and word-form variations like
     *    "Procure office supplies" / "Procurement of office supplies".
     */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        return max(
            self::characterSimilarity($a, $b),
            self::tokenSimilarity($a, $b),
        );
    }

    private static function characterSimilarity(string $a, string $b): float
    {
        // levenshtein() is byte-based; comparing the first 255 bytes keeps a
        // very long description from making the check slow.
        $a = mb_strcut($a, 0, 255, 'UTF-8');
        $b = mb_strcut($b, 0, 255, 'UTF-8');

        $longest = max(strlen($a), strlen($b));

        return $longest === 0 ? 0.0 : 1.0 - (levenshtein($a, $b) / $longest);
    }

    private static function tokenSimilarity(string $a, string $b): float
    {
        $aTokens = self::tokenSet($a);
        $bTokens = self::tokenSet($b);

        if ($aTokens === [] || $bTokens === []) {
            return 0.0;
        }

        $shared = count(array_intersect($aTokens, $bTokens));
        $smaller = min(count($aTokens), count($bTokens));

        $dice = (2 * $shared) / (count($aTokens) + count($bTokens));

        // One description's words all appearing in the other ("Procure
        // office supplies" inside "Procure office supplies for Q2") is a
        // strong signal on its own, even though the extra words dilute the
        // dice coefficient. Two shared words minimum, so a lone "the" or
        // "supplies" never carries a match by itself.
        $containment = ($smaller >= 2 && $shared === $smaller) ? 0.75 : 0.0;

        return max($dice, $containment);
    }

    /**
     * The words of a normalized description, lightly stemmed so word-form
     * variants compare equal: a known synonym collapses to its canonical
     * word, and any word longer than five characters is cut to its first
     * five ("supplies"/"supply" → "suppl", "procurement" → "procu"). A
     * heuristic, not linguistics — it only has to be good enough to surface
     * a suggestion.
     *
     * @return array<int, string>
     */
    private static function tokenSet(string $normalized): array
    {
        $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $set = [];

        foreach ($tokens as $token) {
            $token = self::SYNONYMS[$token] ?? $token;

            $set[mb_strlen($token) > 5 ? mb_substr($token, 0, 5) : $token] = true;
        }

        return array_keys($set);
    }

    /**
     * The compact shape the suggestion panel and the duplicate warning read.
     *
     * @return array<string, mixed>
     */
    public static function payload(MonthlyExpenditureItem $item, string $matchType): array
    {
        return [
            'id' => (int) $item->id,
            'match_type' => $matchType,
            'description' => (string) $item->activity_description,
            'section' => (string) ($item->section ?? 'operations'),
            'objective' => (string) ($item->objective ?? ''),
            'account_title' => (string) ($item->account_title ?? ''),
            'account_code' => (string) ($item->account_code ?? ''),
            'funding_source' => (string) ($item->funding_source ?? ''),
            'organizational_classification' => (string) ($item->organizational_classification ?? ''),
            'resource_kind' => (string) ($item->resource_kind ?? ''),
            'quantity' => $item->quantity !== null ? (string) (float) $item->quantity : '',
            'unit' => (string) ($item->unit ?? ''),
            'unit_cost' => $item->unit_cost !== null ? (string) (float) $item->unit_cost : '',
            'total' => $item->calculated_total !== null ? (float) $item->calculated_total : null,
            'activity_movs' => (string) ($item->activity_movs ?? ''),
            'major_output' => (string) ($item->major_output ?? ''),
            'performance_indicator' => (string) ($item->performance_indicator ?? ''),
        ];
    }

    /**
     * The lines the checker compares against: the matrix's own, minus the
     * line being edited (a line is never its own duplicate).
     *
     * @return Collection<int, MonthlyExpenditureItem>
     */
    private static function candidates(MonthlyExpenditureMatrix $matrix, ?int $excludeItemId): Collection
    {
        return MonthlyExpenditureItem::query()
            ->where('matrix_id', $matrix->id)
            ->when($excludeItemId !== null, fn ($query) => $query->whereKeyNot($excludeItemId))
            ->orderBy('position')
            ->orderBy('id')
            ->get(self::COLUMNS);
    }
}

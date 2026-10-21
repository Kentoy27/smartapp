<?php

namespace App\Support;

use App\Models\OpcrfSubmission;
use Illuminate\Support\Facades\Storage;

class OpcrfSubmissionWorkbook
{
    /**
     * @return array{
     *     title: string,
     *     headers: array,
     *     evaluators: array,
     *     purpose: array,
     *     parts: array,
     *     signers: array
     * }|null
     */
    public static function sheet(OpcrfSubmission $submission): ?array
    {
        if (! $submission->hasFile()) {
            return null;
        }

        try {
            $spreadsheet = new OpcrfSpreadsheet(
                Storage::disk('local')->path((string) $submission->downloadPath())
            );

            return [
                'title' => mb_substr($spreadsheet->title(), 0, 200),
                'headers' => $spreadsheet->headerCells(),
                'evaluators' => $spreadsheet->evaluatorCells(),
                'purpose' => $spreadsheet->purposeStatement(),
                'parts' => array_map(
                    static fn (array $part): array => [
                        'key' => $part['key'],
                        'title' => mb_substr($part['title'], 0, 200),
                        'note' => mb_substr($part['note'], 0, 600),
                        'rows' => self::numberedRows($part['entries']),
                        'total_score' => $part['total_score'],
                        'has_total_row' => $part['has_total_row'],
                        'captions' => $part['captions'] ?? [],
                        'fields' => array_keys($part['captions'] ?? []) ?: [
                            'kra', 'attribution', 'objective', 'timeline', 'weight',
                            'target_value', 'target_description', 'measure', 'scale',
                            'movs', 'accomplishments', 'rating', 'average', 'weighted_average',
                        ],
                        'bands' => $part['bands'] ?? ['planning' => '', 'evaluation' => ''],
                    ],
                    $spreadsheet->parts()
                ),
                'signers' => $spreadsheet->signers(),
            ];
        } catch (\RuntimeException) {
            return null;
        }
    }

    public static function workbook(OpcrfSubmission $submission): ?array
    {
        $sheet = self::sheet($submission);

        if ($sheet === null) {
            return null;
        }

        try {
            return (new OpcrfSpreadsheet(
                Storage::disk('local')->path((string) $submission->downloadPath())
            ))->workbook();
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private static function numberedRows(array $entries): array
    {
        return array_map(static function (array $entry, int $index): array {
            $entry['number'] = $index + 1;

            return $entry;
        }, array_values($entries), array_keys(array_values($entries)));
    }
}

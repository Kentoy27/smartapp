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
        if (! $submission->hasFile() && ! $submission->hasSignedFile()) {
            return null;
        }

        try {
            $spreadsheet = new OpcrfSpreadsheet(
                self::previewPath($submission)
            );

            return $spreadsheet->sheetLayout();
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
                self::previewPath($submission)
            ))->workbook();
        } catch (\RuntimeException) {
            return null;
        }
    }

    private static function previewPath(OpcrfSubmission $submission): string
    {
        $path = $submission->hasFile()
            ? $submission->file_path
            : $submission->downloadPath();

        return Storage::disk('local')->path((string) $path);
    }
}

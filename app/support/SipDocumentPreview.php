<?php

namespace App\Support;

use App\Models\SipSubmission;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Writer\HTML;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SipDocumentPreview
{
    public static function response(SipSubmission $submission): Response|BinaryFileResponse
    {
        $path = Storage::disk('local')->path($submission->file_path);
        $extension = strtolower(pathinfo($submission->downloadName(), PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            $response = response()->file($path, [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
            $response->setContentDisposition('inline', $submission->downloadName());
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }

        abort_unless(in_array($extension, ['doc', 'docx'], true), 415);

        $reader = $extension === 'doc'
            ? IOFactory::createReader('MsDoc')
            : IOFactory::createReader('Word2007');
        $document = $reader->load($path);
        $html = (new HTML($document))->getContent();
        $title = e($submission->downloadName());
        $styles = <<<'HTML'
            <style>
                html { background: #e2e8f0; }
                body { max-width: 1080px; margin: 24px auto; padding: 32px; color: #1e293b; background: #fff; box-shadow: 0 2px 12px #0f172a20; }
                img { max-width: 100%; height: auto; }
                table { max-width: 100%; border-collapse: collapse; }
                td { overflow-wrap: anywhere; }
                @media (max-width: 700px) { body { margin: 0; padding: 16px; } table { display: block; overflow-x: auto; } }
            </style>
            HTML;
        $titleCount = 0;
        $html = preg_replace_callback(
            '/<title>.*?<\/title>/s',
            fn (): string => '<title>'.$title.'</title>',
            $html,
            1,
            $titleCount,
        ) ?? $html;

        if ($titleCount === 0) {
            $html = str_replace('<head>', '<head><title>'.$title.'</title>', $html);
        }

        $html = str_replace('</head>', $styles.'</head>', $html);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox",
        ]);
    }
}

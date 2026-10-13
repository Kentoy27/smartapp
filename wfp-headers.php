<?php

/**
 * THROWAWAY diagnostic — dumps the WFP template's top rows so the real column
 * labels can be matched or pinned in config/wfp.php. Delete when done.
 *
 * Usage:  php wfp-headers.php
 */

require __DIR__.'/vendor/autoload.php';

use App\Support\PurePhpZipReader;

$path = __DIR__.'/storage/forms/WFP-Template.xlsx';

try {
    $parts = (new PurePhpZipReader($path))->readAll();
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not read template: {$e->getMessage()}\n");
    exit(1);
}

// Shared strings table (index => text).
$shared = [];

if (isset($parts['xl/sharedStrings.xml'])
    && preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $parts['xl/sharedStrings.xml'], $si)) {
    foreach ($si[1] as $one) {
        $text = '';
        if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $one, $ts)) {
            $text = implode('', $ts[1]);
        }
        $shared[] = trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
}

// Worksheet path => sheet name.
$names = [];

if (isset($parts['xl/workbook.xml'], $parts['xl/_rels/workbook.xml.rels'])) {
    $targets = [];
    if (preg_match_all('/<Relationship\b[^>]*>/', $parts['xl/_rels/workbook.xml.rels'], $rels)) {
        foreach ($rels[0] as $rel) {
            if (preg_match('/\bId="([^"]*)"/', $rel, $id) && preg_match('/\bTarget="([^"]*)"/', $rel, $t)) {
                $targets[$id[1]] = ltrim($t[1], '/');
            }
        }
    }
    if (preg_match('/<sheets>(.*?)<\/sheets>/s', $parts['xl/workbook.xml'], $blk)
        && preg_match_all('/<sheet\b[^>]*\/?>/', $blk[1], $els)) {
        foreach ($els[0] as $el) {
            if (preg_match('/\bname="([^"]*)"/', $el, $n)
                && preg_match('/\br:id="([^"]*)"/', $el, $r)
                && isset($targets[$r[1]])) {
                $tgt = $targets[$r[1]];
                $p = str_starts_with($tgt, '/') ? ltrim($tgt, '/') : 'xl/'.$tgt;
                $names[$p] = html_entity_decode($n[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }
}

$cellText = static function (string $element) use ($shared): string {
    if (str_contains($element, 't="inlineStr"') && preg_match('/<t\b[^>]*>(.*?)<\/t>/s', $element, $t)) {
        return trim(html_entity_decode($t[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
    if (preg_match('/<v>(.*?)<\/v>/s', $element, $v)) {
        $raw = html_entity_decode($v[1], ENT_QUOTES | ENT_XML1, 'UTF-8');

        return str_contains($element, 't="s"') ? ($shared[(int) $raw] ?? '') : $raw;
    }

    return '';
};

foreach ($parts as $pathKey => $content) {
    if (! preg_match('#^xl/worksheets/sheet\d+\.xml$#', $pathKey)) {
        continue;
    }

    $label = $names[$pathKey] ?? $pathKey;
    echo "=== SHEET: {$label}  ({$pathKey}) ===\n";

    if (preg_match('/<sheetData>(.*?)<\/sheetData>/s', $content, $sd) !== 1) {
        echo "  (no sheetData)\n\n";

        continue;
    }

    if (preg_match_all('/<row\b[^>]*\br="(\d+)"[^>]*(?:\/>|>(.*?)<\/row>)/s', $sd[1], $rows, PREG_SET_ORDER)) {
        foreach ($rows as $row) {
            $num = (int) $row[1];

            if ($num > 30) {
                continue;
            }

            $body = $row[2] ?? '';
            $cells = [];

            if (preg_match_all('#<c\b[^>]*\/>|<c\b[^>]*>.*?</c>#s', $body, $cs)) {
                foreach ($cs[0] as $c) {
                    if (preg_match('/\br="([A-Z]+)\d+"/', $c, $ref)) {
                        $cells[$ref[1]] = $cellText($c);
                    }
                }
            }

            $pairs = [];

            foreach ($cells as $col => $val) {
                if ($val !== '') {
                    $pairs[] = $col.'='.$val;
                }
            }

            if ($pairs === []) {
                continue;
            }

            echo '  r'.$num.': '.implode(' | ', $pairs)."\n";
        }
    }

    echo "\n";
}

echo "Done (rows 1..30 only).\n";

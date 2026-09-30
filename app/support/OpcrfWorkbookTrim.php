<?php

namespace App\Support;

use RuntimeException;

/**
 * Reduces an OPCRF workbook to a single part sheet.
 *
 * The official template keeps one part per tab — "PART I (CY 2025 &
 * SY2025-2026)", "PART II", "PART III", "PART IV" — so handing an account
 * "only Part One" means taking the package apart and rebuilding it without the
 * other tabs: hiding or protecting them would leave the rest one unhide away.
 *
 * An .xlsx is an OPC package: a zip of XML parts wired together by indices
 * that all have to agree, so a sheet is removed in every one of them —
 *
 *   xl/workbook.xml             the <sheets> list, plus any <definedName>
 *                               scoped to a sheet, whose localSheetId is a
 *                               *position* in that list and must be renumbered
 *   xl/_rels/workbook.xml.rels  the r:id → worksheets/sheetN.xml relationship
 *   [Content_Types].xml         the part's ContentType override
 *
 * — together with the sheet's own .rels part and whatever only it referenced
 * (its printer settings in this template), and with xl/calcChain.xml dropped
 * outright: it indexes formula cells by sheet position, so it would describe
 * sheets that no longer exist, and Excel rebuilds it on open anyway.
 *
 * Removal is finished with a reachability sweep: every part the package root
 * can no longer reach through a chain of .rels files (the removed tabs'
 * drawings, comment parts, printer settings) is pruned too, so the rebuilt
 * archive carries no dead weight.
 *
 * Everything else — styles, shared strings, the logo drawing, comments, and
 * the surviving sheet's XML itself — is copied through byte for byte, so Part
 * I still looks exactly like the official form.
 *
 * Zip handling goes through the app's own pure-PHP reader/writer rather than
 * ZipArchive so the trim also works on the XAMPP PHP build, which ships with
 * the zip extension disabled (the same reasoning as OpcrfSpreadsheet).
 *
 * @see \App\Support\OpcrfPartOne for who gets a trimmed workbook and when
 */
class OpcrfWorkbookTrim
{
    /**
     * Build the bytes of a copy of $sourcePath holding only the sheet whose
     * tab name is the requested part.
     *
     * @throws RuntimeException when $sourcePath is not a readable .xlsx
     */
    public static function keepPart(string $sourcePath, string $partKeyword): string
    {
        $parts = (new PurePhpZipReader($sourcePath))->readAll();
        $trimmed = self::keepPartContents($parts, $partKeyword);

        // Nothing needed dropping (single-sheet fixture, tabs not named by
        // part): the original bytes are served untouched rather than
        // re-zipped, so a no-op trim can never degrade the workbook.
        if ($trimmed === $parts) {
            return (string) file_get_contents($sourcePath);
        }

        return PurePhpZipWriter::create($trimmed);
    }

    /**
     * The same trim over an already unpacked package.
     *
     * A workbook with no matching tab — a single-sheet fixture, an older form
     * whose tabs are not named by part — comes back untouched: quietly
     * deleting every sheet would be worse than changing nothing, and the
     * caller still gets a workbook it can serve.
     *
     * @param  array<string, string>  $parts  package parts, in archive order
     * @return array<string, string>
     */
    public static function keepPartContents(array $parts, string $partKeyword): array
    {
        $workbook = $parts['xl/workbook.xml'] ?? null;
        $workbookRels = $parts['xl/_rels/workbook.xml.rels'] ?? null;

        if ($workbook === null || $workbookRels === null) {
            return $parts; // Not a package we can reason about — leave it alone.
        }

        $sheets = self::sheets($workbook);
        $kept = [];
        $dropped = [];

        foreach ($sheets as $index => $sheet) {
            // A sheet with no relationship to follow cannot be traced through
            // the package, so it is never a candidate for removal.
            if ($sheet['rid'] === null) {
                $kept[] = $index;

                continue;
            }

            if (self::isRequestedPart($sheet['name'], $partKeyword)) {
                $kept[] = $index;
            } else {
                $dropped[] = $index;
            }
        }

        if ($dropped === [] || $kept === []) {
            return $parts;
        }

        $removed = [];
        $droppedRids = [];
        $droppedNames = [];

        foreach ($dropped as $index) {
            $sheet = $sheets[$index];
            $target = self::relationshipTarget($workbookRels, $sheet['rid']);

            if ($target !== null) {
                $sheetPart = self::resolve('xl/', $target);

                $removed[] = $sheetPart;
                $removed[] = self::relationshipsPart($sheetPart);
            }

            $droppedRids[] = $sheet['rid'];
            $droppedNames[] = $sheet['raw'];
        }

        // The formula index, which would describe sheets that are gone, and
        // the parts only the removed sheets referenced (their printer
        // settings) — neither may outlive them.
        $calcChain = self::calcChain($workbookRels);

        if ($calcChain['part'] !== null) {
            $removed[] = $calcChain['part'];
        }

        $removed = array_values(array_unique(array_merge(
            $removed,
            self::orphaned($parts, $removed)
        )));

        $parts['xl/workbook.xml'] = self::reindexDefinedNames(
            self::removeSheets($workbook, $droppedRids),
            $kept
        );

        $parts['xl/_rels/workbook.xml.rels'] = self::removeRelationships(
            $workbookRels,
            $calcChain['rid'] === null ? $droppedRids : [...$droppedRids, $calcChain['rid']]
        );

        $parts['[Content_Types].xml'] = self::removeOverrides(
            $parts['[Content_Types].xml'] ?? '',
            $removed
        );

        if (isset($parts['docProps/app.xml'])) {
            $parts['docProps/app.xml'] = self::retitle($parts['docProps/app.xml'], $droppedNames);
        }

        foreach ($removed as $path) {
            unset($parts[$path]);
        }

        return $parts;
    }

    /* ------------------------------------------------------------------
     * Surgery helpers
     * ----------------------------------------------------------------- */

    /**
     * The workbook's <sheet> elements, in tab order.
     *
     * @return array<int, array{name: string, raw: string, rid: ?string}>
     */
    private static function sheets(string $workbook): array
    {
        $sheets = [];

        if (preg_match('/<sheets>(.*?)<\/sheets>/s', $workbook, $block)) {
            preg_match_all('/<sheet\b[^>]*>/u', $block[0], $elements);

            foreach ($elements[0] as $raw) {
                preg_match('/\bname="([^"]*)"/u', $raw, $name);
                preg_match('/\br:id="([^"]*)"/u', $raw, $rid);

                $sheets[] = [
                    // The raw attribute text — entities (&amp;) intact — is
                    // reused verbatim when pruning docProps/app.xml.
                    'raw' => $name[1] ?? '',
                    'name' => html_entity_decode($name[1] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                    'rid' => isset($rid[1]) ? $rid[1] : null,
                ];
            }
        }

        return $sheets;
    }

    /**
     * Whole-word keyword match: "PART I" matches "PART I (CY 2025 &
     * SY2025-2026)" but never "PART II" — the word boundary fails inside it.
     */
    private static function isRequestedPart(string $sheetName, string $partKeyword): bool
    {
        return preg_match('/\b'.preg_quote($partKeyword, '/').'\b/iu', $sheetName) === 1;
    }

    /**
     * The Target a workbook-level relationship id points at, or null.
     */
    private static function relationshipTarget(string $rels, string $rid): ?string
    {
        if (preg_match('/<Relationship\s[^>]*\bId="'.preg_quote($rid, '/').'"[^>]*\bTarget="([^"]*)"/u', $rels, $m)
            || preg_match('/<Relationship\s[^>]*\bTarget="([^"]*)"[^>]*\bId="'.preg_quote($rid, '/').'"/u', $rels, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Resolve a relationship Target against the directory of the part that
     * carries it: "worksheets/sheet1.xml" under "xl/" and
     * "../printerSettings/printerSettings1.bin" under "xl/worksheets/_rels/"
     * both land on real package paths.
     */
    private static function resolve(string $baseDirectory, string $target): string
    {
        $segments = [];

        foreach (explode('/', $baseDirectory.$target) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * The .rels part that describes $partName: "xl/worksheets/sheet1.xml" →
     * "xl/worksheets/_rels/sheet1.xml.rels", "xl/metadata" →
     * "xl/_rels/metadata.rels".
     */
    private static function relationshipsPart(string $partName): string
    {
        $slash = strrpos($partName, '/');

        return ($slash === false ? '' : substr($partName, 0, $slash + 1))
            .'_rels/'
            .($slash === false ? $partName : substr($partName, $slash + 1))
            .'.rels';
    }

    /**
     * The workbook's calcChain relationship — its r:id (so the rels entry can
     * go too) and its package path. Nulls when the workbook has none.
     *
     * @return array{rid: ?string, part: ?string}
     */
    private static function calcChain(string $workbookRels): array
    {
        $hasCalcChain =
            preg_match('/<Relationship\s[^>]*\bType="[^"]*\/calcChain"[^>]*\bId="([^"]*)"/u', $workbookRels, $m) === 1
            || preg_match('/<Relationship\s[^>]*\bId="([^"]*)"[^>]*\bType="[^"]*\/calcChain"/u', $workbookRels, $m) === 1;

        if (! $hasCalcChain) {
            return ['rid' => null, 'part' => null];
        }

        return [
            'rid' => $m[1],
            'part' => self::resolve('xl/', self::relationshipTarget($workbookRels, $m[1]) ?? 'calcChain.xml'),
        ];
    }

    /**
     * Drop the dropped sheets' <sheet> elements from the workbook's <sheets>
     * list, matched by relationship id (unique per sheet).
     */
    private static function removeSheets(string $workbook, array $rids): string
    {
        foreach ($rids as $rid) {
            $workbook = preg_replace(
                '/<sheet\s[^>]*\br:id="'.preg_quote($rid, '/').'"[^>]*(?:\/>|>\s*<\/sheet>)\s*/u',
                '',
                $workbook
            ) ?? $workbook;
        }

        return $workbook;
    }

    /**
     * Drop the removed parts' relationships from the workbook's rels.
     * Attribute order is preserved — the real template's rels do not all
     * share one (Id before Target or the reverse).
     */
    private static function removeRelationships(string $rels, array $rids): string
    {
        foreach ($rids as $rid) {
            $rels = preg_replace(
                '/<Relationship\s[^>]*\bId="'.preg_quote($rid, '/').'"[^>]*\/>\s*/u',
                '',
                $rels
            ) ?? $rels;
        }

        return $rels;
    }

    /**
     * Drop the removed parts' ContentType overrides from [Content_Types].xml.
     */
    private static function removeOverrides(string $contentTypes, array $partNames): string
    {
        foreach ($partNames as $part) {
            // '#' as the delimiter: the part names carry slashes, and
            // preg_quote only escapes the delimiter it is handed.
            $contentTypes = preg_replace(
                '#<Override\s[^>]*PartName="/'.preg_quote($part, '#').'"[^>]*/>#u',
                '',
                $contentTypes
            ) ?? $contentTypes;
        }

        return $contentTypes;
    }

    /**
     * Renumber <definedName localSheetId="…"> attributes: a localSheetId is a
     * *position* in the sheets list, so each surviving sheet's new position
     * is how many kept sheets come before it. Names scoped to a dropped sheet
     * (a print area on PART IV, say) are removed outright — renumbering would
     * silently re-scope them onto some other sheet. Defined names without a
     * localSheetId (workbook scope) are left alone.
     */
    private static function reindexDefinedNames(string $workbook, array $keptPositions): string
    {
        if (! preg_match('/<definedNames>.*?<\/definedNames>/s', $workbook, $block)) {
            return $workbook;
        }

        $block = $block[0];
        $keptPositions = array_values($keptPositions);

        $rebuilt = preg_replace_callback(
            '/<definedName\b[^>]*>.*?<\/definedName>/s',
            function (array $m) use ($keptPositions): string {
                if (! preg_match('/localSheetId="(\d+)"/', $m[0], $id)) {
                    return $m[0];
                }

                $position = (int) $id[1];

                if (! in_array($position, $keptPositions, true)) {
                    return ''; // Scoped to a removed sheet.
                }

                $newPosition = count(array_filter(
                    $keptPositions,
                    fn (int $kept): bool => $kept < $position
                ));

                return str_replace('localSheetId="'.$position.'"', 'localSheetId="'.$newPosition.'"', $m[0]);
            },
            $block
        );

        return str_replace($block, (string) $rebuilt, $workbook);
    }

    /**
     * Update docProps/app.xml so its listed tab names match the rebuilt
     * workbook: TitlesOfParts loses the dropped tabs (and their named-range
     * entries), the vector size follows, and the Worksheets / Named Ranges
     * counts in HeadingPairs are recomputed.
     */
    private static function retitle(string $appXml, array $droppedNames): string
    {
        if (! preg_match('/<TitlesOfParts>.*?<\/TitlesOfParts>/s', $appXml, $block)) {
            return $appXml;
        }

        $block = $block[0];

        if (! preg_match_all('/<vt:lpstr>(.*?)<\/vt:lpstr>/s', $block, $titles) || $titles[1] === []) {
            return $appXml;
        }

        $keptTitles = array_values(array_filter($titles[1], function (string $raw) use ($droppedNames): bool {
            foreach ($droppedNames as $name) {
                $plain = html_entity_decode($name, ENT_XML1 | ENT_QUOTES, 'UTF-8');

                // The tab's own title and any "'TAB NAME'!Print_Area"-style
                // named-range titles that point into it (with or without
                // the Excel-style quotes around the tab name).
                if ($raw === $name || $raw === $plain
                    || preg_match('/^\'?'.preg_quote($name, '/').'\'?!/', $raw)
                    || preg_match('/^\'?'.preg_quote($plain, '/').'\'?!/', $raw)) {
                    return false;
                }
            }

            return true;
        }));

        $rebuilt = '<TitlesOfParts><vt:vector size="'.count($keptTitles).'" baseType="lpstr">'
            .implode('', array_map(fn (string $t): string => '<vt:lpstr>'.$t.'</vt:lpstr>', $keptTitles))
            .'</vt:vector></TitlesOfParts>';

        $appXml = str_replace($block, $rebuilt, $appXml);

        if (preg_match('/<HeadingPairs>.*?<\/HeadingPairs>/s', $appXml, $headingBlock)) {
            $headingBlock = $headingBlock[0];

            $headingRebuilt = preg_replace_callback(
                '/<vt:lpstr>([^<]*)<\/vt:lpstr>(<\/vt:variant><vt:variant>)<vt:i4>(\d+)<\/vt:i4>/s',
                function (array $m) use ($keptTitles): string {
                    $heading = $m[1];

                    if ($heading !== 'Worksheets' && $heading !== 'Named Ranges') {
                        return $m[0];
                    }

                    $count = 0;

                    foreach ($keptTitles as $title) {
                        $isNamedRange = str_contains($title, '!');

                        if ($heading === 'Named Ranges' ? $isNamedRange : ! $isNamedRange) {
                            $count++;
                        }
                    }

                    return '<vt:lpstr>'.$heading.'</vt:lpstr>'.$m[2].'<vt:i4>'.$count.'</vt:i4>';
                },
                $headingBlock
            );

            $appXml = str_replace($headingBlock, (string) $headingRebuilt, $appXml);
        }

        return $appXml;
    }

    /**
     * Parts the package root can no longer reach — the removed sheets'
     * printer settings, drawings, comment parts, and any .rels describing
     * them. Found by walking relationships depth-first from _rels/.rels,
     * never stepping through a removed part.
     *
     * @param  array<string, string>  $parts
     * @param  array<int, string>  $removed  already-condemned parts (traversal barrier)
     * @return array<int, string>
     */
    private static function orphaned(array $parts, array $removed): array
    {
        $removedSet = array_fill_keys($removed, true);
        $referenced = [];
        $keptRels = [];
        $stack = ['_rels/.rels'];

        while ($stack !== []) {
            $relsName = array_pop($stack);

            if (! isset($parts[$relsName]) || isset($keptRels[$relsName])) {
                continue;
            }

            $keptRels[$relsName] = true;

            // A .rels part describes targets relative to the directory its
            // owner sits in: "xl/_rels/workbook.xml.rels" → "xl/".
            $marker = strrpos($relsName, '_rels/');
            $sourceDir = $marker === false ? '' : substr($relsName, 0, $marker);

            if (! preg_match_all('/\bTarget="([^"]*)"/u', $parts[$relsName], $targets)) {
                continue;
            }

            foreach ($targets[1] as $target) {
                if (preg_match('/^\w+:/', $target)) {
                    continue; // An external target (http:, mailto:, …) — not a package part.
                }

                $resolved = self::resolve($sourceDir, $target);

                if (isset($referenced[$resolved]) || isset($removedSet[$resolved])) {
                    continue;
                }

                $referenced[$resolved] = true;

                $childRels = self::relationshipsPart($resolved);

                if (isset($parts[$childRels])) {
                    $stack[] = $childRels;
                }
            }
        }

        // Keep exactly what the root still reaches: the referenced parts, the
        // .rels parts read on the way, and the content types. Everything else
        // is unreachable now.
        $orphaned = [];

        foreach (array_keys($parts) as $name) {
            if ($name === '[Content_Types].xml' || isset($referenced[$name]) || isset($keptRels[$name])) {
                continue;
            }

            $orphaned[] = $name;
        }

        return $orphaned;
    }
}

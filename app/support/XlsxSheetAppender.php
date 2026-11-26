<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Appends worksheets to an existing .xlsx package and folds each donor's
 * style table into the package's single styles part.
 *
 * OOXML gives a workbook ONE styles part, and every cell's `s` index points
 * into that part's cellXfs table. A worksheet built by another exporter
 * therefore cannot be dropped in as-is: its indexes are relative to its own
 * table. This class merges the donor's number formats, fonts, fills, borders
 * and cellXfs into the package's table and rewrites the donor sheet's `s`
 * indexes by the offset the merge assigned it.
 *
 * It supports what the app's from-scratch exporters produce — worksheets with
 * inline strings and no drawings of their own. The base workbook may carry
 * anything (the WFP template's theme, shared strings, printer settings and
 * signature drawings are preserved untouched), because only the sheets passed
 * in are rewritten.
 */
final class XlsxSheetAppender
{
    private const SHEET_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml';

    private const WORKSHEET_RELATIONSHIP = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet';

    /**
     * Append worksheets to a base workbook.
     *
     * @param  array<int, array{name: string, xml: string, styles?: string}>  $sheets
     *         `styles` is the donor workbook's complete xl/styles.xml. Sheets
     *         sharing the same string share one merged table; omit it when the
     *         sheet already uses the base workbook's style indexes.
     * @return array{bytes: string, parts: array<int, string>}
     *         `parts` holds each appended sheet's worksheet part, in the order
     *         given, so the caller can stamp signatures into it afterwards.
     *
     * @throws RuntimeException when the base is not a readable workbook or a
     *                          sheet would collide with an existing tab
     */
    public static function append(string $baseBytes, array $sheets): array
    {
        if ($sheets === []) {
            return ['bytes' => $baseBytes, 'parts' => []];
        }

        $parts = self::readParts($baseBytes);

        foreach (['xl/workbook.xml', 'xl/_rels/workbook.xml.rels', '[Content_Types].xml', 'xl/styles.xml'] as $required) {
            if (! isset($parts[$required])) {
                throw new RuntimeException('The workbook is missing its '.$required.' part.');
            }
        }

        $workbook = $parts['xl/workbook.xml'];
        $relationships = $parts['xl/_rels/workbook.xml.rels'];
        $contentTypes = $parts['[Content_Types].xml'];
        $styles = $parts['xl/styles.xml'];

        $existingNames = self::sheetNames($workbook);
        $sheetPartNumber = self::nextNumber(array_keys($parts), '#^xl/worksheets/sheet(\d+)\.xml$#');
        $relationshipNumber = self::nextNumber([$relationships], '/(?<![:\w])Id="rId(\d+)"/');
        $sheetId = self::nextNumber([$workbook], '/\bsheetId="(\d+)"/');

        /** @var array<string, int> $offsets */
        $offsets = [];
        $sheetEntries = '';
        $relationshipEntries = '';
        $contentOverrides = '';
        $appendedParts = [];

        foreach ($sheets as $sheet) {
            $name = (string) ($sheet['name'] ?? '');
            $xml = (string) ($sheet['xml'] ?? '');
            $donorStyles = (string) ($sheet['styles'] ?? '');

            if ($name === '' || $xml === '') {
                throw new RuntimeException('An appended worksheet needs a name and XML.');
            }

            if (in_array($name, $existingNames, true)) {
                throw new RuntimeException('The workbook already has a “'.$name.'” worksheet.');
            }

            $offset = 0;

            if ($donorStyles !== '') {
                if (! array_key_exists($donorStyles, $offsets)) {
                    [$styles, $offset] = self::mergeStyles($styles, $donorStyles);
                    $offsets[$donorStyles] = $offset;
                } else {
                    $offset = $offsets[$donorStyles];
                }
            }

            if ($offset > 0) {
                $xml = preg_replace_callback(
                    '/\bs="(\d+)"/',
                    static fn (array $found): string => 's="'.((int) $found[1] + $offset).'"',
                    $xml,
                ) ?? $xml;
            }

            $part = 'xl/worksheets/sheet'.$sheetPartNumber.'.xml';
            $parts[$part] = $xml;
            $appendedParts[] = $part;

            $relationshipId = 'rId'.$relationshipNumber;

            $sheetEntries .= '<sheet name="'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                .'" sheetId="'.$sheetId.'" r:id="'.$relationshipId.'"/>';
            $relationshipEntries .= '<Relationship Id="'.$relationshipId.'" Type="'.self::WORKSHEET_RELATIONSHIP
                .'" Target="worksheets/sheet'.$sheetPartNumber.'.xml"/>';
            $contentOverrides .= '<Override PartName="/xl/worksheets/sheet'.$sheetPartNumber.'.xml" '
                .'ContentType="'.self::SHEET_CONTENT_TYPE.'"/>';

            $existingNames[] = $name;
            $sheetPartNumber++;
            $relationshipNumber++;
            $sheetId++;
        }

        $workbook = self::insertBefore($workbook, '</sheets>', $sheetEntries);
        $relationships = self::insertBefore($relationships, '</Relationships>', $relationshipEntries);
        $contentTypes = self::insertBefore($contentTypes, '</Types>', $contentOverrides);

        $parts['xl/workbook.xml'] = $workbook;
        $parts['xl/_rels/workbook.xml.rels'] = $relationships;
        $parts['[Content_Types].xml'] = $contentTypes;
        $parts['xl/styles.xml'] = $styles;

        return ['bytes' => PurePhpZipWriter::create($parts), 'parts' => $appendedParts];
    }

    /**
     * Put the workbook's tabs in the given order and open the named one.
     *
     * Tab order is the order of `<sheet>` entries, while a sheet's part name,
     * sheetId and relationships stay put — so the only references that move
     * are the 0-based `localSheetId` of defined names (the WFP template's
     * Print_Area) and the workbook view's `activeTab`.
     *
     * @param  array<int, string>  $tabOrder  every tab, in the wanted order
     *
     * @throws RuntimeException when the base is not a readable workbook
     */
    public static function arrange(string $bytes, array $tabOrder, string $activeTab): string
    {
        $parts = self::readParts($bytes);
        $workbook = $parts['xl/workbook.xml'] ?? null;

        if ($workbook === null) {
            throw new RuntimeException('The workbook is missing its xl/workbook.xml part.');
        }

        /** @var array<string, string> $entries */
        $entries = [];

        if (preg_match_all('/<sheet\b[^>]*\/>/u', $workbook, $found) === false) {
            throw new RuntimeException('The workbook has no readable sheet list.');
        }

        foreach ($found[0] as $element) {
            $name = self::attribute($element, 'name');

            if ($name !== null) {
                $entries[$name] = $element;
            }
        }

        $oldOrder = array_keys($entries);
        $ordered = [];

        foreach ($tabOrder as $name) {
            if (isset($entries[$name])) {
                $ordered[] = $entries[$name];
                unset($entries[$name]);
            }
        }

        // A tab the caller did not name keeps its relative place at the end.
        foreach ($entries as $element) {
            $ordered[] = $element;
        }

        if (count($ordered) !== count($oldOrder)) {
            throw new RuntimeException('The workbook sheet list could not be rebuilt.');
        }

        $workbook = self::replaceBlock($workbook, 'sheets', implode('', $ordered));

        $newOrder = [];

        foreach ($ordered as $element) {
            $name = self::attribute($element, 'name');

            if ($name !== null) {
                $newOrder[] = $name;
            }
        }

        // Defined names are scoped by the sheet's 0-based INDEX, so moving the
        // WFP tab to the end moves its Print_Area with it.
        $workbook = preg_replace_callback(
            '/\blocalSheetId="(\d+)"/',
            static function (array $found) use ($oldOrder, $newOrder): string {
                $name = $oldOrder[(int) $found[1]] ?? null;
                $index = $name === null ? false : array_search($name, $newOrder, true);

                return $index === false ? $found[0] : 'localSheetId="'.$index.'"';
            },
            $workbook,
        ) ?? $workbook;

        $activeIndex = array_search($activeTab, $newOrder, true);
        $activeIndex = $activeIndex === false ? 0 : $activeIndex;

        $workbook = self::setActiveTab($workbook, $activeIndex);

        // One selected tab, and it is the one the workbook view names.
        foreach (array_keys($parts) as $part) {
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $part) === 1) {
                $parts[$part] = preg_replace('/\s+tabSelected="1"/', '', $parts[$part], 1) ?? $parts[$part];
            }
        }

        $activePart = self::sheetPart($workbook, $parts['xl/_rels/workbook.xml.rels'] ?? '', $activeTab);

        if ($activePart !== null && isset($parts[$activePart])) {
            $parts[$activePart] = preg_replace(
                '/<sheetView\b/',
                '<sheetView tabSelected="1"',
                $parts[$activePart],
                1,
            ) ?? $parts[$activePart];
        }

        $parts['xl/workbook.xml'] = $workbook;

        return PurePhpZipWriter::create($parts);
    }

    /* ------------------------------------------------------------- styles */

    /**
     * Fold a donor style table into the base one.
     *
     * @return array{0: string, 1: int} the merged styles XML, and the index
     *                                  the donor's cellXfs 0 now occupies
     */
    private static function mergeStyles(string $baseXml, string $donorXml): array
    {
        $base = new DOMDocument();
        $donor = new DOMDocument();

        if (@$base->loadXML($baseXml) === false || @$donor->loadXML($donorXml) === false) {
            throw new RuntimeException('A workbook style table could not be read.');
        }

        $baseRoot = $base->documentElement;
        $donorRoot = $donor->documentElement;

        if ($baseRoot === null || $donorRoot === null) {
            throw new RuntimeException('A workbook style table could not be read.');
        }

        $baseNumFmts = self::section($base, 'numFmts');
        $donorNumFmts = self::section($donor, 'numFmts');
        $baseFonts = self::section($base, 'fonts');
        $donorFonts = self::section($donor, 'fonts');
        $baseFills = self::section($base, 'fills');
        $donorFills = self::section($donor, 'fills');
        $baseBorders = self::section($base, 'borders');
        $donorBorders = self::section($donor, 'borders');
        $baseXfs = self::section($base, 'cellXfs');
        $donorXfs = self::section($donor, 'cellXfs');

        if ($baseFonts === null || $baseFills === null || $baseBorders === null || $baseXfs === null) {
            throw new RuntimeException('The workbook has no styles table to extend.');
        }

        $fontOffset = self::childCount($baseFonts, 'font');
        $fillOffset = self::childCount($baseFills, 'fill');
        $borderOffset = self::childCount($baseBorders, 'border');
        $cellXfOffset = self::childCount($baseXfs, 'xf');

        // Number formats are allocated fresh IDs: the base may already use a
        // donor's custom id (the WFP template uses 164 and 165), and two
        // different formats must never share one.
        $numFmtMap = [];

        if ($donorNumFmts !== null) {
            $nextNumFmtId = max(163, self::highestAttribute($baseNumFmts, 'numFmt', 'numFmtId')) + 1;

            if ($baseNumFmts === null) {
                $baseNumFmts = $base->createElementNS(
                    'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
                    'numFmts',
                );
                $baseRoot->insertBefore($baseNumFmts, $baseRoot->firstChild);
            }

            foreach (self::children($donorNumFmts, 'numFmt') as $numFmt) {
                $imported = $base->importNode($numFmt, true);

                if (! $imported instanceof DOMElement) {
                    continue;
                }

                $oldId = (int) $imported->getAttribute('numFmtId');
                $numFmtMap[$oldId] = $nextNumFmtId;
                $imported->setAttribute('numFmtId', (string) $nextNumFmtId);
                $baseNumFmts->appendChild($imported);
                $nextNumFmtId++;
            }

            $baseNumFmts->setAttribute('count', (string) self::childCount($baseNumFmts, 'numFmt'));
        }

        foreach (self::children($donorFonts, 'font') as $font) {
            $imported = $base->importNode($font, true);

            if ($imported !== false) {
                $baseFonts->appendChild($imported);
            }
        }

        foreach (self::children($donorFills, 'fill') as $fill) {
            $imported = $base->importNode($fill, true);

            if ($imported !== false) {
                $baseFills->appendChild($imported);
            }
        }

        foreach (self::children($donorBorders, 'border') as $border) {
            $imported = $base->importNode($border, true);

            if ($imported !== false) {
                $baseBorders->appendChild($imported);
            }
        }

        foreach (self::children($donorXfs, 'xf') as $xf) {
            $imported = $base->importNode($xf, true);

            if (! $imported instanceof DOMElement) {
                continue;
            }

            self::shiftAttribute($imported, 'fontId', $fontOffset);
            self::shiftAttribute($imported, 'fillId', $fillOffset);
            self::shiftAttribute($imported, 'borderId', $borderOffset);

            $numFmtId = (int) $imported->getAttribute('numFmtId');

            if (isset($numFmtMap[$numFmtId])) {
                $imported->setAttribute('numFmtId', (string) $numFmtMap[$numFmtId]);
            }

            $baseXfs->appendChild($imported);
        }

        $baseFonts->setAttribute('count', (string) self::childCount($baseFonts, 'font'));
        $baseFills->setAttribute('count', (string) self::childCount($baseFills, 'fill'));
        $baseBorders->setAttribute('count', (string) self::childCount($baseBorders, 'border'));
        $baseXfs->setAttribute('count', (string) self::childCount($baseXfs, 'xf'));

        return [$base->saveXML() ?: $baseXml, $cellXfOffset];
    }

    /** The styleSheet child section, or null when the table has none. */
    private static function section(DOMDocument $document, string $name): ?DOMElement
    {
        $root = $document->documentElement;

        if ($root === null) {
            return null;
        }

        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * The section's direct children with the given local name.
     *
     * @return array<int, DOMElement>
     */
    private static function children(?DOMElement $section, string $name): array
    {
        if ($section === null) {
            return [];
        }

        $children = [];

        foreach ($section->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private static function childCount(DOMElement $section, string $name): int
    {
        return count(self::children($section, $name));
    }

    private static function highestAttribute(?DOMElement $section, string $child, string $attribute): int
    {
        $highest = 0;

        foreach (self::children($section, $child) as $element) {
            $highest = max($highest, (int) $element->getAttribute($attribute));
        }

        return $highest;
    }

    private static function shiftAttribute(DOMElement $element, string $attribute, int $offset): void
    {
        if ($offset === 0 || ! $element->hasAttribute($attribute)) {
            return;
        }

        $element->setAttribute($attribute, (string) ((int) $element->getAttribute($attribute) + $offset));
    }

    /* ------------------------------------------------------------ workbook */

    /**
     * @return array<int, string>
     */
    private static function sheetNames(string $workbook): array
    {
        $names = [];

        if (preg_match_all('/<sheet\b[^>]*\/>/u', $workbook, $found) === false) {
            return $names;
        }

        foreach ($found[0] as $element) {
            $name = self::attribute($element, 'name');

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function attribute(string $element, string $name): ?string
    {
        if (preg_match('/\b'.preg_quote($name, '/').'="([^"]*)"/u', $element, $found) !== 1) {
            return null;
        }

        return html_entity_decode($found[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** The worksheet part a tab name resolves to, through the manifest. */
    private static function sheetPart(string $workbook, string $relationships, string $name): ?string
    {
        if (preg_match_all('/<sheet\b[^>]*\/>/u', $workbook, $found) === false) {
            return null;
        }

        foreach ($found[0] as $element) {
            if (self::attribute($element, 'name') !== $name) {
                continue;
            }

            $relationshipId = self::attribute($element, 'r:id');

            if ($relationshipId === null) {
                return null;
            }

            if (preg_match_all('/<Relationship\b[^>]*\/>/u', $relationships, $relations) === false) {
                return null;
            }

            foreach ($relations[0] as $relation) {
                if (self::attribute($relation, 'Id') !== $relationshipId) {
                    continue;
                }

                $target = self::attribute($relation, 'Target');

                if ($target === null) {
                    return null;
                }

                return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            }

            return null;
        }

        return null;
    }

    private static function setActiveTab(string $workbook, int $index): string
    {
        if (preg_match('/<workbookView\b[^>]*>/u', $workbook) === 1) {
            return preg_replace_callback(
                '/<workbookView\b[^>]*>/u',
                static function (array $found) use ($index): string {
                    $element = $found[0];

                    if (preg_match('/\bactiveTab="\d+"/u', $element) === 1) {
                        return preg_replace('/\bactiveTab="\d+"/u', 'activeTab="'.$index.'"', $element, 1) ?? $element;
                    }

                    // A self-closing <workbookView .../> needs the attribute
                    // before its slash; an open tag before its closing angle.
                    if (str_ends_with($element, '/>')) {
                        return substr($element, 0, -2).' activeTab="'.$index.'"/>';
                    }

                    return substr($element, 0, -1).' activeTab="'.$index.'">';
                },
                $workbook,
                1,
            ) ?? $workbook;
        }

        $views = '<bookViews><workbookView activeTab="'.$index.'"/></bookViews>';

        return self::insertBefore($workbook, '<sheets>', $views);
    }

    /* --------------------------------------------------------------- xml */

    /** Replace the contents of a block (`<sheets>…</sheets>`). */
    private static function replaceBlock(string $xml, string $tag, string $contents): string
    {
        $pattern = '/<'.preg_quote($tag, '/').'\b[^>]*>.*?<\/'.preg_quote($tag, '/').'>/s';

        if (preg_match($pattern, $xml) !== 1) {
            throw new RuntimeException('The workbook manifest is missing its '.$tag.' block.');
        }

        return preg_replace_callback(
            $pattern,
            static fn (): string => '<'.$tag.'>'.$contents.'</'.$tag.'>',
            $xml,
            1,
        ) ?? $xml;
    }

    private static function insertBefore(string $xml, string $marker, string $addition): string
    {
        $position = strpos($xml, $marker);

        if ($position === false) {
            throw new RuntimeException('The workbook part is missing its '.$marker.' marker.');
        }

        return substr($xml, 0, $position).$addition.substr($xml, $position);
    }

    /**
     * The next number above every match of the pattern, never below 1.
     *
     * @param  array<int, string>  $haystacks
     */
    private static function nextNumber(array $haystacks, string $pattern, int $minimum = 1): int
    {
        $highest = $minimum - 1;

        foreach ($haystacks as $haystack) {
            if (preg_match_all($pattern, $haystack, $found) === false) {
                continue;
            }

            foreach ($found[1] as $number) {
                $highest = max($highest, (int) $number);
            }
        }

        return $highest + 1;
    }

    /**
     * @return array<string, string>
     *
     * @throws RuntimeException when the bytes are not a readable .xlsx package
     */
    private static function readParts(string $bytes): array
    {
        $staged = tempnam(sys_get_temp_dir(), 'xlsx-append-');

        if ($staged === false || file_put_contents($staged, $bytes) === false) {
            throw new RuntimeException('The workbook could not be staged for editing.');
        }

        try {
            return (new PurePhpZipReader($staged))->readAll();
        } finally {
            @unlink($staged);
        }
    }
}

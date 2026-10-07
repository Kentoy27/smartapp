<?php

namespace App\Support;

use App\Models\User;

/**
 * Personalises the shipped OPCRF template for one staff member.
 *
 * The official workbook (storage/forms/OPCRF-TEMPLATE.xlsx) is never
 * redesigned here: this class copies it byte-for-byte and rewrites only the
 * header block's value cells, so every style, merged range, formula,
 * drawing, comment and printer setting in the original survives untouched.
 * The result is still the real template — which is why the app's own
 * analyzer recognises it unchanged (requirement: same structure, same form).
 *
 * Where the user's fields go — discovered from the file, never guessed:
 *
 *   B4  "Name of Employee:"                             → F4  (merged F4:M4)
 *   B5  "Position/Designation:"                         → F5  (merged F5:M5)
 *   B6  "Review Period:"                                → F6  (merged F6:M6)
 *   B7  "Strand/Bureau/Center/Service/Region/Division:"  → F7  (merged F7:M7)
 *
 * Those value cells already exist in the shipped workbook carrying the
 * styles the form designed for them (F4 s=433, F5/F6 s=439, F7 s=464); they
 * are simply empty. So personalisation adds an inline string to the
 * existing <c> element and keeps its r= and s= attributes — the box keeps
 * its border, font and alignment exactly as authored.
 *
 * The rows are found by matching the label text in column B (tolerantly:
 * case, punctuation and line breaks ignored), and each row's target cell is
 * resolved from that row's own merged range — so a template revision that
 * moves the header block down a row still personalises correctly. The
 * evaluator block in columns N..V is never touched: it holds the reviewer's
 * name, not the employee's.
 *
 * @see OpcrfSpreadsheet for the matching reader
 */
class OpcrfTemplatePersonalizer
{
    /**
     * The header fields the system owns, and the label that finds each row.
     */
    private const FIELDS = [
        'name' => 'Name of Employee',
        'position' => 'Position/Designation',
        'review_period' => 'Review Period',
        'division_office' => 'Strand/Bureau/Center/Service/Region/Division',
    ];

    /** The label column the header block's captions live in. */
    private const LABEL_COLUMN = 'B';

    /** Where the value band starts, for rows carrying no merged range. */
    private const VALUE_BAND_FALLBACK = 'F';

    /**
     * The left value band. Scanning starts at C so a merge anchored earlier
     * is still found, and stops at M because N..V belong to the evaluator
     * block — whose cells ship pre-filled with the reviewer's own name.
     */
    private const VALUE_BAND_FIRST = 'C';

    private const VALUE_BAND_LAST = 'M';

    /**
     * The workbook property carrying the template stamp. A custom document
     * property is invisible in the form, survives an Excel save, and does not
     * touch a single cell — the second identity signal that ties an upload
     * back to the template this account generated.
     *
     * It was originally a workbook DEFINED NAME. That was wrong: a
     * <definedName> must resolve to a cell reference or a formula, and
     * writing a bare literal like "OPCRF:56:2026.1" produced a file Excel
     * refused with "We found a problem with some content". Custom document
     * properties exist precisely to hold values like this, so the stamp
     * moved there. readStamp() still understands the old defined name,
     * because workbooks already downloaded carry it.
     */
    private const STAMP_NAME = '_OPCRF_TEMPLATE';

    /* ------------------------------------------------------------------
     * Identity — what the system writes, taken from the account
     * ------------------------------------------------------------------ */

    /**
     * The identity for a staff member, read from their account. The name is
     * the account's registered name; nothing here can come from the
     * workbook.
     *
     * Position is the account's role (the only position-like attribute the
     * users table carries); School and Division come through the account's
     * assigned school.
     *
     * @return array{name: string, position: string, review_period: string, division_office: string, school: string, division: string, employee_id: string}
     */
    public static function identityFor(User $user): array
    {
        $user->loadMissing('school.district');

        $name = trim((string) $user->name);
        $school = trim((string) ($user->school?->name ?? ''));
        $division = trim((string) ($user->school?->district?->displayName() ?? ''));

        // The seventh row is labelled "Strand/Bureau/Center/Service/Region/
        // Division" — one field, so the school and the division it sits in
        // share the line, written the way a person would.
        $office = array_values(array_filter([$school, $division], fn (string $part): bool => $part !== ''));

        return [
            'name' => $name,
            'position' => self::positionLabel($user),
            // The review period belongs to the cycle, not the individual:
            // the school office sets it, so the app never guesses it.
            'review_period' => '',
            'division_office' => implode(' — ', $office),
            'school' => $school,
            'division' => $division,
            'employee_id' => trim((string) $user->username) !== ''
                ? (string) $user->username
                : (string) $user->id,
        ];
    }

    /**
     * The position line.
     *
     * The account's own `position` attribute is the real answer ("Teacher
     * III"), so that is what gets written. Accounts created before the
     * attribute existed fall back to their role — but only when the role
     * actually reads as a job title. The roles this deployment uses are
     * permission flags ("user", "superadmin"), and printing "user" into a
     * box labelled "Position/Designation" would be worse than leaving it
     * blank for the staff member to fill in.
     */
    private static function positionLabel(User $user): string
    {
        $position = trim((string) $user->position);

        if ($position !== '') {
            return $position;
        }

        $role = trim((string) $user->role);

        if ($role === '' || in_array(mb_strtolower($role), self::NON_POSITION_ROLES, true)) {
            return '';
        }

        return $role;
    }

    /**
     * Role values that describe what an account may DO, not what the person
     * does for a living — never printed into the Position box.
     */
    private const NON_POSITION_ROLES = ['user', 'staff', 'superadmin', 'admin', 'member'];

    /**
     * The download name: "OPCRF_Juan_Dela_Cruz.xlsx" — the real registered
     * name, made safe for every filesystem (path separators and the
     * characters Windows rejects dropped, whitespace collapsed to single
     * underscores, length capped).
     *
     * The name is a convenience only. Nothing verifies an upload from it
     * (see verify()), so a renamed copy cannot pass as someone else's.
     */
    public static function downloadNameFor(User $user): string
    {
        $base = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', (string) $user->name) ?? '';
        $base = preg_replace('/\s+/u', '_', $base) ?? '';
        $base = trim((string) (preg_replace('/_+/', '_', $base) ?? ''), '_');

        // No registered name: fall back to the stable account id, so two
        // unnamed staff still get distinguishable files.
        return $base === ''
            ? 'OPCRF_user-'.$user->id.'.xlsx'
            : 'OPCRF_'.mb_substr($base, 0, 60).'.xlsx';
    }

    /* ------------------------------------------------------------------
     * Personalisation
     * ------------------------------------------------------------------ */

    /**
     * The personalised workbook bytes for this staff member: the shipped
     * template (trimmed to the parts available, per OpcrfPartOne) with the
     * header cells rewritten and the account's stamp applied.
     *
     * @throws \RuntimeException when the template cannot be read or its
     *                           header block cannot be located
     */
    public static function personalizeFor(User $user): string
    {
        $version = (string) config('opcrf.template.version', '2026.1');

        return self::personalize(
            OpcrfPartOne::templateBytes(),
            self::identityFor($user),
            $user->id,
            $version
        );
    }

    /**
     * Write the identity into a workbook's header block and stamp it for
     * this account, leaving every other byte of every other package part
     * untouched.
     *
     * @param  array<string, string>  $identity  keys as identityFor() returns
     *
     * @throws \RuntimeException when the workbook has no readable worksheet
     *                           or no recognisable header block
     */
    public static function personalize(string $workbookBytes, array $identity, ?int $userId = null, ?string $version = null): string
    {
        $parts = self::readParts($workbookBytes);

        $sheetPath = self::firstSheetPart($parts);

        if ($sheetPath === null) {
            throw new \RuntimeException('The OPCRF template could not be opened.');
        }

        $rows = self::headerRows($parts[$sheetPath], $parts);

        if ($rows === []) {
            throw new \RuntimeException(
                'The OPCRF template\'s header block could not be located, so it cannot be personalized.'
            );
        }

        $parts[$sheetPath] = self::writeHeaderValues($parts[$sheetPath], $rows, $identity);

        if ($userId !== null) {
            $parts = self::applyStamp(
                $parts,
                (string) $userId,
                (string) ($version ?? config('opcrf.template.version', '2026.1'))
            );
        }

        // Rebuilt with the same pure-PHP zip writer the rest of the app
        // uses, so a PHP build without the zip extension still produces a
        // valid workbook.
        return PurePhpZipWriter::create($parts);
    }

    /**
     * Every package part of a workbook, by name.
     *
     * @return array<string, string>
     */
    private static function readParts(string $workbookBytes): array
    {
        // Both readers work from a path (ZipArchive cannot open a string),
        // so the in-memory workbook is staged through a temp file and
        // removed again whatever happens.
        $staged = tempnam(sys_get_temp_dir(), 'opcrf-');

        if ($staged === false || file_put_contents($staged, $workbookBytes) === false) {
            throw new \RuntimeException('The OPCRF template could not be read.');
        }

        try {
            if (class_exists(\ZipArchive::class)) {
                $zip = new \ZipArchive;

                if ($zip->open($staged) !== true) {
                    throw new \RuntimeException('The OPCRF template could not be opened.');
                }

                try {
                    $parts = [];

                    for ($index = 0; $index < $zip->numFiles; $index++) {
                        $name = (string) $zip->getNameIndex($index);

                        if ($name !== '') {
                            $parts[$name] = (string) $zip->getFromIndex($index);
                        }
                    }

                    return $parts;
                } finally {
                    $zip->close();
                }
            }

            return (new PurePhpZipReader($staged))->readAll();
        } finally {
            @unlink($staged);
        }
    }

    /**
     * The workbook's first worksheet package path, resolved through the
     * workbook manifest the same way the reader resolves it.
     *
     * @param  array<string, string>  $parts
     */
    private static function firstSheetPart(array $parts): ?string
    {
        $workbook = $parts['xl/workbook.xml'] ?? '';
        $rels = $parts['xl/_rels/workbook.xml.rels'] ?? '';

        if ($workbook === '' || preg_match('/<sheets>(.*?)<\/sheets>/s', $workbook, $block) !== 1) {
            return $parts['xl/worksheets/sheet1.xml'] ?? null;
        }

        if (preg_match('/<sheet\b[^>]*>/u', $block[0], $element) !== 1) {
            return $parts['xl/worksheets/sheet1.xml'] ?? null;
        }

        if (preg_match('/\br:id="([^"]*)"/u', $element[0], $rid) === 1 && $rels !== '') {
            $id = preg_quote($rid[1], '/');

            if (preg_match('/<Relationship\s[^>]*\bId="'.$id.'"[^>]*\bTarget="([^"]*)"/u', $rels, $m) === 1
                || preg_match('/<Relationship\s[^>]*\bTarget="([^"]*)"[^>]*\bId="'.$id.'"/u', $rels, $m) === 1) {
                return 'xl/'.ltrim($m[1], '/');
            }
        }

        return $parts['xl/worksheets/sheet1.xml'] ?? null;
    }

    /**
     * The header block's rows as field key => ['row' => int, 'cell' => ref],
     * found by matching the label captions the workbook actually carries.
     *
     * @param  array<string, string>  $parts  the whole package, for its
     *                                        shared strings
     * @return array<string, array{row: int, cell: string}>
     */
    private static function headerRows(string $sheetXml, array $parts): array
    {
        $merges = self::valueBandAnchorsByRow($sheetXml);
        $labels = self::labelCellsByRow($sheetXml, self::sharedStrings($parts));

        $rows = [];
        $taken = [];

        foreach (self::FIELDS as $field => $label) {
            foreach ($labels as $row => $text) {
                if (! self::labelMatches($text, $label)) {
                    continue;
                }

                // The row's own merged anchor; falling back to the shipped
                // band start when the row carries no merge (a hand-made
                // fixture, or a row someone unmerged).
                $cell = $merges[$row] ?? self::VALUE_BAND_FALLBACK.$row;

                // Two fields must never claim the same cell.
                if (in_array($cell, $taken, true)) {
                    continue;
                }

                $rows[$field] = ['row' => $row, 'cell' => $cell];
                $taken[] = $cell;

                break;
            }
        }

        return $rows;
    }

    /**
     * Each header row's value-cell anchor, from the sheet's own merged
     * ranges: for every merge whose top-left cell sits in the left value
     * band, the row number maps to that anchor's reference.
     *
     * @return array<int, string>
     */
    private static function valueBandAnchorsByRow(string $sheetXml): array
    {
        $anchors = [];

        if (preg_match('/<mergeCells[^>]*>(.*?)<\/mergeCells>/s', $sheetXml, $block) !== 1) {
            return $anchors;
        }

        preg_match_all('/<mergeCell ref="([A-Z]+\d+):([A-Z]+\d+)"/', $block[1], $refs, PREG_SET_ORDER);

        $first = self::columnIndex(self::VALUE_BAND_FIRST);
        $last = self::columnIndex(self::VALUE_BAND_LAST);

        foreach ($refs as $ref) {
            preg_match('/^([A-Z]+)(\d+)$/', $ref[1], $anchor);

            if ($anchor === []) {
                continue;
            }

            $column = self::columnIndex($anchor[1]);

            // Only the left band: the evaluator block's merges (O..V) must
            // never be mistaken for the employee's field.
            if ($column < $first || $column > $last) {
                continue;
            }

            $row = (int) $anchor[2];

            // The left-most anchor on a row wins it.
            $anchors[$row] ??= $anchor[1].$row;
        }

        return $anchors;
    }

    /**
     * Every caption in the label column, as row number => text, with shared
     * strings resolved so the workbook's own wording is what gets matched.
     *
     * @param  array<int, string>  $shared
     * @return array<int, string>
     */
    private static function labelCellsByRow(string $sheetXml, array $shared): array
    {
        $labels = [];

        foreach (self::cells($sheetXml, $shared) as $ref => $cell) {
            if (! str_starts_with($ref, self::LABEL_COLUMN) || $cell === '') {
                continue;
            }

            $labels[(int) substr($ref, 1)] ??= $cell;
        }

        ksort($labels);

        return $labels;
    }

    /**
     * The workbook's shared strings table, indexed in file order.
     *
     * @param  array<string, string>  $parts
     * @return array<int, string>
     */
    private static function sharedStrings(array $parts): array
    {
        $xml = $parts['xl/sharedStrings.xml'] ?? '';
        $document = $xml === '' ? false : @simplexml_load_string($xml);

        if ($document === false) {
            return [];
        }

        $strings = [];

        foreach ($document->si as $si) {
            $text = '';

            foreach ($si->t as $t) {
                $text .= (string) $t;
            }

            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * Every cell in a sheet as ref => plain-text value, shared strings and
     * inline strings resolved the way the analyzer resolves them.
     *
     * @param  array<int, string>  $shared
     * @return array<string, string>
     */
    private static function cells(string $sheetXml, array $shared): array
    {
        $cells = [];

        if (preg_match_all('/<c\b[^>]*\/>|<c\b[^>]*>.*?<\/c>/s', $sheetXml, $matches) === false) {
            return $cells;
        }

        foreach ($matches[0] as $element) {
            if (preg_match('/\br="([A-Z]+\d+)"/', $element, $ref) !== 1) {
                continue;
            }

            $value = '';

            if (str_contains($element, 't="inlineStr"')
                && preg_match('/<is>.*?<t[^>]*>(.*?)<\/t>/s', $element, $t) === 1) {
                $value = $t[1];
            } elseif (preg_match('/<v>(.*?)<\/v>/s', $element, $v) === 1) {
                $value = str_contains($element, 't="s"')
                    ? ($shared[(int) $v[1]] ?? '')
                    : $v[1];
            }

            $cells[$ref[1]] = html_entity_decode(trim($value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }

        return $cells;
    }

    /**
     * Put each identity value into its cell, preserving the cell's style and
     * every byte around it.
     *
     * Values are written as inline strings (<is><t>…</t></is>) rather than
     * shared-string references: that needs no edit to sharedStrings.xml,
     * cannot collide with an existing index, and Excel, LibreOffice and the
     * app's own reader all understand it. The cell's existing <c> element is
     * reused, so its s="…" style — the border, alignment and font the
     * template designed for that box — is exactly the one it had.
     *
     * @param  array<string, array{row: int, cell: string}>  $rows
     * @param  array<string, string>  $identity
     */
    private static function writeHeaderValues(string $sheetXml, array $rows, array $identity): string
    {
        foreach ($rows as $field => $target) {
            $value = trim((string) ($identity[$field] ?? ''));

            // e.g. the review period, which the office fills in later.
            if ($value === '') {
                continue;
            }

            $sheetXml = self::replaceCell($sheetXml, $target['cell'], $value);
        }

        return $sheetXml;
    }

    /**
     * Swap one cell's contents for an inline string, keeping its reference
     * and style attributes.
     *
     * The type attribute becomes t="inlineStr" (a cell carries one), and any
     * <v> it held is dropped — that value belonged to whatever was in the
     * box before. When the row exists but the cell does not, nothing is
     * inserted: the template ships these cells, and inventing one would
     * mean inventing a style to go with it.
     */
    private static function replaceCell(string $sheetXml, string $ref, string $value): string
    {
        $pattern = '/<c\b(?=[^>]*\br="'.preg_quote($ref, '/').'")[^>]*\/>|<c\b(?=[^>]*\br="'.preg_quote($ref, '/').'")[^>]*>.*?<\/c>/s';

        if (preg_match($pattern, $sheetXml, $found) !== 1) {
            return $sheetXml;
        }

        $style = preg_match('/\bs="(\d+)"/', $found[0], $s) === 1 ? ' s="'.$s[1].'"' : '';

        $replacement = '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'
            .htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</t></is></c>';

        return str_replace($found[0], $replacement, $sheetXml);
    }

    /**
     * Record the account a workbook was generated for as a CUSTOM DOCUMENT
     * PROPERTY.
     *
     * The property lives in docProps/custom.xml, which is what that part is
     * for: invisible in the form, preserved when the user saves, and — unlike
     * a defined name — a value Excel actually accepts.
     *
     * Three parts have to agree for the stamp to be part of the package, so
     * all three are written together: the part itself, the content-type
     * override that declares it, and the package relationship that points at
     * it. A stamp present but undeclared is a file Excel complains about.
     *
     * A legacy defined-name stamp is removed at the same time, so
     * re-downloading a workbook that was generated before this fix repairs
     * it instead of leaving the invalid name behind.
     *
     * @param  array<string, string>  $parts
     * @return array<string, string>
     */
    private static function applyStamp(array $parts, string $userId, string $version): array
    {
        $parts['docProps/custom.xml'] = self::stampProperty(
            $parts['docProps/custom.xml'] ?? '',
            'OPCRF:'.$userId.':'.$version
        );

        $parts['[Content_Types].xml'] = self::declareCustomProperties($parts['[Content_Types].xml'] ?? '');
        $parts['_rels/.rels'] = self::relateCustomProperties($parts['_rels/.rels'] ?? '');

        // Drop the invalid defined name if this workbook still carries one.
        if (isset($parts['xl/workbook.xml'])) {
            $parts['xl/workbook.xml'] = self::stripLegacyStamp($parts['xl/workbook.xml']);
        }

        return $parts;
    }

    /**
     * The stamp as a custom property, written into docProps/custom.xml.
     *
     * Custom property ids (pid) start at 2 and must be unique, so an
     * existing part keeps every property it already had and only ours is
     * replaced.
     */
    private static function stampProperty(string $existingXml, string $value): string
    {
        $property = '<property fmtid="{D5CDD505-2E9C-101B-9397-08002B2CF9AE}" pid="%d" name="'
            .self::STAMP_NAME.'"><vt:lpwstr>'
            .htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</vt:lpwstr></property>';

        $pattern = '/<property\b[^>]*\bname="'.preg_quote(self::STAMP_NAME, '/').'"[^>]*>.*?<\/property>/s';

        if ($existingXml !== '' && preg_match($pattern, $existingXml) === 1) {
            return (string) preg_replace_callback(
                $pattern,
                fn (array $m): string => sprintf($property, self::propertyPid($m[0])),
                $existingXml,
                1
            );
        }

        if ($existingXml !== '' && str_contains($existingXml, '</Properties>')) {
            // The next free id is one past the highest already used; pids
            // start at 2, and 1 is reserved.
            preg_match_all('/\bpid="(\d+)"/', $existingXml, $used);
            $next = $used[1] === [] ? 2 : max(array_map('intval', $used[1])) + 1;

            return (string) str_replace(
                '</Properties>',
                sprintf($property, $next).'</Properties>',
                $existingXml
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties"'
            .' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .sprintf($property, 2)
            .'</Properties>';
    }

    /** The pid an existing property already carries, so replacing keeps it. */
    private static function propertyPid(string $propertyXml): int
    {
        return preg_match('/\bpid="(\d+)"/', $propertyXml, $m) === 1 ? (int) $m[1] : 2;
    }

    /** Declare docProps/custom.xml in [Content_Types].xml, once. */
    private static function declareCustomProperties(string $contentTypes): string
    {
        if ($contentTypes === '') {
            return $contentTypes;
        }

        $override = '<Override PartName="/docProps/custom.xml" ContentType="'
            .'application/vnd.openxmlformats-officedocument.custom-properties+xml"/>';

        if (str_contains($contentTypes, 'PartName="/docProps/custom.xml"')) {
            return $contentTypes;
        }

        if (str_contains($contentTypes, '</Types>')) {
            return (string) str_replace('</Types>', $override.'</Types>', $contentTypes);
        }

        return $contentTypes;
    }

    /** Point the package at docProps/custom.xml, once, on a free rId. */
    private static function relateCustomProperties(string $rels): string
    {
        if ($rels === '') {
            return $rels;
        }

        if (str_contains($rels, 'Target="docProps/custom.xml"')) {
            return $rels;
        }

        preg_match_all('/\bId="rId(\d+)"/', $rels, $used);
        $next = $used[1] === [] ? 1 : max(array_map('intval', $used[1])) + 1;

        $relationship = '<Relationship Id="rId'.$next.'" Type="'
            .'http://schemas.openxmlformats.org/officeDocument/2006/relationships/custom-properties"'
            .' Target="docProps/custom.xml"/>';

        return (string) str_replace('</Relationships>', $relationship.'</Relationships>', $rels);
    }

    /**
     * Remove the defined-name stamp written by earlier versions.
     *
     * It is the thing Excel rejects, so a workbook that still carries one is
     * repaired the moment it is personalized again — and left alone
     * otherwise, because an untouched upload is only ever read, never
     * rewritten.
     */
    private static function stripLegacyStamp(string $workbookXml): string
    {
        if ($workbookXml === '' || ! str_contains($workbookXml, self::STAMP_NAME)) {
            return $workbookXml;
        }

        $pattern = '/<definedName\s+name="'.preg_quote(self::STAMP_NAME, '/').'"[^>]*>.*?<\/definedName>/s';

        $stripped = (string) preg_replace($pattern, '', $workbookXml, 1);

        // A <definedNames> block left empty by the removal is itself
        // questionable; drop it when nothing else moved into it.
        return (string) preg_replace('/<definedNames>\s*<\/definedNames>/', '', $stripped, 1);
    }

    /**
     * Read the stamp out of an uploaded workbook: which account it was
     * generated for, and at which template version. Null when the file
     * carries no stamp (an older copy, or a workbook made by hand) — the
     * caller then falls back to verifying the name alone.
     *
     * The custom property is read first. A defined name is still accepted
     * because workbooks downloaded before the stamp moved there carry that
     * one instead, and refusing to read it would reject files people have
     * already been sent.
     *
     * @return array{user_id: string, version: string}|null
     */
    public static function readStamp(string $filePath): ?array
    {
        try {
            $reader = new PurePhpZipReader($filePath);
        } catch (\Throwable) {
            return null;
        }

        $custom = $reader->part('docProps/custom.xml');

        if (is_string($custom) && $custom !== '') {
            $pattern = '/<property\b[^>]*\bname="'.preg_quote(self::STAMP_NAME, '/').'"[^>]*>(.*?)<\/property>/s';

            if (preg_match($pattern, $custom, $found) === 1) {
                $stamp = self::parseStampValue($found[1]);

                if ($stamp !== null) {
                    return $stamp;
                }
            }
        }

        // A workbook generated before this fix.
        try {
            $workbook = $reader->part('xl/workbook.xml');
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($workbook) || $workbook === '') {
            return null;
        }

        $pattern = '/<definedName\s+name="'.preg_quote(self::STAMP_NAME, '/').'"[^>]*>(.*?)<\/definedName>/s';

        if (preg_match($pattern, $workbook, $found) !== 1) {
            return null;
        }

        return self::parseStampValue($found[1]);
    }

    /**
     * The stamp's own format: OPCRF:<user id>:<version>.
     *
     * @return array{user_id: string, version: string}|null
     */
    private static function parseStampValue(string $raw): ?array
    {
        $parts = explode(':', html_entity_decode(trim(strip_tags($raw)), ENT_XML1 | ENT_QUOTES, 'UTF-8'));

        if (count($parts) !== 3 || $parts[0] !== 'OPCRF' || $parts[1] === '') {
            return null;
        }

        return ['user_id' => $parts[1], 'version' => $parts[2]];
    }

    /**
     * Tolerant label comparison, matching the analyzer's: alphanumerics
     * only, lowercased, so "NAME OF EMPLOYEE:" matches "Name of Employee"
     * whatever punctuation or line breaks sit in between.
     */
    private static function labelMatches(string $cellValue, string $label): bool
    {
        $needle = self::normalizeLabel($label);

        return $needle !== '' && str_starts_with(self::normalizeLabel($cellValue), $needle);
    }

    private static function normalizeLabel(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    /** A column letter's 1-based index ("A" => 1, "M" => 13). */
    private static function columnIndex(string $column): int
    {
        $index = 0;

        foreach (str_split($column) as $letter) {
            $index = $index * 26 + (ord(strtoupper($letter)) - 64);
        }

        return $index;
    }
    /* ------------------------------------------------------------------
     * Verification — what the system can tell about an upload. The file
     * NAME is never part of the answer, and NO outcome refuses anything.
     *
     * Every signal here is advisory. The whole point of this class is to
     * tell the reviewer what a workbook claims about itself; it is not a
     * gate. A name typed into a spreadsheet is not an identity, a template
     * stamp only records where a file was downloaded, and refusing on
     * either trains staff to work around the check instead of filling the
     * form in. So whatever the comparison finds is recorded on the
     * submission and shown to both sides, and the upload proceeds.
     *
     * The only things that can still refuse an OPCRF are that the file is
     * not a readable .xlsx, or that it is too large — both decided by the
     * upload component's own validation, before this class is consulted.
     * ------------------------------------------------------------------ */

    /** The workbook's name matches the account's registered name. */
    public const MATCH = 'match';

    /** The workbook names somebody else. Recorded; upload allowed. */
    public const MISMATCH = 'mismatch';

    /**
     * The workbook carries the template stamp of a different account — it
     * was generated on somebody else's dashboard. Recorded; upload
     * allowed. This is the only signal the file itself produces, and it
     * records where a file came from, not who filled it in.
     */
    public const STAMP_MISMATCH = 'stamp_mismatch';

    /** No name could be read out of the workbook's header block. Recorded. */
    public const UNREADABLE = 'unreadable';

    /**
     * The ACCOUNT has no name on file, so there is nothing to compare the
     * workbook's name against. Recorded — and kept apart from UNREADABLE
     * because the two mean opposite things to the reader: an unreadable
     * file is the staff member's to complete, whereas this one can only be
     * fixed by an administrator.
     */
    public const ACCOUNT_UNNAMED = 'account_unnamed';

    /** Heads-up shown when the workbook carries another person's name. */
    public const MESSAGE_MISMATCH = 'The name in this OPCRF (“%s”) is not the name on your account (“%s”). You can still submit it — the reviewer will see this note.';

    /**
     * Heads-up shown when no name can be read at all — a blank template, a
     * renamed file, or a form whose header block was edited away.
     */
    public const MESSAGE_UNREADABLE = 'No name could be read from this OPCRF’s header block. You can still submit it — the reviewer will see this note.';

    /**
     * Heads-up shown when the workbook was generated on another account.
     *
     * Phrased as information, not an instruction to go and do something
     * else: the upload is going through either way.
     */
    public const MESSAGE_STAMP_MISMATCH = 'This OPCRF was downloaded from another user’s account, so its name may not be yours. You can still submit it — the reviewer will see this note.';

    /**
     * Heads-up shown when the account itself carries no name.
     *
     * Deliberately names the account, not the file: the upload may be
     * perfectly good, and retrying it will never help.
     */
    public const MESSAGE_ACCOUNT_UNNAMED = 'Your account has no name on file, so this upload cannot be matched to it. You can still submit it — ask an administrator to set your name so your reviewer can identify it.';

    /**
     * Inspect an uploaded workbook against the given account, by reading
     * the workbook itself.
     *
     * Two independent signals, because one alone would miss too much:
     *
     *   1. the template stamp, when the file carries one — the account id
     *      the workbook was generated for, whatever name it spells;
     *   2. the name typed into the header block, compared to the account's
     *      registered name.
     *
     * Neither decides anything. Both are stored on the submission
     * (upload_check + account_name) so the reviewer can see, without
     * opening the workbook, exactly what the file said and what the
     * account says, and both are shown back to the staff member as a
     * heads-up rather than a wall.
     *
     * @return array{status: string, message: string, name: string, expected: string, version: ?string}
     */
    public static function verify(string $filePath, User $user): array
    {
        $expected = trim((string) $user->name);

        $found = '';

        try {
            $found = trim((string) (new OpcrfSpreadsheet($filePath))->analyze()['employee_name']);
        } catch (\Throwable) {
            $found = '';
        }

        $stamp = self::readStamp($filePath);

        $result = [
            'name' => $found,
            'expected' => $expected,
            'version' => $stamp['version'] ?? null,
        ];

        // Signal 1: the file was generated on another account's dashboard.
        // Worth knowing — and worth telling the reviewer — but not a reason
        // to stop somebody filing their OPCR.
        if ($stamp !== null && (string) $user->id !== $stamp['user_id']) {
            return $result + [
                'status' => self::STAMP_MISMATCH,
                'message' => self::MESSAGE_STAMP_MISMATCH,
            ];
        }

        // Signal 2, advisory. The account has no name to compare against,
        // so the workbook's name (if any) can neither be confirmed nor
        // doubted.
        if ($expected === '') {
            return $result + [
                'status' => self::ACCOUNT_UNNAMED,
                'message' => self::MESSAGE_ACCOUNT_UNNAMED,
            ];
        }

        // No recognizable name in the file: recorded, not refused.
        if ($found === '') {
            return $result + [
                'status' => self::UNREADABLE,
                'message' => self::MESSAGE_UNREADABLE,
            ];
        }

        return $result + (self::namesMatch($found, $expected)
            ? [
                'status' => self::MATCH,
                'message' => 'Name verified.',
            ]
            : [
                'status' => self::MISMATCH,
                'message' => sprintf(self::MESSAGE_MISMATCH, $found, $expected),
            ]);
    }

    /**
     * Do these two names denote the same person?
     *
     * Typing a name into a spreadsheet is not exact: Excel recases, a middle
     * initial may or may not be typed, and "DELA CRUZ, Juan" is the same
     * human as "Juan Dela Cruz". So the two names are compared as sets of
     * name parts, order-insensitive, with a single-letter part matching a
     * part that begins with it (J. for Juan). That is lenient enough for
     * the same person typing their own name and far too strict for anyone
     * else: "Juan Dela Cruz" never matches "Maria Santos".
     */
    public static function namesMatch(string $uploaded, string $expected): bool
    {
        $a = self::nameParts($uploaded);
        $b = self::nameParts($expected);

        if ($a === [] || $b === []) {
            return false;
        }

        return self::partsAccountedFor($a, $b) && self::partsAccountedFor($b, $a);
    }

    /**
     * Every token in $parts is present in $other: either the same word, or an
     * initial standing for it in EITHER direction — "J. Dela Cruz" must match
     * "Juan Dela Cruz" whichever side the initial is on.
     *
     * @param  array<int, string>  $parts
     * @param  array<int, string>  $other
     */
    private static function partsAccountedFor(array $parts, array $other): bool
    {
        foreach ($parts as $part) {
            $matched = false;

            foreach ($other as $candidate) {
                if ($part === $candidate
                    || (mb_strlen($part) === 1 && str_starts_with($candidate, $part))
                    || (mb_strlen($candidate) === 1 && str_starts_with($part, $candidate))) {
                    $matched = true;

                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * A name reduced to its comparable words — punctuation dropped, case
     * folded, "Surname, Given" folded into the same bag of words.
     *
     * @return array<int, string>
     */
    private static function nameParts(string $name): array
    {
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', str_replace('.', ' ', $name)) ?? '';
        $text = preg_replace('/\s+/u', ' ', mb_strtolower(trim($text))) ?? '';

        return array_values(array_filter(explode(' ', $text), fn (string $part): bool => $part !== ''));
    }
}

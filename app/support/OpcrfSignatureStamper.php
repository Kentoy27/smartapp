<?php

namespace App\Support;

use RuntimeException;

/**
 * Stamps a signer's digital signature into an OPCRF workbook role slot.
 *
 * The form ends with a signer block — one row of role labels (RATEE, RATER,
 * APPROVING AUTHORITY) with each signer's name a couple of rows above it in
 * the same column, and the empty band above that name is where a person
 * signs. This class puts the account's digital signature there, in the RATEE
 * selected role column, on every worksheet of the package that has such a
 * block (Part I, Part II and Part IV each carry one; Part III signs by hand).
 *
 * Nothing else about the workbook changes. An .xlsx is an OPC package, so the
 * picture is added the way the rest of the app edits workbooks — by touching
 * the parts an image needs and not a byte more:
 *
 *   xl/media/imageN.<ext>             the signature itself
 *   [Content_Types].xml               the image's Default (and a drawing
 *                                     Override when the sheet has none yet)
 *   xl/drawings/drawingN.xml          a <xdr:twoCellAnchor> holding the picture
 *   xl/drawings/_rels/drawingN.xml.rels  the drawing → image relationship
 *   xl/worksheets/sheetN.xml          nothing at all when the sheet already has
 *                                     a drawing (the anchor joins the existing
 *                                     one); otherwise a <drawing r:id="…"/>
 *                                     element in schema order, plus the sheet's
 *                                     own relationships part
 *
 * That is what makes the stamp survive the personalizer and the Part-I trim
 * untouched: the sheet's cells, styles, merges, formulas and its own logo
 * drawing are the template's own bytes, and the trim's reachability sweep
 * keeps the media part because the drawing's .rels points at it.
 *
 * Zip handling goes through the app's own pure-PHP reader/writer rather than
 * ZipArchive so the stamp also works on the XAMPP PHP build, which ships with
 * the zip extension disabled (the same reasoning as OpcrfWorkbookTrim).
 *
 * @see \App\Models\OpcrfSubmission::stampSignature() for who gets stamped and when
 * @see \App\Support\OpcrfSpreadsheet::signers() for the reader's view of the
 *      same block
 */
final class OpcrfSignatureStamper
{
    private const RELATIONSHIPS_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const DRAWING_NS = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';

    private const DRAWINGML_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const DRAWING_RELATIONSHIP = self::R_NS.'/drawing';

    private const IMAGE_RELATIONSHIP = self::R_NS.'/image';

    private const DRAWING_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.drawing+xml';

    /**
     * The picture's content type per image extension. The extension is taken
     * from the image's own bytes (see detectExtension()), so this map only
     * ever answers for a format the header confirmed.
     */
    private const IMAGE_CONTENT_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /**
     * How many rows above the RATEE name the signature covers. The template
     * leaves four empty rows between the last score row and the name, and the
     * analyzer looks exactly four rows up from a role label to find a name —
     * so this is the same band, read from the same direction.
     */
    private const BAND_ROWS = 4;

    /** Columns the signature covers when the name's box is not merged. */
    private const BAND_COLUMNS = 3;

    /** Excel's own conversions: 96 device-independent pixels per inch. */
    private const EMU_PER_PIXEL = 9525;

    /** …and 12700 EMU per point, for row heights. */
    private const EMU_PER_POINT = 12700;

    /** The picture's fallback box, in pixels, when the geometry is unreadable. */
    private const FALLBACK_WIDTH_PX = 220;

    private const FALLBACK_HEIGHT_PX = 70;

    /**
     * A copy of $workbookBytes carrying $signatureBytes in the signer block of
     * every sheet that has one.
     *
     * @param  string  $extension  the signature file's extension, used only
     *                             when the image's own header does not name a
     *                             format
     * @param  string|null  $signerName  written into the picture's alt text so
     *                                   a reviewer hovering the signature sees
     *                                   whose it is
     * @param  string  $role  the workbook signer slot to stamp, such as RATEE,
     *                        RATER, or APPROVING AUTHORITY
     * @return string|null the stamped workbook, or null when the workbook has
     *                     no signer block to stamp (nothing is changed and the
     *                     caller keeps the file it had)
     *
     * @throws RuntimeException when the bytes are not a readable .xlsx package
     */
    public static function stamp(
        string $workbookBytes,
        string $signatureBytes,
        string $extension = 'png',
        ?string $signerName = null,
        string $role = 'RATEE'
    ): ?string {
        if (trim($signatureBytes) === '') {
            return null;
        }

        $format = self::detectExtension($signatureBytes, $extension);

        $parts = self::readParts($workbookBytes);
        $shared = self::sharedStrings($parts);

        // Where the signature goes, per sheet — discovered from the workbook,
        // never assumed: a sheet without a RATEE role label is left alone.
        $anchors = [];

        foreach (self::worksheetParts($parts) as $sheetPart) {
            $anchor = self::signatureAnchor($parts[$sheetPart], $shared, $role);

            if ($anchor !== null) {
                $anchors[$sheetPart] = $anchor;
            }
        }

        if ($anchors === []) {
            return null;
        }

        $mediaPart = self::freeMediaPart($parts, $format);
        $parts[$mediaPart] = $signatureBytes;
        $parts['[Content_Types].xml'] = self::declareImageType(
            $parts['[Content_Types].xml'] ?? '',
            $format
        );

        $pictureNumber = 1;

        foreach ($anchors as $sheetPart => $anchor) {
            $parts = self::stampSheet(
                $parts,
                $sheetPart,
                $anchor,
                $mediaPart,
                $pictureNumber++,
                $signerName
            );
        }

        return PurePhpZipWriter::create($parts);
    }

    /* ------------------------------------------------------------------
     * The signer block — where the signature belongs
     * ------------------------------------------------------------------ */

    /**
     * The band the signature covers on one sheet: the columns of the selected
     * name's box and the empty rows directly above that name.
     *
     * The name is found the way the analyzer finds it — the nearest non-empty,
     * name-shaped cell in the selected role column, at most four rows above the role
     * label. When that cell holds a formula's cached score rather than a name,
     * the template's own layout is assumed: the name sits two rows above the
     * label, exactly as the form prints it.
     *
     * Null when the sheet has no matching role label.
     *
     * @param  array<int, string>  $shared
     * @return array{from_col: int, from_row: int, to_col: int, to_row: int, first_column: string, last_column: string, first_row: int, last_row: int, geometry: array<string, mixed>}|null
     *         the anchor's zero-based from/to cell plus the 1-based band it covers
     */
    private static function signatureAnchor(string $sheetXml, array $shared, string $role): ?array
    {
        $cells = self::cells($sheetXml, $shared);

        $rolesRef = null;

        $targetRole = self::normalizeLabel($role);

        foreach ($cells as $ref => $value) {
            if ($value !== '' && self::normalizeLabel($value) === $targetRole) {
                $rolesRef = $ref;

                break;
            }
        }

        if ($rolesRef === null) {
            return null;
        }

        $column = (string) preg_replace('/\d+/', '', $rolesRef);
        $rolesRow = (int) substr($rolesRef, 1);

        $nameRow = null;

        for ($row = $rolesRow - 1; $row >= max(1, $rolesRow - 4); $row--) {
            $value = trim($cells[$column.$row] ?? '');

            // The first non-empty cell decides, exactly as the analyzer's
            // nameAbove() does: a score where a name belongs means there is no
            // readable name here, not "keep looking".
            if ($value !== '') {
                $nameRow = self::looksLikeName($value) ? $row : null;

                break;
            }
        }

        // The template's own layout: the name a couple of rows above the role
        // label, the empty signing band above that.
        $nameRow ??= $rolesRow - 2;

        if ($nameRow < 1) {
            return null;
        }

        $firstColumn = $column;
        $lastColumn = self::mergeEndColumn($sheetXml, $column.$nameRow)
            ?? self::columnName(self::columnIndex($column) + self::BAND_COLUMNS - 1);

        $firstRow = max(1, $nameRow - self::BAND_ROWS);
        $lastRow = $nameRow - 1;

        if ($lastRow < $firstRow) {
            return null; // No room above the name — nothing sensible to cover.
        }

        return [
            'from_col' => self::columnIndex($firstColumn) - 1,
            'from_row' => $firstRow - 1,
            // The anchor's "to" corner is the far edge of the band: the
            // picture stops where the name's box begins, so the printed name is
            // never covered and the signature never runs off the printed page.
            'to_col' => self::columnIndex($lastColumn),
            'to_row' => $lastRow,
            'first_column' => $firstColumn,
            'last_column' => $lastColumn,
            'first_row' => $firstRow,
            'last_row' => $lastRow,
            // The band's own measurements, for the picture's <a:ext>.
            'geometry' => self::sheetGeometry($sheetXml, $firstRow, $lastRow),
        ];
    }

    /**
     * A value that reads as a person's name: letters, and at least two words.
     * Copied from the analyzer's rule so both sides see the same block.
     */
    private static function looksLikeName(string $value): bool
    {
        return preg_match('/[a-z]/i', $value) === 1
            && preg_match('/\p{L}\s+\p{L}/u', $value) === 1;
    }

    /**
     * The end column of the merged range anchored at $ref ("D151:F152" → "F"),
     * or null when that cell is not a merge's top-left corner.
     */
    private static function mergeEndColumn(string $sheetXml, string $ref): ?string
    {
        $pattern = '/<mergeCell ref="'.preg_quote($ref, '/').':([A-Z]+)\d+"/';

        return preg_match($pattern, $sheetXml, $found) === 1 ? $found[1] : null;
    }

    /* ------------------------------------------------------------------
     * The package — image, drawing, relationships, content types
     * ------------------------------------------------------------------ */

    /**
     * Put one sheet's signature in place: an existing drawing part gains the
     * picture, a sheet that has none gets a drawing of its own, wired up the
     * way the package format requires (an element on the sheet, a
     * relationship to the part, and a content-type override).
     *
     * @param  array<string, string>  $parts
     * @param  array{from_col: int, from_row: int, to_col: int, to_row: int, first_column: string, last_column: string, first_row: int, last_row: int, geometry: array<string, mixed>}  $anchor
     * @return array<string, string>
     */
    private static function stampSheet(
        array $parts,
        string $sheetPart,
        array $anchor,
        string $mediaPart,
        int $pictureNumber,
        ?string $signerName
    ): array {
        $sheetRelsPart = self::relationshipsPart($sheetPart);
        // An empty string and a missing part mean the same thing here: the
        // sheet has no relationships yet.
        $sheetRels = ($parts[$sheetRelsPart] ?? null) ?: null;

        // Which part the sheet actually draws with: the one its own <drawing>
        // element names, so a sheet can never be left pointing at a drawing
        // that does not carry the signature.
        $drawing = self::drawingForSheet($parts, $sheetPart, $sheetRels);

        if ($drawing === null) {
            // No drawing on this sheet (Part II, Part III, Part IV …): one is
            // created for the signature alone, and the sheet is pointed at it.
            $drawingPart = self::freeDrawingPart($parts);

            $parts[$drawingPart] = self::drawingRoot('xdr', 'a').'</xdr:wsDr>';
            $parts[self::relationshipsPart($drawingPart)] = self::relsRoot();

            $parts['[Content_Types].xml'] = self::declareDrawingOverride(
                $parts['[Content_Types].xml'] ?? '',
                $drawingPart
            );

            [$sheetRels, $rid] = self::addRelationship(
                $sheetRels ?? self::relsRoot(),
                self::DRAWING_RELATIONSHIP,
                self::relativeTarget(self::partDirectory($sheetPart), $drawingPart)
            );

            $parts[$sheetRelsPart] = $sheetRels;
            $parts[$sheetPart] = self::attachDrawingElement($parts[$sheetPart], $rid);
        } else {
            // The sheet already draws something (the template's logo): the
            // picture joins that drawing part rather than replacing it, and the
            // sheet keeps pointing at the same one.
            $drawingPart = $drawing['part'];
            $parts[$sheetPart] = self::attachDrawingElement($parts[$sheetPart], $drawing['rid']);
        }

        [$xdr, $a] = self::drawingPrefixes($parts[$drawingPart]);

        $drawingRelsPart = self::relationshipsPart($drawingPart);
        [$drawingRels, $imageRid] = self::addRelationship(
            $parts[$drawingRelsPart] ?? self::relsRoot(),
            self::IMAGE_RELATIONSHIP,
            self::relativeTarget(self::partDirectory($drawingPart), $mediaPart)
        );

        $parts[$drawingRelsPart] = $drawingRels;
        $parts[$drawingPart] = self::appendPicture(
            $parts[$drawingPart],
            $anchor,
            $imageRid,
            $pictureNumber,
            $xdr,
            $a,
            $signerName
        );

        return $parts;
    }

    /**
     * The picture anchored over the signing band, as one <xdr:twoCellAnchor>.
     *
     * The anchor (not a fixed size) is what places the signature: Excel and
     * LibreOffice both stretch a two-cell-anchored picture to the band it was
     * given, so a wide scan of a signature and a small one land in the same
     * box, on the same rows, on every sheet. The accompanying <a:ext> is the
     * band's measured size, which keeps viewers that read it first in step.
     */
    private static function appendPicture(
        string $drawingXml,
        array $anchor,
        string $imageRid,
        int $pictureNumber,
        string $xdr,
        string $a,
        ?string $signerName
    ): string {
        $id = self::nextPictureId($drawingXml);
        $description = $signerName !== null && trim($signerName) !== ''
            ? 'Digital signature of '.trim($signerName)
            : 'Digital signature';

        $extent = self::bandExtent($anchor);

        $picture = '<'.$xdr.':twoCellAnchor editAs="oneCell">'
            .'<'.$xdr.':from>'
            .'<'.$xdr.':col>'.$anchor['from_col'].'</'.$xdr.':col><'.$xdr.':colOff>0</'.$xdr.':colOff>'
            .'<'.$xdr.':row>'.$anchor['from_row'].'</'.$xdr.':row><'.$xdr.':rowOff>0</'.$xdr.':rowOff>'
            .'</'.$xdr.':from>'
            .'<'.$xdr.':to>'
            .'<'.$xdr.':col>'.$anchor['to_col'].'</'.$xdr.':col><'.$xdr.':colOff>0</'.$xdr.':colOff>'
            .'<'.$xdr.':row>'.$anchor['to_row'].'</'.$xdr.':row><'.$xdr.':rowOff>0</'.$xdr.':rowOff>'
            .'</'.$xdr.':to>'
            .'<'.$xdr.':pic>'
            .'<'.$xdr.':nvPicPr>'
            .'<'.$xdr.':cNvPr id="'.$id.'" name="Digital Signature '.$pictureNumber.'" descr="'
            .htmlspecialchars($description, ENT_XML1 | ENT_QUOTES, 'UTF-8').'"/>'
            .'<'.$xdr.':cNvPicPr><'.$a.':picLocks noChangeAspect="1"/></'.$xdr.':cNvPicPr>'
            .'</'.$xdr.':nvPicPr>'
            .'<'.$xdr.':blipFill>'
            // The relationship namespace is declared on the element that uses
            // it: the template's own drawing part declares only xdr and a.
            .'<'.$a.':blip xmlns:r="'.self::R_NS.'" r:embed="'.$imageRid.'"/>'
            .'<'.$a.':stretch><'.$a.':fillRect/></'.$a.':stretch>'
            .'</'.$xdr.':blipFill>'
            .'<'.$xdr.':spPr>'
            .'<'.$a.':xfrm><'.$a.':off x="0" y="0"/><'.$a.':ext cx="'.$extent['width'].'" cy="'.$extent['height'].'"/></'.$a.':xfrm>'
            .'<'.$a.':prstGeom prst="rect"><'.$a.':avLst/></'.$a.':prstGeom>'
            .'</'.$xdr.':spPr>'
            .'</'.$xdr.':pic>'
            .'<'.$xdr.':clientData/>'
            .'</'.$xdr.':twoCellAnchor>';

        $closing = '</'.$xdr.':wsDr>';

        // Appended last, before the root's own close: a drawing part is a list
        // of anchors, and the picture that was just anchored is the last one.
        if (str_contains($drawingXml, $closing)) {
            return (string) str_replace($closing, $picture.$closing, $drawingXml);
        }

        return $drawingXml.$picture.$closing;
    }

    /**
     * The band's measured size in EMU, so a viewer that reads the picture's
     * own extent agrees with the anchor: the covered columns' widths (Excel's
     * characters-to-pixels rule) and the covered rows' heights.
     */
    private static function bandExtent(array $anchor): array
    {
        return [
            'width' => self::bandWidthPixels($anchor) * self::EMU_PER_PIXEL,
            'height' => self::bandHeightPoints($anchor) * self::EMU_PER_POINT,
        ];
    }

    private static function bandWidthPixels(array $anchor): int
    {
        $width = 0;

        for (
            $column = self::columnIndex($anchor['first_column']);
            $column <= self::columnIndex($anchor['last_column']);
            $column++
        ) {
            $width += self::columnWidthPixels($anchor, $column);
        }

        return $width > 0 ? $width : self::FALLBACK_WIDTH_PX;
    }

    private static function bandHeightPoints(array $anchor): float
    {
        $height = 0.0;

        for ($row = $anchor['first_row']; $row <= $anchor['last_row']; $row++) {
            $height += self::rowHeightPoints($anchor, $row);
        }

        return $height > 0 ? $height : self::FALLBACK_HEIGHT_PX;
    }

    /* ------------------------------------------------------------------
     * Sheet edit: the <drawing> element, in schema order
     * ------------------------------------------------------------------ */

    /**
     * Point a sheet at its drawing part.
     *
     * The element order inside <worksheet> is fixed by the schema, and the
     * parsers are stricter about it than about almost anything else, so
     * <drawing> is placed before whichever element must follow it — the
     * template's own part ends `<drawing/><legacyDrawing/>` — and simply
     * before the close when the sheet has nothing after it.
     */
    private static function attachDrawingElement(string $sheetXml, string $rid): string
    {
        $sheetXml = self::ensureRelationshipNamespace($sheetXml);

        $element = '<drawing r:id="'.$rid.'"/>';

        // A sheet carries exactly one <drawing> element. When it already has
        // one pointing somewhere else, that one named a part this package does
        // not hold — the sheet is re-pointed at the drawing that does.
        if (preg_match('/<drawing\b[^>]*\/>/', $sheetXml, $existing) === 1) {
            return str_contains($existing[0], 'r:id="'.$rid.'"')
                ? $sheetXml
                : (string) preg_replace('/<drawing\b[^>]*\/>/', $element, $sheetXml, 1);
        }

        foreach (['<legacyDrawing', '<legacyDrawingHF', '<picture', '<oleObjects', '<controls', '<webPublishItems', '<tableParts', '<extLst'] as $follower) {
            $position = strpos($sheetXml, $follower);

            if ($position !== false) {
                return substr($sheetXml, 0, $position).$element.substr($sheetXml, $position);
            }
        }

        return (string) str_replace('</worksheet>', $element.'</worksheet>', $sheetXml);
    }

    /**
     * The worksheet root has to declare the relationships namespace for a
     * `r:id` attribute to mean anything; the shipped template's sheets already
     * do (their pageSetup carries one), a hand-built or third-party workbook
     * may not.
     */
    private static function ensureRelationshipNamespace(string $sheetXml): string
    {
        if ($sheetXml === '' || str_contains($sheetXml, 'xmlns:r="')) {
            return $sheetXml;
        }

        return (string) preg_replace(
            '/<worksheet\b/',
            '<worksheet xmlns:r="'.self::R_NS.'"',
            $sheetXml,
            1
        );
    }

    /* ------------------------------------------------------------------
     * Relationships
     * ------------------------------------------------------------------ */

    /** A <Relationships> part, empty or holding one entry. */
    private static function relsRoot(string $inner = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="'.self::RELATIONSHIPS_NS.'">'.$inner.'</Relationships>';
    }

    /**
     * Add one relationship on a free rId, returning the new XML and the id.
     *
     * @return array{0: string, 1: string}
     */
    private static function addRelationship(string $rels, string $type, string $target): array
    {
        $used = [];

        if (preg_match_all('/\bId="rId(\d+)"/', $rels, $matches)) {
            $used = array_map('intval', $matches[1]);
        }

        $rid = 'rId'.($used === [] ? 1 : max($used) + 1);

        $relationship = '<Relationship Id="'.$rid.'" Type="'.$type.'" Target="'
            .htmlspecialchars($target, ENT_XML1 | ENT_QUOTES, 'UTF-8').'"/>';

        if ($rels !== '' && str_contains($rels, '</Relationships>')) {
            return [(string) str_replace('</Relationships>', $relationship.'</Relationships>', $rels), $rid];
        }

        return [self::relsRoot($relationship), $rid];
    }

    /**
     * The package path a relationship of $type points at, resolved against the
     * directory of the part that carries the relationships part.
     */
    private static function relationshipTargetByType(string $rels, string $type, string $baseDirectory): ?string
    {
        if (preg_match_all('/<Relationship\b[^>]*\/?>/u', $rels, $elements) === 0) {
            return null;
        }

        foreach ($elements[0] as $element) {
            if (! str_contains($element, 'Type="'.$type.'"')) {
                continue;
            }

            if (preg_match('/\bTarget="([^"]*)"/u', $element, $target) !== 1) {
                continue;
            }

            if (preg_match('/^\w+:/', $target[1]) === 1) {
                continue; // An external target — not a package part.
            }

            return self::resolve($baseDirectory, $target[1]);
        }

        return null;
    }

    /**
     * Resolve a relationship Target against the directory of the part that
     * carries it, the same way OpcrfWorkbookTrim does.
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
     * The r:id of the first relationship of $type, or null when the part has
     * none of that kind.
     */
    private static function relationshipIdByType(string $rels, string $type): ?string
    {
        if (preg_match_all('/<Relationship\b[^>]*\/?>/u', $rels, $elements) === 0) {
            return null;
        }

        foreach ($elements[0] as $element) {
            if (str_contains($element, 'Type="'.$type.'"')
                && preg_match('/\bId="([^"]*)"/u', $element, $found) === 1) {
                return $found[1];
            }
        }

        return null;
    }

    /**
     * The directory a package part sits in, with its trailing slash — the base
     * a relative Target is resolved against: "xl/worksheets/sheet1.xml" →
     * "xl/worksheets/".
     */
    private static function partDirectory(string $part): string
    {
        $slash = strrpos($part, '/');

        return $slash === false ? '' : substr($part, 0, $slash + 1);
    }

    /**
     * The drawing part a sheet displays, and the r:id that points at it: the
     * one the sheet's own <drawing r:id="…"> element names, falling back to
     * its first drawing relationship. Null when the sheet draws nothing — or
     * when what it names is not in the package, which the caller then repairs.
     *
     * @param  array<string, string>  $parts
     * @return array{part: string, rid: string}|null
     */
    private static function drawingForSheet(array $parts, string $sheetPart, ?string $sheetRels): ?array
    {
        if ($sheetRels === null) {
            return null;
        }

        $base = self::partDirectory($sheetPart);

        if (preg_match('/<drawing\b[^>]*\br:id="([^"]+)"/', $parts[$sheetPart] ?? '', $found) === 1) {
            $target = self::relationshipTarget($sheetRels, $found[1]);

            if ($target !== null && isset($parts[self::resolve($base, $target)])) {
                return ['part' => self::resolve($base, $target), 'rid' => $found[1]];
            }
        }

        $rid = self::relationshipIdByType($sheetRels, self::DRAWING_RELATIONSHIP);

        if ($rid === null) {
            return null;
        }

        $referenced = self::relationshipTargetByType($sheetRels, self::DRAWING_RELATIONSHIP, $base);

        return $referenced !== null && isset($parts[$referenced])
            ? ['part' => $referenced, 'rid' => $rid]
            : null;
    }

    /** The Target of one relationship by its r:id, or null when it is not there. */
    private static function relationshipTarget(string $rels, string $rid): ?string
    {
        if (preg_match('/<Relationship\s[^>]*\bId="'.preg_quote($rid, '/').'"[^>]*\bTarget="([^"]*)"/u', $rels, $found) === 1
            || preg_match('/<Relationship\s[^>]*\bTarget="([^"]*)"[^>]*\bId="'.preg_quote($rid, '/').'"/u', $rels, $found) === 1) {
            return $found[1];
        }

        return null;
    }

    /** The relationship Target for $part as seen from the directory $fromDirectory. */
    private static function relativeTarget(string $fromDirectory, string $part): string
    {
        $from = array_values(array_filter(explode('/', trim($fromDirectory, '/')), fn (string $s): bool => $s !== ''));
        $to = explode('/', $part);

        $shared = 0;

        while ($shared < count($from) && $shared < count($to) - 1 && $from[$shared] === $to[$shared]) {
            $shared++;
        }

        $up = array_fill(0, count($from) - $shared, '..');

        return implode('/', [...$up, ...array_slice($to, $shared)]);
    }

    /**
     * The .rels part describing $partName: "xl/worksheets/sheet1.xml" →
     * "xl/worksheets/_rels/sheet1.xml.rels" (same rule as the trim).
     */
    private static function relationshipsPart(string $partName): string
    {
        $slash = strrpos($partName, '/');

        return ($slash === false ? '' : substr($partName, 0, $slash + 1))
            .'_rels/'
            .($slash === false ? $partName : substr($partName, $slash + 1))
            .'.rels';
    }

    /* ------------------------------------------------------------------
     * [Content_Types].xml
     * ------------------------------------------------------------------ */

    /** Declare the image format once, by extension. */
    private static function declareImageType(string $contentTypes, string $extension): string
    {
        if ($contentTypes === '' || str_contains($contentTypes, 'Extension="'.$extension.'"')) {
            return $contentTypes;
        }

        $default = '<Default Extension="'.$extension.'" ContentType="'.self::IMAGE_CONTENT_TYPES[$extension].'"/>';

        return (string) str_replace('</Types>', $default.'</Types>', $contentTypes);
    }

    /** Declare a drawing part this class created. */
    private static function declareDrawingOverride(string $contentTypes, string $drawingPart): string
    {
        $override = '<Override PartName="/'.$drawingPart.'" ContentType="'.self::DRAWING_CONTENT_TYPE.'"/>';

        if ($contentTypes === '' || str_contains($contentTypes, 'PartName="/'.$drawingPart.'"')) {
            return $contentTypes;
        }

        return (string) str_replace('</Types>', $override.'</Types>', $contentTypes);
    }

    /* ------------------------------------------------------------------
     * Drawing parts — the template's own, or one built here
     * ------------------------------------------------------------------ */

    /** A drawing part's root element, as a string of XML. */
    private static function drawingRoot(string $xdr, string $a): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<'.$xdr.':wsDr xmlns:'.$xdr.'="'.self::DRAWING_NS.'" xmlns:'.$a.'="'.self::DRAWINGML_NS.'"'
            .' xmlns:r="'.self::R_NS.'">';
    }

    /**
     * The prefixes a drawing part uses for the drawing and DrawingML
     * namespaces — read from its root, because the template's own part is
     * free to name them anything.
     *
     * @return array{0: string, 1: string}
     */
    private static function drawingPrefixes(string $drawingXml): array
    {
        $xdr = 'xdr';
        $a = 'a';

        if (preg_match('/xmlns:([A-Za-z0-9_]+)="'.preg_quote(self::DRAWING_NS, '/').'"/', $drawingXml, $found) === 1) {
            $xdr = $found[1];
        }

        if (preg_match('/xmlns:([A-Za-z0-9_]+)="'.preg_quote(self::DRAWINGML_NS, '/').'"/', $drawingXml, $found) === 1) {
            $a = $found[1];
        }

        return [$xdr, $a];
    }

    /** A free picture id inside one drawing part (ids must be unique there). */
    private static function nextPictureId(string $drawingXml): int
    {
        if (preg_match_all('/<[A-Za-z0-9_]+:cNvPr\b[^>]*\bid="(\d+)"/', $drawingXml, $matches) === 0) {
            return 2; // 1 belongs to the drawing's own cNvGrpSpPr.
        }

        return max(array_map('intval', $matches[1])) + 1;
    }

    /** A free "xl/drawings/drawingN.xml" name. */
    private static function freeDrawingPart(array $parts): string
    {
        $highest = 0;

        foreach (array_keys($parts) as $name) {
            if (preg_match('#^xl/drawings/drawing(\d+)\.xml$#', $name, $found) === 1) {
                $highest = max($highest, (int) $found[1]);
            }
        }

        return 'xl/drawings/drawing'.($highest + 1).'.xml';
    }

    /** A free "xl/media/imageN.<ext>" name, never colliding with the logo. */
    private static function freeMediaPart(array $parts, string $extension): string
    {
        $highest = 0;

        foreach (array_keys($parts) as $name) {
            if (preg_match('#^xl/media/image(\d+)\.[A-Za-z0-9]+$#', $name, $found) === 1) {
                $highest = max($highest, (int) $found[1]);
            }
        }

        return 'xl/media/image'.($highest + 1).'.'.$extension;
    }

    /* ------------------------------------------------------------------
     * The workbook — sheets, cells, strings
     * ------------------------------------------------------------------ */

    /**
     * Every worksheet part of the package, in tab order, resolved through the
     * workbook manifest (falling back to the numeric sheet parts when the
     * manifest cannot be read).
     *
     * @return array<int, string>
     */
    private static function worksheetParts(array $parts): array
    {
        $workbook = $parts['xl/workbook.xml'] ?? '';
        $rels = $parts['xl/_rels/workbook.xml.rels'] ?? '';
        $ordered = [];

        if ($workbook !== '' && $rels !== ''
            && preg_match('/<sheets>(.*?)<\/sheets>/s', $workbook, $block) === 1
            && preg_match_all('/<sheet\b[^>]*\/?>/u', $block[1], $elements) > 0) {
            foreach ($elements[0] as $element) {
                if (preg_match('/\br:id="([^"]*)"/u', $element, $rid) !== 1) {
                    continue;
                }

                if (preg_match('/<Relationship\s[^>]*\bId="'.preg_quote($rid[1], '/').'"[^>]*\bTarget="([^"]*)"/u', $rels, $found) !== 1) {
                    continue;
                }

                $part = self::resolve('xl/', $found[1]);

                if (isset($parts[$part])) {
                    $ordered[] = $part;
                }
            }
        }

        if ($ordered === []) {
            foreach (array_keys($parts) as $name) {
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) === 1) {
                    $ordered[] = $name;
                }
            }

            usort($ordered, fn (string $a, string $b): int => self::partNumber($a) <=> self::partNumber($b));
        }

        return array_values(array_unique($ordered));
    }

    /** The trailing number of a sheet part's name, for a stable order. */
    private static function partNumber(string $part): int
    {
        return preg_match('/(\d+)\.xml$/', $part, $found) === 1 ? (int) $found[1] : 0;
    }

    /**
     * Every cell of a sheet as ref => plain text, shared strings and inline
     * strings resolved (the same reading the personalizer does).
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
     * The workbook's shared strings table, indexed in file order.
     *
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
     * Every package part of a workbook, by name — through the app's pure-PHP
     * reader, so the stamp works on a PHP build without the zip extension.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException when the bytes are not a readable .xlsx package
     */
    private static function readParts(string $workbookBytes): array
    {
        $staged = tempnam(sys_get_temp_dir(), 'opcrf-sign-');

        if ($staged === false || file_put_contents($staged, $workbookBytes) === false) {
            throw new RuntimeException('The OPCRF workbook could not be read.');
        }

        try {
            return (new PurePhpZipReader($staged))->readAll();
        } finally {
            @unlink($staged);
        }
    }

    /* ------------------------------------------------------------------
     * The image itself
     * ------------------------------------------------------------------ */

    /**
     * The image's real format, read from its own first bytes; the caller's
     * extension is only the answer when the header is one this class does not
     * know (a format it cannot put a content type on anyway).
     */
    private static function detectExtension(string $bytes, string $fallback): string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'jpg';
        }

        if (str_starts_with($bytes, 'GIF8')) {
            return 'gif';
        }

        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }

        $fallback = strtolower(ltrim($fallback, '.'));

        return isset(self::IMAGE_CONTENT_TYPES[$fallback]) ? $fallback : 'png';
    }

    /* ------------------------------------------------------------------
     * Geometry helpers
     * ------------------------------------------------------------------ */

    /** A column letter's 1-based index ("A" => 1, "M" => 13). */
    private static function columnIndex(string $column): int
    {
        $index = 0;

        foreach (str_split($column) as $letter) {
            $index = $index * 26 + (ord(strtoupper($letter)) - 64);
        }

        return $index;
    }

    /** The column letters for a 1-based index (13 => "M"). */
    private static function columnName(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $name = chr(65 + $remainder).$name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }

    /**
     * A column's width in pixels: the sheet's own <cols> entry when it has
     * one, the sheet's default otherwise, converted the way Excel converts a
     * character width to pixels (7 pixels per character plus the cell
     * padding).
     */
    private static function columnWidthPixels(array $anchor, int $column): int
    {
        $width = null;

        if (preg_match_all('/<col\b[^>]*\/?>/', (string) ($anchor['geometry']['cols'] ?? ''), $columns) > 0) {
            foreach ($columns[0] as $element) {
                if (preg_match('/\bmin="(\d+)"/', $element, $min) !== 1 || preg_match('/\bmax="(\d+)"/', $element, $max) !== 1) {
                    continue;
                }

                if ($column < (int) $min[1] || $column > (int) $max[1]) {
                    continue;
                }

                if (preg_match('/\bwidth="([\d.]+)"/', $element, $found) === 1) {
                    $width = (float) $found[1];

                    break;
                }
            }
        }

        $width ??= (float) ($anchor['geometry']['default_col_width'] ?? 8.43);

        return (int) round($width * 7) + 5;
    }

    /** A row's height in points: its own ht attribute, or the sheet default. */
    private static function rowHeightPoints(array $anchor, int $row): float
    {
        foreach ($anchor['geometry']['row_heights'] ?? [] as $number => $height) {
            if ($number === $row) {
                return $height;
            }
        }

        return (float) ($anchor['geometry']['default_row_height'] ?? 15.0);
    }

    /**
     * The band's measurements from the sheet itself: its column definitions,
     * its sheet-wide defaults, and the covered rows' own heights.
     *
     * Only the band's rows are measured — a template sheet has hundreds of
     * rows, and nothing outside the signature's four is any of this class's
     * business.
     *
     * @return array{cols: string, default_col_width: ?float, default_row_height: ?float, row_heights: array<int, float>}
     */
    private static function sheetGeometry(string $sheetXml, int $firstRow, int $lastRow): array
    {
        $rowHeights = [];

        for ($row = $firstRow; $row <= $lastRow; $row++) {
            if (preg_match('/<row\b[^>]*\br="'.$row.'"[^>]*>/', $sheetXml, $element) === 1
                && preg_match('/\bht="([\d.]+)"/', $element[0], $height) === 1) {
                $rowHeights[$row] = (float) $height[1];
            }
        }

        return [
            'cols' => preg_match('/<cols>.*?<\/cols>/s', $sheetXml, $cols) === 1 ? $cols[0] : '',
            'default_col_width' => self::sheetFormatValue($sheetXml, 'defaultColWidth'),
            'default_row_height' => self::sheetFormatValue($sheetXml, 'defaultRowHeight'),
            'row_heights' => $rowHeights,
        ];
    }

    /** A numeric <sheetFormatPr> attribute, or null when the sheet omits it. */
    private static function sheetFormatValue(string $sheetXml, string $attribute): ?float
    {
        if (preg_match('/<sheetFormatPr\b[^>]*\b'.$attribute.'="([\d.]+)"/', $sheetXml, $found) === 1) {
            return (float) $found[1];
        }

        return null;
    }

    /**
     * Tolerant label comparison, matching the analyzer's: alphanumerics only,
     * lowercased, so "RATEE" matches whatever punctuation sits around it.
     */
    private static function normalizeLabel(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
    }
}

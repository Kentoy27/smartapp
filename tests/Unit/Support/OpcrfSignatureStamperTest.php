<?php

namespace Tests\Unit\Support;

use App\Support\OpcrfSignatureStamper;
use App\Support\OpcrfWorkbookTrim;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsOpcrfWorkbooks;
use ZipArchive;

/**
 * The signature stamp's surgery on a workbook: the picture lands in the RATEE
 * column of every sheet that has a signer block, and nothing else about the
 * package moves.
 */
class OpcrfSignatureStamperTest extends TestCase
{
    use BuildsOpcrfWorkbooks;

    /** A real 2×2 transparent PNG, so the bytes are an image nothing can mistake. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFUlEQVR4nGP8z8DAwMDAwMDEAAWMDAwAKrQCAo0Y7sMAAAAASUVORK5CYII=';

    /** The shipped template, where these rules have to hold for real. */
    private const SHIPPED_TEMPLATE = __DIR__.'/../../../storage/forms/OPCRF-TEMPLATE.xlsx';

    private function signaturePng(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    /**
     * Every package part of a workbook, by name.
     *
     * @return array<string, string>
     */
    private function partsOf(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'opcrf-stamp-test-').'.xlsx';
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $zip->open($path);

        $parts = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $parts[$name] = (string) $zip->getFromName($name);
        }

        $zip->close();

        return $parts;
    }

    /**
     * The picture one sheet ended up with: the drawing part its <drawing>
     * element points at, the anchor written there, and the image bytes its
     * relationships lead to.
     *
     * @param  array<string, string>  $parts
     * @return array{rid: string, drawing: string, xml: string, anchor: string, image: string}
     */
    private function pictureOn(array $parts, string $sheetPart): array
    {
        $this->assertSame(1, preg_match(
            '/<drawing\s+r:id="([^"]+)"\s*\/>/',
            $parts[$sheetPart],
            $found
        ), "The sheet $sheetPart must point at a drawing.");

        $rid = $found[1];
        $sheetRels = $parts['xl/worksheets/_rels/'.basename($sheetPart).'.rels'];

        $this->assertStringContainsString('Id="'.$rid.'"', $sheetRels);

        $this->assertSame(1, preg_match(
            '/Id="'.preg_quote($rid, '/').'"[^>]*Target="([^"]+)"/',
            $sheetRels,
            $target
        ));

        $drawingPart = 'xl/drawings/'.ltrim(str_replace('../drawings/', '', $target[1]), '/');
        $drawing = $parts[$drawingPart] ?? '';

        $this->assertNotSame('', $drawing, "The drawing part $drawingPart must exist.");

        $this->assertSame(1, preg_match(
            '/<xdr:twoCellAnchor[^>]*>.*?<xdr:blipFill>.*?r:embed="([^"]+)"/s',
            $drawing,
            $embed
        ), 'The drawing must hold an anchored picture.');

        $drawingRels = $parts['xl/drawings/_rels/'.basename($drawingPart).'.rels'];

        $this->assertSame(1, preg_match(
            '/Id="'.preg_quote($embed[1], '/').'"[^>]*Target="([^"]+)"/',
            $drawingRels,
            $image
        ));

        return [
            'rid' => $rid,
            'drawing' => $drawingPart,
            'xml' => $drawing,
            'anchor' => (string) preg_replace('/.*?(<xdr:twoCellAnchor.*?<\/xdr:twoCellAnchor>).*/s', '$1', $drawing),
            'image' => $parts['xl/'.ltrim(str_replace('../', '', $image[1]), '/')] ?? '',
        ];
    }

    /** The 1-based band one anchor covers, as "D147:F150". */
    private function anchorBand(string $anchor): string
    {
        preg_match('/<xdr:from><xdr:col>(\d+)<\/xdr:col><xdr:colOff>0<\/xdr:colOff><xdr:row>(\d+)<\/xdr:row>/', $anchor, $from);
        preg_match('/<xdr:to><xdr:col>(\d+)<\/xdr:col><xdr:colOff>0<\/xdr:colOff><xdr:row>(\d+)<\/xdr:row>/', $anchor, $to);

        $name = function (int $zeroBased): string {
            $letters = '';

            for ($index = $zeroBased + 1; $index > 0; $index = intdiv($index - 1, 26)) {
                $letters = chr(65 + (($index - 1) % 26)).$letters;
            }

            return $letters;
        };

        return $name((int) $from[1]).((int) $from[2] + 1).':'.$name((int) $to[1]).((int) $to[2] + 1);
    }

    public function test_every_part_with_a_signer_block_gets_the_signature(): void
    {
        $stamped = OpcrfSignatureStamper::stamp(
            file_get_contents($this->buildFullOpcrfWorkbook()),
            $this->signaturePng(),
            'png',
            'Jane D. Doe'
        );

        $this->assertNotNull($stamped);

        $parts = $this->partsOf($stamped);

        // PART I, PART II and PART IV each end in a RATEE block…
        foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet4.xml'] as $sheet) {
            $picture = $this->pictureOn($parts, $sheet);

            $this->assertSame($this->signaturePng(), $picture['image']);
        }

        // …while PART III, which is signed by hand, is left alone.
        $this->assertStringNotContainsString('<drawing', $parts['xl/worksheets/sheet3.xml']);
    }

    public function test_the_signature_sits_above_each_ratee_name_in_its_own_column(): void
    {
        $stamped = OpcrfSignatureStamper::stamp(
            file_get_contents($this->buildFullOpcrfWorkbook()),
            $this->signaturePng()
        );

        $parts = $this->partsOf((string) $stamped);

        // PART I signs in D (RATEE label D153), the four empty rows above it.
        $this->assertSame('D147:G151', $this->anchorBand($this->pictureOn($parts, 'xl/worksheets/sheet1.xml')['anchor']));

        // PART II signs in C (RATEE label C68).
        $this->assertSame('C62:F66', $this->anchorBand($this->pictureOn($parts, 'xl/worksheets/sheet2.xml')['anchor']));

        // PART IV signs in C too (RATEE label C26).
        $this->assertSame('C20:F24', $this->anchorBand($this->pictureOn($parts, 'xl/worksheets/sheet4.xml')['anchor']));
    }

    public function test_a_reviewer_signature_targets_the_rater_slot(): void
    {
        $stamped = OpcrfSignatureStamper::stamp(
            file_get_contents($this->buildThreePartOpcrf()),
            $this->signaturePng(),
            'png',
            'EVA M. DOLLOSA RN',
            'RATER'
        );

        $this->assertNotNull($stamped);
        $parts = $this->partsOf($stamped);
        $picture = $this->pictureOn($parts, 'xl/worksheets/sheet1.xml');

        $this->assertSame('I147:L151', $this->anchorBand($picture['anchor']));
        $this->assertStringContainsString('Digital signature of EVA M. DOLLOSA RN', $picture['xml']);
    }

    public function test_the_sheets_own_cells_are_copied_through_untouched(): void
    {
        $original = file_get_contents($this->buildFullOpcrfWorkbook());
        $stamped = (string) OpcrfSignatureStamper::stamp($original, $this->signaturePng());

        $before = $this->partsOf($original);
        $after = $this->partsOf($stamped);

        $sheets = ['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet3.xml', 'xl/worksheets/sheet4.xml'];

        // A sheet that had no relationships namespace of its own has to
        // declare one for the new r:id to mean anything; it is the only other
        // thing that changes, so it is normalised away on both sides here.
        $normalise = fn (string $xml): string => (string) preg_replace(
            '/<worksheet xmlns:r="[^"]+"/',
            '<worksheet',
            $xml,
            1
        );

        foreach ($sheets as $sheet) {
            // Take the one element back out and the sheet is the sheet the
            // staff member uploaded, to the byte.
            $withoutIt = $sheet === 'xl/worksheets/sheet3.xml'
                ? $after[$sheet]
                : str_replace('<drawing r:id="'.$this->pictureOn($after, $sheet)['rid'].'"/>', '', $after[$sheet]);

            $this->assertSame($normalise($before[$sheet]), $normalise($withoutIt), "The sheet $sheet must be otherwise untouched.");

            if ($sheet !== 'xl/worksheets/sheet3.xml') {
                $this->assertStringContainsString('xmlns:r=', $after[$sheet], "$sheet must declare the relationships namespace.");
            }
        }

        // Every part that is not a sheet, a sheet's relationships, the content
        // types, or one of the added image parts is copied through byte for
        // byte — the workbook is edited, not rebuilt.
        $untouched = array_filter(
            array_keys($before),
            fn (string $name): bool => ! in_array($name, [...$sheets, '[Content_Types].xml'], true)
                && ! str_starts_with($name, 'xl/drawings/'
                    ) && ! str_starts_with($name, 'xl/worksheets/_rels/')
                && ! str_starts_with($name, 'xl/media/')
        );

        $this->assertNotSame([], $untouched, 'The fixture must carry parts beyond the sheets.');

        foreach ($untouched as $name) {
            $this->assertSame($before[$name], $after[$name] ?? '', "$name must be copied through untouched.");
        }

        // A signed sheet gains exactly one <drawing> element, never two — and
        // the sheet with no signer block gains none at all.
        foreach ($sheets as $sheet) {
            $expected = $sheet === 'xl/worksheets/sheet3.xml' ? 0 : 1;

            $this->assertSame($expected, substr_count($after[$sheet], '<drawing'), "The drawing count of $sheet is wrong.");
        }
    }

    public function test_the_parts_it_touches_stay_well_formed_xml(): void
    {
        $stamped = (string) OpcrfSignatureStamper::stamp(
            file_get_contents($this->buildFullOpcrfWorkbook()),
            $this->signaturePng(),
            'png',
            'Jane D. Doe'
        );

        $previous = libxml_use_internal_errors(true);

        foreach ($this->partsOf($stamped) as $name => $content) {
            if (preg_match('/\.(xml|rels)$/', $name) !== 1) {
                continue;
            }

            $this->assertNotFalse(
                @simplexml_load_string($content),
                "$name must still parse — an undeclared namespace prefix would show up here."
            );
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    public function test_the_image_format_is_read_from_the_image_itself(): void
    {
        // JPEG bytes, whatever the caller claims the extension is.
        $jpeg = "\xFF\xD8\xFF\xE0".str_repeat("\x00", 32);

        $stamped = (string) OpcrfSignatureStamper::stamp(
            file_get_contents($this->buildThreePartOpcrf()),
            $jpeg,
            'png'
        );

        $parts = $this->partsOf($stamped);

        $this->assertSame($jpeg, $parts['xl/media/image1.jpg'] ?? '');
        $this->assertStringContainsString('<Default Extension="jpg" ContentType="image/jpeg"/>', $parts['[Content_Types].xml']);
    }

    public function test_a_workbook_with_no_signer_block_is_handed_back_unchanged(): void
    {
        $path = $this->buildOpcrfWorkbook(['B4' => 'Name of Employee:', 'F4' => 'Jane D. Doe']);

        $this->assertNull(
            OpcrfSignatureStamper::stamp(file_get_contents($path), $this->signaturePng())
        );
    }

    public function test_an_empty_signature_stamps_nothing(): void
    {
        $this->assertNull(
            OpcrfSignatureStamper::stamp(file_get_contents($this->buildThreePartOpcrf()), '')
        );
    }

    public function test_the_shipped_template_gains_a_picture_beside_its_own_logo(): void
    {
        if (! is_file(self::SHIPPED_TEMPLATE)) {
            $this->markTestSkipped('The shipped OPCRF template is not in storage/forms.');
        }

        $stamped = (string) OpcrfSignatureStamper::stamp(
            (string) file_get_contents(self::SHIPPED_TEMPLATE),
            $this->signaturePng(),
            'png',
            'Jane D. Doe'
        );

        $parts = $this->partsOf($stamped);

        // The template's Part I already draws its logo: the signature joins
        // that drawing part instead of replacing it.
        $this->assertStringContainsString('image1.png', $parts['xl/drawings/_rels/drawing1.xml.rels']);
        $this->assertStringContainsString('Digital Signature', $parts['xl/drawings/drawing1.xml']);
        $this->assertSame($this->signaturePng(), $parts['xl/media/image2.png']);

        // The sheet itself is untouched: its logo drawing was already wired.
        $before = $this->partsOf((string) file_get_contents(self::SHIPPED_TEMPLATE));
        $this->assertSame($before['xl/worksheets/sheet1.xml'], $parts['xl/worksheets/sheet1.xml']);

        // And the Part-I trim the staff download goes through keeps it.
        $path = tempnam(sys_get_temp_dir(), 'opcrf-stamped-').'.xlsx';
        file_put_contents($path, $stamped);

        $trimmed = $this->partsOf(OpcrfWorkbookTrim::keepPart($path, 'PART I'));

        $this->assertSame($this->signaturePng(), $trimmed['xl/media/image2.png'] ?? '');
        $this->assertStringContainsString('Digital Signature', $trimmed['xl/drawings/drawing1.xml'] ?? '');
    }
}

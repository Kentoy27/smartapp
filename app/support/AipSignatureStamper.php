<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class AipSignatureStamper
{
    private const EMU_PER_PIXEL = 9525;

    /**
     * Add each recorded signer's signature inside that role's fixed cell band.
     *
     * @param  array<int, array{user: User, slot: int}>  $signers
     * @param  array<int, array{start: int, widths: array<int, int>, row?: int}>  $slotWidths
     */
    public static function stamp(string $workbook, array $signers, int $row, array $slotWidths): string
    {
        $disk = Storage::disk('local');
        $pictures = [];

        foreach ($signers as $signerData) {
            $signer = $signerData['user'];

            if (! $signer->hasSignature()) {
                continue;
            }

            $path = (string) $signer->signature_path;

            if (! $disk->exists($path)) {
                continue;
            }

            $image = $disk->get($path);
            $imageInfo = getimagesizefromstring($image);

            if (! is_array($imageInfo) || ! isset($imageInfo[0], $imageInfo[1], $imageInfo['mime'])) {
                throw new RuntimeException('The saved digital signature is not a readable image.');
            }

            [$image, $extension, $mime] = self::officeImage($image, (string) $imageInfo['mime']);
            $imageInfo = getimagesizefromstring($image);

            if (! is_array($imageInfo) || $imageInfo[0] < 1 || $imageInfo[1] < 1) {
                throw new RuntimeException('The saved digital signature has invalid dimensions.');
            }

            $slot = $signerData['slot'];
            $slotData = $slotWidths[$slot] ?? ['start' => 0, 'widths' => []];
            $pictureRow = (int) ($slotData['row'] ?? $row);
            $pictures[] = self::picture(
                count($pictures) + 1,
                $signer,
                $slot,
                $pictureRow,
                $image,
                $extension,
                $mime,
                (int) $imageInfo[0],
                (int) $imageInfo[1],
                $slotData['start'],
                $slotData['widths'],
            );
        }

        if ($pictures === []) {
            return $workbook;
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'aip-signature-');

        if ($temporaryFile === false) {
            throw new RuntimeException('A temporary workbook file could not be created.');
        }

        try {
            if (file_put_contents($temporaryFile, $workbook) !== strlen($workbook)) {
                throw new RuntimeException('The workbook could not be staged for signature placement.');
            }

            $parts = (new PurePhpZipReader($temporaryFile))->readAll();
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        $sheetPath = 'xl/worksheets/sheet1.xml';

        if (! isset($parts[$sheetPath])) {
            throw new RuntimeException('The AIP workbook is missing its worksheet.');
        }

        $signatureRows = array_unique(array_map(
            static fn (array $picture): int => $picture['row_index'] + 1,
            $pictures,
        ));

        foreach ($signatureRows as $signatureRow) {
            $parts[$sheetPath] = self::setSignatureRowHeight($parts[$sheetPath], $signatureRow);
        }

        $parts[$sheetPath] = self::appendSheetDrawingReference($parts[$sheetPath]);
        $parts['xl/worksheets/_rels/sheet1.xml.rels'] = self::sheetRelationships();
        $parts['xl/drawings/drawing1.xml'] = self::drawingXml($pictures);
        $parts['xl/drawings/_rels/drawing1.xml.rels'] = self::drawingRelationships($pictures);

        foreach ($pictures as $picture) {
            $parts['xl/media/image'.$picture['id'].'.'.$picture['extension']] = $picture['image'];
            $parts['[Content_Types].xml'] = self::addContentTypes(
                $parts['[Content_Types].xml'] ?? '',
                $picture['extension'],
                $picture['mime'],
            );
        }

        return PurePhpZipWriter::create($parts);
    }

    /**
     * @param  array<int, int>  $widths
     * @return array{id: int, slot: int, name: string, image: string, extension: string, mime: string, from_col: int, from_col_off: int, to_col: int, to_col_off: int, row_index: int, from_row_off: int, to_row_off: int, width: int, height: int}
     */
    private static function picture(
        int $id,
        User $signer,
        int $slot,
        int $row,
        string $image,
        string $extension,
        string $mime,
        int $imageWidth,
        int $imageHeight,
        int $firstColumn,
        array $widths,
    ): array {
        $columnWidths = array_map(static fn (int $width): int => (int) floor($width * 7 + 5), $widths);
        $availableWidth = array_sum($columnWidths);
        $scale = min(1, 90 / $imageWidth, 20 / $imageHeight);
        $pictureWidth = max(1, (int) round($imageWidth * $scale));
        $pictureHeight = max(1, (int) round($imageHeight * $scale));
        $left = (int) floor(($availableWidth - $pictureWidth) / 2);
        $start = self::columnPosition($left, $columnWidths);
        $end = self::columnPosition($left + $pictureWidth, $columnWidths);
        $fromRowOffset = (int) floor((32 - $pictureHeight) / 2) * self::EMU_PER_PIXEL;

        return [
            'id' => $id,
            'slot' => $slot,
            'name' => $signer->name,
            'image' => $image,
            'extension' => $extension,
            'mime' => $mime,
            'from_col' => $firstColumn + $start['column'],
            'from_col_off' => $start['offset'] * self::EMU_PER_PIXEL,
            'to_col' => $firstColumn + $end['column'],
            'to_col_off' => $end['offset'] * self::EMU_PER_PIXEL,
            'row_index' => $row - 1,
            'from_row_off' => $fromRowOffset,
            'to_row_off' => $fromRowOffset + $pictureHeight * self::EMU_PER_PIXEL,
            'width' => $pictureWidth * self::EMU_PER_PIXEL,
            'height' => $pictureHeight * self::EMU_PER_PIXEL,
        ];
    }

    /**
     * @param  array<int, int>  $widths
     * @return array{column: int, offset: int}
     */
    private static function columnPosition(int $pixel, array $widths): array
    {
        $column = 0;

        foreach ($widths as $width) {
            if ($pixel <= $width) {
                return ['column' => $column, 'offset' => $pixel];
            }

            $pixel -= $width;
            $column++;
        }

        return ['column' => $column, 'offset' => 0];
    }

    /** @return array{string, string, string} */
    private static function officeImage(string $image, string $mime): array
    {
        if ($mime === 'image/png') {
            return [$image, 'png', $mime];
        }

        if ($mime === 'image/jpeg') {
            return [$image, 'jpeg', $mime];
        }

        if ($mime !== 'image/webp') {
            throw new RuntimeException('The saved digital signature must be a PNG, JPG, or WEBP image.');
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagepng')) {
            throw new RuntimeException('This server cannot convert WEBP signatures for the Excel download. Upload a PNG or JPG signature.');
        }

        $source = imagecreatefromstring($image);

        if ($source === false) {
            throw new RuntimeException('The saved WEBP signature could not be decoded.');
        }

        ob_start();
        $converted = imagepng($source);
        $png = ob_get_clean();
        imagedestroy($source);

        if (! $converted || ! is_string($png)) {
            throw new RuntimeException('The saved WEBP signature could not be converted for Excel.');
        }

        return [$png, 'png', 'image/png'];
    }

    private static function setSignatureRowHeight(string $sheet, int $row): string
    {
        $pattern = '/<row\b(?=[^>]*\br="'.preg_quote((string) $row, '/').'")[^>]*>/';
        $updated = preg_replace_callback($pattern, static function (array $match): string {
            $tag = (string) preg_replace('/\s(?:ht|customHeight)="[^"]*"/', '', $match[0]);

            return substr($tag, 0, -1).' ht="24" customHeight="1">';
        }, $sheet, 1, $count);

        if (! is_string($updated) || $count !== 1) {
            throw new RuntimeException('The AIP signature row could not be located.');
        }

        return $updated;
    }

    private static function appendSheetDrawingReference(string $sheet): string
    {
        $sheet = preg_replace(
            '/<worksheet\b/',
            '<worksheet xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"',
            $sheet,
            1,
        ) ?? $sheet;
        $reference = '<drawing r:id="rId1"/>';
        $updated = str_replace('</worksheet>', $reference.'</worksheet>', $sheet, $count);

        if ($count !== 1) {
            throw new RuntimeException('The AIP worksheet could not be prepared for its signature.');
        }

        return $updated;
    }

    private static function sheetRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
            .'</Relationships>';
    }

    private static function drawingXml(array $pictures): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            ;

        foreach ($pictures as $picture) {
            $description = htmlspecialchars('Digital signature of '.$picture['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $startCol = $picture['from_col'];
            $endCol = $picture['to_col'];

            $xml .= '<xdr:twoCellAnchor editAs="oneCell">'
                .'<xdr:from><xdr:col>'.$startCol.'</xdr:col><xdr:colOff>'.$picture['from_col_off'].'</xdr:colOff>'
                .'<xdr:row>'.$picture['row_index'].'</xdr:row><xdr:rowOff>'.$picture['from_row_off'].'</xdr:rowOff></xdr:from>'
                .'<xdr:to><xdr:col>'.$endCol.'</xdr:col><xdr:colOff>'.$picture['to_col_off'].'</xdr:colOff>'
                .'<xdr:row>'.$picture['row_index'].'</xdr:row><xdr:rowOff>'.$picture['to_row_off'].'</xdr:rowOff></xdr:to>'
                .'<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="'.$picture['id'].'" name="Digital Signature '.$picture['id'].'" descr="'.$description.'"/>'
                .'<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
                .'<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId'.$picture['id'].'"/>'
                .'<a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                .'<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$picture['width'].'" cy="'.$picture['height'].'"/></a:xfrm>'
                .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/>'
                .'</xdr:twoCellAnchor>';
        }

        return $xml.'</xdr:wsDr>';
    }

    private static function drawingRelationships(array $pictures): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            ;

        foreach ($pictures as $picture) {
            $xml .= '<Relationship Id="rId'.$picture['id'].'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image'.$picture['id'].'.'.$picture['extension'].'"/>';
        }

        return $xml.'</Relationships>';
    }

    private static function addContentTypes(string $types, string $extension, string $mime): string
    {
        if ($types === '' || ! str_contains($types, '</Types>')) {
            throw new RuntimeException('The AIP workbook is missing its content type manifest.');
        }

        $imageType = '<Default Extension="'.$extension.'" ContentType="'.$mime.'"/>';
        $drawingType = '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';

        if (! str_contains($types, 'Extension="'.$extension.'"')) {
            $types = str_replace('</Types>', $imageType.'</Types>', $types);
        }

        if (! str_contains($types, 'PartName="/xl/drawings/drawing1.xml"')) {
            $types = str_replace('</Types>', $drawingType.'</Types>', $types);
        }

        return $types;
    }
}

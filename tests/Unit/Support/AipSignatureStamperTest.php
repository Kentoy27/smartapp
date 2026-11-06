<?php

namespace Tests\Unit\Support;

use App\Models\User;
use App\Support\AipSignatureStamper;
use App\Support\PurePhpZipReader;
use App\Support\PurePhpZipWriter;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AipSignatureStamperTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFUlEQVR4nGP8z8DAwMDAwMDEAAWMDAwAKrQCAo0Y7sMAAAAASUVORK5CYII=';

    public function test_it_places_the_signature_inside_a_fixed_height_row_without_stretching_it(): void
    {
        Storage::fake('local');
        $image = (string) base64_decode(self::PNG, true);
        Storage::disk('local')->put('signatures/school-head.png', $image);
        Storage::disk('local')->put('signatures/viewer.png', $image);
        Storage::disk('local')->put('signatures/eve.png', $image);
        Storage::disk('local')->put('signatures/sy.png', $image);

        $schoolHead = new User;
        $schoolHead->forceFill([
            'name' => 'School Head',
            'signature_path' => 'signatures/school-head.png',
        ]);
        $viewer = new User;
        $viewer->forceFill(['name' => 'Viewer', 'signature_path' => 'signatures/viewer.png']);
        $eve = new User;
        $eve->forceFill(['name' => 'Eve', 'signature_path' => 'signatures/eve.png']);
        $sy = new User;
        $sy->forceFill(['name' => 'SY', 'signature_path' => 'signatures/sy.png']);

        $workbook = PurePhpZipWriter::create([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="AIP" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="7"></row><row r="9"></row></sheetData></worksheet>',
        ]);

        $signed = AipSignatureStamper::stamp($workbook, [
            0 => ['user' => $schoolHead, 'slot' => 0],
            1 => ['user' => $viewer, 'slot' => 1],
            2 => ['user' => $eve, 'slot' => 2],
            3 => ['user' => $sy, 'slot' => 3],
        ], 7, [
            0 => ['start' => 0, 'widths' => [30, 25, 36]],
            1 => ['start' => 3, 'widths' => [28, 24, 24], 'row' => 7],
            2 => ['start' => 0, 'widths' => [30, 25, 36], 'row' => 9],
            3 => ['start' => 3, 'widths' => [28, 24, 24], 'row' => 9],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'aip-stamped-');

        $this->assertNotFalse($path);

        try {
            file_put_contents($path, $signed);
            $parts = (new PurePhpZipReader($path))->readAll();
            $spreadsheet = IOFactory::load($path);
            $this->assertCount(4, $spreadsheet->getActiveSheet()->getDrawingCollection());
            $spreadsheet->disconnectWorksheets();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->assertSame($image, $parts['xl/media/image1.png'] ?? null);
        $this->assertSame($image, $parts['xl/media/image2.png'] ?? null);
        $this->assertSame($image, $parts['xl/media/image3.png'] ?? null);
        $this->assertSame($image, $parts['xl/media/image4.png'] ?? null);
        $this->assertStringContainsString('ht="24" customHeight="1"', $parts['xl/worksheets/sheet1.xml'] ?? '');
        $this->assertSame(2, substr_count($parts['xl/worksheets/sheet1.xml'] ?? '', 'ht="24" customHeight="1"'));
        $this->assertSame(4, substr_count($parts['xl/drawings/drawing1.xml'] ?? '', '<xdr:twoCellAnchor'));
        $this->assertSame(4, substr_count($parts['xl/drawings/drawing1.xml'] ?? '', 'cx="19050" cy="19050"'));
        $this->assertStringContainsString('Digital signature of Eve', $parts['xl/drawings/drawing1.xml'] ?? '');
        $this->assertStringContainsString('Digital signature of SY', $parts['xl/drawings/drawing1.xml'] ?? '');
        $this->assertSame(4, substr_count($parts['xl/drawings/drawing1.xml'] ?? '', '<xdr:row>8</xdr:row>'));
        $this->assertStringContainsString('<xdr:colOff>1047750</xdr:colOff>', $parts['xl/drawings/drawing1.xml'] ?? '');
    }
}

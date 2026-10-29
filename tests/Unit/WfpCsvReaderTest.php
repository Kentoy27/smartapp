<?php

namespace Tests\Unit;

use App\Support\WfpCsvReader;
use Tests\TestCase;

class WfpCsvReaderTest extends TestCase
{
    /**
     * @param  string  $content
     * @return string
     */
    private function tempCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wfp-csv-test-').'.csv';
        file_put_contents($path, $content);

        return $path;
    }

    public function test_it_detects_a_header_row_and_maps_by_name(): void
    {
        $path = $this->tempCsv("Code,Name,Active\nMOOE,Maintenance and Other Operating Expenses,Yes\nSEF,Special Education Fund,Yes\n");

        $rows = WfpCsvReader::read(
            $path,
            [
                'code' => ['label' => 'Code', 'aliases' => ['code']],
                'name' => ['label' => 'Name', 'aliases' => ['name']],
                'is_active' => ['label' => 'Active', 'aliases' => ['active']],
            ],
            100
        );

        $this->assertCount(2, $rows);
        $this->assertSame('MOOE', $rows[0]['data']['code']);
        $this->assertSame('Maintenance and Other Operating Expenses', $rows[0]['data']['name']);
        $this->assertSame('Yes', $rows[0]['data']['is_active']);
        // Lines carry the real file line numbers: the header is line 1, so
        // the first data row is line 2 (the error report points at the file).
        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame(3, $rows[1]['line']);
    }

    public function test_it_reads_positionally_when_there_is_no_header(): void
    {
        $path = $this->tempCsv("MOOE,Maintenance and Other Operating Expenses,Yes\nSEF,Special Education Fund,Yes\n");

        $rows = WfpCsvReader::read(
            $path,
            [
                'code' => ['label' => 'Code', 'aliases' => ['code']],
                'name' => ['label' => 'Name', 'aliases' => ['name']],
                'is_active' => ['label' => 'Active', 'aliases' => ['active']],
            ],
            100
        );

        $this->assertCount(2, $rows);
        $this->assertSame('MOOE', $rows[0]['data']['code']);
        $this->assertSame('Maintenance and Other Operating Expenses', $rows[0]['data']['name']);
        $this->assertSame('SEF', $rows[1]['data']['code']);
        $this->assertSame('Special Education Fund', $rows[1]['data']['name']);
    }

    public function test_it_strips_a_utf8_bom(): void
    {
        $path = $this->tempCsv("\xEF\xBB\xBFCode,Name\nMOOE,Maintenance and Other Operating Expenses\n");

        $rows = WfpCsvReader::read(
            $path,
            [
                'code' => ['label' => 'Code', 'aliases' => ['code']],
                'name' => ['label' => 'Name', 'aliases' => ['name']],
            ],
            100
        );

        $this->assertCount(1, $rows);
        $this->assertSame('MOOE', $rows[0]['data']['code']);
        $this->assertSame('Maintenance and Other Operating Expenses', $rows[0]['data']['name']);
    }

    public function test_it_skips_blank_rows(): void
    {
        $path = $this->tempCsv("Code,Name\n\nMOOE,Maintenance and Other Operating Expenses\n\nSEF,Special Education Fund\n");

        $rows = WfpCsvReader::read(
            $path,
            [
                'code' => ['label' => 'Code', 'aliases' => ['code']],
                'name' => ['label' => 'Name', 'aliases' => ['name']],
            ],
            100
        );

        $this->assertCount(2, $rows);
        $this->assertSame('MOOE', $rows[0]['data']['code']);
        $this->assertSame('SEF', $rows[1]['data']['code']);
    }

    public function test_it_enforces_the_row_cap(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('more than 2 data rows');

        $lines = ['Code,Name'];
        for ($i = 0; $i < 3; $i++) {
            $lines[] = "CODE{$i},Name {$i}";
        }

        $path = $this->tempCsv(implode("\n", $lines)."\n");

        WfpCsvReader::read(
            $path,
            ['code' => ['label' => 'Code', 'aliases' => ['code']], 'name' => ['label' => 'Name', 'aliases' => ['name']]],
            2
        );
    }

    public function test_it_parses_boolean_values(): void
    {
        $trueValues = ['Yes', 'yes', 'Y', '1', 'true', 'True', 'active', 'ACTIVE', 'enabled', 'on', ''];
        foreach ($trueValues as $value) {
            $this->assertSame(['valid' => true, 'value' => true], WfpCsvReader::parseBoolean($value), "parseBoolean({$value}) should be valid=true");
        }

        $falseValues = ['No', 'no', 'N', '0', 'false', 'False', 'inactive', 'INACTIVE', 'disabled', 'off'];
        foreach ($falseValues as $value) {
            $this->assertSame(['valid' => true, 'value' => false], WfpCsvReader::parseBoolean($value), "parseBoolean({$value}) should be valid=false");
        }

        $this->assertSame(['valid' => false, 'value' => true], WfpCsvReader::parseBoolean('maybe'));
        $this->assertSame(['valid' => false, 'value' => true], WfpCsvReader::parseBoolean('123'));
    }

    public function test_it_normalizes_headers_by_stripping_punctuation_and_spaces(): void
    {
        $path = $this->tempCsv("Funding Code,Source Name, Is Active\nMOOE,Maintenance and Other Operating Expenses,Yes\n");

        $rows = WfpCsvReader::read(
            $path,
            [
                'code' => ['label' => 'Code', 'aliases' => ['fundingcode', 'fundsourcecode', 'code']],
                'name' => ['label' => 'Name', 'aliases' => ['sourcename', 'name']],
                'is_active' => ['label' => 'Active', 'aliases' => ['active', 'isactive']],
            ],
            100
        );

        $this->assertCount(1, $rows);
        $this->assertSame('MOOE', $rows[0]['data']['code']);
        $this->assertSame('Maintenance and Other Operating Expenses', $rows[0]['data']['name']);
        $this->assertSame('Yes', $rows[0]['data']['is_active']);
    }

    public function test_it_preserves_leading_zeroes_in_codes(): void
    {
        $path = $this->tempCsv("Code,Name\n00123,Zero Padded Code\n");

        $rows = WfpCsvReader::read(
            $path,
            [
                'code' => ['label' => 'Code', 'aliases' => ['code']],
                'name' => ['label' => 'Name', 'aliases' => ['name']],
            ],
            100
        );

        $this->assertCount(1, $rows);
        $this->assertSame('00123', $rows[0]['data']['code']);
    }
}

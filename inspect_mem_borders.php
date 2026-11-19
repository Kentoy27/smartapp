<?php
require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\District;
use App\Models\School;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\MonthlyExpenditureItem;
use App\Support\MemXlsxExporter;

$db = app('db');
$db->beginTransaction();

try {
    $district = District::create(['name' => 'District Test']);
    $school = School::create(['district_id' => $district->id, 'name' => 'Test School']);
    $user = User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => $school->id, 'name' => 'Test User']);

    $matrix = MonthlyExpenditureMatrix::create([
        'school_id' => $school->id,
        'year' => 2026,
        'month' => 1,
        'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
        'version' => 1,
        'created_by' => $user->id,
    ]);

    $matrix->items()->create([
        'position' => 1,
        'section' => 'operations',
        'objective' => 'To ensure timely payment',
        'activity_description' => 'Payment for Electricity',
        'resource_kind' => 'Electricity',
        'quantity' => 1,
        'unit' => 'mo',
        'unit_cost' => 500,
        'account_title' => 'Electricity Expenses',
        'account_code' => '5020402000',
    ]);

    $matrix->items()->create([
        'position' => 2,
        'section' => 'project',
        'objective' => 'Improved facilities',
        'activity_description' => 'Build a fence',
        'resource_kind' => 'Materials',
        'quantity' => 10,
        'unit' => 'pcs',
        'unit_cost' => 100,
        'account_title' => 'Construction Materials',
        'account_code' => '5020301000',
    ]);

    $bytes = MemXlsxExporter::bytes($matrix, $user);

    $tmp = tempnam(sys_get_temp_dir(), 'mem-');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($tmp);

    preg_match_all('/<row r="(\d+)">(.*?)<\/row>/s', $sheet, $rowMatches, PREG_SET_ORDER);
    foreach ($rowMatches as $rowMatch) {
        $rowNum = $rowMatch[1];
        $rowXml = $rowMatch[2];
        preg_match_all('/<c r="([A-Z]+\d+)"([^>]*)>(.*?)<\/c>|<c r="([A-Z]+\d+)"([^>]*)\/>/s', $rowXml, $cellMatches, PREG_SET_ORDER);
        $cells = [];
        foreach ($cellMatches as $cm) {
            $ref = $cm[1] ?: $cm[4];
            $attrs = $cm[2] ?: $cm[5];
            $hasS = preg_match('/\bs="(\d+)"/', $attrs, $s) ? $s[1] : 'none';
            $cells[] = $ref.'(s='.$hasS.')';
        }
        echo "Row $rowNum: ".implode(', ', $cells)."\n";
    }

    $db->rollBack();
} catch (\Throwable $e) {
    $db->rollBack();
    echo "ERROR: ".$e->getMessage()."\n";
    echo $e->getTraceAsString()."\n";
}

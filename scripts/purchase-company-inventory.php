<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$connection = DB::connection();
echo json_encode(['connection' => $connection->getDriverName(), 'database' => $connection->getDatabaseName()]).PHP_EOL;
foreach (['vat_inputs', 'purchase_adjustments', 'pending_purchase_uploads', 'importation_entries', 'withholding_companies'] as $table) {
    echo $table.': '.DB::table($table)->count().PHP_EOL;
}
echo 'purchase totals: '.json_encode(App\Models\VatInput::query()->excludingImportationMirrors()->selectRaw('COUNT(*) as rows_count, SUM(total) as total, SUM(input_vat) as input_vat')->first()->toArray()).PHP_EOL;
echo 'company profiles: '.json_encode(DB::table('withholding_companies')->get(['tin','branch_code','registered_name','is_active','rdo_code'])->all()).PHP_EOL;

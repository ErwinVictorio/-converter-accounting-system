<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$folder = storage_path('app/private/purchase-company-backups/'.date('Ymd-His'));
if (!mkdir($folder, 0700, true) && !is_dir($folder)) throw new RuntimeException('Cannot create backup');
$manifest = ['database' => DB::connection()->getDatabaseName(), 'tables' => []];
DB::transaction(function () use ($folder, &$manifest) {
    foreach (['vat_inputs', 'purchase_adjustments', 'pending_purchase_uploads', 'importation_entries', 'withholding_companies'] as $table) {
        $rows=DB::table($table)->orderBy('id')->get()->all();
        $payload=json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $path=$folder.'/'.$table.'.json';
        if (file_put_contents($path,$payload) === false) throw new RuntimeException('Backup failed');
        $manifest['tables'][$table]=['rows'=>count($rows),'sha256'=>hash_file('sha256',$path)];
    }
});
file_put_contents($folder.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo $folder.PHP_EOL;
echo json_encode(array_map(fn($t)=>$t['rows'],$manifest['tables'])).PHP_EOL;

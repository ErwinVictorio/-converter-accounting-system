<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$folders=glob(storage_path('app/private/purchase-company-backups/*'));sort($folders);$folder=end($folders);
foreach(['vat_inputs','purchase_adjustments','pending_purchase_uploads','importation_entries','withholding_companies'] as $table){
 $old=json_decode(file_get_contents($folder.'/'.$table.'.json'),true,512,JSON_THROW_ON_ERROR);
 $new=Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
 if(count($old)!==count($new))throw new RuntimeException("Count changed: $table");
 foreach($old as $i=>$row){foreach($row as $key=>$value){if($new[$i][$key]!==$value)throw new RuntimeException("Changed: $table.$key");}}
 echo "$table: unchanged original rows/fields (".count($old).")\n";
}
echo 'Unassigned purchase rows: '.App\Models\VatInput::excludingImportationMirrors()->whereNull('filing_company_tin')->count().PHP_EOL;

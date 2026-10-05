<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || !str_contains(config('database.connections.sqlite.database'),'purchase-company-browser.sqlite')) throw new RuntimeException('Isolated browser database required');
App\Models\User::create(['name'=>'Browser Verification','username'=>'purchase-browser','password'=>'browser-test-only']);
App\Models\WithholdingCompany::create(['tin'=>'008564445','branch_code'=>'0000','registered_name'=>'STC','address1'=>'STC STREET','address2'=>'MANILA','rdo_code'=>'045','is_active'=>true]);
foreach (['008791976','008564445',null] as $i=>$tin) {
 App\Models\VatInput::create(['filing_company_tin'=>$tin,'filing_company_branch_code'=>$tin?'0000':null,'supplier_name'=>['FSI ONLY','STC ONLY','LEGACY UNASSIGNED'][$i],'company_name'=>'TEST SUPPLIER','tin_number'=>'123456789','vendor_type'=>'company','address1'=>'STREET','address2'=>'MANILA','date_uploaded'=>'2026-05-31','purchase_local'=>1000,'services'=>0,'other_than_capital_goods'=>1000,'input_vat'=>120,'total'=>1120,'total_purchases'=>1120,'is_adjusted'=>false,'is_imported'=>false]);
}
echo "Isolated browser fixtures ready\n";

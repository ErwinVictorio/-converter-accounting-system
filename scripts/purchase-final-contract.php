<?php
function edit($f,$a,$b){$s=str_replace("\r\n","\n",file_get_contents($f));if(!str_contains($s,$a))throw new RuntimeException($f);file_put_contents($f,str_replace($a,$b,$s));}
edit('app/Imports/VatInputImport.php','public function __construct(?string $uploadDate = null, protected array $filingCompany = [])','public function __construct(?string $uploadDate, protected array $filingCompany)');
edit('app/Imports/VatInputImport.php', '$this->uploadDate = $uploadDate ?? now()->toDateString();', <<<'CODE'
$this->filingCompany = app(\App\Services\BIR\PurchaseCompanyDirectory::class)->resolve($filingCompany['tin'] ?? null, $filingCompany['branch_code'] ?? null);
        $this->uploadDate = $uploadDate ?? now()->toDateString();
CODE);
edit('tests/Feature/PurchaseServicesConsolidationTest.php','new VatInputImport(self::PERIOD)', "new VatInputImport(self::PERIOD, ['tin' => '008791976', 'branch_code' => '0000'])");
edit('tests/Feature/PurchaseServicesConsolidationTest.php','VatInput::create([', "VatInput::create([\n            'filing_company_tin' => '008791976', 'filing_company_branch_code' => '0000',");
edit('app/Services/PendingPurchaseUploadService.php', '        $token = (string) Str::uuid();', "        \$company = app(\\App\\Services\\BIR\\PurchaseCompanyDirectory::class)->resolve(\$company['tin'] ?? null, \$company['branch_code'] ?? null);\n        \$token = (string) Str::uuid();");
foreach(['ExpandedWtaxDatFileTest','ImportationDatFileTest','ImportationEntryTest','ImportationUploadTest','DatFileAlphabeticalOrderingTest'] as $name){
$f="tests/Feature/$name.php";$lines=file($f);foreach($lines as &$line){if(preg_match('/record_type=(expanded|sales|importation)/',$line))$line=str_replace('filing_company_tin=008791976&filing_company_branch_code=0000&','',$line);}unset($line);file_put_contents($f,implode('',$lines));
}
$f='tests/Feature/PurchaseCompanySelectionTest.php';$s=file_get_contents($f);
$s=str_replace("        try {\n            app(PurchaseUploadService", "        \$failed = false;\n        try {\n            app(PurchaseUploadService",$s);
$s=str_replace("            \$this->fail('An unreadable workbook must fail.');", '',$s);
$s=str_replace("            \$this->assertNotEmpty(\$error->getMessage());", "            \$failed = true;",$s);
$s=str_replace("        \$this->assertSame(\$before,VatInput::forFilingCompany(self::FSI)->sole()->getAttributes());", "        \$this->assertTrue(\$failed, 'An unreadable workbook must fail.');\n        \$this->assertSame(\$before,VatInput::forFilingCompany(self::FSI)->sole()->getAttributes());",$s);
file_put_contents($f,$s);

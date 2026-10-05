<?php
function edit($file, $from, $to) {
    $s = str_replace("\r\n", "\n", file_get_contents($file));
    if (!str_contains($s, $from)) throw new RuntimeException("Missing anchor in $file: $from");
    file_put_contents($file, str_replace($from, $to, $s));
}
edit('app/Http/Controllers/VatInputController.php', '$sources = VatInput::query()', '$sources = VatInput::query()->forFilingCompany($target->filingCompanyIdentity())');
edit('app/Models/WithholdingCompany.php', 'return ExpandedWtaxEntry::query()', <<<'CODE'
return VatInput::query()->forFilingCompany(['tin' => $this->tin, 'branch_code' => $this->branch_code])->exists()
            || PendingPurchaseUpload::query()->where('filing_company_tin', $this->tin)->where('filing_company_branch_code', $this->branch_code)->exists()
            || ExpandedWtaxEntry::query()
CODE);
edit('app/Http/Controllers/DatFileController.php', "        \$reportType = \$recordType === 'expanded'", <<<'CODE'
        $purchaseDirectory = app(\App\Services\BIR\PurchaseCompanyDirectory::class);
        $purchaseCompany = $recordType === 'purchase' && ($request->filled('filing_company_tin') || $request->filled('filing_company_branch_code'))
            ? $purchaseDirectory->resolve($request->input('filing_company_tin'), $request->input('filing_company_branch_code'), true)
            : null;
        $reportType = $recordType === 'expanded'
CODE);
edit('app/Http/Controllers/DatFileController.php', '$this->purchasePeriods($purchaseValidator)', '$purchaseCompany ? $this->purchasePeriods($purchaseValidator, $purchaseCompany) : [[], []]');
edit('app/Http/Controllers/DatFileController.php', "'defaultCompany' => config('bir.companies.008791976'),", <<<'CODE'
'defaultCompany' => config('bir.companies.008791976'),
            'purchaseCompanies' => $purchaseDirectory->companies(true),
            'selectedPurchaseCompany' => $purchaseCompany,
            'unassignedPurchaseCount' => $recordType === 'purchase'
                ? VatInput::query()->excludingImportationMirrors()->whereNull('filing_company_tin')->count() : 0,
CODE);
edit('app/Http/Controllers/DatFileController.php', 'return $this->downloadPurchase($period, $purchaseGenerator, $purchaseValidator, $attachmentBuilder, $pdfRenderer);', <<<'CODE'
$directory = app(\App\Services\BIR\PurchaseCompanyDirectory::class);
        $company = $directory->forDat($directory->resolve(
            $request->input('filing_company_tin'), $request->input('filing_company_branch_code'), true
        ));
        return $this->downloadPurchase($period, $purchaseGenerator, $purchaseValidator, $attachmentBuilder, $pdfRenderer, $company);
CODE);
edit('app/Http/Controllers/DatFileController.php', 'private function purchasePeriods(BirPurchaseRowValidator $validator): array', 'private function purchasePeriods(BirPurchaseRowValidator $validator, array $company): array');
// Only the Purchase period method and download are changed; other record types retain their contracts.
$f='app/Http/Controllers/DatFileController.php';
$s=file_get_contents($f);
$a=strpos($s,'    private function purchasePeriods('); $b=strpos($s,'    private function ', $a+30);
$part=substr($s,$a,$b-$a);
$part=str_replace('->excludingImportationMirrors()', '->excludingImportationMirrors()->forFilingCompany($company)', $part);
// Group periods in PHP to support both MySQL and test SQLite.
$start=strpos($part,'        $availablePeriods ='); $end=strpos($part,'        $periodIssues =');
$part=substr_replace($part, <<<'CODE'
        $availablePeriods = VatInput::query()->excludingImportationMirrors()->forFilingCompany($company)
            ->orderByDesc('date_uploaded')->get()->groupBy(fn ($row) => $row->date_uploaded->format('Y-m'))
            ->map(fn ($rows, $month) => ['value' => $month, 'label' => $rows->first()->date_uploaded->format('F Y'), 'records_count' => $rows->count()])
            ->values();

CODE, $start,$end-$start);
$s=substr_replace($s,$part,$a,$b-$a);
$a=strpos($s,'    private function downloadPurchase('); $b=strpos($s,'    private function ', $a+30);
$part=substr($s,$a,$b-$a);
$part=str_replace('DatAttachmentPdfRenderer $pdfRenderer', 'DatAttachmentPdfRenderer $pdfRenderer, array $company', $part);
$part=str_replace('->excludingImportationMirrors()', '->excludingImportationMirrors()->forFilingCompany($company)', $part);
$part=str_replace('        $company = $this->defaultReliefCompany();', '', $part);
$part=str_replace('selected reporting month.', 'selected company and reporting month.', $part);
$s=substr_replace($s,$part,$a,$b-$a); file_put_contents($f,$s);

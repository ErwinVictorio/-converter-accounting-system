<?php
function edit($file, $from, $to) {
    $s = str_replace("\r\n", "\n", file_get_contents($file));
    if (!str_contains($s, $from)) throw new RuntimeException("Missing anchor in $file: $from");
    file_put_contents($file, str_replace($from, $to, $s));
}
$f='resources/js/Pages/RecordEntry.jsx';
$s=file_get_contents($f);
$guard=<<<'CODE'
    if (data.record_type === "purchase" && !data.filing_company_tin) {
      toast.error("Select a Purchase filing company before uploading.");
      return;
    }
CODE;
$s=str_replace($guard, '', $s);
$s=str_replace("  const handleSubmit = (e) => {\n    e.preventDefault();", "  const handleSubmit = (e) => {\n    e.preventDefault();\n".$guard, $s);
file_put_contents($f,$s);
$f='app/Http/Controllers/RecordController.php';
edit($f, '        $vatInputs = VatInput::query()', <<<'CODE'
        $companyKey = (string) $request->input('company', '');
        $companyQuery = VatInput::query();
        if ($companyKey === 'unassigned') {
            $companyQuery->whereNull('filing_company_tin')->excludingImportationMirrors();
        } elseif ($companyKey !== '') {
            [$tin, $branch] = array_pad(explode('|', $companyKey, 2), 2, null);
            $company = app(\App\Services\BIR\PurchaseCompanyDirectory::class)->resolve($tin, $branch, true);
            $companyQuery->forFilingCompany($company)->excludingImportationMirrors();
        }
        $vatInputs = (clone $companyQuery)
CODE);
edit($f, "'months' => \$this->availableMonths(VatInput::query(), 'date_uploaded'),", "'months' => \$this->availableMonths(clone \$companyQuery, 'date_uploaded'),\n            'purchaseCompanies' => app(\\App\\Services\\BIR\\PurchaseCompanyDirectory::class)->companies(true),");
// Only Purchase filters.
edit($f, "'vatInputs' => \$vatInputs,", "'vatInputs' => \$vatInputs,\n            'companyFilter' => \$companyKey,");
$f='resources/js/Components/Records/RecordPeriodFilter.jsx';
edit($f, '    queryKey = "period",', '    queryKey = "period", params = {},');
edit($f, '                ...(value ?', '                ...params,' . "\n                ...(value ?");
$f='resources/js/Pages/Records/PurchaseRecords.jsx';
edit($f, 'months = [], filters = {}', 'months = [], filters = {}, purchaseCompanies = [], companyFilter = ""');
edit($f, '                description="Uploaded VAT input rows. Upload new files under Import Data."', '                description="Purchase records. Unassigned historical rows are excluded from company DAT downloads until ownership is reviewed."');
edit($f, '                        <RecordSearchInput', <<<'CODE'
                        <select aria-label="Filter Purchase company" value={companyFilter}
                            className="h-9 rounded-md border border-slate-200 bg-white px-3 text-sm"
                            onChange={(event) => router.get("/records/purchases", { ...filters, company: event.target.value }, { preserveScroll: true })}>
                            <option value="">All companies / unassigned</option>
                            <option value="unassigned">Unassigned Purchase records</option>
                            {purchaseCompanies.map((company) => <option key={`${company.tin}|${company.branch_code}`} value={`${company.tin}|${company.branch_code}`}>{company.name} ({company.tin}-{company.branch_code})</option>)}
                        </select>
                        <RecordSearchInput
CODE);
edit($f, 'params={filters.period ? { period: filters.period } : {}}', 'params={{ ...(filters.period ? { period: filters.period } : {}), company: companyFilter }}');
edit($f, '                            months={months}', '                            months={months} params={{ company: companyFilter }}');
edit($f, '                                                <span>{item.supplier_name}</span>', <<<'CODE'
                                                <span>{item.supplier_name}<small className="block font-normal text-slate-500">{item.filing_company_tin ? `${item.filing_company_tin}-${item.filing_company_branch_code}` : "Unassigned / legacy"}</small></span>
CODE);
$f='app/Http/Controllers/WithholdingCompanyController.php';
edit($f, 'Expanded WTAX records already exist for ', 'Purchase, pending upload, or Expanded WTAX records already exist for ');
edit($f, ': Expanded WTAX records were filed under ', ': Purchase, pending upload, or Expanded WTAX records exist under ');
// A null identity may contain mixed historical companies; never merge or restore it automatically.
$f='app/Http/Controllers/VatInputController.php';
foreach (['adjustedLookup(Request $request, VatInput $vatInput)', 'update(Request $request, VatInput $vatInput)', 'destroyAdjusted(Request $request, VatInput $vatInput)'] as $signature) {
    edit($f, "public function $signature\n    {", "public function $signature\n    {\n        if (! \$vatInput->filing_company_tin || ! \$vatInput->filing_company_branch_code) {\n            throw ValidationException::withMessages(['filing_company_tin' => 'Assign the historical Purchase company before adjusting or restoring amounts.']);\n        }");
}

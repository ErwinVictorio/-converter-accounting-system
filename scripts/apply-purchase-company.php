<?php
function edit($file, $from, $to) {
    $s = file_get_contents($file);
    $s = str_replace("\r\n", "\n", $s);
    if (!str_contains($s, $from)) throw new RuntimeException("Missing anchor in $file: $from");
    file_put_contents($file, str_replace($from, $to, $s));
}
edit('app/Models/VatInput.php', "  protected \$fillable = [", "  protected \$fillable = [\n    'filing_company_tin',\n    'filing_company_branch_code',");
edit('app/Models/VatInput.php', '  public function outgoingPurchaseAdjustments()', <<<'CODE'
  public function scopeForFilingCompany(Builder $query, array $company): Builder
  {
    return $query->where('filing_company_tin', $company['tin'] ?? null)
      ->where('filing_company_branch_code', $company['branch_code'] ?? null);
  }

  public function filingCompanyIdentity(): array
  {
    return ['tin' => $this->filing_company_tin, 'branch_code' => $this->filing_company_branch_code];
  }

  public function outgoingPurchaseAdjustments()
CODE);
edit('app/Models/PendingPurchaseUpload.php', "    protected \$fillable = [", "    protected \$fillable = [\n        'filing_company_tin',\n        'filing_company_branch_code',");
edit('app/Imports/VatInputImport.php', 'public function __construct(?string $uploadDate = null)', 'public function __construct(?string $uploadDate = null, protected array $filingCompany = [])');
edit('app/Imports/VatInputImport.php', "\$existingRecord = VatInput::where('supplier_name', \$supplierName)", "\$existingRecord = VatInput::where('supplier_name', \$supplierName)\n            ->forFilingCompany(\$this->filingCompany)");
edit('app/Imports/VatInputImport.php', '        VatInput::create([', <<<'CODE'
        VatInput::create([
            'filing_company_tin' => $this->filingCompany['tin'] ?? null,
            'filing_company_branch_code' => $this->filingCompany['branch_code'] ?? null,
CODE);
edit('app/Services/PurchaseUploadService.php', 'public function replace($file, string $reportingPeriod): array', 'public function replace($file, string $reportingPeriod, array $company): array');
edit('app/Services/PurchaseUploadService.php', '$import = new VatInputImport($reportingPeriod);', <<<'CODE'
$company = app(\App\Services\BIR\PurchaseCompanyDirectory::class)->resolve($company['tin'] ?? null, $company['branch_code'] ?? null);
        $import = new VatInputImport($reportingPeriod, $company);
CODE);
edit('app/Services/PurchaseUploadService.php', 'DB::transaction(function () use ($reportingPeriod, $file, $import) {', <<<'CODE'
DB::transaction(function () use ($reportingPeriod, $file, $import, $company) {
            $identity = $company['tin'].'|'.$company['branch_code'].'|'.$reportingPeriod;
            DB::table('purchase_upload_locks')->insertOrIgnore(['identity' => $identity]);
            DB::table('purchase_upload_locks')->where('identity', $identity)->lockForUpdate()->first();
CODE);
edit('app/Services/PurchaseUploadService.php', '            VatInput::query()', "            VatInput::query()\n                ->forFilingCompany(\$company)");
edit('app/Services/PendingPurchaseUploadService.php', '?string $retainedContents = null', '?string $retainedContents = null, array $company = []');
edit('app/Services/PendingPurchaseUploadService.php', 'return PendingPurchaseUpload::create([', <<<'CODE'
return PendingPurchaseUpload::create([
                'filing_company_tin' => $company['tin'] ?? null,
                'filing_company_branch_code' => $company['branch_code'] ?? null,
CODE);
edit('app/Services/PendingPurchaseUploadService.php', "'reporting_period' => \$pending->reporting_period->format('Y-m'),", <<<'CODE'
'reporting_period' => $pending->reporting_period->format('Y-m'),
            'filing_company_tin' => $pending->filing_company_tin,
            'filing_company_branch_code' => $pending->filing_company_branch_code,
            'filing_company_label' => $pending->filing_company_tin
                ? $pending->filing_company_tin.'-'.$pending->filing_company_branch_code
                : 'Unassigned - upload again with a selected company',
CODE);
edit('app/Services/PendingPurchaseUploadService.php', "'ready_to_retry' => \$grouped['items'] === [],", "'ready_to_retry' => \$grouped['items'] === [] && \$pending->filing_company_tin !== null,");
edit('app/Http/Controllers/PendingPurchaseUploadController.php', '        $claimed = PendingPurchaseUpload::query()', <<<'CODE'
        $company = app(\App\Services\BIR\PurchaseCompanyDirectory::class)->resolve(
            $pendingPurchaseUpload->filing_company_tin,
            $pendingPurchaseUpload->filing_company_branch_code
        );
        foreach (['filing_company_tin' => 'tin', 'filing_company_branch_code' => 'branch_code'] as $field => $key) {
            if ($request->has($field) && $request->input($field) !== $company[$key]) {
                throw ValidationException::withMessages([$field => 'Retry cannot change the retained company. Upload again to select another company.']);
            }
        }
        $claimed = PendingPurchaseUpload::query()
CODE);
edit('app/Http/Controllers/PendingPurchaseUploadController.php', <<<'CODE'
$result = $this->purchaseUploads->replace(
                $path,
                $pendingPurchaseUpload->reporting_period->toDateString()
CODE, <<<'CODE'
$result = $this->purchaseUploads->replace(
                $path,
                $pendingPurchaseUpload->reporting_period->toDateString(),
                $company
CODE);
edit('app/Http/Controllers/VatInputController.php', "'birCompanies' => \$this->companies->activeCompanies(),", "'birCompanies' => \$this->companies->activeCompanies(),\n            'purchaseCompanies' => app(\\App\\Services\\BIR\\PurchaseCompanyDirectory::class)->companies(),");
edit('app/Http/Controllers/VatInputController.php', "        try {\n            \$file = \$request->file('excel_file');", <<<'CODE'
        $purchaseCompany = $request->input('record_type') === 'purchase'
            ? app(\App\Services\BIR\PurchaseCompanyDirectory::class)->resolve(
                $request->input('filing_company_tin'), $request->input('filing_company_branch_code')
            ) : null;
        try {
            $file = $request->file('excel_file');
CODE);
edit('app/Http/Controllers/VatInputController.php', "                    \$birIssues,\n                    \$retainedContents", "                    \$birIssues,\n                    \$retainedContents,\n                    \$purchaseCompany");
edit('app/Http/Controllers/VatInputController.php', '$this->purchaseUploads->replace($file, $reportingPeriod)', '$this->purchaseUploads->replace($file, $reportingPeriod, $purchaseCompany)');
edit('app/Http/Controllers/VatInputController.php', "'Purchase VAT report for '.Carbon::parse(\$reportingPeriod)", "'Purchase VAT report for '.\$purchaseCompany['name'].' ('.\$purchaseCompany['tin'].'-'.\$purchaseCompany['branch_code'].') / '.Carbon::parse(\$reportingPeriod)");
edit('app/Http/Controllers/VatInputController.php', "->whereKeyNot(\$vatInput->getKey())", "->forFilingCompany(\$vatInput->filingCompanyIdentity())\n            ->whereKeyNot(\$vatInput->getKey())");
edit('app/Http/Controllers/VatInputController.php', '                    ...$adjustedPayload,', <<<'CODE'
                    ...$adjustedPayload,
                    'filing_company_tin' => $sourceRecord->filing_company_tin,
                    'filing_company_branch_code' => $sourceRecord->filing_company_branch_code,
CODE);
edit('app/Http/Controllers/VatInputController.php', "->whereKeyNot(\$target->id)", "->forFilingCompany(\$target->filingCompanyIdentity())\n            ->whereKeyNot(\$target->id)");

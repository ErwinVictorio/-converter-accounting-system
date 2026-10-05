<?php
function edit($file, $from, $to) {
    $s=str_replace("\r\n","\n",file_get_contents($file));
    if(!str_contains($s,$from)) throw new RuntimeException("Missing anchor: $file: $from");
    file_put_contents($file,str_replace($from,$to,$s));
}
$f='resources/js/Pages/Records/PurchaseRecords.jsx';
edit($f, '"Unassigned / legacy"', '"Unassigned / legacy - review company before adjustment"');
edit($f, 'onClick={() => router.get(`/records/${item.id}/edit`)}', 'onClick={() => router.get(`/records/${item.id}/edit`, { return_to: window.location.pathname + window.location.search })}');
edit($f, '                                                        onClick={() => openDeleteDialog(item)}', '                                                        disabled={!item.filing_company_tin}' . "\n" . '                                                        onClick={() => openDeleteDialog(item)}');
$f='app/Http/Controllers/VatInputController.php';
edit($f, 'public function edit(VatInput $vatInput)', 'public function edit(Request $request, VatInput $vatInput)');
edit($f, "'vatInput' => \$vatInput,", "'vatInput' => \$vatInput,\n            'returnTo' => \$this->purchaseReturnUrl(\$request),");
edit($f, 'return redirect("/records/{$vatInput->id}/edit")->with', 'return redirect("/records/{$vatInput->id}/edit".($request->filled(\'return_to\') ? \'?\'.http_build_query([\'return_to\' => $this->purchaseReturnUrl($request)]) : \'\'))->with');
edit($f, '    private function withholdingAgentFromRequest(Request $request): array', <<<'CODE'
    private function purchaseReturnUrl(Request $request): string
    {
        $url = (string) $request->input('return_to', '/records/purchases');
        return preg_match('~^/records/purchases(?:\?[^\r\n]*)?$~D', $url) ? $url : '/records/purchases';
    }

    private function withholdingAgentFromRequest(Request $request): array
CODE);
$f='resources/js/Pages/EditVatInputRecord.jsx';
edit($f, 'const { flash, vatInput }', 'const { flash, vatInput, returnTo = "/records/purchases" }');
edit($f, '    supplier_name: "",', '    return_to: returnTo,' . "\n" . '    supplier_name: "",');
edit($f, '<Link href="/records/purchases">', '<Link href={returnTo}>');
$f='tests/Feature/RecordPagesTest.php';
edit($f, 'return VatInput::create(array_merge([', "return VatInput::create(array_merge([\n            'filing_company_tin' => '008791976', 'filing_company_branch_code' => '0000',");
$f='tests/Feature/UploadWorkbookTypePreflightTest.php';
edit($f, "Storage::fake('local');", "Storage::set('local', Storage::fake('upload-preflight-'.\\Illuminate\\Support\\Str::uuid()));");

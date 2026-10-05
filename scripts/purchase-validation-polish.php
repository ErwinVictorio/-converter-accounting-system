<?php
function edit($f,$a,$b){$s=str_replace("\r\n","\n",file_get_contents($f));if(!str_contains($s,$a))throw new RuntimeException($f);file_put_contents($f,str_replace($a,$b,$s));}
foreach(['app/Http/Controllers/VatInputController.php','app/Http/Controllers/DatFileController.php'] as $f){
edit($f, "            'withholding_agent_tin' => ['nullable',", "            'filing_company_tin' => ['nullable', 'string', 'regex:/^(\\d{9}|\\d{3}-\\d{3}-\\d{3})$/'],\n            'filing_company_branch_code' => ['nullable', 'string', 'regex:/^\\d{1,4}$/'],\n            'withholding_agent_tin' => ['nullable',");
}
$f='app/Http/Controllers/DatFileController.php';
edit($f,'DatAttachmentPdfRenderer $pdfRenderer, array $company',"DatAttachmentPdfRenderer \$pdfRenderer,\n        array \$company");
edit($f,"        }\n\n\n\n        \$content = \$generator->generate(","        }\n\n        \$content = \$generator->generate(");
$f='app/Http/Controllers/VatInputController.php';
$s=file_get_contents($f);$a=strpos($s,'    private function purchaseReturnUrl(');$b=strpos($s,'    private function withholdingAgentFromRequest(', $a);$helper=substr($s,$a,$b-$a);$s=substr_replace($s,'',$a,$b-$a);$a=strpos($s,'    public function edit(');$s=substr_replace($s,$helper,$a,0);file_put_contents($f,$s);
$f='app/Services/PendingPurchaseUploadService.php';edit($f,'?string $retainedContents = null, array $company = []',"?string \$retainedContents = null,\n        array \$company = []");

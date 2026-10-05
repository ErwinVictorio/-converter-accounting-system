<?php
$f='tests/Feature/PurchaseCompanySelectionTest.php';$s=file_get_contents($f);
$s=str_replace("'purchase_local'=>1000,", "'purchase_local'=>1000, 'services'=>0,",$s);
$s=str_replace("'SUPPLIER A','STREET','MANILA',0", "'SUPPLIER A','','',0",$s);
$s=str_replace("\$this->put('/vat-inputs/'.\$legacy->id,[])->assertStatus(404); // Actual adjustment route is covered below via its named route.", "\$this->put('/records/'.\$legacy->id,[])->assertSessionHasErrors('filing_company_tin');",$s);
file_put_contents($f,$s);
$files=['PendingPurchaseUploadTest','UploadWorkbookTypePreflightTest','PurchaseAdjustmentMergeTest','PurchaseAdjustedDeleteTest','DatFileAlphabeticalOrderingTest','DatTextValidationRelaxationTest','ImportationUploadTest','ImportationEntryTest','ImportationDatFileTest','ExpandedWtaxDatFileTest'];
foreach($files as $name){
 $f="tests/Feature/$name.php";$s=str_replace("\r\n","\n",file_get_contents($f));
 $s=str_replace("'record_type' => 'purchase',", "'record_type' => 'purchase',\n            'filing_company_tin' => '008791976',\n            'filing_company_branch_code' => '0000',",$s);
 $s=preg_replace('/(?<!Sales)VatInput::create\((array_merge\()?\[/', "$0\n            'filing_company_tin' => '008791976',\n            'filing_company_branch_code' => '0000',",$s);
 $s=str_replace('/download-datfile?', '/download-datfile?filing_company_tin=008791976&filing_company_branch_code=0000&',$s);
 if($name==='DatTextValidationRelaxationTest') $s=str_replace('/generate-datfile?', '/generate-datfile?filing_company_tin=008791976&filing_company_branch_code=0000&',$s);
 file_put_contents($f,$s);
}

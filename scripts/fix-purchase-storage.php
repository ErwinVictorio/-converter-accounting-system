<?php
foreach(['PurchaseCompanySelectionTest','PendingPurchaseUploadTest'] as $name){
$f="tests/Feature/$name.php";$s=file_get_contents($f);$s=str_replace("Storage::fake('local');", "Storage::set('local', Storage::fake('purchase-'.\\Illuminate\\Support\\Str::uuid()));",$s);file_put_contents($f,$s);
}

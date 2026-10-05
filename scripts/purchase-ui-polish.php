<?php
function edit($file, $from, $to) {
    $s=str_replace("\r\n","\n",file_get_contents($file));
    if(!str_contains($s,$from)) throw new RuntimeException("Missing anchor: $file");
    file_put_contents($file,str_replace($from,$to,$s));
}
edit('resources/js/Pages/RecordEntry.jsx', '                  The original workbook is retained privately until', '                  {uploadIssueDialog.record_type === "purchase" && <>Company: {uploadIssueDialog.pending_upload.filing_company_label}. </>}The original workbook is retained privately until');
edit('resources/js/Pages/Dashboard.jsx', 'BIR Data &amp; DAT File Automation Overview', 'BIR Data &amp; DAT File Automation Overview (all companies, including unassigned historical records)');
edit('resources/js/Pages/Records/PurchaseRecords.jsx', 'disabled={!isBroker}', 'disabled={!isBroker || !item.filing_company_tin}');
edit('resources/js/Pages/Records/PurchaseRecords.jsx', '                                                {isAdjusted && (\n', '                                                {isAdjusted && (\n');
edit('resources/js/Pages/Records/PurchaseRecords.jsx', '"Unassigned / legacy"', '"Unassigned / legacy - company review required before adjustment"');
// Preserve listing company/month/search via a validated local return URL.
edit('resources/js/Pages/Records/PurchaseRecords.jsx', 'window.location.href = `/records/${item.id}/edit`', 'window.location.href = `/records/${item.id}/edit?return_to=${encodeURIComponent(window.location.pathname + window.location.search)}`');

<?php
function edit($file, $from, $to) {
    $s = str_replace("\r\n", "\n", file_get_contents($file));
    if (!str_contains($s, $from)) throw new RuntimeException("Missing anchor in $file: $from");
    file_put_contents($file, str_replace($from, $to, $s));
}
$f='resources/js/Pages/RecordEntry.jsx';
edit($f, 'import ExcelUploadPanel', 'import PurchaseCompanySelect from "@/Components/PurchaseCompanySelect";' . "\nimport ExcelUploadPanel");
edit($f, 'const { flash, birCompanies = [] }', 'const { flash, birCompanies = [], purchaseCompanies = [] }');
edit($f, '    excel_file: null,', "    excel_file: null,\n    filing_company_tin: \"\",\n    filing_company_branch_code: \"\",");
edit($f, '            <ExcelUploadPanel', <<<'CODE'
            {data.record_type === "purchase" && (
              <div className="mb-5 space-y-2">
                <PurchaseCompanySelect companies={purchaseCompanies} tin={data.filing_company_tin} branch={data.filing_company_branch_code}
                  disabled={processing} error={errors.filing_company_tin || errors.filing_company_branch_code}
                  onChange={(value) => {
                    const [tin = "", branch = ""] = value.split("|");
                    setData((current) => ({ ...current, filing_company_tin: tin, filing_company_branch_code: branch }));
                    clearErrors("filing_company_tin", "filing_company_branch_code");
                  }} />
                <p className="text-xs text-slate-500">Uploading replaces normal Purchase records only for the selected company, branch and reporting month.</p>
              </div>
            )}
            <ExcelUploadPanel
CODE);
// Client checks supplement, never replace, server validation.
edit($f, '    e.preventDefault();', <<<'CODE'
    e.preventDefault();
    if (data.record_type === "purchase" && !data.filing_company_tin) {
      toast.error("Select a Purchase filing company before uploading.");
      return;
    }
CODE);
$f='resources/js/Pages/GenerateDatFile.jsx';
edit($f, 'useEffect, useMemo', 'useEffect, useMemo, useState');
edit($f, 'import MainLayout', 'import PurchaseCompanySelect from "@/Components/PurchaseCompanySelect";' . "\nimport MainLayout");
edit($f, '        flash,', "        flash,\n        purchaseCompanies = [],\n        selectedPurchaseCompany = null,\n        unassignedPurchaseCount = 0,");
edit($f, '    const currentMonth =', "    const [refreshingCompany, setRefreshingCompany] = useState(false);\n    const currentMonth =");
edit($f, '        period: defaultPeriod,', "        period: defaultPeriod,\n        filing_company_tin: selectedPurchaseCompany?.tin || \"\",\n        filing_company_branch_code: selectedPurchaseCompany?.branch_code || \"\",");
edit($f, 'const downloadDisabled = processing ||', 'const downloadDisabled = processing || refreshingCompany || (data.record_type === "purchase" && (!selectedPeriod || !data.filing_company_tin || data.filing_company_tin !== selectedPurchaseCompany?.tin || data.filing_company_branch_code !== selectedPurchaseCompany?.branch_code)) ||');
edit($f, '        setData("record_type", recordType);', "        setData(\"filing_company_tin\", selectedPurchaseCompany?.tin || \"\");\n        setData(\"filing_company_branch_code\", selectedPurchaseCompany?.branch_code || \"\");\n        setData(\"record_type\", recordType);");
edit($f, 'availablePeriods, selectedWithholdingAgent]);', 'availablePeriods, selectedWithholdingAgent, selectedPurchaseCompany]);');
edit($f, '        setData("record_type", value);', "        setRefreshingCompany(true);\n        setData(\"record_type\", value);");
// Every type/company reload must release the loading guard.
edit($f, '                preserveScroll: true,', "                onFinish: () => setRefreshingCompany(false),\n                preserveScroll: true,");
edit($f, '    const handleDownload = (e) => {', <<<'CODE'
    const handlePurchaseCompanyChange = (value) => {
        const [tin = "", branch = ""] = value.split("|");
        setRefreshingCompany(true);
        setData((current) => ({ ...current, filing_company_tin: tin, filing_company_branch_code: branch, period: "" }));
        router.get("/generate-datfile", { record_type: "purchase", filing_company_tin: tin, filing_company_branch_code: branch }, {
            preserveScroll: true, replace: true, onFinish: () => setRefreshingCompany(false),
        });
    };

    const handleDownload = (e) => {
CODE);
edit($f, '        e.preventDefault();', "        e.preventDefault();\n        if (downloadDisabled) return;");
edit($f, '        window.location.href =', <<<'CODE'
        if (data.record_type === "purchase") {
            params.set("filing_company_tin", data.filing_company_tin);
            params.set("filing_company_branch_code", data.filing_company_branch_code);
        }
        window.location.href =
CODE);
// Insert Purchase selector before the reporting-month area, without touching Expanded's controls.
edit($f, '                    {data.record_type === "expanded" && (', <<<'CODE'
                    {data.record_type === "purchase" && (
                        <div className="space-y-3">
                            <PurchaseCompanySelect companies={purchaseCompanies} tin={data.filing_company_tin} branch={data.filing_company_branch_code}
                                disabled={refreshingCompany} onChange={handlePurchaseCompanyChange} error={errors.filing_company_tin || errors.filing_company_branch_code} />
                            {unassignedPurchaseCount > 0 && <p role="status" className="rounded-md bg-amber-50 p-3 text-sm text-amber-800">{unassignedPurchaseCount} historical Purchase records have no assigned company and are excluded from company DAT downloads. Review them in Purchase Records; no ownership has been assumed.</p>}
                            {!refreshingCompany && selectedPurchaseCompany && availablePeriods.length === 0 && <p className="text-sm text-slate-500">No Purchase records for this company and branch.</p>}
                        </div>
                    )}
                    {data.record_type === "expanded" && (
CODE);
$f='resources/js/Pages/ManageSupplier.jsx';
edit($f, 'Reporting month {supplierFixContext.pending_upload.reporting_period}', 'Company {supplierFixContext.pending_upload.filing_company_label} / Reporting month {supplierFixContext.pending_upload.reporting_period}');

import React, { useEffect, useState, useRef } from "react";
import { useForm, usePage, router } from "@inertiajs/react";
import { toast } from "sonner";
import { 
  UploadCloud, 
  FileSpreadsheet, 
  X, 
  Loader2, 
  Search,
  Pencil
} from "lucide-react";

import MainLayout from "@/Layouts/MainLayout";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { Badge } from "@/Components/ui/badge";
import {
  Card,
  CardContent,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/Components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/Components/ui/dialog";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/Components/ui/table";
import DataTablePagination from "@/Layouts/Pagination";

/**
 * The upload type is always chosen explicitly -- it is never inferred from the
 * workbook's shape, since the three layouts overlap enough to guess wrong.
 */
const RECORD_TYPES = {
  purchase: {
    label: "Purchase",
    title: "VAT Input Records",
    searchPlaceholder: "Search supplier or TIN...",
  },
  sales: {
    label: "Sales",
    title: "Sales VAT Records",
    searchPlaceholder: "Search customer, TIN, or document...",
  },
  expanded: {
    label: "Expanded WTAX",
    title: "Expanded Withholding Tax Records",
    searchPlaceholder: "Search payee, TIN, or ATC...",
  },
};

function RecordEntry() {
  const { flash, vatInputs, salesVatInputs, expandedWtaxEntries, birCompanies = [], filters } = usePage().props;
  const defaultBirCompany = birCompanies[0] || { tin: "008791976", branch_code: "0000", name: "FORTRESS STEEL INC." };
  const [isDragging, setIsDragging] = useState(false);
  const [searchTerm, setSearchTerm] = useState(filters?.search || "");
  const [selectedBirRecord, setSelectedBirRecord] = useState(null);
  const fileInputRef = useRef(null);

  // Inertia Form Setup para sa File Upload
  const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
    excel_file: null,
    reporting_month: new Date().toISOString().slice(0, 7),
    record_type: "purchase",
    withholding_agent_tin: defaultBirCompany.tin,
    withholding_agent_branch_code: defaultBirCompany.branch_code || "0000",
  });
  const isSalesMode = data.record_type === "sales";
  const isExpandedMode = data.record_type === "expanded";
  const recordType = RECORD_TYPES[data.record_type] || RECORD_TYPES.purchase;
  const selectedBirCompanyKey = `${data.withholding_agent_tin}|${data.withholding_agent_branch_code}`;
  /*
   * The list comes from Master Data > Companies (with config and
   * already-uploaded agents as fallbacks). The TIN and branch inputs below stay
   * editable, so the typed pair can end up matching no option -- without an
   * explicit entry for that the select would silently display the first company
   * while a different TIN was about to be uploaded.
   */
  const isKnownBirCompany = birCompanies.some(
    (company) => `${company.tin}|${company.branch_code}` === selectedBirCompanyKey
  );

  const {
    data: birData,
    setData: setBirData,
    put: putBirInfo,
    processing: birProcessing,
    errors: birErrors,
    reset: resetBirData,
    clearErrors: clearBirErrors,
  } = useForm({
    vendor_type: "company",
    tin_number: "",
    company_name: "",
    last_name: "",
    first_name: "",
    middle_name: "",
    address1: "",
    address2: "",
  });

  useEffect(() => {
    if (flash?.success) {
      toast.success(flash.success);
    }
    if (flash?.error) {
      toast.error(flash.error);
    }
  }, [flash]);

  // Handle Search Input with Debounce
  useEffect(() => {
    const timer = setTimeout(() => {
      if (searchTerm !== (filters?.search || "")) {
        router.get(
          "/records", 
          { search: searchTerm },
          { preserveState: true, replace: true }
        );
      }
    }, 300);

    return () => clearTimeout(timer);
  }, [searchTerm]);

  // Format monetary values
  const formatCurrency = (val) => {
    return new Intl.NumberFormat("en-US", {
      style: "decimal",
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(val || 0);
  };

  // reporting_period arrives as a month-end date; only the month is meaningful.
  const formatMonth = (value) => {
    if (!value) return "—";

    const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);

    return Number.isNaN(date.getTime())
      ? value
      : date.toLocaleDateString("en-US", { month: "short", year: "numeric" });
  };

  // Handle File Selection
  const handleFileChange = (file) => {
    if (!file) return;

    const validTypes = [
      "application/vnd.ms-excel",
      "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      "application/vnd.ms-excel.sheet.macroEnabled.12",
    ];
    const isExcelExtension = /\.(xlsx|xls|xlsm)$/i.test(file.name);

    if (!validTypes.includes(file.type) && !isExcelExtension) {
      toast.error("Please upload a valid Excel file (.xls, .xlsx, .xlsm)");
      return;
    }

    if (file.size > 500 * 1024 * 1024) {
      toast.error("File size exceeds the 500MB limit.");
      return;
    }

    clearErrors("excel_file");
    setData("excel_file", file);
  };

  // Drag and Drop Handlers
  const handleDragOver = (e) => {
    e.preventDefault();
    setIsDragging(true);
  };

  const handleDragLeave = (e) => {
    e.preventDefault();
    setIsDragging(false);
  };

  const handleDrop = (e) => {
    e.preventDefault();
    setIsDragging(false);
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      handleFileChange(e.dataTransfer.files[0]);
    }
  };

  // Clear File
  const handleRemoveFile = () => {
    setData("excel_file", null);
    if (fileInputRef.current) {
      fileInputRef.current.value = "";
    }
  };

  const formatTinInput = (value) => {
    const digits = String(value || "").replace(/\D/g, "").slice(0, 12);
    const parts = [digits.slice(0, 3), digits.slice(3, 6), digits.slice(6, 9), digits.slice(9, 12)].filter(Boolean);

    return parts.join("-");
  };

  const openBirEditor = (record) => {
    setSelectedBirRecord(record);
    clearBirErrors();
    setBirData({
      vendor_type: record.vendor_type || "company",
      tin_number: formatTinInput(record.tin_number || ""),
      company_name: record.company_name || record.supplier_name || "",
      last_name: record.last_name || "",
      first_name: record.first_name || "",
      middle_name: record.middle_name || "",
      address1: record.address1 || "",
      address2: record.address2 || "",
    });
  };

  const closeBirEditor = () => {
    setSelectedBirRecord(null);
    resetBirData();
    clearBirErrors();
  };

  const handleBirSubmit = (e) => {
    e.preventDefault();

    if (!selectedBirRecord) return;

    putBirInfo(`/records/${selectedBirRecord.id}/bir-info`, {
      preserveScroll: true,
      onSuccess: closeBirEditor,
    });
  };

  const handleWithholdingAgentChange = (value) => {
    const [tin, branchCode] = value.split("|");
    setData((current) => ({
      ...current,
      withholding_agent_tin: tin,
      withholding_agent_branch_code: branchCode || "0000",
    }));
  };

  // Submit Form to Backend
  const handleSubmit = (e) => {
    e.preventDefault();

    if (!data.excel_file) {
      toast.error("Please select an Excel file to upload.");
      return;
    }

    if (!data.reporting_month) {
      toast.error("Please select the reporting month.");
      return;
    }

    if (!data.record_type) {
      toast.error("Please select the file type.");
      return;
    }

    post("/vat-import", {
      forceFormData: true,
      onSuccess: () => {
        reset("excel_file");
        if (fileInputRef.current) fileInputRef.current.value = "";
      },
      onError: (err) => {
        console.error("Upload error:", err);
      },
    });
  };

  return (
    // Inayos ang container padding at max-width upang maiwasan ang pumuputol na screen
    <section className="space-y-6 w-full max-w-full overflow-hidden">
      {/* Excel Upload Card */}
      <Card className="w-full shadow-sm border rounded-xl overflow-hidden bg-white">
        <CardHeader className="p-4 sm:p-6 border-b bg-gray-50/50">
          <CardTitle className="text-lg sm:text-xl font-semibold text-gray-900">
            Upload Excel File
          </CardTitle>
        </CardHeader>

        <CardContent className="p-4 sm:p-6">
          <form id="record-upload-form" onSubmit={handleSubmit}>
            <div className="mb-5 grid max-w-xl grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <label className="text-sm font-medium text-slate-700">
                  Reporting Month <span className="text-red-500">*</span>
                </label>
                <Input
                  type="month"
                  value={data.reporting_month}
                  onChange={(e) => setData("reporting_month", e.target.value)}
                  className={errors.reporting_month ? "border-red-500 focus-visible:ring-red-500" : ""}
                />
                {errors.reporting_month && (
                  <p className="text-xs text-red-500 font-medium">{errors.reporting_month}</p>
                )}
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium text-slate-700">
                  File Type <span className="text-red-500">*</span>
                </label>
                <select
                  value={data.record_type}
                  onChange={(e) => setData("record_type", e.target.value)}
                  className={`flex h-10 w-full rounded-md border bg-white px-3 py-2 text-sm text-slate-900 shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-[3px] ${
                    errors.record_type
                      ? "border-red-500 focus-visible:ring-red-500/20"
                      : "border-slate-300 focus-visible:border-blue-500 focus-visible:ring-blue-500/20"
                  }`}
                >
                  <option value="purchase">Purchase</option>
                  <option value="sales">Sales</option>
                  <option value="expanded">Expanded WTAX</option>
                </select>
                {errors.record_type && (
                  <p className="text-xs text-red-500 font-medium">{errors.record_type}</p>
                )}
                {false && isExpandedMode && (
                  <p className="text-xs text-slate-500">
                    Upload the BIR 1601EQ Schedule 1 workbook — layout below.
                  </p>
                )}
              </div>
            </div>

            {isExpandedMode && (
              <div className="mb-5 grid max-w-3xl grid-cols-1 gap-4 sm:grid-cols-3">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">
                    Known Company
                  </label>
                  <select
                    value={selectedBirCompanyKey}
                    onChange={(e) => handleWithholdingAgentChange(e.target.value)}
                    className={`flex h-10 w-full rounded-md border bg-white px-3 py-2 text-sm text-slate-900 shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-[3px] ${
                      errors.withholding_agent_tin
                        ? "border-red-500 focus-visible:ring-red-500/20"
                        : "border-slate-300 focus-visible:border-blue-500 focus-visible:ring-blue-500/20"
                    }`}
                  >
                    {birCompanies.map((company) => (
                      <option
                        key={`${company.tin}|${company.branch_code}`}
                        value={`${company.tin}|${company.branch_code}`}
                      >
                        {company.name} ({company.tin}-{company.branch_code})
                      </option>
                    ))}
                    {!isKnownBirCompany && (
                      <option value={selectedBirCompanyKey}>
                        {birCompanies.length === 0
                          ? "No companies yet -- add one in Master Data > Companies"
                          : `Not listed (${data.withholding_agent_tin}-${data.withholding_agent_branch_code})`}
                      </option>
                    )}
                  </select>
                  <p className="text-xs text-slate-400">
                    Maintained in Master Data &gt; Companies.
                  </p>
                </div>

                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">
                    Company TIN <span className="text-red-500">*</span>
                  </label>
                  <Input
                    value={data.withholding_agent_tin}
                    onChange={(e) => setData("withholding_agent_tin", e.target.value.replace(/\D/g, "").slice(0, 9))}
                    className={errors.withholding_agent_tin ? "border-red-500 focus-visible:ring-red-500" : ""}
                  />
                  {errors.withholding_agent_tin && (
                    <p className="text-xs text-red-500 font-medium">{errors.withholding_agent_tin}</p>
                  )}
                </div>

                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">
                    Branch Code <span className="text-red-500">*</span>
                  </label>
                  <Input
                    value={data.withholding_agent_branch_code}
                    onChange={(e) => setData("withholding_agent_branch_code", e.target.value.replace(/\D/g, "").slice(0, 4))}
                    className={errors.withholding_agent_branch_code ? "border-red-500 focus-visible:ring-red-500" : ""}
                  />
                  {errors.withholding_agent_branch_code && (
                    <p className="text-xs text-red-500 font-medium">{errors.withholding_agent_branch_code}</p>
                  )}
                </div>
              </div>
            )}

            {/*
              The upload stores what the workbook says and recomputes nothing, so
              whether a month files correctly comes down to whether the file is
              laid out right. Spelled out on the upload screen rather than in a doc
              nobody opens mid-upload.
            */}
            {isExpandedMode && (
              <div className="mb-5 rounded-lg border border-slate-200 bg-slate-50/70 p-4 text-xs leading-relaxed text-slate-600">
                <p className="text-sm font-semibold text-slate-800">
                  Expanded WTAX accepted file layouts
                </p>
                <ul className="mt-2 list-disc space-y-1.5 pl-4">
                  <li>
                    BIR 1601EQ Schedule 1: headings on{" "}
                    <span className="font-medium text-slate-800">row 1</span>, data on row 2, with{" "}
                    <span className="font-mono text-[11px] text-slate-800">
                      Reporting_Month, Vendor_TIN, branchCode, companyName, surName, firstName,
                      middleName, ATC, income_payment, ewt_rate, tax_amount
                    </span>.
                  </li>
                  <li>
                    System Expanded WTAX export: headings on{" "}
                    <span className="font-medium text-slate-800">row 3</span>, data on row 4, with{" "}
                    <span className="font-mono text-[11px] text-slate-800">
                      No, Date, Supplier Name, TIN, Reference, (1%), (2%), (5%), (10%), (15%), Total
                    </span>.
                  </li>
                  <li>
                    For BIR Schedule 1, ATC and computed amounts are read from the workbook exactly as filed.
                  </li>
                  <li>
                    For the system export, each non-zero rate column becomes one WTAX line; income payment
                    is computed from tax withheld and rate because that export has no income-payment column.
                  </li>
                  <li>
                    System export ATC codes come from the configured rate mapping: 1% WC158, 2% WC160,
                    5% WC100, and 10% WC139 by default.
                  </li>
                  <li>
                    The row Date or Reporting_Month must fall inside the selected month, and re-uploading
                    a month replaces that month's rows.
                  </li>
                  <li>
                    Rows sharing reporting month, TIN, ATC and rate are listed and filed as a single line,
                    with their income payment and tax amount added together.
                  </li>
                </ul>
              </div>
            )}

            {false && isExpandedMode && (
              <div className="mb-5 rounded-lg border border-slate-200 bg-slate-50/70 p-4 text-xs leading-relaxed text-slate-600">
                <p className="text-sm font-semibold text-slate-800">
                  Expanded WTAX file layout — BIR 1601EQ Schedule 1
                </p>
                <ul className="mt-2 list-disc space-y-1.5 pl-4">
                  <li>
                    Column headings sit on <span className="font-medium text-slate-800">row 1</span> and
                    data starts on row 2 — no title rows above the headings.
                  </li>
                  <li>
                    Eleven columns, in this order:{" "}
                    <span className="font-mono text-[11px] text-slate-800">
                      Reporting_Month, Vendor_TIN, branchCode, companyName, surName, firstName,
                      middleName, ATC, income_payment, ewt_rate, tax_amount
                    </span>
                  </li>
                  <li>
                    Name a payee on one side only:{" "}
                    <span className="font-medium text-slate-800">companyName</span> for a company, or{" "}
                    <span className="font-medium text-slate-800">surName / firstName / middleName</span>{" "}
                    for an individual — never both.
                  </li>
                  <li>
                    <span className="font-medium text-slate-800">income_payment</span> and{" "}
                    <span className="font-medium text-slate-800">tax_amount</span> are read and stored
                    exactly as the file states them. Neither is derived from the other, so the workbook's
                    own computed figures are what gets filed.
                  </li>
                  <li>
                    Amount columns follow the template's ReadMe:{" "}
                    <span className="font-medium text-slate-800">Number</span> format, not Comma or
                    Accounting, and no thousands separators.
                  </li>
                  <li>
                    Fill <span className="font-medium text-slate-800">ATC</span> on every row. A blank one
                    is imported but blocks the DAT, because the rate alone cannot choose between the
                    company and individual code at 5% or 10%.
                  </li>
                  <li>
                    Every <span className="font-medium text-slate-800">Reporting_Month</span> must fall
                    inside the month selected above, and re-uploading a month replaces that month's rows.
                  </li>
                  <li>
                    Rows sharing reporting month, TIN, ATC and rate are listed and filed as a single line,
                    with their income payment and tax amount added together.
                  </li>
                </ul>
              </div>
            )}

            <input
              type="file"
              ref={fileInputRef}
              accept=".xls,.xlsx,.xlsm"
              className="hidden"
              onChange={(e) => handleFileChange(e.target.files[0])}
            />

            {!data.excel_file ? (
              <div
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
                className={`border-2 border-dashed rounded-xl p-6 sm:p-10 flex flex-col items-center justify-center text-center transition-colors cursor-pointer ${
                  isDragging
                    ? "border-indigo-500 bg-indigo-50/50"
                    : "border-gray-200 hover:border-indigo-300 hover:bg-gray-50/50"
                }`}
                onClick={() => fileInputRef.current?.click()}
              >
                <div className="w-12 h-12 rounded-full bg-indigo-50 flex items-center justify-center mb-4 text-indigo-600">
                  <UploadCloud className="w-6 h-6" />
                </div>

                <h4 className="text-base sm:text-lg font-bold text-gray-800 mb-1">
                  Drag & drop to upload file
                </h4>
                <p className="text-xs sm:text-sm text-gray-400 font-medium mb-6">
                  (XLS, XLSX, XLSM up to 500MB)
                </p>

                <div className="w-full max-w-xs flex items-center gap-3 my-2 mb-6">
                  <div className="h-[1px] bg-gray-200 flex-1"></div>
                  <span className="text-xs text-gray-400 uppercase font-medium">or</span>
                  <div className="h-[1px] bg-gray-200 flex-1"></div>
                </div>

                <Button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation();
                    fileInputRef.current?.click();
                  }}
                  className="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-6 py-2.5 rounded-lg shadow-sm"
                >
                  Browse Files
                </Button>
              </div>
            ) : (
              <div className="border rounded-xl p-4 sm:p-6 bg-slate-50 flex items-center justify-between">
                <div className="flex items-center space-x-4 min-w-0">
                  <div className="p-3 bg-emerald-100 text-emerald-700 rounded-lg shrink-0">
                    <FileSpreadsheet className="w-8 h-8" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-gray-800 truncate">
                      {data.excel_file.name}
                    </p>
                    <p className="text-xs text-gray-500">
                      {(data.excel_file.size / (1024 * 1024)).toFixed(2)} MB - {recordType.label}
                    </p>
                  </div>
                </div>

                <button
                  type="button"
                  onClick={handleRemoveFile}
                  disabled={processing}
                  className="text-gray-400 hover:text-red-500 transition-colors p-1 shrink-0"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
            )}

            {errors.excel_file && (
              <p className="text-xs text-red-500 mt-2">{errors.excel_file}</p>
            )}
          </form>
        </CardContent>

        <CardFooter className="flex flex-col sm:flex-row justify-end gap-3 p-4 sm:p-6 border-t bg-gray-50/50">
          <Button
            type="button"
            variant="outline"
            onClick={handleRemoveFile}
            disabled={!data.excel_file || processing}
            className="w-full sm:w-auto px-5"
          >
            Clear File
          </Button>
          <Button
            type="submit"
            form="record-upload-form"
            disabled={!data.excel_file || processing}
            className="w-full sm:w-auto bg-slate-900 text-white hover:bg-slate-800 px-6 min-w-[120px]"
          >
            {processing ? (
              <>
                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                Uploading...
              </>
            ) : (
              "Submit File"
            )}
          </Button>
        </CardFooter>
      </Card>

      {/* Shadcn Data Table Section */}
      <Card className="w-full shadow-sm border rounded-xl overflow-hidden bg-white">
        <CardHeader className="p-4 sm:p-6 border-b flex flex-col md:flex-row items-start md:items-center justify-between gap-4 bg-gray-50/50">
          <CardTitle className="text-lg sm:text-xl font-semibold text-gray-900">
            {recordType.title}
          </CardTitle>

          {/* Search Input Filter */}
          <div className="relative w-full md:w-72">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
            <Input
              type="text"
              placeholder={recordType.searchPlaceholder}
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="pl-9 bg-white"
            />
          </div>
        </CardHeader>

        {/* 1. DAGDAG: overflow-x-auto dito para pwedeng ma-scroll ang table horizontally nang hindi nasisira ang buong card */}
        <CardContent className="p-0 overflow-x-auto">
          {isExpandedMode ? (
            <Table className="min-w-[1100px]">
              <TableHeader>
                <TableRow className="bg-slate-50 hover:bg-slate-50">
                  <TableHead className="font-semibold text-slate-700">Payee</TableHead>
                  <TableHead className="font-semibold text-slate-700">Agent TIN</TableHead>
                  <TableHead className="font-semibold text-slate-700">TIN</TableHead>
                  <TableHead className="font-semibold text-slate-700">Branch</TableHead>
                  <TableHead className="font-semibold text-slate-700">ATC</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Rate</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Income Payment</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Tax Withheld</TableHead>
                  <TableHead className="font-semibold text-slate-700">Reporting Month</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {expandedWtaxEntries?.data?.length > 0 ? (
                  expandedWtaxEntries.data.map((item) => (
                    <TableRow key={item.id} className="hover:bg-slate-50/60 transition-colors">
                      <TableCell className="font-medium text-slate-900 whitespace-nowrap">
                        <div className="flex items-center gap-2">
                          <span>{item.payee_name}</span>
                          <Badge className="bg-slate-100 text-slate-700 hover:bg-slate-100">
                            {item.payee_type === "individual" ? "Individual" : "Company"}
                          </Badge>
                          {/*
                            Consolidated line: this row is more than one worksheet row added
                            together. Without the badge the list would just show fewer rows
                            than were uploaded, which reads as missing data.
                          */}
                          {item.merged_rows > 1 && (
                            <Badge
                              className="border-sky-200 bg-sky-50 text-sky-700 hover:bg-sky-50"
                              title={`${item.merged_rows} uploaded rows share this reporting month, payee, ATC and rate, so they file as one line.`}
                            >
                              {item.merged_rows} rows merged
                            </Badge>
                          )}
                          {/*
                            The merged rows named one payee but disagreed about the TIN. A
                            detail line carries only one, so the group keeps the first
                            filable one and says so here -- the alternative is filing the
                            same payee twice, which the BIR schedule does not want.
                          */}
                          {item.has_multiple_payee_tins && (
                            <Badge
                              className="border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-50"
                              title={`Rows share the same payee name and rate but have different TINs (${(item.distinct_payee_tins || []).join(", ")}). The DAT uses the first valid TIN in the group.`}
                            >
                              Multiple TINs
                            </Badge>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="text-slate-600 font-mono text-xs whitespace-nowrap">
                        {item.withholding_agent_tin}-{item.withholding_agent_branch_code || "0000"}
                      </TableCell>
                      <TableCell className="text-slate-600 font-mono text-xs whitespace-nowrap">
                        {item.payee_tin || "No TIN"}
                      </TableCell>
                      <TableCell className="text-slate-600 font-mono text-xs whitespace-nowrap">
                        {item.payee_branch_code || "0000"}
                      </TableCell>
                      <TableCell className="whitespace-nowrap">
                        {item.atc_code ? (
                          <Badge className="bg-slate-100 font-mono text-slate-700 hover:bg-slate-100">
                            {item.atc_code}
                          </Badge>
                        ) : (
                          // The workbook's ATC column was blank. Blocks DAT generation until
                          // it is filled and the month re-uploaded -- the rate alone cannot
                          // choose between the company and individual code at 5% or 10%.
                          <Badge className="border-amber-200 bg-amber-100 text-amber-800 hover:bg-amber-100">
                            No ATC
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                        {Number(item.tax_rate || 0).toFixed(2)}%
                      </TableCell>
                      <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                        {formatCurrency(item.income_payment)}
                      </TableCell>
                      <TableCell className="text-right font-mono text-xs font-bold text-slate-900 whitespace-nowrap">
                        {formatCurrency(item.tax_withheld)}
                      </TableCell>
                      <TableCell className="text-slate-600 text-xs whitespace-nowrap">
                        {formatMonth(item.reporting_period)}
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={9} className="h-32 text-center text-slate-500">
                      No expanded withholding tax records found.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          ) : isSalesMode ? (
            <Table className="min-w-[1100px]">
              <TableHeader>
                <TableRow className="bg-slate-50 hover:bg-slate-50">
                  <TableHead className="font-semibold text-slate-700">Customer Name</TableHead>
                  <TableHead className="font-semibold text-slate-700">TIN Number</TableHead>
                  <TableHead className="font-semibold text-slate-700">Type</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Exempt</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Zero Rated</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Taxable Net of VAT</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Output VAT</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Total Sales</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Gross Taxable</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {salesVatInputs?.data?.length > 0 ? (
                  salesVatInputs.data.map((item) => (
                    <TableRow key={item.id} className="hover:bg-slate-50/60 transition-colors">
                      <TableCell className="font-medium text-slate-900 whitespace-nowrap">
                        {item.customer_name}
                      </TableCell>
                      <TableCell className="text-slate-600 font-mono text-xs whitespace-nowrap">
                        {item.customer_tin || "No TIN"}
                      </TableCell>
                      <TableCell>
                        <Badge className="bg-slate-100 text-slate-700 hover:bg-slate-100">
                          {item.customer_type === "individual" ? "Individual" : "Company"}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                        {formatCurrency(item.exempt_sales)}
                      </TableCell>
                      <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                        {formatCurrency(item.zero_rated_sales)}
                      </TableCell>
                      <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                        {formatCurrency(item.taxable_net_of_vat)}
                      </TableCell>
                      <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                        {formatCurrency(item.output_vat)}
                      </TableCell>
                      <TableCell className="text-right font-mono text-xs font-bold text-slate-900 whitespace-nowrap">
                        {formatCurrency(item.net_amount)}
                      </TableCell>
                      <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                        {formatCurrency(item.gross_amount)}
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={9} className="h-32 text-center text-slate-500">
                      No sales records found.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          ) : (
          <Table className="min-w-[800px]">
            <TableHeader>
              <TableRow className="bg-slate-50 hover:bg-slate-50">
                <TableHead className="font-semibold text-slate-700">Supplier Name</TableHead>
                <TableHead className="font-semibold text-slate-700">TIN Number</TableHead>
                <TableHead className="font-semibold text-slate-700">Imported</TableHead>
                <TableHead className="font-semibold text-slate-700 text-right">Purchase Imported</TableHead>
                <TableHead className="font-semibold text-slate-700 text-right">Purchase Local</TableHead>
                <TableHead className="font-semibold text-slate-700 text-right">Services</TableHead>
                <TableHead className="font-semibold text-slate-700 text-right">Others</TableHead>
                <TableHead className="font-semibold text-slate-700 text-right">Total</TableHead>
                <TableHead className="font-semibold text-slate-700 text-right">Action</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {vatInputs?.data?.length > 0 ? (
                vatInputs.data.map((item) => {
                  const isBroker = Number(item.is_broker) === 1;
                  const isImported = Number(item.is_imported) === 1;
                  const isAdjusted = Number(item.is_adjusted) === 1;
                  const hasBirTin = /^(\d{9}|\d{12}|\d{3}-\d{3}-\d{3}|\d{3}-\d{3}-\d{3}-\d{3})$/.test(String(item.tin_number || ""));
                  const hasBirName =
                    item.vendor_type === "individual"
                      ? Boolean(item.last_name && item.first_name && item.middle_name)
                      : Boolean(item.company_name || item.supplier_name);

                  return (
                  <TableRow key={item.id} className="hover:bg-slate-50/60 transition-colors">
                    <TableCell className="font-medium text-slate-900 whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <span>{item.supplier_name}</span>
                        {isAdjusted && (
                          <Badge className="bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-50">
                            Adjusted
                          </Badge>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="text-slate-600 font-mono text-xs whitespace-nowrap">
                      {item.tin_number || "—"}
                    </TableCell>
                    <TableCell>
                      <Badge
                        variant={isImported ? "default" : "secondary"}
                        className={
                          isImported
                            ? "bg-amber-100 text-amber-800 hover:bg-amber-100 border-amber-200"
                            : "bg-slate-100 text-slate-700 hover:bg-slate-100"
                        }
                      >
                        {isImported ? "Yes" : "No"}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                      {formatCurrency(item.purchase_imported)}
                    </TableCell>
                    <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                      {formatCurrency(item.purchase_local)}
                    </TableCell>
                    <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                      {formatCurrency(item.services)}
                    </TableCell>
                    <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                      {formatCurrency(item.others)}
                    </TableCell>
                    <TableCell className="text-right font-mono text-xs font-bold text-slate-900 whitespace-nowrap">
                      {formatCurrency(item.total)}
                    </TableCell>
                    <TableCell className="text-right whitespace-nowrap">
                      <div className="flex justify-end gap-2">
                        <Button
                          type="button"
                          variant={hasBirTin && hasBirName ? "outline" : "default"}
                          size="sm"
                          onClick={() => openBirEditor(item)}
                          className="h-8 gap-1.5"
                        >
                          <Pencil className="h-3.5 w-3.5" />
                          BIR Info
                        </Button>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          disabled={!isBroker}
                          onClick={() => router.get(`/records/${item.id}/edit`)}
                          title={
                            isBroker
                              ? "Edit VAT record"
                              : "Only broker records can be edited"
                          }
                          className="h-8 gap-1.5"
                        >
                          <Pencil className="h-3.5 w-3.5" />
                          Adjust
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                  );
                })
              ) : (
                <TableRow>
                  <TableCell colSpan={9} className="h-32 text-center text-slate-500">
                    No records found.
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
          )}
        </CardContent>

        <DataTablePagination
          links={
            isExpandedMode
              ? expandedWtaxEntries?.links
              : isSalesMode
                ? salesVatInputs?.links
                : vatInputs?.links
          }
        />
      </Card>

      <Dialog open={Boolean(selectedBirRecord)} onOpenChange={(open) => !open && closeBirEditor()}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader className="border-b border-slate-100 pb-4 pr-8">
            <DialogTitle className="text-base font-semibold text-slate-900">
              BIR Vendor Information
            </DialogTitle>
          </DialogHeader>

          <form id="bir-info-form" onSubmit={handleBirSubmit} className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <label className="text-sm font-medium text-slate-700">Vendor Type</label>
              <select
                value={birData.vendor_type}
                onChange={(e) => setBirData("vendor_type", e.target.value)}
                className="flex h-10 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-xs transition-colors focus-visible:border-blue-500 focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-blue-500/20"
              >
                <option value="company">Company</option>
                <option value="individual">Individual</option>
              </select>
              {birErrors.vendor_type && (
                <p className="text-xs text-red-500 font-medium">{birErrors.vendor_type}</p>
              )}
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium text-slate-700">
                TIN Number <span className="text-red-500">*</span>
              </label>
              <Input
                value={birData.tin_number}
                onChange={(e) => setBirData("tin_number", formatTinInput(e.target.value))}
                placeholder="000-000-000-000"
                className={`h-10 bg-white ${birErrors.tin_number ? "border-red-500 focus-visible:ring-red-500" : ""}`}
              />
              {birErrors.tin_number && (
                <p className="text-xs text-red-500 font-medium">{birErrors.tin_number}</p>
              )}
            </div>

            {birData.vendor_type === "company" ? (
              <div className="space-y-2 md:col-span-2">
                <label className="text-sm font-medium text-slate-700">
                  Company Name <span className="text-red-500">*</span>
                </label>
                <Input
                  value={birData.company_name}
                  onChange={(e) => setBirData("company_name", e.target.value)}
                  className={`h-10 bg-white ${birErrors.company_name ? "border-red-500 focus-visible:ring-red-500" : ""}`}
                />
                {birErrors.company_name && (
                  <p className="text-xs text-red-500 font-medium">{birErrors.company_name}</p>
                )}
              </div>
            ) : (
              <>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">
                    Last Name <span className="text-red-500">*</span>
                  </label>
                    <Input
                      value={birData.last_name}
                      onChange={(e) => setBirData("last_name", e.target.value)}
                      className={`h-10 bg-white ${birErrors.last_name ? "border-red-500 focus-visible:ring-red-500" : ""}`}
                    />
                  {birErrors.last_name && (
                    <p className="text-xs text-red-500 font-medium">{birErrors.last_name}</p>
                  )}
                </div>

                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">
                    First Name <span className="text-red-500">*</span>
                  </label>
                    <Input
                      value={birData.first_name}
                      onChange={(e) => setBirData("first_name", e.target.value)}
                      className={`h-10 bg-white ${birErrors.first_name ? "border-red-500 focus-visible:ring-red-500" : ""}`}
                    />
                  {birErrors.first_name && (
                    <p className="text-xs text-red-500 font-medium">{birErrors.first_name}</p>
                  )}
                </div>

                <div className="space-y-2 md:col-span-2">
                  <label className="text-sm font-medium text-slate-700">
                    Middle Name <span className="text-red-500">*</span>
                  </label>
                  <Input
                    value={birData.middle_name}
                    onChange={(e) => setBirData("middle_name", e.target.value)}
                    className={`h-10 bg-white ${birErrors.middle_name ? "border-red-500 focus-visible:ring-red-500" : ""}`}
                  />
                  {birErrors.middle_name && (
                    <p className="text-xs text-red-500 font-medium">{birErrors.middle_name}</p>
                  )}
                </div>
              </>
            )}

            <div className="space-y-2">
              <label className="text-sm font-medium text-slate-700">Address 1</label>
              <Input
                value={birData.address1}
                onChange={(e) => setBirData("address1", e.target.value)}
                className={`h-10 bg-white ${birErrors.address1 ? "border-red-500 focus-visible:ring-red-500" : ""}`}
              />
              <p className="text-xs text-slate-500">Street/building/barangay only. Do not include comma.</p>
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium text-slate-700">Address 2 / City</label>
              <Input
                value={birData.address2}
                onChange={(e) => setBirData("address2", e.target.value)}
                className={`h-10 bg-white ${birErrors.address2 ? "border-red-500 focus-visible:ring-red-500" : ""}`}
              />
              <p className="text-xs text-slate-500">City/province goes here as a separate DAT field.</p>
            </div>
          </form>

          <DialogFooter className="border-t border-slate-100 pt-4">
            <Button type="button" variant="outline" onClick={closeBirEditor}>
              Cancel
            </Button>
            <Button type="submit" form="bir-info-form" disabled={birProcessing}>
              {birProcessing ? "Saving..." : "Save"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </section>
  );
}

RecordEntry.layout = (page) => (
  <MainLayout title="Purchase Entries">{page}</MainLayout>
);

export default RecordEntry;

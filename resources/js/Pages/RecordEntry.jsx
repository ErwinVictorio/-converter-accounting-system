import { useEffect, useRef, useState } from "react";
import { Link, useForm, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import { ArrowRight, FileSpreadsheet, Loader2, UploadCloud, X } from "lucide-react";

import MainLayout from "@/Layouts/MainLayout";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import {
  Card,
  CardContent,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/Components/ui/card";

/**
 * The upload type is always chosen explicitly -- it is never inferred from the
 * workbook's shape, since the three layouts overlap enough to guess wrong.
 *
 * `recordsUrl` is where the stored rows are browsed afterwards: this screen only
 * uploads now, so the confirmation links there instead of listing them here.
 */
const RECORD_TYPES = {
  purchase: {
    label: "Purchase",
    recordsLabel: "Purchase Records",
    recordsUrl: "/records/purchases",
  },
  sales: {
    label: "Sales",
    recordsLabel: "Sales Records",
    recordsUrl: "/records/sales",
  },
  expanded: {
    label: "Expanded WTAX",
    recordsLabel: "Expanded WTAX Records",
    recordsUrl: "/records/expanded-wtax",
  },
};

function RecordEntry() {
  const { flash, birCompanies = [] } = usePage().props;
  const defaultBirCompany = birCompanies[0] || { tin: "008791976", branch_code: "0000", name: "FORTRESS STEEL INC." };
  const [isDragging, setIsDragging] = useState(false);
  // Which Record page the last successful upload landed in, so the confirmation
  // can point at it. Derived from the submitted type, not from a new flash key.
  const [uploadedType, setUploadedType] = useState(null);
  const fileInputRef = useRef(null);

  // Inertia Form Setup para sa File Upload
  const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
    excel_file: null,
    reporting_month: new Date().toISOString().slice(0, 7),
    record_type: "purchase",
    withholding_agent_tin: defaultBirCompany.tin,
    withholding_agent_branch_code: defaultBirCompany.branch_code || "0000",
  });
  const isExpandedMode = data.record_type === "expanded";
  const recordType = RECORD_TYPES[data.record_type] || RECORD_TYPES.purchase;
  const uploadedRecordType = uploadedType ? RECORD_TYPES[uploadedType] : null;
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

  useEffect(() => {
    if (flash?.success) {
      toast.success(flash.success);
    }
    if (flash?.error) {
      toast.error(flash.error);
    }
  }, [flash]);

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

    const submittedType = data.record_type;

    post("/vat-import", {
      forceFormData: true,
      onSuccess: () => {
        reset("excel_file");
        if (fileInputRef.current) fileInputRef.current.value = "";
        setUploadedType(submittedType);
      },
      onError: (err) => {
        console.error("Upload error:", err);
      },
    });
  };

  return (
    <section className="space-y-6 w-full max-w-full overflow-hidden">
      {/* Excel Upload Card */}
      <Card className="w-full shadow-sm border rounded-xl overflow-hidden bg-white">
        <CardHeader className="p-4 sm:p-6 border-b bg-gray-50/50">
          <CardTitle className="text-lg sm:text-xl font-semibold text-gray-900">
            Upload Excel File
          </CardTitle>
        </CardHeader>

        <CardContent className="p-4 sm:p-6">
          {/*
            The rows themselves live under Record now, so a finished upload has
            nothing to show on this screen -- it hands over the link instead.
          */}
          {uploadedRecordType && (
            <div className="mb-5 flex flex-col gap-3 rounded-lg border border-emerald-200 bg-emerald-50/70 p-4 sm:flex-row sm:items-center sm:justify-between">
              <p className="text-sm text-emerald-900">
                {uploadedRecordType.label} file uploaded. The stored rows are under{" "}
                Record &gt; {uploadedRecordType.recordsLabel}.
              </p>
              <Button
                asChild
                variant="outline"
                size="sm"
                className="w-full shrink-0 border-emerald-300 bg-white text-emerald-800 hover:bg-emerald-100 sm:w-auto"
              >
                <Link href={uploadedRecordType.recordsUrl}>
                  View imported records
                  <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                </Link>
              </Button>
            </div>
          )}

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
                  Drag &amp; drop to upload file
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
    </section>
  );
}

RecordEntry.layout = (page) => (
  <MainLayout title="Import Data">{page}</MainLayout>
);

export default RecordEntry;


import { useEffect, useRef, useState } from "react";
import { Link, useForm, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import { AlertTriangle, ArrowRight, Copy } from "lucide-react";

import ExcelUploadPanel from "@/Components/ExcelUploadPanel";
import MainLayout from "@/Layouts/MainLayout";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/Components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/Components/ui/dialog";
import {
  ANNUAL_FULL_YEAR_HINT,
  annualCoveredPeriodError,
} from "@/lib/annualCoveredPeriod";

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

const expandedUploadErrorDetails = (message = "") => {
  const prefixes = [
    "Expanded withholding tax upload rejected. ",
    "Expanded withholding tax annual upload rejected. ",
  ];
  const prefix = prefixes.find((item) => message.startsWith(item));

  if (!prefix) {
    return [];
  }

  const body = message.slice(prefix.length).trim();

  return body
    .split(/(?=Rows \d)/)
    .map((item) => item.trim())
    .filter(Boolean);
};

const issueDialogText = (dialog) => {
  if (!dialog) return "";

  return (dialog.issues || [])
    .map((issue) => [
      `Row ${issue.row} - ${issue.name || "Unnamed record"}`,
      `Problem: ${issue.problem}`,
      `Fix in: ${issue.fix_location}`,
      `Needed fields: ${(issue.needed_fields || []).join(", ")}`,
      `Match used: ${issue.match_basis}`,
    ].filter(Boolean).join("\n"))
    .join("\n\n");
};

function RecordEntry() {
  const { flash, birCompanies = [] } = usePage().props;
  const defaultBirCompany = birCompanies[0] || { tin: "008791976", branch_code: "0000", name: "FORTRESS STEEL INC." };
  const [isDragging, setIsDragging] = useState(false);
  // Which Record page the last successful upload landed in, so the confirmation
  // can point at it. Derived from the submitted type, not from a new flash key.
  const [uploadedType, setUploadedType] = useState(null);
  const [uploadErrorDialogOpen, setUploadErrorDialogOpen] = useState(false);
  const [uploadErrorDetails, setUploadErrorDetails] = useState([]);
  const [uploadIssueDialog, setUploadIssueDialog] = useState(null);
  const fileInputRef = useRef(null);

  // Inertia Form Setup para sa File Upload
  const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
    excel_file: null,
    reporting_month: new Date().toISOString().slice(0, 7),
    report_type: "quarterly",
    start_date: `${new Date().getFullYear()}-01-01`,
    end_date: `${new Date().getFullYear()}-12-31`,
    record_type: "purchase",
    withholding_agent_tin: defaultBirCompany.tin,
    withholding_agent_branch_code: defaultBirCompany.branch_code || "0000",
  });
  const isExpandedMode = data.record_type === "expanded";
  const isAnnualExpanded = isExpandedMode && data.report_type === "annual";
  /*
   * Annual rows are filed in a 1604E dated 12/31 of the taxable year, so the
   * covered period has to be that whole year -- the same rule the backend applies
   * in AnnualCoveredPeriodValidator, repeated here so the file is not uploaded
   * only to be refused.
   */
  const annualPeriodError = isAnnualExpanded
    ? annualCoveredPeriodError(data.start_date, data.end_date)
    : "";
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
    if (flash?.warning) {
      toast.warning(flash.warning);
    }
    if (flash?.error) {
      if (flash?.uploadIssueDialog) {
        setUploadIssueDialog(flash.uploadIssueDialog);
        setUploadErrorDetails([]);
        setUploadErrorDialogOpen(true);
        toast.error("Upload rejected. Fix BIR info before importing.");

        return;
      }

      const details = expandedUploadErrorDetails(flash.error);

      if (details.length > 0) {
        setUploadIssueDialog(null);
        setUploadErrorDetails(details);
        setUploadErrorDialogOpen(true);
        toast.error("Upload rejected. Review the workbook corrections.");
      } else {
        toast.error(flash.error);
      }
    }
  }, [flash]);

  const copyUploadErrorDetails = async () => {
    const text = uploadIssueDialog
      ? issueDialogText(uploadIssueDialog)
      : uploadErrorDetails.join("\n\n");

    try {
      await navigator.clipboard.writeText(text);
      toast.success("Error details copied.");
    } catch {
      toast.error("Unable to copy error details.");
    }
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

    if (!(isExpandedMode && data.report_type === "annual") && !data.reporting_month) {
      toast.error("Please select the reporting month.");
      return;
    }

    if (annualPeriodError) {
      toast.error(annualPeriodError);
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
              {isExpandedMode ? (
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">
                    Type of Report <span className="text-red-500">*</span>
                  </label>
                  <select
                    value={data.report_type}
                    onChange={(e) => setData("report_type", e.target.value)}
                    className={`flex h-10 w-full rounded-md border bg-white px-3 py-2 text-sm text-slate-900 shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-[3px] ${
                      errors.report_type
                        ? "border-red-500 focus-visible:ring-red-500/20"
                        : "border-slate-300 focus-visible:border-blue-500 focus-visible:ring-blue-500/20"
                    }`}
                  >
                    <option value="quarterly">Quarterly</option>
                    <option value="annual">Annual</option>
                  </select>
                  {errors.report_type && (
                    <p className="text-xs text-red-500 font-medium">{errors.report_type}</p>
                  )}
                </div>
              ) : (
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
              )}

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
                {data.report_type === "quarterly" ? (
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
                ) : (
                  <>
                    <div className="space-y-2">
                      <label className="text-sm font-medium text-slate-700">
                        Start Date <span className="text-red-500">*</span>
                      </label>
                      <Input
                        type="date"
                        value={data.start_date}
                        onChange={(e) => setData("start_date", e.target.value)}
                        className={errors.start_date ? "border-red-500 focus-visible:ring-red-500" : ""}
                      />
                      {errors.start_date && (
                        <p className="text-xs text-red-500 font-medium">{errors.start_date}</p>
                      )}
                    </div>

                    <div className="space-y-2">
                      <label className="text-sm font-medium text-slate-700">
                        End Date <span className="text-red-500">*</span>
                      </label>
                      <Input
                        type="date"
                        value={data.end_date}
                        onChange={(e) => setData("end_date", e.target.value)}
                        className={errors.end_date ? "border-red-500 focus-visible:ring-red-500" : ""}
                      />
                      {errors.end_date && (
                        <p className="text-xs text-red-500 font-medium">{errors.end_date}</p>
                      )}
                    </div>

                    {/*
                      The 1604E these rows are filed in is dated 12/31 of the
                      taxable year, so a partial covered period is refused rather
                      than widened. Said here, before the file is sent.
                    */}
                    <div className="sm:col-span-3">
                      {annualPeriodError ? (
                        <p className="text-xs font-medium text-red-500">{annualPeriodError}</p>
                      ) : (
                        <p className="text-xs text-slate-400">{ANNUAL_FULL_YEAR_HINT}</p>
                      )}
                    </div>
                  </>
                )}

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
                    The row Date or Reporting_Month must fall inside the selected month or annual covered
                    period, and re-uploading replaces rows in that selected scope.
                  </li>
                  <li>
                    Rows sharing reporting month, TIN, ATC and rate are listed and filed as a single line,
                    with their income payment and tax amount added together.
                  </li>
                </ul>
              </div>
            )}

            <ExcelUploadPanel
              accept=".xls,.xlsx,.xlsm"
              acceptLabel=".xls, .xlsx, .xlsm"
              clearLabel="Clear File"
              error={errors.excel_file}
              file={data.excel_file}
              fileInputRef={fileInputRef}
              formId="record-upload-form"
              isDragging={isDragging}
              maxSizeLabel="10MB"
              onDragLeave={handleDragLeave}
              onDragOver={handleDragOver}
              onDrop={handleDrop}
              onFileChange={handleFileChange}
              onRemove={handleRemoveFile}
              processing={processing}
              selectedFileLabel={recordType.label}
              submitLabel="Submit File"
            />
          </form>
        </CardContent>
      </Card>

      <Dialog open={uploadErrorDialogOpen} onOpenChange={setUploadErrorDialogOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
          <DialogHeader className="border-b border-slate-100 pb-4 pr-8">
            <div className="flex items-start gap-3">
              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600">
                <AlertTriangle className="h-5 w-5" />
              </span>
              <div>
                <DialogTitle className="text-base font-semibold text-slate-900">
                  {uploadIssueDialog?.title || "Upload Rejected"}
                </DialogTitle>
                <DialogDescription className="mt-1 text-sm text-slate-500">
                  {uploadIssueDialog?.message || "Some company names use more than one TIN. Correct the workbook and upload it again."}
                </DialogDescription>
              </div>
            </div>
          </DialogHeader>

          {uploadIssueDialog ? (
            <div className="space-y-3">
              <p className="text-sm font-medium text-slate-700">
                {uploadIssueDialog.summary || `${uploadIssueDialog.issues?.length || 0} issue(s) found. No records were imported or replaced.`}
              </p>

              {(uploadIssueDialog.issues || []).map((issue, index) => (
                <div
                  key={`${issue.row}-${issue.field}-${index}`}
                  className="rounded-lg border border-red-100 bg-red-50/70 p-4 text-sm leading-relaxed text-red-950"
                >
                  <p className="font-semibold">
                    Row {issue.row} - {issue.name || "Unnamed record"}
                  </p>
                  <p className="mt-2">
                    <span className="font-medium">Problem:</span> {issue.problem}
                  </p>
                  <p>
                    <span className="font-medium">Fix in:</span> {issue.fix_location}
                  </p>
                  <p>
                    <span className="font-medium">Needed fields:</span>{" "}
                    {(issue.needed_fields || []).join(", ")}
                  </p>
                  <p>
                    <span className="font-medium">Match used:</span> {issue.match_basis}
                  </p>
                </div>
              ))}
            </div>
          ) : (
            <div className="space-y-3">
              {uploadErrorDetails.map((detail, index) => (
                <div
                  key={`${detail}-${index}`}
                  className="rounded-lg border border-red-100 bg-red-50/70 p-4 text-sm leading-relaxed text-red-950"
                >
                  {detail}
                </div>
              ))}
            </div>
          )}

          <DialogFooter className="border-t border-slate-100 pt-4">
            <Button
              type="button"
              variant="outline"
              onClick={copyUploadErrorDetails}
              className="gap-1.5"
            >
              <Copy className="h-4 w-4" />
              Copy Errors
            </Button>
            {uploadIssueDialog?.issues?.[0]?.fix_route && (
              <Button asChild type="button" variant="outline">
                <Link href={uploadIssueDialog.issues[0].fix_route}>
                  Open {uploadIssueDialog.record_type === "sales" ? "Customers" : "Suppliers"}
                </Link>
              </Button>
            )}
            <Button
              type="button"
              onClick={() => setUploadErrorDialogOpen(false)}
              className="bg-slate-900 text-white hover:bg-slate-800"
            >
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </section>
  );
}

RecordEntry.layout = (page) => (
  <MainLayout title="Import Data">{page}</MainLayout>
);

export default RecordEntry;

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

function RecordEntry() {
  const { flash, vatInputs, filters } = usePage().props;
  const [isDragging, setIsDragging] = useState(false);
  const [searchTerm, setSearchTerm] = useState(filters?.search || "");
  const [selectedBirRecord, setSelectedBirRecord] = useState(null);
  const fileInputRef = useRef(null);

  // Inertia Form Setup para sa File Upload
  const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
    excel_file: null,
    reporting_month: new Date().toISOString().slice(0, 7),
  });

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

  const openBirEditor = (record) => {
    setSelectedBirRecord(record);
    clearBirErrors();
    setBirData({
      vendor_type: record.vendor_type || "company",
      tin_number: (record.tin_number || "").replace(/\D/g, "").slice(0, 9),
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
            <div className="mb-5 max-w-xs space-y-2">
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
                      {(data.excel_file.size / (1024 * 1024)).toFixed(2)} MB
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
            VAT Input Records
          </CardTitle>

          {/* Search Input Filter */}
          <div className="relative w-full md:w-72">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
            <Input
              type="text"
              placeholder="Search supplier or TIN..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="pl-9 bg-white"
            />
          </div>
        </CardHeader>

        {/* 1. DAGDAG: overflow-x-auto dito para pwedeng ma-scroll ang table horizontally nang hindi nasisira ang buong card */}
        <CardContent className="p-0 overflow-x-auto">
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
                  const hasBirTin = /^\d{9}$/.test(String(item.tin_number || ""));
                  const hasBirName =
                    item.vendor_type === "individual"
                      ? Boolean(item.last_name && item.first_name && item.middle_name)
                      : Boolean(item.company_name || item.supplier_name);

                  return (
                  <TableRow key={item.id} className="hover:bg-slate-50/60 transition-colors">
                    <TableCell className="font-medium text-slate-900 whitespace-nowrap">{item.supplier_name}</TableCell>
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
        </CardContent>

        <DataTablePagination links={vatInputs?.links}/>
      </Card>

      <Dialog open={Boolean(selectedBirRecord)} onOpenChange={(open) => !open && closeBirEditor()}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>BIR Vendor Information</DialogTitle>
          </DialogHeader>

          <form id="bir-info-form" onSubmit={handleBirSubmit} className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <label className="text-sm font-medium text-slate-700">Vendor Type</label>
              <select
                value={birData.vendor_type}
                onChange={(e) => setBirData("vendor_type", e.target.value)}
                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors focus-visible:border-ring focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
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
                onChange={(e) => setBirData("tin_number", e.target.value.replace(/\D/g, "").slice(0, 9))}
                placeholder="9 digits only"
                className={birErrors.tin_number ? "border-red-500 focus-visible:ring-red-500" : ""}
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
                  className={birErrors.company_name ? "border-red-500 focus-visible:ring-red-500" : ""}
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
                    className={birErrors.last_name ? "border-red-500 focus-visible:ring-red-500" : ""}
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
                    className={birErrors.first_name ? "border-red-500 focus-visible:ring-red-500" : ""}
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
                    className={birErrors.middle_name ? "border-red-500 focus-visible:ring-red-500" : ""}
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
                className={birErrors.address1 ? "border-red-500 focus-visible:ring-red-500" : ""}
              />
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium text-slate-700">Address 2</label>
              <Input
                value={birData.address2}
                onChange={(e) => setBirData("address2", e.target.value)}
                className={birErrors.address2 ? "border-red-500 focus-visible:ring-red-500" : ""}
              />
            </div>
          </form>

          <DialogFooter>
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

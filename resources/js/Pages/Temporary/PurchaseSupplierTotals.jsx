import axios from "axios";
import { useRef, useState } from "react";
import { FileDown, Info } from "lucide-react";

import ExcelUploadPanel from "@/Components/ExcelUploadPanel";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import MainLayout from "@/Layouts/MainLayout";

function PurchaseSupplierTotals() {
    const fileInputRef = useRef(null);
    const [file, setFile] = useState(null);
    const [error, setError] = useState("");
    const [processing, setProcessing] = useState(false);
    const [progress, setProgress] = useState(null);
    const [isDragging, setIsDragging] = useState(false);

    const clearFile = () => {
        setFile(null);
        setError("");
        setProgress(null);
        if (fileInputRef.current) fileInputRef.current.value = "";
    };

    const selectFile = (selectedFile) => {
        setError("");

        if (selectedFile && !selectedFile.name.toLowerCase().endsWith(".xlsx")) {
            setFile(null);
            setError("Please select an XLSX workbook.");
            if (fileInputRef.current) fileInputRef.current.value = "";
            return;
        }

        setFile(selectedFile);
    };

    const handleDrop = (event) => {
        event.preventDefault();
        setIsDragging(false);
        selectFile(event.dataTransfer.files?.[0] ?? null);
    };

    const downloadReport = async (event) => {
        event.preventDefault();
        if (!file || processing) return;

        const payload = new FormData();
        payload.append("excel_file", file);

        setError("");
        setProgress(null);
        setProcessing(true);

        try {
            const response = await axios.post("/temporary/purchase-supplier-totals", payload, {
                headers: { Accept: "application/json" },
                responseType: "blob",
                onUploadProgress: (upload) => {
                    if (upload.total) {
                        setProgress(Math.round((upload.loaded * 100) / upload.total));
                    }
                },
            });

            const disposition = response.headers["content-disposition"] ?? "";
            const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
            const quotedName = disposition.match(/filename="([^"]+)"/i)?.[1];
            const plainName = disposition.match(/filename=([^;\s]+)/i)?.[1];
            const filename = encodedName
                ? decodeURIComponent(encodedName)
                : quotedName || plainName || "PURCHASE_SUPPLIER_TOTALS.xlsx";
            const url = window.URL.createObjectURL(response.data);
            const link = document.createElement("a");
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
        } catch (requestError) {
            let message = "The supplier total report could not be generated.";

            if (requestError.response?.data instanceof Blob) {
                try {
                    const payload = JSON.parse(await requestError.response.data.text());
                    message = payload.errors?.excel_file?.[0] ?? payload.message ?? message;
                } catch {
                    // Keep the safe fallback when an upstream response is not JSON.
                }
            }

            setError(message);
        } finally {
            setProcessing(false);
            setProgress(null);
        }
    };

    return (
        <section className="mx-auto max-w-5xl space-y-6">
            <div>
                <h1 className="text-2xl font-bold tracking-tight text-slate-800">
                    Supplier Total Report
                </h1>
                <p className="mt-1 text-sm text-slate-500">
                    Upload a Purchases Summary workbook and download one total per supplier.
                </p>
            </div>

            <div className="flex gap-3 rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                <Info className="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p className="font-semibold">Automatic sheet and column detection</p>
                    <p className="mt-1 text-blue-800">
                        Finds the worksheet and columns with Supplier Name and Amount, then groups
                        rows with the same supplier name. No Purchase or Supplier records are saved.
                    </p>
                </div>
            </div>

            <Card className="overflow-hidden rounded-xl border border-slate-100 bg-white shadow-sm">
                <CardHeader className="border-b border-slate-100 bg-slate-50/50">
                    <CardTitle className="flex items-center gap-2 text-lg text-slate-800">
                        <FileDown className="h-5 w-5 text-emerald-700" />
                        Generate Excel Report
                    </CardTitle>
                </CardHeader>
                <CardContent className="p-6">
                    <form id="supplier-total-form" onSubmit={downloadReport}>
                        <ExcelUploadPanel
                            accept=".xlsx"
                            acceptLabel=".xlsx"
                            error={error}
                            file={file}
                            fileInputRef={fileInputRef}
                            formId="supplier-total-form"
                            isDragging={isDragging}
                            onBrowse={() => fileInputRef.current?.click()}
                            onDragLeave={(event) => {
                                event.preventDefault();
                                setIsDragging(false);
                            }}
                            onDragOver={(event) => {
                                event.preventDefault();
                                setIsDragging(true);
                            }}
                            onDrop={handleDrop}
                            onFileChange={selectFile}
                            onRemove={clearFile}
                            processing={processing}
                            processingLabel="Generating Supplier Totals"
                            progress={progress}
                            selectedFileLabel="Supplier Name and Amount will be detected"
                            submitLabel="Generate and Download Report"
                        />
                    </form>
                </CardContent>
            </Card>
        </section>
    );
}

PurchaseSupplierTotals.layout = (page) => <MainLayout title="Supplier Total Report">{page}</MainLayout>;

export default PurchaseSupplierTotals;

import { CheckCircle2, FileSpreadsheet, Loader2, UploadCloud, X } from "lucide-react";

import { Button } from "@/Components/ui/button";

const fileSize = (file) => {
    if (!file?.size) return "";

    return `${(file.size / (1024 * 1024)).toFixed(2)} MB`;
};

export default function ExcelUploadPanel({
    accept = ".xlsx,.xls,.csv",
    acceptLabel = ".xlsx, .xls, .csv",
    clearLabel = "Cancel",
    error,
    file,
    fileInputRef,
    footerActions,
    formId,
    isDragging = false,
    maxSizeLabel = "10MB",
    onBrowse,
    onDragLeave,
    onDragOver,
    onDrop,
    onFileChange,
    onRemove,
    processing = false,
    processingLabel = "Processing Excel File",
    progress = null,
    selectedFileLabel,
    submitLabel = "Upload",
}) {
    const progressValue = Math.max(0, Math.min(100, Number(progress ?? 0)));
    const hasProgress = progress !== null && progress !== undefined;

    const browseFiles = () => {
        if (processing) return;
        if (onBrowse) {
            onBrowse();
            return;
        }

        fileInputRef?.current?.click();
    };

    return (
        <div className="space-y-5">
            <input
                type="file"
                ref={fileInputRef}
                accept={accept}
                className="hidden"
                onChange={(event) => onFileChange?.(event.target.files?.[0] ?? null)}
            />

            {processing ? (
                <div className="rounded-xl border border-emerald-100 bg-white p-6 text-center shadow-sm sm:p-8">
                    <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-2xl border border-emerald-100 bg-emerald-50 text-emerald-700 shadow-sm">
                        <FileSpreadsheet className="h-10 w-10" />
                    </div>

                    <h3 className="mt-5 text-lg font-bold text-slate-900">{processingLabel}</h3>
                    <div className="mx-auto mt-4 h-2 w-full max-w-md overflow-hidden rounded-full bg-slate-100">
                        <div
                            className={`h-full rounded-full bg-emerald-700 transition-all duration-300 ${
                                hasProgress ? "" : "w-1/3 animate-pulse"
                            }`}
                            style={hasProgress ? { width: `${progressValue}%` } : undefined}
                        />
                    </div>

                    <p className="mt-3 text-sm font-semibold text-emerald-700">
                        {hasProgress ? `${Math.round(progressValue)}% complete` : "Uploading file..."}
                    </p>
                    <p className="mt-1 text-xs text-slate-500">This may take a few moments.</p>

                    <div className="mx-auto mt-6 max-w-md divide-y divide-slate-100 text-left">
                        <div className="flex items-center gap-3 py-3 text-sm font-medium text-slate-800">
                            <CheckCircle2 className="h-4 w-4 text-emerald-700" />
                            Reading file
                        </div>
                        <div className="flex items-center gap-3 py-3 text-sm font-medium text-slate-800">
                            <span className="flex h-4 w-4 items-center justify-center rounded-full bg-emerald-100">
                                <Loader2 className="h-3 w-3 animate-spin text-emerald-700" />
                            </span>
                            Mapping fields...
                        </div>
                        <div className="flex items-center gap-3 py-3 text-sm font-medium text-slate-500">
                            <span className="h-3 w-3 rounded-full bg-slate-200" />
                            Validating data
                        </div>
                    </div>
                </div>
            ) : !file ? (
                <div
                    onDragOver={onDragOver}
                    onDragLeave={onDragLeave}
                    onDrop={onDrop}
                    onClick={browseFiles}
                    className={`flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-6 text-center transition-colors sm:p-10 ${
                        isDragging
                            ? "border-emerald-500 bg-emerald-50"
                            : "border-slate-200 bg-white hover:border-emerald-300 hover:bg-slate-50"
                    }`}
                >
                    <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700">
                        <UploadCloud className="h-7 w-7" />
                    </div>

                    <h4 className="text-base font-bold text-slate-900 sm:text-lg">
                        Drag &amp; drop file or choose a file
                    </h4>
                    <p className="mt-1 text-xs font-medium text-slate-500 sm:text-sm">
                        Supported formats: {acceptLabel} - Max {maxSizeLabel}
                    </p>

                    <Button
                        type="button"
                        onClick={(event) => {
                            event.stopPropagation();
                            browseFiles();
                        }}
                        className="mt-6 bg-emerald-700 px-6 text-white hover:bg-emerald-800"
                    >
                        Browse Files
                    </Button>
                </div>
            ) : (
                <div className="flex flex-col gap-4 rounded-xl border border-emerald-100 bg-emerald-50/70 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                    <div className="flex min-w-0 items-center gap-4">
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white text-emerald-700 shadow-sm">
                            <FileSpreadsheet className="h-7 w-7" />
                        </div>
                        <div className="min-w-0">
                            <p className="truncate text-sm font-semibold text-slate-900">{file.name}</p>
                            <p className="text-xs text-slate-500">
                                {[fileSize(file), selectedFileLabel].filter(Boolean).join(" - ")}
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        onClick={onRemove}
                        className="self-end rounded-md p-1 text-slate-400 transition-colors hover:bg-white hover:text-red-500 sm:self-auto"
                        aria-label="Remove selected file"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>
            )}

            {error && <p className="text-xs font-medium text-red-500">{error}</p>}

            <div className="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
                {footerActions}
                <Button
                    type="button"
                    variant="outline"
                    onClick={onRemove}
                    disabled={!file || processing}
                    className="w-full border-slate-200 bg-white px-5 text-slate-700 hover:bg-slate-50 sm:w-auto"
                >
                    {clearLabel}
                </Button>
                <Button
                    type="submit"
                    form={formId}
                    disabled={!file || processing}
                    className="w-full min-w-[120px] bg-emerald-700 px-6 text-white hover:bg-emerald-800 sm:w-auto"
                >
                    {processing ? (
                        <>
                            <Loader2 className="h-4 w-4 animate-spin" />
                            Uploading...
                        </>
                    ) : (
                        <>
                            <UploadCloud className="h-4 w-4" />
                            {submitLabel}
                        </>
                    )}
                </Button>
            </div>
        </div>
    );
}

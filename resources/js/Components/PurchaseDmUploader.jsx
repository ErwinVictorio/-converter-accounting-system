import { useRef, useState } from "react";
import axios from "axios";
import { UploadCloud } from "lucide-react";
import { Button } from "@/Components/ui/button";
import { DataTable } from "@/Components/DataTable";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/Components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/Components/ui/select";

const PURCHASE_TYPES = [
    ["local", "VAT INPUT TAX - PURCHASES LOCAL"],
    ["imported", "VAT INPUT TAX - PURCHASES IMPORTED"],
    ["services", "VAT INPUT TAX - SERVICES"],
    ["others", "VAT INPUT TAX - OTHERS"],
];
const money = (value) => value == null ? "Unknown" : `PHP ${Number(value).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const metricLabels = { vat: "Purchase VAT", base: "Taxable amount", input_vat: "Input VAT", visible_total: "Purchase Total", gross: "Total with VAT" };
const statusLabels = { ready: "Ready", already_applied: "Already applied", no_matching_record: "No matching record", non_dm: "Non-DM skipped", invalid_dm: "Invalid DM", unsupported_record: "Unsupported record", ambiguous_identity: "Ambiguous identity", invalid_tin: "Invalid TIN", ambiguous_group: "Ambiguous group", inconsistent_amounts: "Inconsistent amounts", conflicting_reference: "Conflicting reference", amount_out_of_range: "Amount out of range" };

// Parent keys by company/branch/month, unmounting stale selections on scope changes.
export default function PurchaseDmUploader({ scope, companyName, disabled = false }) {
    const input = useRef(null);
    const requestBusy = useRef(false);
    const [file, setFile] = useState(null);
    const [purchaseType, setPurchaseType] = useState("");
    const [open, setOpen] = useState(false);
    const [errors, setErrors] = useState([]);
    const [dragging, setDragging] = useState(false);
    const [busy, setBusy] = useState(false);
    const [review, setReview] = useState(null);
    const [result, setResult] = useState(null);
    const [ackSkipped, setAckSkipped] = useState(false);
    const [ackWarnings, setAckWarnings] = useState(false);
    const [filter, setFilter] = useState("all");
    const scopeReady = Boolean(scope.filing_company_tin && scope.filing_company_branch_code && scope.reporting_month);
    const unavailable = disabled || !scopeReady || busy;
    const selectFiles = (files) => {
        setDragging(false);
        if (unavailable) return;
        setErrors([]);
        if (!files?.length) return;
        if (files.length !== 1) { setErrors(["Choose one DM workbook at a time."]); return; }
        const selected = files[0];
        if (!/\.(xlsx|xls)$/i.test(selected.name) || selected.size > 10 * 1024 * 1024 || selected.size === 0) {
            setErrors(["Choose a non-empty .xlsx or .xls workbook up to 10MB."]); return;
        }
        setFile(selected); setPurchaseType(""); setReview(null); setResult(null); setAckSkipped(false); setAckWarnings(false); setFilter("all"); setOpen(true);
    };
    const close = () => {
        if (requestBusy.current) return;
        setOpen(false); setFile(null); setPurchaseType(""); setReview(null); setErrors([]);
        if (input.current) input.current.value = "";
    };
    const fail = (error) => setErrors(Object.values(error.response?.data?.errors || {}).flat().length
        ? Object.values(error.response.data.errors).flat() : [error.response?.data?.message || "Request failed. Please try again."]);
    const upload = async () => {
        if (requestBusy.current || !file || !purchaseType || unavailable) return;
        requestBusy.current = true; setBusy(true); setErrors([]); setAckSkipped(false); setAckWarnings(false);
        try {
            const body = new FormData(); body.append("excel_file", file); body.append("purchase_type", purchaseType);
            Object.entries(scope).forEach(([key, value]) => body.append(key, value));
            const { data } = await axios.post("/purchase-dm/review", body, { headers: { Accept: "application/json" } });
            if (data.review_version !== 1 || !data.summary || !Array.isArray(data.entries) || !Array.isArray(data.targets)) throw new Error("Invalid review response");
            setReview(data); setFilter("all");
        } catch (error) { fail(error); } finally { requestBusy.current = false; setBusy(false); }
    };
    const apply = async () => {
        if (requestBusy.current || !review?.token) return;
        requestBusy.current = true; setBusy(true); setErrors([]);
        try {
            const { data } = await axios.post("/purchase-dm/apply", { token: review.token, review_version: review.review_version, acknowledge_skipped: ackSkipped, acknowledge_warnings: ackWarnings });
            if (typeof data.message !== "string") throw new Error("Invalid save response");
            setResult({ ...data, scope: review.scope }); setOpen(false); setFile(null); setReview(null);
        } catch (error) { fail(error); } finally { requestBusy.current = false; setBusy(false); }
    };
    const canConfirm = review?.token && (!review.summary.skipped_count || ackSkipped) && (!review.warnings.length || ackWarnings);
    const visibleEntries = review ? (filter === "non_dm" ? review.excluded : review.entries.filter((entry) => filter === "all" || (filter === "needs_review" ? !["ready", "already_applied"].includes(entry.status) : entry.status === filter))) : [];
    const recordsUrl = result ? `/records/purchases?${new URLSearchParams({ period: result.scope.reporting_month, company: `${result.scope.filing_company_tin}|${result.scope.filing_company_branch_code}` })}` : "#";

    return <section className="space-y-4 rounded-xl border border-emerald-200 bg-white p-6" aria-labelledby="purchase-dm-title">
        <div className="space-y-2">
            <h2 id="purchase-dm-title" className="text-lg font-semibold">(DM) Purchase Uploader</h2>
            <p className="text-sm text-slate-600">Select a company and month, then upload your DM file.</p>
            <p className="text-sm text-slate-600">Only DM Credit amounts will be deducted. Other rows are skipped.</p>
            <p className="text-xs text-slate-500">Re-uploaded Purchase records need their DM deductions applied again.</p>
        </div>
        {!scopeReady && <p role="status" className="text-sm text-amber-800">Select a filing company, branch, and reporting month above to choose a DM file.</p>}
        <input ref={input} type="file" accept=".xlsx,.xls" className="hidden" disabled={unavailable} aria-label="Choose Purchase DM workbook"
            onChange={(event) => { selectFiles(event.target.files); event.target.value = ""; }} />
        <div className={`rounded-lg border-2 border-dashed p-8 text-center transition-colors ${dragging ? "border-emerald-500 bg-emerald-50" : "border-emerald-200"} ${unavailable ? "opacity-60" : ""}`}
            onDragOver={(event) => { event.preventDefault(); if (!unavailable) setDragging(true); }}
            onDragLeave={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setDragging(false); }}
            onDrop={(event) => { event.preventDefault(); selectFiles(event.dataTransfer.files); }}>
            <UploadCloud aria-hidden="true" className="mx-auto mb-3 h-9 w-9 rounded-lg bg-emerald-50 p-2 text-emerald-600" />
            <p className="text-sm font-medium text-slate-700">Drag &amp; drop file or choose a file</p>
            <p className="mb-4 mt-1 text-xs text-slate-500">Supported formats: .xlsx, .xls · Max 10MB</p>
            <Button type="button" disabled={unavailable} onClick={() => input.current?.click()}>Browse Files</Button>
        </div>
        {!open && errors.map((error, i) => <p role="alert" key={i} className="text-sm text-red-700">{error}</p>)}
        {result && <div role="status" className="space-y-2 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900">
            <p>{result.message} Applied: {result.summary.eligible_count}; skipped DM: {result.summary.skipped_count}.</p>
            <a href={recordsUrl} className="underline">View Purchase Records</a>
            {!!result.skipped_entries?.length && <details><summary>View skipped DM entries</summary>{result.skipped_entries.map((e) => <p key={e.key}>Row {e.row}: {e.reference} — {e.supplier}: {e.reason}</p>)}</details>}
        </div>}
        <Dialog open={open} onOpenChange={(value) => { if (!value) close(); }}>
            <DialogContent className={`flex max-h-[90vh] w-[calc(100%-2rem)] min-w-0 flex-col overflow-hidden ${review ? "sm:max-w-5xl" : "sm:max-w-lg"}`}>
                <DialogHeader>
                    <DialogTitle>{review ? "Review Purchase DM Adjustment" : "Upload Purchase DM"}</DialogTitle>
                    <DialogDescription>{review ? "Review the amounts before confirming." : "Choose a Purchase type to continue."}</DialogDescription>
                </DialogHeader>
                <div className="min-h-0 min-w-0 space-y-4 overflow-y-auto pr-1">
                    <dl className="space-y-2 rounded-lg bg-slate-50 p-3 text-sm">
                        <div><dt className="text-slate-500">File</dt><dd className="break-all font-medium">{file?.name}</dd></div>
                        <div><dt className="text-slate-500">Company / Branch</dt><dd className="break-words">{companyName || scope.filing_company_tin} · {scope.filing_company_tin}-{scope.filing_company_branch_code}</dd></div>
                        <div><dt className="text-slate-500">Reporting month</dt><dd>{scope.reporting_month}</dd></div>
                    </dl>
                    <div className="min-w-0 space-y-2">
                        <label htmlFor="purchase-dm-type" className="text-sm font-medium">Select Purchase Type <span aria-hidden="true">*</span></label>
                        <Select value={purchaseType} required disabled={busy || !!review} onValueChange={setPurchaseType}>
                            <SelectTrigger id="purchase-dm-type" className="h-auto min-h-10 w-full whitespace-normal text-left" aria-describedby="purchase-dm-type-help"><SelectValue placeholder="Select Purchase Type" /></SelectTrigger>
                            <SelectContent className="z-[102] max-w-[calc(100vw-3rem)] bg-white text-slate-950">{PURCHASE_TYPES.map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}</SelectContent>
                        </Select>
                        <p id="purchase-dm-type-help" className="text-sm text-slate-600">Credit will be deducted from the selected Purchase type. Related totals update automatically.</p>
                    </div>
                    {errors.map((error, i) => <p role="alert" key={i} className="text-sm text-red-700">{error}</p>)}
                    {review && <>
                        {!review.apply_available && <p role="alert" className="text-sm text-amber-800">Saving is unavailable. Purchase DM database setup must be completed.</p>}
                        <div className="grid gap-3 sm:grid-cols-3">{[["file", "DM Credit in file"], ["eligible", "To deduct"], ["skipped", "Skipped amount"]].map(([key, label]) => <div key={key} className="rounded-lg border p-3 text-sm"><p>{label}</p><p className="font-semibold">{money(review.summary[`${key}_total`])}</p><p>{review.summary[`${key}_count`]} entries</p>{review.summary[`${key}_unknown_count`] > 0 && <p className="text-amber-800">Plus {review.summary[`${key}_unknown_count`]} unreadable amounts (not included in sum).</p>}</div>)}</div>
                        <div className="space-y-3">{review.targets.map((target) => <div key={target.key} className="rounded-lg border p-3">
                            <h3 className="mb-2 font-semibold">{target.supplier} <span className="text-xs font-normal">({target.source_count} original records)</span></h3>
                            <DataTable mode="client" data={Object.entries(target.metrics).map(([key, values]) => ({ key, ...values }))} getRowId={(row) => row.key} showToolbar={false} showPagination={false} tableClassName="min-w-[580px]" columns={[
                                { id: "metric", header: "Amount", cell: ({ row }) => metricLabels[row.original.key] },
                                ...["before", "deduction", "after"].map((field) => ({ id: field, header: { before: "Original", deduction: "Deduction", after: "Remaining" }[field], cell: ({ row }) => money(row.original[field]) })),
                            ]} />
                            <details className="mt-2 text-sm"><summary>View eligible DM references</summary>{review.entries.filter((e) => e.status === "ready" && e.target_key === target.key).map((e) => <p key={e.key}>Row {e.row} · {e.date} · {e.reference} · {money(e.amount)}</p>)}</details>
                        </div>)}</div>
                        {purchaseType === "imported" && <p className="text-sm text-slate-600">Imported deductions update Imported amounts. Purchase Total shows Local, Services and Others only.</p>}
                        <div className="flex flex-wrap gap-2" aria-label="DM entry filters">{[["all", "All DM"], ["ready", "Ready"], ["already_applied", "Already applied"], ["needs_review", "Needs review"], ["non_dm", `Non-DM skipped (${review.excluded_count})`]].map(([value, label]) => <Button key={value} type="button" size="sm" variant={filter === value ? "default" : "outline"} onClick={() => setFilter(value)}>{label}</Button>)}</div>
                        <DataTable mode="client" data={visibleEntries} getRowId={(row) => String(row.row)} showToolbar={false} showPagination={false} tableClassName="min-w-[800px]" emptyState={{ title: "No entries match this filter." }} columns={[
                            { id: "row", header: "Row / Date", cell: ({ row }) => `${row.original.row} / ${row.original.date || "—"}` },
                            { id: "reference", header: "Reference", accessorKey: "reference" },
                            { id: "supplier", header: "Supplier", accessorKey: "supplier" },
                            { id: "credit", header: "DM Credit", cell: ({ row }) => row.original.status === "non_dm" ? "Not deducted" : money(row.original.amount) },
                            { id: "status", header: "Status / Reason", cell: ({ row }) => <div><p className="font-medium">{statusLabels[row.original.status]}</p><p className="text-xs text-slate-600">{row.original.reason}</p></div> },
                        ]} />
                        {review.warnings.length > 0 && <div role="alert" className="space-y-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900"><p className="font-semibold">Review adjustment warnings</p>{review.warnings.map((warning, i) => <p key={i}>{warning}</p>)}<label className="flex gap-2"><input type="checkbox" checked={ackWarnings} disabled={busy} onChange={(e) => setAckWarnings(e.target.checked)} />I understand and want to continue.</label></div>}
                        {!!review.summary.skipped_count && !!review.summary.eligible_count && <label className="flex gap-2 text-sm"><input type="checkbox" checked={ackSkipped} disabled={busy} onChange={(e) => setAckSkipped(e.target.checked)} />Apply ready DM entries only. Skip the rest.</label>}
                        {!review.summary.eligible_count && <p role="status" className="text-sm font-medium">No DM entries are ready. Check the reasons above.</p>}
                    </>}
                </div>
                <DialogFooter className="flex-wrap gap-2">
                    <Button type="button" variant="outline" disabled={busy} onClick={close}>Cancel</Button>
                    {review && <Button type="button" variant="outline" disabled={busy} onClick={() => { setReview(null); setErrors([]); setAckSkipped(false); setAckWarnings(false); }}>Back to selection</Button>}
                    {review ? <Button type="button" disabled={unavailable || !canConfirm} onClick={apply}>{busy ? "Saving..." : review.summary.skipped_count ? "Apply eligible DM only" : "Confirm Deduction"}</Button>
                        : <Button type="button" disabled={unavailable || !file || !purchaseType} onClick={upload}>{busy ? "Reviewing..." : "Upload"}</Button>}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>;
}

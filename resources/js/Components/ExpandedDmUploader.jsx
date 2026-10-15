import { DataTable } from '@/Components/DataTable';
import { useRef, useState } from "react";
import axios from "axios";
import { router } from "@inertiajs/react";
import { toast } from "sonner";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/Components/ui/dialog";

const money = (value) => Number(value).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function ExpandedDmUploader({ scope }) {
    const input = useRef(null);
    const [file, setFile] = useState(null);
    const [percentage, setPercentage] = useState("");
    const [context, setContext] = useState(null);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState([]);
    const [review, setReview] = useState(null);
    const [result, setResult] = useState(null);
    const [ackSkipped, setAckSkipped] = useState(false);
    const [ackWarnings, setAckWarnings] = useState(false);
    const [filter, setFilter] = useState("all");

    const select = (selected) => {
        if (busy || !selected) return;
        setErrors([]); setResult(null); setReview(null); setAckSkipped(false); setAckWarnings(false); setFilter("all");
        if (!/\.(xlsx|xls)$/i.test(selected.name) || selected.size > 10 * 1024 * 1024) {
            setErrors(["Choose an .xlsx or .xls workbook up to 10MB."]); return;
        }
        setFile(selected); setPercentage(""); setContext({ ...scope }); setOpen(true);
    };
    const close = () => {
        if (busy) return;
        setOpen(false); setFile(null); setReview(null); setErrors([]);
        if (input.current) input.current.value = "";
    };
    const fail = (error) => setErrors(Object.values(error.response?.data?.errors || {}).flat().length
        ? Object.values(error.response.data.errors).flat()
        : [error.response?.data?.message || "The request failed. Please try again."]);
    const upload = async () => {
        if (!/^\d+(\.\d{1,2})?$/.test(percentage) || Number(percentage) <= 0 || Number(percentage) > 100) {
            setErrors(["Enter a percentage greater than 0 and at most 100, with up to two decimal places."]); return;
        }
        setBusy(true); setErrors([]);
        try {
            const body = new FormData();
            body.append("excel_file", file); body.append("percentage", percentage);
            Object.entries(context).forEach(([key, value]) => body.append(key, value));
            const response = await axios.post("/expanded-dm/review", body, { headers: { Accept: "application/json" } });
            if (response.data?.review_version !== 4 || !response.data?.summary || !Array.isArray(response.data?.supplier_groups) || !Array.isArray(response.data?.targets)) {
                setErrors(["The DM review format changed. Refresh the page and review the file again."]); return;
            }
            setReview(response.data);
        } catch (error) { fail(error); } finally { setBusy(false); }
    };
    const apply = async () => {
        setBusy(true); setErrors([]);
        try {
            const response = await axios.post("/expanded-dm/apply", { token: review.token, review_version: review.review_version, acknowledge_warnings: ackWarnings, acknowledge_skipped: ackSkipped }, { headers: { Accept: "application/json" } });
            if (typeof response.data?.message !== "string") {
                setErrors(["The save response could not be read. Retry Confirm Deduction; the same review will not be deducted twice."]); return;
            }
            setResult({ ...response.data, scope: { ...context, percentage: review.scope.percentage } }); toast.success(response.data.message);
            setOpen(false); setFile(null); setReview(null);
            if (input.current) input.current.value = "";
            router.reload({ only: ["flash"] });
        } catch (error) { fail(error); } finally { setBusy(false); }
    };

    const labels = {
        ready: "Ready", already_applied: "Already applied", no_matching_record: "No matching record",
        invalid_tin: "Invalid TIN", ambiguous_tin: "Multiple TINs", ambiguous_group: "Multiple payee / ATC groups",
        conflicting_identity: "Conflicting identity",
    };
    const matchesFilter = (entry) => filter === "all" || (filter === "ready" ? entry.status === "ready"
        : filter === "already_applied" ? entry.status === "already_applied" : !["ready", "already_applied"].includes(entry.status));
    const canConfirm = review?.token && (!review.summary.skipped_count || ackSkipped) && (!review.warnings.length || ackWarnings);
    const recordsUrl = result ? `/records/expanded-wtax?${new URLSearchParams({ period: result.scope.reporting_month,
        withholding_agent_tin: result.scope.withholding_agent_tin, withholding_agent_branch_code: result.scope.withholding_agent_branch_code, report_type: result.scope.report_type, tax_rate: result.scope.percentage })}` : "#";

    return <section className="space-y-4 rounded-xl border border-emerald-200 bg-white p-6">
        <h2 className="text-lg font-semibold">(DM) Expanded Uploader</h2>
        <p className="text-sm text-slate-600">Upload ACCOUNT DETAILS to deduct eligible DM Debit from Tax Withheld and reduce Income Payment using the selected rate. Select the company and reporting month above.</p>
        {scope.report_type !== "quarterly" ? <p className="text-sm text-amber-800">DM adjustments currently support quarterly records for one reporting month. Select Quarterly above.</p> : <>
            <input ref={input} type="file" accept=".xlsx,.xls" className="hidden" onChange={(event) => { select(event.target.files?.[0]); event.target.value = ""; }} />
            <div className="rounded-lg border-2 border-dashed border-emerald-200 p-8 text-center" onDragOver={(event) => event.preventDefault()} onDrop={(event) => { event.preventDefault(); select(event.dataTransfer.files?.[0]); }}>
                <p className="mb-3 text-sm">Drag &amp; drop ACCOUNT DETAILS here, or choose a file (.xlsx, .xls; max 10MB).</p>
                <Button type="button" disabled={busy} onClick={() => input.current?.click()}>Browse DM File</Button>
            </div>
        </>}
        {result && <div role="status" className="space-y-2 text-sm text-emerald-800">
            <p>{result.message} <a className="underline" href={recordsUrl}>View Expanded records</a></p>
            {result.summary && <p>Applied: PHP {money(result.summary.eligible_total)}. Skipped: PHP {money(result.summary.skipped_total)}.</p>}
            {result.skipped_entries?.length > 0 && <details><summary className="cursor-pointer">View skipped entries</summary>
                <ul className="space-y-2">{result.skipped_entries.map(entry => <li key={entry.key}>Row {entry.row}: {entry.reference} — {entry.supplier} — PHP {money(entry.amount)}. {entry.reason}</li>)}</ul>
                <p>Correct unresolved records and upload the DM file again. Previously applied references will remain skipped.</p>
            </details>}
        </div>}
        {!open && errors.map((error, index) => <p role="alert" key={index} className="text-sm text-red-700">{error}</p>)}
        <Dialog open={open} onOpenChange={(value) => { if (!value) close(); }}>
            <DialogContent className="flex w-[calc(100%-2rem)] min-w-0 max-h-[90vh] flex-col overflow-hidden sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>{review ? "Review DM Adjustment" : "Enter the Percentage"}</DialogTitle>
                    <DialogDescription>{context?.reporting_month} · Company {context?.withholding_agent_tin}-{context?.withholding_agent_branch_code}{review && ` · Rate: ${Number(review.scope.percentage)}%`}</DialogDescription>
                </DialogHeader>
                <div className="min-h-0 min-w-0 space-y-4 overflow-y-auto pr-1">
                    {!review ? <div className="space-y-3">
                        <label htmlFor="dm-percentage" className="text-sm font-medium">Percentage (%)</label>
                        <Input id="dm-percentage" inputMode="decimal" value={percentage} disabled={busy} onChange={(event) => setPercentage(event.target.value)} placeholder="Example: 1" />
                        <p className="text-sm text-slate-600">For DM-only files, enter the rate for these transactions. If the workbook includes an account rate, it must match.</p>
                    </div> : <>
                        <p className="break-words text-sm text-slate-600">{file?.name} · Preview only. No adjustments have been saved.</p>
                        {!review.apply_available && <p role="alert">Saving is unavailable. Contact your administrator to check the DM setup.</p>}
                        <div className="grid gap-2 sm:grid-cols-3">{[["file", "Total DM in file"], ["eligible", "Eligible deduction"], ["skipped", "Skipped amount"]].map(([key, label]) =>
                            <div key={key} className="rounded-lg border p-3 text-sm"><p>{label}</p><p className="font-semibold">PHP {money(review.summary[`${key}_total`])}</p><p>{review.summary[`${key}_count`]} DM entries</p></div>)}</div>
                        <p className="text-sm text-slate-600">Already applied: {review.summary.already_applied_count} entries / PHP {money(review.summary.already_applied_total)}. Needs review: {review.summary.needs_review_count} entries / PHP {money(review.summary.needs_review_total)}.</p>
                        <label className="block text-sm">Show suppliers
                            <select className="ml-2 rounded border text-sm" value={filter} onChange={event => setFilter(event.target.value)}>
                                <option value="all">All suppliers</option><option value="ready">Ready to apply</option><option value="needs_review">Needs review</option><option value="already_applied">Already applied</option>
                            </select>
                        </label>
                        <div className="overflow-x-auto"><DataTable mode="client" data={review.supplier_groups.filter(group => group.entries.some(matchesFilter))} getRowId={row => String(row.key)} showToolbar={false} showPagination={false} tableClassName="min-w-[800px]" emptyState={{ title: 'No DM groups match this filter.' }} columns={[{ id: 'column_0', header: "Supplier / Status", enableSorting: false, enableHiding: false, meta: { cellClassName: "min-w-[220px] p-2", headerClassName: '' }, cell: ({row}) => { const group=row.original; const target = review.targets.find(item => item.key === group.target_key);
                                const ready = group.entries.filter(entry => entry.status === "ready").length;
                                const skipped = group.entries.length - ready;
                                const statuses = [...new Set(group.entries.map(entry => labels[entry.status]))];
                                 return (<><p className="font-medium">{target?.supplier || group.supplier}</p>
                                        <p className={`text-xs ${skipped ? "text-amber-800" : "text-emerald-800"}`}>{statuses.length > 1 ? `Mixed: ${ready} ready, ${skipped} skipped` : statuses[0]}</p>
                                        {target && (Number(target.after) < 0 || Number(target.income_after) < 0) && <p className="text-xs text-amber-800">Negative after deduction</p>}
                                        <details className="mt-2"><summary className="cursor-pointer text-slate-600">View {group.entries.length} DM entries</summary>
                                            {target && <div className="my-2 text-xs text-slate-600"><p>TIN {target.tin} · ATC {target.atc} · {target.rate}% · {target.source_count} source rows</p><p>Income Payment: PHP {money(target.income_before)} − {money(target.income_deduction)} = {money(target.income_after)}</p></div>}
                                            <ul className="space-y-2 text-xs">{group.entries.map(entry => <li key={entry.key}><p>Row {entry.row}: {entry.reference} · PHP {money(entry.amount)}</p><p>{labels[entry.status]} — {entry.reason}</p></li>)}</ul>
                                        </details>
                                    </>); } },
{ id: 'column_1', header: "Current Tax", enableSorting: false, enableHiding: false, meta: { cellClassName: "whitespace-nowrap p-2 text-right", headerClassName: 'text-right' }, cell: ({row}) => { const group=row.original; const target = review.targets.find(item => item.key === group.target_key);
                                const ready = group.entries.filter(entry => entry.status === "ready").length;
                                const skipped = group.entries.length - ready;
                                const statuses = [...new Set(group.entries.map(entry => labels[entry.status]))];
                                 return (<>{target ? money(target.before) : "—"}</>); } },
{ id: 'column_2', header: "Eligible DM deduction", enableSorting: false, enableHiding: false, meta: { cellClassName: "whitespace-nowrap p-2 text-right", headerClassName: 'text-right' }, cell: ({row}) => { const group=row.original; const target = review.targets.find(item => item.key === group.target_key);
                                const ready = group.entries.filter(entry => entry.status === "ready").length;
                                const skipped = group.entries.length - ready;
                                const statuses = [...new Set(group.entries.map(entry => labels[entry.status]))];
                                 return (<>{target ? money(target.deduction) : "—"}</>); } },
{ id: 'column_3', header: "New Tax", enableSorting: false, enableHiding: false, meta: { cellClassName: "whitespace-nowrap p-2 text-right font-semibold", headerClassName: 'text-right' }, cell: ({row}) => { const group=row.original; const target = review.targets.find(item => item.key === group.target_key);
                                const ready = group.entries.filter(entry => entry.status === "ready").length;
                                const skipped = group.entries.length - ready;
                                const statuses = [...new Set(group.entries.map(entry => labels[entry.status]))];
                                 return (<>{target ? money(target.after) : "—"}</>); } }]} /></div>
                        <p className="text-xs text-slate-600">Amounts in PHP. Only eligible entries affect New Tax. Unavailable balances are shown as —.</p>
                        <p className="text-sm text-slate-600">Tax Withheld and Income Payment will both be reduced. Income Payment deduction = eligible DM deduction ÷ (rate / 100).</p>
                        {(review.negative_balance_warnings || []).length > 0 && <div role="alert" className="space-y-2 rounded-lg bg-amber-50 p-4 text-sm text-amber-950"><p className="font-semibold">Negative balance after DM deduction</p>{review.negative_balance_warnings.map((warning, i) => <p key={i}>{warning}</p>)}</div>}
                        {(review.bir_warnings || []).length > 0 && <div role="alert" className="space-y-2 rounded-lg bg-amber-50 p-4 text-sm text-amber-950"><p className="font-semibold">Needs attention before DAT generation</p><p>Saving these deductions does not resolve DAT validation issues.</p>{review.bir_warnings.map((warning, i) => <p key={i}>{warning}</p>)}</div>}
                        <p className="text-xs text-slate-600">{review.excluded_count} PV rows excluded (Debit PHP {money(review.excluded_debit)}); not part of DM totals.</p>
                        {review.token ? <div className="space-y-3 rounded-lg border p-3 text-sm">
                            <p className="font-semibold">{review.summary.eligible_count} DM entries will be applied; {review.summary.skipped_count} entries will be skipped. Eligible deduction: PHP {money(review.summary.eligible_total)}.</p>
                            {review.summary.skipped_count > 0 && <label className="flex items-start gap-2"><input type="checkbox" checked={ackSkipped} disabled={busy} onChange={event => setAckSkipped(event.target.checked)} /><span>I understand that skipped entries will not be saved as deductions. Apply eligible DM only.</span></label>}
                            {review.warnings.length > 0 && <label className="flex items-start gap-2"><input type="checkbox" checked={ackWarnings} disabled={busy} onChange={event => setAckWarnings(event.target.checked)} /><span>I reviewed the warnings and confirm saving these amounts, including any negative balances.</span></label>}
                        </div> : review.summary.eligible_count === 0 ? <p role="status" className="font-medium">No eligible DM entries to apply. Review the supplier details and correct unresolved records before uploading again.</p> : null}
                    </>}
                    {errors.map((error, index) => <p role="alert" key={index} className="text-sm text-red-700">{error}</p>)}
                </div>
                <div className="flex shrink-0 flex-wrap justify-end gap-2 border-t pt-3">
                    <Button type="button" variant="outline" disabled={busy} onClick={close}>{review && !review.token ? "Close" : "Cancel"}</Button>
                    {review ? (review.apply_available && review.token && <Button type="button" disabled={busy || !canConfirm} onClick={apply}>{busy ? "Saving…" : review.summary.skipped_count ? "Apply eligible DM only" : "Confirm Deduction"}</Button>)
                        : <Button type="button" disabled={busy} onClick={upload}>{busy ? "Reading…" : "Upload"}</Button>}
                </div>
            </DialogContent>
        </Dialog>
    </section>;
}
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
    const [acknowledged, setAcknowledged] = useState(false);
    const [result, setResult] = useState(null);

    const select = (selected) => {
        if (busy || !selected) return;
        setErrors([]); setResult(null); setReview(null); setAcknowledged(false);
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
            if (!Array.isArray(response.data?.targets) || !Array.isArray(response.data?.issues)) {
                setErrors(["Unexpected server response. Refresh the page and sign in again before retrying."]); return;
            }
            setReview(response.data); setAcknowledged(false);
        } catch (error) { fail(error); } finally { setBusy(false); }
    };
    const apply = async () => {
        setBusy(true); setErrors([]);
        try {
            const response = await axios.post("/expanded-dm/apply", { token: review.token, acknowledge_warnings: acknowledged }, { headers: { Accept: "application/json" } });
            setResult(response.data.message); toast.success(response.data.message);
            setOpen(false); setFile(null); setReview(null);
            if (input.current) input.current.value = "";
            router.reload({ only: ["flash"] });
        } catch (error) { fail(error); } finally { setBusy(false); }
    };

    return <section className="space-y-4 rounded-xl border border-emerald-200 bg-white p-6">
        <h2 className="text-lg font-semibold">(DM) Expanded Uploader</h2>
        <p className="text-sm text-slate-600">Upload ACCOUNT DETAILS to deduct DM Debit from existing Tax Withheld totals. Income Payment stays unchanged. Select the company and reporting month above.</p>
        {scope.report_type !== "quarterly" ? <p className="text-sm text-amber-800">DM adjustments currently support quarterly records for one reporting month. Select Quarterly above.</p> : <>
            <input ref={input} type="file" accept=".xlsx,.xls" className="hidden" onChange={(event) => { select(event.target.files?.[0]); event.target.value = ""; }} />
            <div className="rounded-lg border-2 border-dashed border-emerald-200 p-8 text-center" onDragOver={(event) => event.preventDefault()} onDrop={(event) => { event.preventDefault(); select(event.dataTransfer.files?.[0]); }}>
                <p className="mb-3 text-sm">Drag &amp; drop ACCOUNT DETAILS here, or choose a file (.xlsx, .xls; max 10MB).</p>
                <Button type="button" disabled={busy} onClick={() => input.current?.click()}>Browse DM File</Button>
            </div>
        </>}
        {result && <p role="status" className="text-sm text-emerald-800">{result} <a className="underline" href="/records/expanded-wtax">View Expanded records</a></p>}
        {!open && errors.map((error, index) => <p role="alert" key={index} className="text-sm text-red-700">{error}</p>)}
        <Dialog open={open} onOpenChange={(value) => { if (!value) close(); }}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{review ? "Review DM deductions" : "Enter the Percentage"}</DialogTitle>
                    <DialogDescription>{file?.name} · Company {context?.withholding_agent_tin}-{context?.withholding_agent_branch_code} · {context?.reporting_month}</DialogDescription>
                </DialogHeader>
                {!review ? <div className="space-y-3">
                    <label htmlFor="dm-percentage" className="text-sm font-medium">Percentage (%)</label>
                    <Input id="dm-percentage" inputMode="decimal" value={percentage} disabled={busy} onChange={(event) => setPercentage(event.target.value)} placeholder="Example: 1" />
                    <p className="text-sm text-slate-600">The percentage must match the account rate in your workbook.</p>
                </div> : <div className="space-y-4">
                    <p className="text-sm">{review.dm_count} DM rows · Total deduction ₱{money(review.total_deduction)} · {review.excluded_count} PV rows excluded (Debit ₱{money(review.excluded_debit)}).</p>
                    {!review.apply_available && <p role="status" className="text-sm text-slate-600">Preview only. No adjustments have been saved.</p>}
                    <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr>{["Supplier / TIN / ATC", "Before", "Deduction", "After"].map((label) => <th key={label} className="p-2">{label}</th>)}</tr></thead>
                        <tbody>{review.targets.map((target, index) => <tr key={index} className="border-t">
                            <td className="p-2"><p>{target.supplier}</p><p className="text-xs text-slate-500">{target.tin} · {target.atc} · {target.rate}% · {target.source_count} source rows</p><ul className="mt-2 text-xs">{target.items.map((item) => <li key={item.reference}>Row {item.row}: {item.reference} · {item.supplier} · ₱{money(item.debit_cents / 100)}</li>)}</ul></td>
                            <td className="p-2">{money(target.before)}</td><td className="p-2">{money(target.deduction)}</td><td className="p-2 font-semibold">{money(target.after)}</td>
                        </tr>)}</tbody></table></div>
                    {review.issues.map((issue, index) => <p key={index} role="alert" className="text-sm text-red-700">{issue}</p>)}
                    <p className="text-sm text-slate-600">The DM total is deducted once from each matched supplier's Tax Withheld. Income Payment stays unchanged.</p>
                    {review.warnings.length > 0 && <div className="space-y-2 rounded-lg bg-amber-50 p-4 text-sm text-amber-950">
                        <p className="font-semibold">Resulting BIR validation issues — DAT generation may be blocked</p>
                        {review.warnings.map((warning, index) => <p key={index}>{warning}</p>)}
                        <label className="flex items-start gap-2"><input type="checkbox" checked={acknowledged} disabled={busy} onChange={(event) => setAcknowledged(event.target.checked)} />I understand these validation issues and want to apply the Tax Withheld deduction.</label>
                    </div>}
                </div>}
                {errors.map((error, index) => <p role="alert" key={index} className="text-sm text-red-700">{error}</p>)}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" disabled={busy} onClick={close}>Cancel</Button>
                    {review ? <Button type="button" disabled={busy || !review.apply_available || !review.token || (review.warnings.length > 0 && !acknowledged)} onClick={apply}>{busy ? "Applying…" : "Apply Deductions"}</Button>
                        : <Button type="button" disabled={busy} onClick={upload}>{busy ? "Reading…" : "Upload"}</Button>}
                </div>
            </DialogContent>
        </Dialog>
    </section>;
}

import { Input } from "@/Components/ui/input";

export default function PurchaseCompanySelect({ companies = [], tin = "", branch = "", onChange, error, disabled = false }) {
    return (
        <div className="space-y-2">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <label className="space-y-2 text-sm text-slate-700">
                    <span>Known Company <span className="text-red-500">*</span></span>
                    <select aria-label="Purchase filing company" disabled={disabled} value={tin ? `${tin}|${branch}` : ""}
                        onChange={(event) => onChange(event.target.value)}
                        className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm">
                        <option value="">Select company</option>
                        {companies.map((company) => (
                            <option key={`${company.tin}|${company.branch_code}`} value={`${company.tin}|${company.branch_code}`}>
                                {company.name} ({company.tin}-{company.branch_code}){company.is_active === false ? " - Inactive" : ""}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="space-y-2 text-sm text-slate-700"><span>Company TIN</span><Input aria-label="Purchase company TIN" value={tin} readOnly /></label>
                <label className="space-y-2 text-sm text-slate-700"><span>Branch Code</span><Input aria-label="Purchase branch code" value={branch} readOnly /></label>
            </div>
            {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
        </div>
    );
}

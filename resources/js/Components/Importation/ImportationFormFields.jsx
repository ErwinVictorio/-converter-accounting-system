import { useEffect } from "react";

import { Input } from "@/Components/ui/input";

/**
 * The importation entry fields, shared by the add form on /importation and the
 * edit dialog on Record > Importation Records.
 *
 * Both screens key the same paperwork and must derive the same amounts, so the
 * field list, the two read-only derived boxes and the VAT formula live here
 * rather than being copied per screen. Nothing about the stored columns or the
 * DAT layout changes -- this is the same markup the single Importation page had.
 */

// The reporting month is always the one just closed: filing in August 2026
// covers July 2026.
export const previousMonth = () => {
    const now = new Date();
    const previous = new Date(now.getFullYear(), now.getMonth() - 1, 1);

    return `${previous.getFullYear()}-${String(previous.getMonth() + 1).padStart(2, "0")}`;
};

// The entry screen mirrors the customs paperwork: users key total landed cost,
// and "all charges before release" + "taxable goods" are derived from it. Those
// two are shown read-only here and computed server-side, never posted.
export const defaultValues = {
    tax_month: previousMonth(),
    import_entry_no: "",
    assessment_date: "",
    supplier: "",
    importation_date: "",
    country: "",
    total_landed_cost: "",
    dutiable_value: "0",
    exempt: "0",
    vat_rate: "12",
    vat_payable: "0.00",
    or_number: "000",
    payment_date: "",
};

export const money = (value) =>
    Number(value ?? 0).toLocaleString("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

// Same two formulas the controller uses; null while the inputs are incomplete.
export const derive = (landedRaw, subtractRaw) => {
    const landed = Number(landedRaw);
    const subtract = Number(subtractRaw ?? 0);

    if (String(landedRaw ?? "").trim() === "" || Number.isNaN(landed) || Number.isNaN(subtract)) {
        return null;
    }

    return Math.round((landed - subtract) * 100) / 100;
};

// VAT is taxable goods x VAT rate, never keyed. Installed on both the add and
// the edit form, so re-opening a stored row also refreshes its VAT.
export function useComputedVat(watch, setValue) {
    const landed = watch("total_landed_cost");
    const exempt = watch("exempt");
    const vatRate = watch("vat_rate");

    useEffect(() => {
        const taxable = derive(landed, exempt);
        const rate = Number(vatRate);

        if (taxable === null || String(vatRate ?? "").trim() === "" || Number.isNaN(rate)) {
            return;
        }

        setValue("vat_payable", (Math.round(taxable * rate) / 100).toFixed(2), {
            shouldValidate: false,
        });
    }, [landed, exempt, vatRate, setValue]);
}

const renderField = (
    field,
    label,
    { type = "text", placeholder = "", step, suffix, readOnly = false, hint, onValueChange } = {},
    fieldErrors,
    fieldRegister
) => {
    const registration = fieldRegister(field);

    return (
        <div className="space-y-2" key={field}>
            <label className={`text-sm font-medium ${readOnly ? "text-slate-500" : "text-slate-700"}`}>
                {label} {!readOnly && <span className="text-red-500">*</span>}
            </label>
            <div className="flex items-center gap-2">
                <Input
                    type={type}
                    step={step}
                    placeholder={placeholder}
                    readOnly={readOnly}
                    tabIndex={readOnly ? -1 : undefined}
                    {...registration}
                    onChange={(event) => {
                        registration.onChange(event);
                        onValueChange?.(event.target.value);
                    }}
                    className={`${readOnly ? "bg-slate-100 text-slate-600 cursor-not-allowed" : ""} ${
                        fieldErrors[field] ? "border-red-500 focus-visible:ring-red-500" : ""
                    }`}
                />
                {suffix && <span className="text-sm text-slate-500">{suffix}</span>}
            </div>
            {hint && <p className="text-xs text-slate-400">{hint}</p>}
            {fieldErrors[field] && (
                <p className="text-xs text-red-500 font-medium">{fieldErrors[field].message}</p>
            )}
        </div>
    );
};

// Read-only twin of renderField for the two amounts the system computes --
// the greyed boxes on the old entry screen.
const renderDerivedField = (label, value, formula) => (
    <div className="space-y-2">
        <label className="text-sm font-medium text-slate-500">{label}</label>
        <Input
            readOnly
            tabIndex={-1}
            value={value === null ? "" : money(value)}
            placeholder="0.00"
            className={`bg-slate-100 text-slate-600 cursor-not-allowed ${
                value !== null && value < 0 ? "text-red-600 font-medium" : ""
            }`}
        />
        <p className="text-xs text-slate-400">{formula}</p>
    </div>
);

/**
 * Field order and wording follow the old Importation Data Entry Screen.
 * Sequence number is intentionally absent -- it is assigned server-side.
 */
export default function ImportationFormFields({ errors, register, watch, setValue }) {
    const landed = watch("total_landed_cost");
    const charges = derive(landed, watch("dutiable_value"));
    const taxableGoods = derive(landed, watch("exempt"));

    const amount = { type: "number", step: "0.01", placeholder: "0.00" };

    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
                {renderField("tax_month", "Tax Month", { type: "month" }, errors, register)}
                {renderField("import_entry_no", "Import Entry No.", { placeholder: "e.g. C-12345" }, errors, register)}
                {renderField("supplier", "Name of Seller", { placeholder: "Foreign supplier name" }, errors, register)}
                {renderField("assessment_date", "Assessment / Release Date", { type: "date" }, errors, register)}
                {renderField("importation_date", "Date of Importation", { type: "date" }, errors, register)}
                {renderField("country", "Country of Origin", { placeholder: "e.g. CHINA" }, errors, register)}
            </div>

            <div className="rounded-lg border border-slate-100 bg-slate-50/40 p-4">
                <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
                    {renderField("vat_rate", "VAT rate", { ...amount, placeholder: "12", suffix: "%" }, errors, register)}
                    {renderField(
                        "total_landed_cost",
                        "Total Landed Cost",
                        {
                            ...amount,
                            // Dutiable value tracks the landed cost. Editing it afterwards is
                            // still allowed -- that is what makes charges non-zero.
                            onValueChange: (value) =>
                                setValue("dutiable_value", value, { shouldValidate: false }),
                        },
                        errors,
                        register
                    )}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="grid grid-cols-1 gap-5 rounded-lg border border-slate-100 bg-slate-50/40 p-4 sm:grid-cols-2">
                    {renderField("dutiable_value", "Dutiable Value", amount, errors, register)}
                    {renderDerivedField(
                        "All Charges Before Release from Custom's Custody",
                        charges,
                        "Total Landed Cost − Dutiable Value"
                    )}
                </div>

                <div className="grid grid-cols-1 gap-5 rounded-lg border border-slate-100 bg-slate-50/40 p-4 sm:grid-cols-2">
                    {renderField("exempt", "Exempt", amount, errors, register)}
                    {renderDerivedField("Taxable Goods", taxableGoods, "Total Landed Cost − Exempt")}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
                {renderField("or_number", "OR Number", { placeholder: "e.g. 987654" }, errors, register)}
                {renderField("payment_date", "Date of VAT Payment", { type: "date" }, errors, register)}
                {renderField(
                    "vat_payable",
                    "VAT",
                    { ...amount, readOnly: true, hint: "Taxable Goods × VAT rate" },
                    errors,
                    register
                )}
            </div>
        </div>
    );
}


import { useEffect, useState } from "react";
import { AlertCircle, ChevronDown, Copy, Loader2 } from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";

const isBlank = (value) => value === null || value === undefined
    || (typeof value === "string" && value.trim() === "")
    || (Array.isArray(value) && value.length === 0);

const allFields = (sections) => (sections || []).flatMap((section) => section.fields || []);
const copyableFields = new Set([
    "tin", "tin_number", "customer_tin", "payee_tin", "withholding_agent_tin",
    "document_no", "document_refs", "import_entry_no", "or_number",
]);
const amountHelp = {
    purchase: {
        display_calculated_total: "Total = (Purchase Local + Services + Others) / 0.12",
    },
    importation: {
        total_landed_cost: "Total Landed Cost = Dutiable Value + Charges",
    },
};

async function copyField(field) {
    try {
        await navigator.clipboard.writeText(String(field.value));
        toast.success(`${field.label} copied.`);
    } catch {
        toast.error("Copy is unavailable. Select the value and copy it manually.");
    }
}

// Keep technical metadata available to the application, but out of the record review.
const internalFields = new Set([
    "id",
    "vat_input_id",
    "created_at",
    "updated_at",
    "name_key",
    "stored_is_broker",
    "label",
]);

const sourceTitle = (source, index) => {
    const fields = (source.sections || []).flatMap((section) => section.fields || []);
    const documentNumber = fields.find((field) => field.key === "document_no")?.value;

    return isBlank(documentNumber) ? `Source Record ${index + 1}` : String(documentNumber);
};

const decimal = (value) =>
    new Intl.NumberFormat("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value) || 0);

const titleCase = (value) =>
    String(value)
        .replace(/[_-]+/g, " ")
        .replace(/\b\w/g, (letter) => letter.toUpperCase());

const dateValue = (value, includeTime = false) => {
    if (isBlank(value)) return "-";

    const source = String(value);
    const date = new Date(includeTime ? source.replace(" ", "T") : `${source.slice(0, 10)}T00:00:00`);

    if (Number.isNaN(date.getTime())) return source;

    return date.toLocaleString("en-US", includeTime
        ? { year: "numeric", month: "short", day: "numeric", hour: "numeric", minute: "2-digit" }
        : { year: "numeric", month: "short", day: "numeric" });
};

function FieldValue({ field }) {
    const { type = "text", value } = field;

    if (type === "boolean") {
        return (
            <Badge className={value
                ? "border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-50"
                : "border-slate-200 bg-slate-100 text-slate-600 hover:bg-slate-100"}
            >
                {value ? "Yes" : "No"}
            </Badge>
        );
    }

    if (type === "badge" && !isBlank(value)) {
        return (
            <Badge className="border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-50">
                {titleCase(value)}
            </Badge>
        );
    }

    if (type === "money") return <span className="font-mono tabular-nums">{decimal(value)}</span>;
    if (type === "percentage") return <span className="font-mono tabular-nums">{decimal(value)}%</span>;
    if (type === "date") return <span>{dateValue(value)}</span>;
    if (type === "datetime") return <span>{dateValue(value, true)}</span>;

    if (Array.isArray(value)) {
        if (value.length === 0) return <span className="text-slate-400">-</span>;

        return (
            <ul className="space-y-1">
                {value.map((item, index) => <li key={`${field.key}-${index}`}>{String(item)}</li>)}
            </ul>
        );
    }

    return (
        <span className={`${type === "identifier" ? "font-mono text-xs" : ""} ${isBlank(value) ? "text-slate-400" : ""} whitespace-pre-wrap break-words`}>
            {isBlank(value) ? "-" : String(value)}
        </span>
    );
}

function ViewInfoSection({ section, resourceType }) {
    const fields = (section.fields || []).filter((field) => !internalFields.has(field.key)
        && !(resourceType === "purchase" && ["total", "total_purchases"].includes(field.key))
        && field.key !== "validation_errors" && !isBlank(field.value));

    if (fields.length === 0) return null;

    return (
        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h3 className="border-b border-slate-100 bg-slate-50/80 px-4 py-3 text-sm font-semibold text-slate-800">
                {section.title === "Tax and System Information" ? "Tax Information" : section.title}
            </h3>
            <dl className="grid grid-cols-1 sm:grid-cols-2">
                {fields.map((field) => (
                    <div key={field.key} className="border-b border-slate-100 px-4 py-3 last:border-b-0 sm:[&:nth-last-child(-n+2)]:border-b-0">
                        <dt className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                            {resourceType === "purchase" && field.key === "display_calculated_total" ? "Total" : field.label}
                        </dt>
                        <dd className="min-w-0 text-sm text-slate-900">
                            <div className="flex items-start gap-2">
                                <div className="min-w-0 flex-1 break-words"><FieldValue field={field} /></div>
                                {copyableFields.has(field.key) && (
                                    <Button type="button" variant="ghost" size="sm" className="h-7 shrink-0 px-2"
                                        aria-label={`Copy ${field.label}`} title={`Copy ${field.label}`}
                                        onClick={() => copyField(field)}>
                                        <Copy className="h-3.5 w-3.5" />
                                    </Button>
                                )}
                            </div>
                            {amountHelp[resourceType]?.[field.key] && (
                                <p className="mt-1 whitespace-pre-line text-xs leading-relaxed text-slate-500">{amountHelp[resourceType][field.key]}</p>
                            )}
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

export default function ViewInfoDialog({
    open,
    onOpenChange,
    resourceType,
    recordId,
    period = "",
    fallbackTitle = "Record Information",
    fallbackSubtitle = "",
}) {
    const [details, setDetails] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState("");
    const [requestVersion, setRequestVersion] = useState(0);
    const [sourceSearch, setSourceSearch] = useState("");

    const fields = allFields(details?.sections);
    const summaryKeys = new Set([
        "tin", "tin_number", "customer_tin", "payee_tin", "period_scope",
        "reporting_period", "tax_month", "date_uploaded", "status", "is_active", "is_adjusted",
    ]);
    const summaryFields = fields.filter((field) => summaryKeys.has(field.key) && !isBlank(field.value));
    const issues = fields.find((field) => field.key === "validation_errors")?.value || [];
    const sources = (details?.source_records || []).map((source, index) => ({ source, index }));
    const sourceSearchEnabled = resourceType !== "expanded-wtax";
    const searchTerm = sourceSearch.trim().toLowerCase();
    const visibleSources = !sourceSearchEnabled ? sources : sources.filter(({ source, index }) => {
        const values = allFields(source.sections)
            .filter((field) => !internalFields.has(field.key))
            .map((field) => String(field.value ?? ""));
        return [sourceTitle(source, index), ...values].join(" ").toLowerCase().includes(searchTerm);
    });

    useEffect(() => {
        setSourceSearch("");
        if (!open || !resourceType || recordId === null || recordId === undefined || recordId === "") {
            setDetails(null);
            setError("");
            setLoading(false);
            return undefined;
        }

        let cancelled = false;

        setDetails(null);
        setError("");
        setLoading(true);

        window.axios
            .get(`/view-info/${encodeURIComponent(resourceType)}/${encodeURIComponent(recordId)}`, {
                params: period ? { period } : {},
            })
            .then((response) => {
                if (!cancelled) setDetails(response.data);
            })
            .catch((requestError) => {
                if (cancelled) return;

                setError(
                    requestError?.response?.data?.message
                    || "The complete record information could not be loaded."
                );
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [open, resourceType, recordId, period, requestVersion]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{details?.title || fallbackTitle}</DialogTitle>
                    <DialogDescription>
                        {details?.subtitle || fallbackSubtitle || "Complete read-only information for the selected row."}
                    </DialogDescription>
                </DialogHeader>

                {loading && (
                    <div className="flex min-h-48 items-center justify-center gap-2 text-slate-500">
                        <Loader2 className="h-5 w-5 animate-spin" />
                        Loading complete information...
                    </div>
                )}

                {!loading && error && (
                    <div className="flex min-h-48 flex-col items-center justify-center gap-3 rounded-xl border border-red-200 bg-red-50 p-6 text-center text-red-700">
                        <AlertCircle className="h-6 w-6" />
                        <p>{error}</p>
                        <Button type="button" variant="outline" onClick={() => setRequestVersion((value) => value + 1)}>
                            Retry
                        </Button>
                    </div>
                )}

                {!loading && !error && details && (
                    <div className="space-y-4">
                        {summaryFields.length > 0 && (
                            <ViewInfoSection section={{ title: "At a Glance", fields: summaryFields }} resourceType={resourceType} />
                        )}
                        {issues.length > 0 && (
                            <section className="rounded-xl border border-amber-200 bg-amber-50 p-4" aria-label="Needs Attention">
                                <h3 className="flex items-center gap-2 font-semibold text-amber-900">
                                    <AlertCircle className="h-4 w-4" /> Needs Attention
                                </h3>
                                <p className="mt-1 text-xs text-amber-900">Review these reported fields before generating the DAT file.</p>
                                <ul className="mt-3 list-disc space-y-2 pl-5 text-sm text-amber-900">
                                    {issues.map((issue, index) => <li key={index}>{issue}</li>)}
                                </ul>
                            </section>
                        )}
                        {(details.sections || []).map((section) => (
                            <ViewInfoSection key={section.title} section={section} resourceType={resourceType} />
                        ))}

                        {(details.source_records || []).length > 0 && (
                            <section className="space-y-3">
                                <div>
                                    <h3 className="font-semibold text-slate-900">Source Records</h3>
                                    <p className="text-xs text-slate-500">
                                        Original stored rows represented by the consolidated summary above.
                                    </p>
                                </div>

                                {sourceSearchEnabled && (
                                    <Input value={sourceSearch} onChange={(event) => setSourceSearch(event.target.value)}
                                        aria-label="Search source records" placeholder="Search document, date, name, or amount..." />
                                )}
                                <p className="text-xs text-slate-500" role="status">
                                    {sourceSearchEnabled ? `${visibleSources.length} of ${sources.length}` : sources.length} source records
                                </p>
                                {visibleSources.length === 0 && <p className="py-4 text-sm text-slate-500">No source records match your search.</p>}

                                {visibleSources.map(({ source, index }) => (
                                    <details key={source.id ?? index} className="group rounded-xl border border-slate-200 bg-slate-50/40">
                                        <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 font-medium text-slate-800">
                                            <span className="min-w-0 break-words">
                                                {sourceTitle(source, index)}
                                                <span className="mt-1 block text-xs font-normal text-slate-500">
                                                    {allFields(source.sections)
                                                        .filter((field) => ["document_date", "reporting_period", "net_amount", "tax_withheld"].includes(field.key) && !isBlank(field.value))
                                                        .map((field) => `${field.label}: ${field.type === "money" ? decimal(field.value) : dateValue(field.value)}`)
                                                        .join(" · ")}
                                                </span>
                                            </span>
                                            <ChevronDown className="h-4 w-4 shrink-0 transition-transform group-open:rotate-180" />
                                        </summary>
                                        <div className="space-y-3 border-t border-slate-200 p-3">
                                            {(source.sections || []).map((section) => (
                                                <ViewInfoSection key={`${source.id}-${section.title}`} section={section} resourceType={resourceType} />
                                            ))}
                                        </div>
                                    </details>
                                ))}
                            </section>
                        )}
                    </div>
                )}

                <DialogFooter showCloseButton />
            </DialogContent>
        </Dialog>
    );
}

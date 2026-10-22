import RecordsSummarySection from "@/Components/Records/RecordsSummarySection";
import { formatPeso } from "@/Components/Records/format";
import { Coins } from "lucide-react";
import { currentTableQuery } from '@/lib/dataTable';
import { InertiaDataTable } from '@/Components/DataTable';
import { useEffect, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import { AlertTriangle, Eye, Link2, Loader2, Pencil, Plus, Trash2, X } from "lucide-react";

import MainLayout from "@/Layouts/MainLayout";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
import { Input } from "@/Components/ui/input";
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import BirVendorDialog from "@/Components/Records/BirVendorDialog";
import RecordPeriodFilter from "@/Components/Records/RecordPeriodFilter";
import RecordSearchInput from "@/Components/Records/RecordSearchInput";
import RecordTableShell from "@/Components/Records/RecordTableShell";
import ViewInfoDialog from "@/Components/Records/ViewInfoDialog";
import { formatCurrency } from "@/Components/Records/format";

// A DAT detail line needs 9 or 12 digits, dashed or not.
const BIR_TIN = /^(\d{9}|\d{12}|\d{3}-\d{3}-\d{3}|\d{3}-\d{3}-\d{3}-\d{3})$/;

const adjustmentAmountFields = [
    { name: "purchase_imported", label: "Purchase Imported" },
    { name: "purchase_local", label: "Purchase Local" },
    { name: "services", label: "Services" },
    { name: "others", label: "Others" },
];

const allocationRow = (amounts = {}) => ({
    source_vat_input_id: "",
    purchase_imported: amounts.purchase_imported || "0.00",
    purchase_local: amounts.purchase_local || "0.00",
    services: amounts.services || "0.00",
    others: amounts.others || "0.00",
});

function PurchaseRecords() {
    const { purchaseSummary, flash, vatInputs, months = [], filters = {}, purchaseCompanies = [], companyFilter = "" } = usePage().props;
    const [selectedBirRecord, setSelectedBirRecord] = useState(null);
    const [selectedInfoRecord, setSelectedInfoRecord] = useState(null);
    const [deleteRecord, setDeleteRecord] = useState(null);
    const [deleteContext, setDeleteContext] = useState(null);
    const [deleteContextStatus, setDeleteContextStatus] = useState("idle");
    const [allocations, setAllocations] = useState([]);
    const [deleting, setDeleting] = useState(false);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.warning) toast.warning(flash.warning);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    const closeDeleteDialog = () => {
        if (deleting) return;

        setDeleteRecord(null);
        setDeleteContext(null);
        setDeleteContextStatus("idle");
        setAllocations([]);
    };

    const openDeleteDialog = (record) => {
        setDeleteRecord(record);
        setDeleteContext(null);
        setDeleteContextStatus("loading");
        setAllocations([]);

        window.axios
            .get(`/records/${record.id}/adjustment-delete-context`)
            .then((response) => {
                const context = response.data;

                setDeleteContext(context);
                setDeleteContextStatus("ready");

                if (context.status === "needs_link") {
                    setAllocations([allocationRow(context.unresolved_amounts)]);
                }
            })
            .catch((error) => {
                const message = error.response?.data?.message || "Unable to prepare this adjusted record for deletion.";

                setDeleteContextStatus("error");
                toast.error(message);
            });
    };

    const updateAllocation = (index, field, value) => {
        setAllocations((current) =>
            current.map((allocation, allocationIndex) =>
                allocationIndex === index ? { ...allocation, [field]: value } : allocation
            )
        );
    };

    const addAllocation = () => {
        setAllocations((current) => [...current, allocationRow()]);
    };

    const removeAllocation = (index) => {
        setAllocations((current) => current.filter((_, allocationIndex) => allocationIndex !== index));
    };

    const handleUndoAndDelete = () => {
        if (!deleteRecord || !deleteContext || deleteContext.status === "invalid") return;

        const data = deleteContext.status === "needs_link" ? { allocations } : {};

        router.visit(`/records/${deleteRecord.id}`, {
            method: "delete",
            data,
            preserveScroll: true,
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onSuccess: (page) => {
                if (page.props.flash?.error) return;

                setDeleteRecord(null);
                setDeleteContext(null);
                setDeleteContextStatus("idle");
                setAllocations([]);
            },
            onError: (errors) => {
                const message = Object.values(errors || {})[0];
                if (message) toast.error(message);
            },
        });
    };

    return (
        <section className="w-full max-w-full space-y-6 overflow-hidden">
            <RecordsSummarySection title="Purchase Summary"
                description="Amounts based on current filters."
                empty={purchaseSummary.record_count === 0}
                metrics={[
                    { key: "purchase_local_total", title: "Purchase Local", value: formatPeso(purchaseSummary.purchase_local_total), icon: Coins, tone: "blue" },
                    { key: "services_total", title: "Services", value: formatPeso(purchaseSummary.services_total), icon: Coins, tone: "emerald" },
                    { key: "others_total", title: "Others", value: formatPeso(purchaseSummary.others_total), icon: Coins, tone: "amber" },
                ]}
                total={{ title: "Total Purchase Amount", value: formatPeso(purchaseSummary.total_purchase_amount), description: "Across all filtered records." }}
                footer="Amounts follow the table's existing display rules. The total is calculated separately per record."
            />
            <RecordTableShell
                title="Purchase Records"
                description="Purchase records. Unassigned historical rows are excluded from company DAT downloads until ownership is reviewed."
                actions={
                    <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                        <select aria-label="Filter Purchase company" value={companyFilter}
                            className="h-9 rounded-md border border-slate-200 bg-white px-3 text-sm"
                            onChange={(event) => router.get("/records/purchases", currentTableQuery({ ...filters, company: event.target.value }), { preserveScroll: true })}>
                            <option value="">All companies / unassigned</option>
                            <option value="unassigned">Unassigned Purchase records</option>
                            {purchaseCompanies.map((company) => <option key={`${company.tin}|${company.branch_code}`} value={`${company.tin}|${company.branch_code}`}>{company.name} ({company.tin}-{company.branch_code})</option>)}
                        </select>
                        <RecordSearchInput
                            url="/records/purchases"
                            placeholder="Search supplier or TIN..."
                            initialValue={filters.search || ""}
                            params={{ ...(filters.period ? { period: filters.period } : {}), company: companyFilter }}
                        />
                        <RecordPeriodFilter
                            url="/records/purchases"
                            months={months} params={{ company: companyFilter }}
                            initialValue={filters.period || ""}
                            search={filters.search || ""}
                        />
                    </div>
                }
            >
                <InertiaDataTable paginator={vatInputs} tableClassName="min-w-[800px]" emptyState={{ title: 'No records found.' }} columns={[{ id: 'supplier_name', accessorKey: 'supplier_name', header: "Supplier Name", enableSorting: true, enableHiding: false, meta: { label: "Supplier Name", cellClassName: "whitespace-nowrap font-medium text-slate-900", headerClassName: "" }, cell: ({ row }) => { const item = row.original; const isBroker = Number(item.is_broker) === 1;
                                const isImported = Number(item.is_imported) === 1;
                                const isAdjusted = Number(item.is_adjusted) === 1;
                                const hasBirTin = BIR_TIN.test(String(item.tin_number || ""));
                                const hasBirName =
                                    item.vendor_type === "individual"
                                        ? Boolean(item.last_name && item.first_name && item.middle_name)
                                        : Boolean(item.company_name || item.supplier_name);

                                 return (<>
                                            <div className="flex items-center gap-2">
                                                <span>{item.supplier_name}<small className="block font-normal text-slate-500">{item.filing_company_tin ? `${item.filing_company_tin}-${item.filing_company_branch_code}` : "Unassigned / legacy - review company before adjustment"}</small></span>
                                                {isAdjusted && (
                                                    <Badge className="border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-50">
                                                        Adjusted
                                                    </Badge>
                                                )}
                                            </div>
                                        </>); } },
{ id: 'tin_number', accessorKey: 'tin_number', header: "TIN Number", enableSorting: true, enableHiding: true, meta: { label: "TIN Number", cellClassName: "whitespace-nowrap font-mono text-xs text-slate-600", headerClassName: "" }, cell: ({ row }) => { const item = row.original; const isBroker = Number(item.is_broker) === 1;
                                const isImported = Number(item.is_imported) === 1;
                                const isAdjusted = Number(item.is_adjusted) === 1;
                                const hasBirTin = BIR_TIN.test(String(item.tin_number || ""));
                                const hasBirName =
                                    item.vendor_type === "individual"
                                        ? Boolean(item.last_name && item.first_name && item.middle_name)
                                        : Boolean(item.company_name || item.supplier_name);

                                 return (<>
                                            {item.tin_number || "—"}
                                        </>); } },
{ id: 'display_purchase_local_amount', accessorKey: 'display_purchase_local_amount', header: "Purchase Local", enableSorting: true, enableHiding: true, meta: { label: "Purchase Local", cellClassName: "whitespace-nowrap text-right font-mono text-xs text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original; const isBroker = Number(item.is_broker) === 1;
                                const isImported = Number(item.is_imported) === 1;
                                const isAdjusted = Number(item.is_adjusted) === 1;
                                const hasBirTin = BIR_TIN.test(String(item.tin_number || ""));
                                const hasBirName =
                                    item.vendor_type === "individual"
                                        ? Boolean(item.last_name && item.first_name && item.middle_name)
                                        : Boolean(item.company_name || item.supplier_name);

                                 return (<>
                                            {formatCurrency(item.display_purchase_local_amount)}
                                        </>); } },
{ id: 'display_services_amount', accessorKey: 'display_services_amount', header: "Services", enableSorting: true, enableHiding: true, meta: { label: "Services", cellClassName: "whitespace-nowrap text-right font-mono text-xs text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original; const isBroker = Number(item.is_broker) === 1;
                                const isImported = Number(item.is_imported) === 1;
                                const isAdjusted = Number(item.is_adjusted) === 1;
                                const hasBirTin = BIR_TIN.test(String(item.tin_number || ""));
                                const hasBirName =
                                    item.vendor_type === "individual"
                                        ? Boolean(item.last_name && item.first_name && item.middle_name)
                                        : Boolean(item.company_name || item.supplier_name);

                                 return (<>
                                            {formatCurrency(item.display_services_amount)}
                                        </>); } },
{ id: 'display_others_amount', accessorKey: 'display_others_amount', header: "Others", enableSorting: true, enableHiding: true, meta: { label: "Others", cellClassName: "whitespace-nowrap text-right font-mono text-xs text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original; const isBroker = Number(item.is_broker) === 1;
                                const isImported = Number(item.is_imported) === 1;
                                const isAdjusted = Number(item.is_adjusted) === 1;
                                const hasBirTin = BIR_TIN.test(String(item.tin_number || ""));
                                const hasBirName =
                                    item.vendor_type === "individual"
                                        ? Boolean(item.last_name && item.first_name && item.middle_name)
                                        : Boolean(item.company_name || item.supplier_name);

                                 return (<>
                                            {formatCurrency(item.display_others_amount)}
                                        </>); } },
{ id: 'display_calculated_total', accessorKey: 'display_calculated_total', header: "Total", enableSorting: true, enableHiding: true, meta: { label: "Total", cellClassName: "whitespace-nowrap text-right font-mono text-xs font-bold text-slate-900", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original; const isBroker = Number(item.is_broker) === 1;
                                const isImported = Number(item.is_imported) === 1;
                                const isAdjusted = Number(item.is_adjusted) === 1;
                                const hasBirTin = BIR_TIN.test(String(item.tin_number || ""));
                                const hasBirName =
                                    item.vendor_type === "individual"
                                        ? Boolean(item.last_name && item.first_name && item.middle_name)
                                        : Boolean(item.company_name || item.supplier_name);

                                 return (<>
                                            {formatCurrency(item.display_calculated_total)}
                                        </>); } },
{ id: 'actions', accessorKey: 'actions', header: "Actions", enableSorting: false, enableHiding: false, meta: { label: "Actions", cellClassName: "whitespace-nowrap text-right", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original; const isBroker = Number(item.is_broker) === 1;
                                const isImported = Number(item.is_imported) === 1;
                                const isAdjusted = Number(item.is_adjusted) === 1;
                                const hasBirTin = BIR_TIN.test(String(item.tin_number || ""));
                                const hasBirName =
                                    item.vendor_type === "individual"
                                        ? Boolean(item.last_name && item.first_name && item.middle_name)
                                        : Boolean(item.company_name || item.supplier_name);

                                 return (<>
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => setSelectedInfoRecord(item)}
                                                    className="h-8 gap-1.5"
                                                >
                                                    <Eye className="h-3.5 w-3.5" />
                                                    View Info
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant={hasBirTin && hasBirName ? "outline" : "default"}
                                                    size="sm"
                                                    onClick={() => setSelectedBirRecord(item)}
                                                    className="h-8 gap-1.5"
                                                >
                                                    <Pencil className="h-3.5 w-3.5" />
                                                    BIR Info
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={!isBroker || !item.filing_company_tin}
                                                    onClick={() => router.get(`/records/${item.id}/edit`, { return_to: window.location.pathname + window.location.search })}
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
                                                {isAdjusted && (
                                                    <Button
                                                        type="button"
                                                        variant="destructive"
                                                        size="sm"
                                                        disabled={!item.filing_company_tin}
                                                        onClick={() => openDeleteDialog(item)}
                                                        title={
                                                            item.adjustment_history_complete
                                                                ? "Undo and delete adjusted Purchase record"
                                                                : "Link the original broker, then undo and delete"
                                                        }
                                                        className="h-8 gap-1.5"
                                                    >
                                                        {item.adjustment_history_complete ? (
                                                            <Trash2 className="h-3.5 w-3.5" />
                                                        ) : (
                                                            <Link2 className="h-3.5 w-3.5" />
                                                        )}
                                                        {item.adjustment_history_complete ? "Delete" : "Link & Delete"}
                                                    </Button>
                                                )}
                                            </div>
                                        </>); } }]} />
            </RecordTableShell>

            <BirVendorDialog
                record={selectedBirRecord}
                onClose={() => setSelectedBirRecord(null)}
            />
            <ViewInfoDialog
                open={Boolean(selectedInfoRecord)}
                onOpenChange={(open) => !open && setSelectedInfoRecord(null)}
                resourceType="purchase"
                recordId={selectedInfoRecord?.id}
                fallbackTitle="Purchase Information"
                fallbackSubtitle={selectedInfoRecord?.supplier_name}
            />

            <Dialog open={Boolean(deleteRecord)} onOpenChange={(open) => !open && closeDeleteDialog()}>
                <DialogContent className="sm:max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>
                            {deleteContext?.status === "needs_link"
                                ? "Link original broker and delete"
                                : "Undo and delete adjusted record"}
                        </DialogTitle>
                        <DialogDescription>
                            {deleteRecord?.supplier_name}
                            {deleteRecord?.tin_number ? ` - ${deleteRecord.tin_number}` : ""}
                        </DialogDescription>
                    </DialogHeader>

                    {deleteContextStatus === "loading" && (
                        <div className="flex min-h-40 items-center justify-center gap-2 text-slate-500">
                            <Loader2 className="h-5 w-5 animate-spin" />
                            Checking adjustment history...
                        </div>
                    )}

                    {deleteContextStatus === "error" && (
                        <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                            The adjustment history could not be loaded. Close this dialog and try again.
                        </div>
                    )}

                    {deleteContextStatus === "ready" && deleteContext?.status === "invalid" && (
                        <div className="flex gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-red-800">
                            <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
                            <div>
                                <p className="font-semibold">This record cannot be deleted.</p>
                                <p className="mt-1 text-sm">{deleteContext.message}</p>
                            </div>
                        </div>
                    )}

                    {deleteContextStatus === "ready" && deleteContext?.status === "ready" && (
                        <>
                            <div className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900">
                                <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
                                <div>
                                    <p className="font-semibold">The amounts below will return to their original broker records.</p>
                                    <p className="mt-1 text-sm">The adjusted row will be deleted only after the full restoration succeeds.</p>
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {adjustmentAmountFields.map((field) => (
                                    <div key={field.name} className="rounded-lg border bg-slate-50 p-3">
                                        <p className="text-xs text-slate-500">{field.label}</p>
                                        <p className="mt-1 font-mono font-semibold text-slate-900">
                                            {formatCurrency(deleteContext.target_amounts?.[field.name])}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </>
                    )}

                    {deleteContextStatus === "ready" && deleteContext?.status === "needs_link" && (
                        <div className="space-y-5">
                            <div className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900">
                                <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
                                <div>
                                    <p className="font-semibold">This older adjustment has no complete source history.</p>
                                    <p className="mt-1 text-sm">
                                        Select the original broker record and allocate every untracked amount. Nothing will be deleted unless the totals match exactly.
                                    </p>
                                </div>
                            </div>

                            <div>
                                <p className="mb-2 text-sm font-semibold text-slate-800">Amounts that still need a source</p>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    {adjustmentAmountFields.map((field) => (
                                        <div key={field.name} className="rounded-lg border bg-slate-50 p-3">
                                            <p className="text-xs text-slate-500">{field.label}</p>
                                            <p className="mt-1 font-mono font-semibold text-slate-900">
                                                {formatCurrency(deleteContext.unresolved_amounts?.[field.name])}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {deleteContext.candidates?.length > 0 ? (
                                <div className="space-y-4">
                                    {allocations.map((allocation, index) => (
                                        <div key={index} className="rounded-lg border border-slate-200 p-4">
                                            <div className="mb-4 flex items-center gap-2">
                                                <select
                                                    value={allocation.source_vat_input_id}
                                                    onChange={(event) => updateAllocation(index, "source_vat_input_id", event.target.value)}
                                                    className="flex h-9 min-w-0 flex-1 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                                >
                                                    <option value="">Select original broker...</option>
                                                    {deleteContext.candidates.map((candidate) => (
                                                        <option key={candidate.id} value={candidate.id}>
                                                            {candidate.supplier_name} - {candidate.tin_number || "No TIN"}
                                                        </option>
                                                    ))}
                                                </select>
                                                {allocations.length > 1 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        onClick={() => removeAllocation(index)}
                                                        title="Remove broker allocation"
                                                        className="text-red-600 hover:bg-red-50 hover:text-red-700"
                                                    >
                                                        <X className="h-4 w-4" />
                                                    </Button>
                                                )}
                                            </div>

                                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                                {adjustmentAmountFields.map((field) => (
                                                    <div key={field.name} className="space-y-1.5">
                                                        <label className="text-xs font-medium text-slate-600">{field.label}</label>
                                                        <Input
                                                            type="number"
                                                            min="0"
                                                            step="0.01"
                                                            value={allocation[field.name]}
                                                            onChange={(event) => updateAllocation(index, field.name, event.target.value)}
                                                        />
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ))}

                                    <Button type="button" variant="outline" size="sm" onClick={addAllocation} className="gap-1.5">
                                        <Plus className="h-3.5 w-3.5" />
                                        Add another broker
                                    </Button>
                                </div>
                            ) : (
                                <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                                    No eligible broker record was found for the same month and Imported status. Keep this row and reconcile its source first.
                                </div>
                            )}
                        </div>
                    )}

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline" disabled={deleting}>Cancel</Button>
                        </DialogClose>
                        {deleteContextStatus === "ready" && deleteContext?.status !== "invalid" && (
                            <Button
                                type="button"
                                variant="destructive"
                                disabled={deleting || (deleteContext.status === "needs_link" && !deleteContext.candidates?.length)}
                                onClick={handleUndoAndDelete}
                                className="gap-1.5"
                            >
                                {deleting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
                                {deleting ? "Restoring..." : "Undo and Delete"}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

PurchaseRecords.layout = (page) => (
    <MainLayout title="Purchase Records">{page}</MainLayout>
);

export default PurchaseRecords;

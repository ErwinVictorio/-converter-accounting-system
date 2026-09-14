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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
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
    const { flash, vatInputs, months = [], filters = {} } = usePage().props;
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
            <RecordTableShell
                title="Purchase Records"
                description="Uploaded VAT input rows. Upload new files under Import Data."
                links={vatInputs?.links}
                actions={
                    <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                        <RecordSearchInput
                            url="/records/purchases"
                            placeholder="Search supplier or TIN..."
                            initialValue={filters.search || ""}
                            params={filters.period ? { period: filters.period } : {}}
                        />
                        <RecordPeriodFilter
                            url="/records/purchases"
                            months={months}
                            initialValue={filters.period || ""}
                            search={filters.search || ""}
                        />
                    </div>
                }
            >
                <Table className="min-w-[800px]">
                    <TableHeader>
                        <TableRow className="bg-slate-50 hover:bg-slate-50">
                            <TableHead className="font-semibold text-slate-700">Supplier Name</TableHead>
                            <TableHead className="font-semibold text-slate-700">TIN Number</TableHead>
                            <TableHead className="font-semibold text-slate-700">Imported</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Purchase Imported</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Purchase Local</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Services</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Others</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Total</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {vatInputs?.data?.length > 0 ? (
                            vatInputs.data.map((item) => {
                                const isBroker = Number(item.is_broker) === 1;
                                const isImported = Number(item.is_imported) === 1;
                                const isAdjusted = Number(item.is_adjusted) === 1;
                                const hasBirTin = BIR_TIN.test(String(item.tin_number || ""));
                                const hasBirName =
                                    item.vendor_type === "individual"
                                        ? Boolean(item.last_name && item.first_name && item.middle_name)
                                        : Boolean(item.company_name || item.supplier_name);

                                return (
                                    <TableRow key={item.id} className="transition-colors hover:bg-slate-50/60">
                                        <TableCell className="whitespace-nowrap font-medium text-slate-900">
                                            <div className="flex items-center gap-2">
                                                <span>{item.supplier_name}</span>
                                                {isAdjusted && (
                                                    <Badge className="border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-50">
                                                        Adjusted
                                                    </Badge>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap font-mono text-xs text-slate-600">
                                            {item.tin_number || "—"}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={isImported ? "default" : "secondary"}
                                                className={
                                                    isImported
                                                        ? "border-amber-200 bg-amber-100 text-amber-800 hover:bg-amber-100"
                                                        : "bg-slate-100 text-slate-700 hover:bg-slate-100"
                                                }
                                            >
                                                {isImported ? "Yes" : "No"}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                            {formatCurrency(item.purchase_imported)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                            {formatCurrency(item.purchase_local)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                            {formatCurrency(item.display_services_amount)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                            {formatCurrency(item.others)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-right font-mono text-xs font-bold text-slate-900">
                                            {formatCurrency(item.display_calculated_total)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-right">
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
                                                {isAdjusted && (
                                                    <Button
                                                        type="button"
                                                        variant="destructive"
                                                        size="sm"
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

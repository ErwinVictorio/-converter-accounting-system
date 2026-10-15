import { InertiaDataTable } from '@/Components/DataTable';
import { useEffect, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { Eye, Loader2, Pencil, Trash2 } from "lucide-react";

import MainLayout from "@/Layouts/MainLayout";
import { Button } from "@/Components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import ImportationFormFields, {
    defaultValues,
    money,
    useComputedVat,
} from "@/Components/Importation/ImportationFormFields";
import RecordSearchInput from "@/Components/Records/RecordSearchInput";
import RecordPeriodFilter from "@/Components/Records/RecordPeriodFilter";
import RecordTableShell from "@/Components/Records/RecordTableShell";
import ViewInfoDialog from "@/Components/Records/ViewInfoDialog";
import { importationSchema } from "@/lib/FormSchema";

/**
 * Record > Importation Records: the stored manual entries.
 *
 * Editing and deleting stay here, next to the rows they act on -- keying a new
 * importation lives on the Importation screen. Both mutators redirect back, so
 * saving from this page returns to this page.
 */
function ImportationRecords() {
    const { flash, entries, months = [], filters = {} } = usePage().props;
    const rows = entries?.data || [];
    const [isUpdating, setIsUpdating] = useState(false);
    const [editingEntry, setEditingEntry] = useState(null);
    const [selectedInfoRecord, setSelectedInfoRecord] = useState(null);

    const {
        register: registerEdit,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        setError: setEditError,
        setValue: setEditValue,
        watch: watchEdit,
        formState: { errors: editErrors },
    } = useForm({
        resolver: zodResolver(importationSchema),
        defaultValues,
    });

    useComputedVat(watchEdit, setEditValue);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    const handleCloseEdit = () => {
        if (isUpdating) return;
        setEditingEntry(null);
        resetEdit(defaultValues);
    };

    const handleOpenEdit = (entry) => {
        setEditingEntry(entry);
        resetEdit({
            tax_month: (entry.tax_month || "").slice(0, 7),
            import_entry_no: entry.import_entry_no || "",
            assessment_date: (entry.assessment_date || "").slice(0, 10),
            supplier: entry.supplier || "",
            importation_date: (entry.importation_date || "").slice(0, 10),
            country: entry.country || "",
            total_landed_cost: String(entry.total_landed_cost ?? "0"),
            dutiable_value: String(entry.dutiable_value ?? "0"),
            exempt: String(entry.exempt ?? "0"),
            vat_rate: String(entry.vat_rate ?? "12"),
            vat_payable: String(entry.vat_payable ?? "0"),
            or_number: entry.or_number || "",
            payment_date: (entry.payment_date || "").slice(0, 10),
        });
    };

    const onEditSubmit = (formData) => {
        if (!editingEntry) return;

        setIsUpdating(true);

        router.put(`/importation/${editingEntry.id}`, formData, {
            preserveScroll: true,
            onSuccess: () => {
                setIsUpdating(false);
                handleCloseEdit();
            },
            onError: (err) => {
                setIsUpdating(false);
                Object.keys(err || {}).forEach((key) => {
                    setEditError(key, { message: err[key] });
                });
            },
        });
    };

    const handleDelete = (id) => {
        if (confirm("Delete this importation entry? The linked DAT record will also be removed.")) {
            router.delete(`/importation/${id}`, { preserveScroll: true });
        }
    };

    return (
        <section className="w-full max-w-full space-y-6 overflow-hidden">
            <RecordTableShell
                title="Importation Records"
                description="Manually keyed importations. Add a new one under Data & Transactions > Importation."
                actions={
                    <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                        <RecordSearchInput
                            url="/records/importations"
                            placeholder="Search any column..."
                            initialValue={filters.search || ""}
                            params={filters.tax_month ? { tax_month: filters.tax_month } : {}}
                        />
                        <RecordPeriodFilter
                            url="/records/importations"
                            months={months}
                            initialValue={filters.tax_month || ""}
                            search={filters.search || ""}
                            queryKey="tax_month"
                        />
                    </div>
                }
            >
                <InertiaDataTable paginator={entries} tableClassName="min-w-[800px]" emptyState={{ title: 'No records found.' }} columns={[{ id: 'sequence_number', accessorKey: 'sequence_number', header: "#", enableSorting: true, enableHiding: false, meta: { label: "#", cellClassName: "py-3 pl-6 text-slate-500", headerClassName: "" }, cell: ({ row }) => { const entry = row.original;  return (<>{entry.sequence_number}</>); } },
{ id: 'tax_month', accessorKey: 'tax_month', header: "Tax Month", enableSorting: true, enableHiding: true, meta: { label: "Tax Month", cellClassName: "whitespace-nowrap text-slate-700", headerClassName: "" }, cell: ({ row }) => { const entry = row.original;  return (<>
                                        {(entry.tax_month || "").slice(0, 7)}
                                    </>); } },
{ id: 'import_entry_no', accessorKey: 'import_entry_no', header: "Import Entry No.", enableSorting: true, enableHiding: true, meta: { label: "Import Entry No.", cellClassName: "whitespace-nowrap font-medium text-slate-900", headerClassName: "" }, cell: ({ row }) => { const entry = row.original;  return (<>
                                        {entry.import_entry_no}
                                    </>); } },
{ id: 'supplier', accessorKey: 'supplier', header: "Name of Seller", enableSorting: true, enableHiding: true, meta: { label: "Name of Seller", cellClassName: "min-w-[200px] text-slate-700", headerClassName: "" }, cell: ({ row }) => { const entry = row.original;  return (<>{entry.supplier}</>); } },
{ id: 'country', accessorKey: 'country', header: "Country", enableSorting: true, enableHiding: true, meta: { label: "Country", cellClassName: "whitespace-nowrap text-slate-600", headerClassName: "" }, cell: ({ row }) => { const entry = row.original;  return (<>{entry.country}</>); } },
{ id: 'total_landed_cost', accessorKey: 'total_landed_cost', header: "Landed Cost", enableSorting: true, enableHiding: true, meta: { label: "Landed Cost", cellClassName: "whitespace-nowrap text-right text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const entry = row.original;  return (<>{money(entry.total_landed_cost)}</>); } },
{ id: 'dutiable_value', accessorKey: 'dutiable_value', header: "Dutiable", enableSorting: true, enableHiding: true, meta: { label: "Dutiable", cellClassName: "whitespace-nowrap text-right text-slate-600", headerClassName: "text-right" }, cell: ({ row }) => { const entry = row.original;  return (<>{money(entry.dutiable_value)}</>); } },
{ id: 'charges', accessorKey: 'charges', header: "Charges", enableSorting: true, enableHiding: true, meta: { label: "Charges", cellClassName: "whitespace-nowrap text-right text-slate-600", headerClassName: "text-right" }, cell: ({ row }) => { const entry = row.original;  return (<>{money(entry.charges)}</>); } },
{ id: 'exempt', accessorKey: 'exempt', header: "Exempt", enableSorting: true, enableHiding: true, meta: { label: "Exempt", cellClassName: "whitespace-nowrap text-right text-slate-600", headerClassName: "text-right" }, cell: ({ row }) => { const entry = row.original;  return (<>{money(entry.exempt)}</>); } },
{ id: 'taxable_goods', accessorKey: 'taxable_goods', header: "Taxable Goods", enableSorting: true, enableHiding: true, meta: { label: "Taxable Goods", cellClassName: "whitespace-nowrap text-right text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const entry = row.original;  return (<>{money(entry.taxable_goods)}</>); } },
{ id: 'vat_rate', accessorKey: 'vat_rate', header: "VAT Rate", enableSorting: true, enableHiding: true, meta: { label: "VAT Rate", cellClassName: "whitespace-nowrap text-right text-slate-600", headerClassName: "text-right" }, cell: ({ row }) => { const entry = row.original;  return (<>{money(entry.vat_rate)}</>); } },
{ id: 'vat_payable', accessorKey: 'vat_payable', header: "VAT", enableSorting: true, enableHiding: true, meta: { label: "VAT", cellClassName: "whitespace-nowrap text-right text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const entry = row.original;  return (<>{money(entry.vat_payable)}</>); } },
{ id: 'or_number', accessorKey: 'or_number', header: "OR No.", enableSorting: true, enableHiding: true, meta: { label: "OR No.", cellClassName: "whitespace-nowrap text-slate-600", headerClassName: "" }, cell: ({ row }) => { const entry = row.original;  return (<>{entry.or_number}</>); } },
{ id: 'payment_date', accessorKey: 'payment_date', header: "VAT Payment", enableSorting: true, enableHiding: true, meta: { label: "VAT Payment", cellClassName: "whitespace-nowrap text-slate-600", headerClassName: "" }, cell: ({ row }) => { const entry = row.original;  return (<>
                                        {(entry.payment_date || "").slice(0, 10)}
                                    </>); } },
{ id: 'actions', accessorKey: 'actions', header: "Actions", enableSorting: false, enableHiding: false, meta: { label: "Actions", cellClassName: "whitespace-nowrap py-3 pr-6 text-right", headerClassName: "text-right" }, cell: ({ row }) => { const entry = row.original;  return (<>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setSelectedInfoRecord(entry)}
                                            className="mr-1 h-8 gap-1.5"
                                        >
                                            <Eye className="h-3.5 w-3.5" />
                                            View Info
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => handleOpenEdit(entry)}
                                            className="h-8 w-8 cursor-pointer rounded-lg text-blue-600 hover:bg-blue-50 hover:text-blue-700"
                                        >
                                            <Pencil className="h-4 w-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => handleDelete(entry.id)}
                                            className="h-8 w-8 cursor-pointer rounded-lg text-red-500 hover:bg-red-50 hover:text-red-600"
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </>); } }]} />
            </RecordTableShell>

            <Dialog open={Boolean(editingEntry)} onOpenChange={(open) => !open && handleCloseEdit()}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>Edit Importation Entry</DialogTitle>
                        <DialogDescription>
                            Update the manual importation record. Saving also re-syncs the linked purchase DAT row.
                        </DialogDescription>
                    </DialogHeader>

                    <form id="edit-importation-form" onSubmit={handleEditSubmit(onEditSubmit)}>
                        <ImportationFormFields
                            errors={editErrors}
                            register={registerEdit}
                            watch={watchEdit}
                            setValue={setEditValue}
                        />
                    </form>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={handleCloseEdit} disabled={isUpdating}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="edit-importation-form"
                            disabled={isUpdating}
                            className="bg-[#0344a4] text-white hover:bg-[#023384]"
                        >
                            {isUpdating ? <Loader2 className="h-4 w-4 animate-spin" /> : "Save Changes"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ViewInfoDialog
                open={Boolean(selectedInfoRecord)}
                onOpenChange={(open) => !open && setSelectedInfoRecord(null)}
                resourceType="importation"
                recordId={selectedInfoRecord?.id}
                fallbackTitle="Importation Information"
                fallbackSubtitle={selectedInfoRecord?.import_entry_no}
            />
        </section>
    );
}

ImportationRecords.layout = (page) => (
    <MainLayout title="Importation Records">{page}</MainLayout>
);

export default ImportationRecords;

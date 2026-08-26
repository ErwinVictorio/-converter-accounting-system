import { useEffect, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { Loader2, Pencil, Trash2, X } from "lucide-react";

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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import ImportationFormFields, {
    defaultValues,
    money,
    useComputedVat,
} from "@/Components/Importation/ImportationFormFields";
import RecordTableShell from "@/Components/Records/RecordTableShell";
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
    const [monthFilter, setMonthFilter] = useState(filters.tax_month || "");

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

    useEffect(() => {
        setMonthFilter(filters.tax_month || "");
    }, [filters.tax_month]);

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

    const handleMonthChange = (value) => {
        setMonthFilter(value);
        router.get(
            "/records/importations",
            value ? { tax_month: value } : {},
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    return (
        <section className="w-full max-w-full space-y-6 overflow-hidden">
            <RecordTableShell
                title="Importation Records"
                description="Manually keyed importations. Add a new one under Data & Transactions > Importation."
                links={entries?.links}
                actions={
                    <div className="flex items-center gap-2">
                        <select
                            value={monthFilter}
                            onChange={(event) => handleMonthChange(event.target.value)}
                            className="h-9 rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            <option value="">All months</option>
                            {months.map((month) => (
                                <option key={month.value} value={month.value}>
                                    {month.label} ({month.records_count})
                                </option>
                            ))}
                        </select>
                        {monthFilter && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => handleMonthChange("")}
                                className="h-9"
                            >
                                <X className="h-4 w-4" />
                                Clear
                            </Button>
                        )}
                    </div>
                }
            >
                <Table>
                    <TableHeader className="bg-slate-50/70">
                        <TableRow>
                            <TableHead className="pl-6 font-semibold text-slate-700">#</TableHead>
                            <TableHead className="font-semibold text-slate-700">Tax Month</TableHead>
                            <TableHead className="font-semibold text-slate-700">Import Entry No.</TableHead>
                            <TableHead className="font-semibold text-slate-700">Name of Seller</TableHead>
                            <TableHead className="font-semibold text-slate-700">Country</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Landed Cost</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Dutiable</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Charges</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Exempt</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Taxable Goods</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">VAT Rate</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">VAT</TableHead>
                            <TableHead className="font-semibold text-slate-700">OR No.</TableHead>
                            <TableHead className="font-semibold text-slate-700">VAT Payment</TableHead>
                            <TableHead className="pr-6 text-right font-semibold text-slate-700">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>                        {rows.length > 0 ? (
                            rows.map((entry) => (
                                <TableRow key={entry.id} className="transition-colors hover:bg-slate-50/50">
                                    <TableCell className="py-3 pl-6 text-slate-500">{entry.sequence_number}</TableCell>
                                    <TableCell className="whitespace-nowrap text-slate-700">
                                        {(entry.tax_month || "").slice(0, 7)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap font-medium text-slate-900">
                                        {entry.import_entry_no}
                                    </TableCell>
                                    <TableCell className="min-w-[200px] text-slate-700">{entry.supplier}</TableCell>
                                    <TableCell className="whitespace-nowrap text-slate-600">{entry.country}</TableCell>
                                    <TableCell className="whitespace-nowrap text-right text-slate-700">{money(entry.total_landed_cost)}</TableCell>
                                    <TableCell className="whitespace-nowrap text-right text-slate-600">{money(entry.dutiable_value)}</TableCell>
                                    <TableCell className="whitespace-nowrap text-right text-slate-600">{money(entry.charges)}</TableCell>
                                    <TableCell className="whitespace-nowrap text-right text-slate-600">{money(entry.exempt)}</TableCell>
                                    <TableCell className="whitespace-nowrap text-right text-slate-700">{money(entry.taxable_goods)}</TableCell>
                                    <TableCell className="whitespace-nowrap text-right text-slate-600">{money(entry.vat_rate)}</TableCell>
                                    <TableCell className="whitespace-nowrap text-right text-slate-700">{money(entry.vat_payable)}</TableCell>
                                    <TableCell className="whitespace-nowrap text-slate-600">{entry.or_number}</TableCell>
                                    <TableCell className="whitespace-nowrap text-slate-600">
                                        {(entry.payment_date || "").slice(0, 10)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap py-3 pr-6 text-right">
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
                                    </TableCell>
                                </TableRow>
                            ))
                        ) : (
                            <TableRow>
                                <TableCell colSpan={15} className="py-8 text-center text-slate-400">
                                    No importation entries found.
                                </TableCell>
                            </TableRow>
                        )}</TableBody>
                </Table>
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
        </section>
    );
}

ImportationRecords.layout = (page) => (
    <MainLayout title="Importation Records">{page}</MainLayout>
);

export default ImportationRecords;

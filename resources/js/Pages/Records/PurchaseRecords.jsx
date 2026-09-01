import { useEffect, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import { Pencil } from "lucide-react";

import MainLayout from "@/Layouts/MainLayout";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
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
import { formatCurrency } from "@/Components/Records/format";

// A DAT detail line needs 9 or 12 digits, dashed or not.
const BIR_TIN = /^(\d{9}|\d{12}|\d{3}-\d{3}-\d{3}|\d{3}-\d{3}-\d{3}-\d{3})$/;

function PurchaseRecords() {
    const { flash, vatInputs, months = [], filters = {} } = usePage().props;
    const [selectedBirRecord, setSelectedBirRecord] = useState(null);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

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
                            <TableHead className="text-right font-semibold text-slate-700">Action</TableHead>
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
                                            {formatCurrency(item.services)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                            {formatCurrency(item.others)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-right font-mono text-xs font-bold text-slate-900">
                                            {formatCurrency(item.total)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-right">
                                            <div className="flex justify-end gap-2">
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
        </section>
    );
}

PurchaseRecords.layout = (page) => (
    <MainLayout title="Purchase Records">{page}</MainLayout>
);

export default PurchaseRecords;

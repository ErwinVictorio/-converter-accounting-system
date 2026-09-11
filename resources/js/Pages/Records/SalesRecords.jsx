import { useEffect, useState } from "react";
import { usePage } from "@inertiajs/react";
import { toast } from "sonner";
import { Eye } from "lucide-react";

import MainLayout from "@/Layouts/MainLayout";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import RecordSearchInput from "@/Components/Records/RecordSearchInput";
import RecordPeriodFilter from "@/Components/Records/RecordPeriodFilter";
import RecordTableShell from "@/Components/Records/RecordTableShell";
import ViewInfoDialog from "@/Components/Records/ViewInfoDialog";
import { formatCurrency } from "@/Components/Records/format";

function SalesRecords() {
    const { flash, salesVatInputs, months = [], filters = {} } = usePage().props;
    const [selectedInfoRecord, setSelectedInfoRecord] = useState(null);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    return (
        <section className="w-full max-w-full space-y-6 overflow-hidden">
            <RecordTableShell
                title="Sales VAT Records"
                description="Uploaded sales rows, grouped per customer. Upload new files under Import Data."
                links={salesVatInputs?.links}
                actions={
                    <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                        <RecordSearchInput
                            url="/records/sales"
                            placeholder="Search customer, TIN, or document..."
                            initialValue={filters.search || ""}
                            params={filters.period ? { period: filters.period } : {}}
                        />
                        <RecordPeriodFilter
                            url="/records/sales"
                            months={months}
                            initialValue={filters.period || ""}
                            search={filters.search || ""}
                        />
                    </div>
                }
            >
                <Table className="min-w-[1100px]">
                    <TableHeader>
                        <TableRow className="bg-slate-50 hover:bg-slate-50">
                            <TableHead className="font-semibold text-slate-700">Customer Name</TableHead>
                            <TableHead className="font-semibold text-slate-700">TIN Number</TableHead>
                            <TableHead className="font-semibold text-slate-700">Type</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">SI Rows</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">CM Rows</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Exempt</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Zero Rated</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Taxable Net of VAT</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Output VAT</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Gross Taxable</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {salesVatInputs?.data?.length > 0 ? (
                            salesVatInputs.data.map((item) => (
                                <TableRow key={item.id} className="transition-colors hover:bg-slate-50/60">
                                    <TableCell className="whitespace-nowrap font-medium text-slate-900">
                                        {item.customer_name}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap font-mono text-xs text-slate-600">
                                        {item.customer_tin || "No TIN"}
                                    </TableCell>
                                    <TableCell>
                                        <Badge className="bg-slate-100 text-slate-700 hover:bg-slate-100">
                                            {item.customer_type === "individual" ? "Individual" : "Company"}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                        {item.si_count || 0}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                        {item.cm_count || 0}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                        {formatCurrency(item.exempt_sales)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                        {formatCurrency(item.zero_rated_sales)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                        {formatCurrency(item.taxable_net_of_vat)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                        {formatCurrency(item.output_vat)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                        {formatCurrency(item.gross_amount)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-right">
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
                                    </TableCell>
                                </TableRow>
                            ))
                        ) : (
                            <TableRow>
                                <TableCell colSpan={11} className="h-32 text-center text-slate-500">
                                    No sales records found.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </RecordTableShell>
            <ViewInfoDialog
                open={Boolean(selectedInfoRecord)}
                onOpenChange={(open) => !open && setSelectedInfoRecord(null)}
                resourceType="sales"
                recordId={selectedInfoRecord?.id}
                period={filters.period || ""}
                fallbackTitle="Sales Information"
                fallbackSubtitle={selectedInfoRecord?.customer_name}
            />
        </section>
    );
}

SalesRecords.layout = (page) => (
    <MainLayout title="Sales Records">{page}</MainLayout>
);

export default SalesRecords;

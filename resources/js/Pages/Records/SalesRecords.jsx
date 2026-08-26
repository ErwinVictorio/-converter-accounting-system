import { useEffect } from "react";
import { usePage } from "@inertiajs/react";
import { toast } from "sonner";

import MainLayout from "@/Layouts/MainLayout";
import { Badge } from "@/Components/ui/badge";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import RecordSearchInput from "@/Components/Records/RecordSearchInput";
import RecordTableShell from "@/Components/Records/RecordTableShell";
import { formatCurrency } from "@/Components/Records/format";

function SalesRecords() {
    const { flash, salesVatInputs, filters } = usePage().props;

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
                    <RecordSearchInput
                        url="/records/sales"
                        placeholder="Search customer, TIN, or document..."
                        initialValue={filters?.search || ""}
                    />
                }
            >
                <Table className="min-w-[1100px]">
                    <TableHeader>
                        <TableRow className="bg-slate-50 hover:bg-slate-50">
                            <TableHead className="font-semibold text-slate-700">Customer Name</TableHead>
                            <TableHead className="font-semibold text-slate-700">TIN Number</TableHead>
                            <TableHead className="font-semibold text-slate-700">Type</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Exempt</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Zero Rated</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Taxable Net of VAT</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Output VAT</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Total Sales</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Gross Taxable</TableHead>
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
                                    <TableCell className="whitespace-nowrap text-right font-mono text-xs font-bold text-slate-900">
                                        {formatCurrency(item.net_amount)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                        {formatCurrency(item.gross_amount)}
                                    </TableCell>
                                </TableRow>
                            ))
                        ) : (
                            <TableRow>
                                <TableCell colSpan={9} className="h-32 text-center text-slate-500">
                                    No sales records found.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </RecordTableShell>
        </section>
    );
}

SalesRecords.layout = (page) => (
    <MainLayout title="Sales Records">{page}</MainLayout>
);

export default SalesRecords;

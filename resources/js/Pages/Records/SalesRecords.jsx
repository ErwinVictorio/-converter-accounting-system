import RecordsSummarySection from "@/Components/Records/RecordsSummarySection";
import { formatPeso } from "@/Components/Records/format";
import { Coins } from "lucide-react";
import { InertiaDataTable } from '@/Components/DataTable';
import { useEffect, useState } from "react";
import { usePage } from "@inertiajs/react";
import { toast } from "sonner";
import { Eye } from "lucide-react";

import MainLayout from "@/Layouts/MainLayout";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import RecordSearchInput from "@/Components/Records/RecordSearchInput";
import RecordPeriodFilter from "@/Components/Records/RecordPeriodFilter";
import RecordTableShell from "@/Components/Records/RecordTableShell";
import ViewInfoDialog from "@/Components/Records/ViewInfoDialog";
import { formatCurrency } from "@/Components/Records/format";

function SalesRecords() {
    const { salesSummary, flash, salesVatInputs, months = [], filters = {} } = usePage().props;
    const [selectedInfoRecord, setSelectedInfoRecord] = useState(null);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    return (
        <section className="w-full max-w-full space-y-6 overflow-hidden">
            <RecordsSummarySection title="Sales Summary"
                description="Amounts based on current filters."
                empty={salesSummary.record_count === 0}
                metrics={[
                    { key: "exempt_sales_total", title: "Exempt Sales", value: formatPeso(salesSummary.exempt_sales_total), icon: Coins, tone: "blue" },
                    { key: "zero_rated_sales_total", title: "Zero-Rated Sales", value: formatPeso(salesSummary.zero_rated_sales_total), icon: Coins, tone: "emerald" },
                    { key: "taxable_net_of_vat_total", title: "Taxable Net of VAT", value: formatPeso(salesSummary.taxable_net_of_vat_total), icon: Coins, tone: "amber" },
                    { key: "output_vat_total", title: "Output VAT", value: formatPeso(salesSummary.output_vat_total), icon: Coins, tone: "rose" },
                ]}
                total={{ title: "Total Gross Amount", value: formatPeso(salesSummary.gross_amount_total), description: "Across all filtered records." }}
                footer=""
            />
            <RecordTableShell
                title="Sales VAT Records"
                description="Uploaded sales rows, grouped per customer. Upload new files under Import Data."
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
                <InertiaDataTable paginator={salesVatInputs} tableClassName="min-w-[800px]" emptyState={{ title: 'No records found.' }} columns={[{ id: 'customer_name', accessorKey: 'customer_name', header: "Customer Name", enableSorting: true, enableHiding: false, meta: { label: "Customer Name", cellClassName: "whitespace-nowrap font-medium text-slate-900", headerClassName: "" }, cell: ({ row }) => { const item = row.original;  return (<>
                                        {item.customer_name}
                                    </>); } },
{ id: 'customer_tin', accessorKey: 'customer_tin', header: "TIN Number", enableSorting: true, enableHiding: true, meta: { label: "TIN Number", cellClassName: "whitespace-nowrap font-mono text-xs text-slate-600", headerClassName: "" }, cell: ({ row }) => { const item = row.original;  return (<>
                                        {item.customer_tin || "No TIN"}
                                    </>); } },
{ id: 'customer_type', accessorKey: 'customer_type', header: "Type", enableSorting: true, enableHiding: true, meta: { label: "Type", cellClassName: "", headerClassName: "" }, cell: ({ row }) => { const item = row.original;  return (<>
                                        <Badge className="bg-slate-100 text-slate-700 hover:bg-slate-100">
                                            {item.customer_type === "individual" ? "Individual" : "Company"}
                                        </Badge>
                                    </>); } },
{ id: 'si_count', accessorKey: 'si_count', header: "SI Rows", enableSorting: true, enableHiding: true, meta: { label: "SI Rows", cellClassName: "whitespace-nowrap text-right font-mono text-xs text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original;  return (<>
                                        {item.si_count || 0}
                                    </>); } },
{ id: 'cm_count', accessorKey: 'cm_count', header: "CM Rows", enableSorting: true, enableHiding: true, meta: { label: "CM Rows", cellClassName: "whitespace-nowrap text-right font-mono text-xs text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original;  return (<>
                                        {item.cm_count || 0}
                                    </>); } },
{ id: 'exempt_sales', accessorKey: 'exempt_sales', header: "Exempt", enableSorting: true, enableHiding: true, meta: { label: "Exempt", cellClassName: "whitespace-nowrap text-right font-mono text-xs text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original;  return (<>
                                        {formatCurrency(item.exempt_sales)}
                                    </>); } },
{ id: 'zero_rated_sales', accessorKey: 'zero_rated_sales', header: "Zero Rated", enableSorting: true, enableHiding: true, meta: { label: "Zero Rated", cellClassName: "whitespace-nowrap text-right font-mono text-xs text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original;  return (<>
                                        {formatCurrency(item.zero_rated_sales)}
                                    </>); } },
{ id: 'taxable_net_of_vat', accessorKey: 'taxable_net_of_vat', header: "Taxable Net of VAT", enableSorting: true, enableHiding: true, meta: { label: "Taxable Net of VAT", cellClassName: "whitespace-nowrap text-right font-mono text-xs text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original;  return (<>
                                        {formatCurrency(item.taxable_net_of_vat)}
                                    </>); } },
{ id: 'output_vat', accessorKey: 'output_vat', header: "Output VAT", enableSorting: true, enableHiding: true, meta: { label: "Output VAT", cellClassName: "whitespace-nowrap text-right font-mono text-xs text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original;  return (<>
                                        {formatCurrency(item.output_vat)}
                                    </>); } },
{ id: 'gross_amount', accessorKey: 'gross_amount', header: "Gross Taxable", enableSorting: true, enableHiding: true, meta: { label: "Gross Taxable", cellClassName: "whitespace-nowrap text-right font-mono text-xs text-slate-700", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original;  return (<>
                                        {formatCurrency(item.gross_amount)}
                                    </>); } },
{ id: 'actions', accessorKey: 'actions', header: "Actions", enableSorting: false, enableHiding: false, meta: { label: "Actions", cellClassName: "whitespace-nowrap text-right", headerClassName: "text-right" }, cell: ({ row }) => { const item = row.original;  return (<>
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
                                    </>); } }]} />
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

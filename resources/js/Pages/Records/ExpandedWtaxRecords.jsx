import { useEffect, useMemo, useState } from "react";
import { usePage } from "@inertiajs/react";
import { toast } from "sonner";
import MainLayout from '@/Layouts/MainLayout';
import { DataTable } from '@/Components/DataTable';
import useInertiaDataTable from '@/hooks/useInertiaDataTable';
import expandedWtaxColumns from './columns/expandedWtaxColumns';
import ViewInfoDialog from "@/Components/Records/ViewInfoDialog";
import WithholdingTaxRateSummary from "@/Components/Records/WithholdingTaxRateSummary";

/**
 * Rows arrive already consolidated -- one line per reporting month, payee, ATC
 * and rate, which is the same grouping the 1601EQ DAT files. The badges below
 * exist so a consolidated list cannot be mistaken for missing data.
 */
function ExpandedWtaxRecords() {
    const {
        flash,
        expandedWtaxEntries,
        withholdingTaxRateSummary = { rates: [] },
        months = [],
        filters = {},
    } = usePage().props;
    const scopeParams = Object.fromEntries(["withholding_agent_tin", "withholding_agent_branch_code", "report_type", "tax_rate"]
        .filter(key => filters[key] !== undefined && filters[key] !== null && filters[key] !== "").map(key => [key, filters[key]]));
    const [selectedInfoRecord, setSelectedInfoRecord] = useState(null);

    const table = useInertiaDataTable({ url: '/records/expanded-wtax', paginator: expandedWtaxEntries, query: filters });
    const columns = useMemo(() => expandedWtaxColumns({ onView: setSelectedInfoRecord }), []);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    return (
        <section className="w-full max-w-full space-y-6 overflow-hidden">
            {Object.keys(scopeParams).length > 0 && <p className="text-sm text-slate-600">
                Showing company {filters.withholding_agent_tin}-{filters.withholding_agent_branch_code}, {filters.report_type}, rate {filters.tax_rate}%.
                {" "}<a className="underline" href="/records/expanded-wtax">View all Expanded records</a>
            </p>}
            {/* Above the shell rather than inside it: the table area scrolls
                horizontally at 1100px, and the cards should not scroll with it. */}
            <WithholdingTaxRateSummary
                rates={withholdingTaxRateSummary?.rates || []}
                total={withholdingTaxRateSummary?.total_tax_withheld ?? "0.00"}
            />

            <div className="space-y-3">
                <div>
                    <h2 className="text-lg font-semibold text-slate-900">Expanded Withholding Tax Records</h2>
                    <p className="text-sm text-slate-500">Consolidated 1601EQ lines per agent, payee, ATC and rate. Upload new files under Import Data.</p>
                </div>
                <DataTable columns={columns} data={expandedWtaxEntries?.data || []}
                    getRowId={row => row.id} pagination={table.pagination} rowCount={expandedWtaxEntries?.total || 0}
                    onPaginationChange={table.onPaginationChange} sorting={table.sorting} onSortingChange={table.onSortingChange}
                    search={{ ...table.search, placeholder: 'Search payee, TIN, or ATC...' }} loading={table.loading}
                    emptyState={{ title: 'No expanded withholding tax records found.' }} tableClassName="min-w-[1100px]"
                    filters={<label className="flex items-center gap-2 text-sm text-slate-600">Reporting month
                        <select aria-label="Reporting month" value={filters.period || ''} disabled={table.loading}
                            onChange={event => table.setFilters({ period: event.target.value })}
                            className="h-9 rounded-md border border-slate-200 bg-white py-1 pl-3 pr-8">
                            <option value="">All months</option>
                            {months.map(month => <option key={month.value} value={month.value}>{month.label} ({month.records_count})</option>)}
                        </select>
                    </label>}
                />
            </div>
            <ViewInfoDialog
                open={Boolean(selectedInfoRecord)}
                onOpenChange={(open) => !open && setSelectedInfoRecord(null)}
                resourceType="expanded-wtax"
                recordId={selectedInfoRecord?.source_record_id}
                fallbackTitle="Expanded WTAX Information"
                fallbackSubtitle={selectedInfoRecord?.payee_name}
            />
        </section>
    );
}

ExpandedWtaxRecords.layout = (page) => (
    <MainLayout title="Expanded WTAX Records">{page}</MainLayout>
);

export default ExpandedWtaxRecords;

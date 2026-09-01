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
import RecordPeriodFilter from "@/Components/Records/RecordPeriodFilter";
import RecordTableShell from "@/Components/Records/RecordTableShell";
import { formatCurrency, formatMonth } from "@/Components/Records/format";

/**
 * Rows arrive already consolidated -- one line per reporting month, payee, ATC
 * and rate, which is the same grouping the 1601EQ DAT files. The badges below
 * exist so a consolidated list cannot be mistaken for missing data.
 */
function ExpandedWtaxRecords() {
    const { flash, expandedWtaxEntries, months = [], filters = {} } = usePage().props;

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    return (
        <section className="w-full max-w-full space-y-6 overflow-hidden">
            <RecordTableShell
                title="Expanded Withholding Tax Records"
                description="Consolidated 1601EQ lines per agent, payee, ATC and rate. Upload new files under Import Data."
                links={expandedWtaxEntries?.links}
                actions={
                    <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                        <RecordSearchInput
                            url="/records/expanded-wtax"
                            placeholder="Search payee, TIN, or ATC..."
                            initialValue={filters.search || ""}
                            params={filters.period ? { period: filters.period } : {}}
                        />
                        <RecordPeriodFilter
                            url="/records/expanded-wtax"
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
                            <TableHead className="font-semibold text-slate-700">Payee</TableHead>
                            <TableHead className="font-semibold text-slate-700">Agent TIN</TableHead>
                            <TableHead className="font-semibold text-slate-700">TIN</TableHead>
                            <TableHead className="font-semibold text-slate-700">Branch</TableHead>
                            <TableHead className="font-semibold text-slate-700">ATC</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Rate</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Income Payment</TableHead>
                            <TableHead className="text-right font-semibold text-slate-700">Tax Withheld</TableHead>
                            <TableHead className="font-semibold text-slate-700">Reporting Month</TableHead>
                            <TableHead className="font-semibold text-slate-700">Report Type</TableHead>
                            <TableHead className="font-semibold text-slate-700">Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {expandedWtaxEntries?.data?.length > 0 ? (
                            expandedWtaxEntries.data.map((item) => (
                                <TableRow key={item.id} className="transition-colors hover:bg-slate-50/60">
                                    <TableCell className="whitespace-nowrap font-medium text-slate-900">
                                        <div className="flex items-center gap-2">
                                            <span>{item.payee_name}</span>
                                            <Badge className="bg-slate-100 text-slate-700 hover:bg-slate-100">
                                                {item.payee_type === "individual" ? "Individual" : "Company"}
                                            </Badge>
                                            {/*
                                                Consolidated line: this row is more than one worksheet row added
                                                together. Without the badge the list would just show fewer rows
                                                than were uploaded, which reads as missing data.
                                            */}
                                            {item.merged_rows > 1 && (
                                                <Badge
                                                    className="border-sky-200 bg-sky-50 text-sky-700 hover:bg-sky-50"
                                                    title={`${item.merged_rows} uploaded rows share this reporting month, payee, ATC and rate, so they file as one line.`}
                                                >
                                                    {item.merged_rows} rows merged
                                                </Badge>
                                            )}
                                            {/*
                                                The merged rows named one payee but disagreed about the TIN. A
                                                detail line carries only one, so the group keeps the first
                                                filable one and says so here -- the alternative is filing the
                                                same payee twice, which the BIR schedule does not want.
                                            */}
                                            {item.has_multiple_payee_tins && (
                                                <Badge
                                                    className="border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-50"
                                                    title={`Rows share the same payee name and rate but have different TINs (${(item.distinct_payee_tins || []).join(", ")}). The DAT uses the first valid TIN in the group.`}
                                                >
                                                    Multiple TINs
                                                </Badge>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap font-mono text-xs text-slate-600">
                                        {item.withholding_agent_tin}-{item.withholding_agent_branch_code || "0000"}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap font-mono text-xs text-slate-600">
                                        {item.payee_tin || "No TIN"}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap font-mono text-xs text-slate-600">
                                        {item.payee_branch_code || "0000"}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {item.atc_code ? (
                                            <Badge className="bg-slate-100 font-mono text-slate-700 hover:bg-slate-100">
                                                {item.atc_code}
                                            </Badge>
                                        ) : (
                                            // The workbook's ATC column was blank. Blocks DAT generation until
                                            // it is filled and the month re-uploaded -- the rate alone cannot
                                            // choose between the company and individual code at 5% or 10%.
                                            <Badge className="border-amber-200 bg-amber-100 text-amber-800 hover:bg-amber-100">
                                                No ATC
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                        {Number(item.tax_rate || 0).toFixed(2)}%
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-right font-mono text-xs text-slate-700">
                                        {formatCurrency(item.income_payment)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-right font-mono text-xs font-bold text-slate-900">
                                        {formatCurrency(item.tax_withheld)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-xs text-slate-600">
                                        {formatMonth(item.reporting_period)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        <Badge className={
                                            item.report_type === "annual"
                                                ? "border-violet-200 bg-violet-50 text-violet-700 hover:bg-violet-50"
                                                : "bg-slate-100 text-slate-700 hover:bg-slate-100"
                                        }>
                                            {item.report_type === "annual" ? "Annual" : "Quarterly"}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {item.invalid_count > 0 ? (
                                            <Badge
                                                className="border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-50"
                                                title={(item.validation_errors || []).join(" ")}
                                            >
                                                {item.has_missing_id ? "Missing ID/TIN" : "Needs BIR info"}
                                            </Badge>
                                        ) : (
                                            <Badge className="border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-50">
                                                Ready
                                            </Badge>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))
                        ) : (
                            <TableRow>
                                <TableCell colSpan={11} className="h-32 text-center text-slate-500">
                                    No expanded withholding tax records found.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </RecordTableShell>
        </section>
    );
}

ExpandedWtaxRecords.layout = (page) => (
    <MainLayout title="Expanded WTAX Records">{page}</MainLayout>
);

export default ExpandedWtaxRecords;

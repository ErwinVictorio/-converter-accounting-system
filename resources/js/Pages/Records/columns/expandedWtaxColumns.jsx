import { Eye } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { formatCurrency, formatMonth } from '@/Components/Records/format';

export default function expandedWtaxColumns({ onView }) {
    return [
        {
            id: 'payee_name', accessorKey: 'payee_name', header: 'Payee', enableSorting: true, enableHiding: false,
            meta: { label: 'Payee', cellClassName: 'whitespace-nowrap font-medium text-slate-900', headerClassName: '' },
            cell: ({ row }) => {
                const item = row.original; return (<>
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
                </>);
            },
        },
        {
            id: 'withholding_agent_tin', accessorKey: 'withholding_agent_tin', header: 'Agent TIN', enableSorting: true, enableHiding: true,
            meta: { label: 'Agent TIN', cellClassName: 'whitespace-nowrap font-mono text-xs text-slate-600', headerClassName: '' },
            cell: ({ row }) => {
                const item = row.original; return (<>
                    {item.withholding_agent_tin}-{item.withholding_agent_branch_code || "0000"}
                </>);
            },
        },
        {
            id: 'payee_tin', accessorKey: 'payee_tin', header: 'TIN', enableSorting: true, enableHiding: true,
            meta: { label: 'TIN', cellClassName: 'whitespace-nowrap font-mono text-xs text-slate-600', headerClassName: '' },
            cell: ({ row }) => {
                const item = row.original; return (<>
                    {item.payee_tin || "No TIN"}
                </>);
            },
        },
        {
            id: 'payee_branch_code', accessorKey: 'payee_branch_code', header: 'Branch', enableSorting: true, enableHiding: true,
            meta: { label: 'Branch', cellClassName: 'whitespace-nowrap font-mono text-xs text-slate-600', headerClassName: '' },
            cell: ({ row }) => {
                const item = row.original; return (<>
                    {item.payee_branch_code || "0000"}
                </>);
            },
        },
        {
            id: 'atc_code', accessorKey: 'atc_code', header: 'ATC', enableSorting: true, enableHiding: true,
            meta: { label: 'ATC', cellClassName: 'whitespace-nowrap', headerClassName: '' },
            cell: ({ row }) => {
                const item = row.original; return (<>
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
                </>);
            },
        },
        {
            id: 'tax_rate', accessorKey: 'tax_rate', header: 'Rate', enableSorting: true, enableHiding: true,
            meta: { label: 'Rate', cellClassName: 'whitespace-nowrap text-right font-mono text-xs text-slate-700', headerClassName: 'text-right' },
            cell: ({ row }) => {
                const item = row.original; return (<>
                    {Number(item.tax_rate || 0).toFixed(2)}%
                </>);
            },
        },
        {
            id: 'income_payment', accessorKey: 'income_payment', header: 'Income Payment', enableSorting: true, enableHiding: true,
            meta: { label: 'Income Payment', cellClassName: 'whitespace-nowrap text-right font-mono text-xs text-slate-700', headerClassName: 'text-right' },
            cell: ({ row }) => {
                const item = row.original; return (<>
                    {formatCurrency(item.income_payment)}
                </>);
            },
        },
        {
            id: 'tax_withheld', accessorKey: 'tax_withheld', header: 'Tax Withheld', enableSorting: true, enableHiding: true,
            meta: { label: 'Tax Withheld', cellClassName: 'whitespace-nowrap text-right font-mono text-xs font-bold text-slate-900', headerClassName: 'text-right' },
            cell: ({ row }) => {
                const item = row.original; return (<>
                    {formatCurrency(item.tax_withheld)}
                </>);
            },
        },
        {
            id: 'reporting_period', accessorKey: 'reporting_period', header: 'Reporting Month', enableSorting: true, enableHiding: true,
            meta: { label: 'Reporting Month', cellClassName: 'whitespace-nowrap text-xs text-slate-600', headerClassName: '' },
            cell: ({ row }) => {
                const item = row.original; return (<>
                    {formatMonth(item.reporting_period)}
                </>);
            },
        },
        {
            id: 'report_type', accessorKey: 'report_type', header: 'Report Type', enableSorting: true, enableHiding: true,
            meta: { label: 'Report Type', cellClassName: 'whitespace-nowrap', headerClassName: '' },
            cell: ({ row }) => {
                const item = row.original; return (<>
                    <Badge className={
                        item.report_type === "annual"
                            ? "border-violet-200 bg-violet-50 text-violet-700 hover:bg-violet-50"
                            : "bg-slate-100 text-slate-700 hover:bg-slate-100"
                    }>
                        {item.report_type === "annual" ? "Annual" : "Quarterly"}
                    </Badge>
                </>);
            },
        },
        {
            id: 'status', header: 'Status', enableSorting: false, enableHiding: true,
            meta: { label: 'Status', cellClassName: 'whitespace-nowrap', headerClassName: '' },
            cell: ({ row }) => {
                const item = row.original; return (<>
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
                </>);
            },
        },
        {
            id: 'actions', header: 'Actions', enableSorting: false, enableHiding: false,
            meta: { label: 'Actions', cellClassName: 'whitespace-nowrap text-right', headerClassName: 'text-right' },
            cell: ({ row }) => {
                const item = row.original; return (<>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => onView(item)}
                        className="h-8 gap-1.5"
                    >
                        <Eye className="h-3.5 w-3.5" />
                        View Info
                    </Button>
                </>);
            },
        }
    ];
}

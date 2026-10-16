import { useEffect, useMemo, useState } from "react";
import { motion, useReducedMotion } from "motion/react";
import { Checkbox } from "radix-ui";
import { Check, Minus } from "lucide-react";
import { flexRender, getCoreRowModel, getPaginationRowModel, getSortedRowModel, useReactTable } from "@tanstack/react-table";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/ui/table";
import { cn } from "@/lib/utils";
import DataTableColumnHeader from "./DataTableColumnHeader";
import DataTableToolbar from "./DataTableToolbar";
import DataTablePagination from "./DataTablePagination";

const AnimatedTableRow = motion.create(TableRow);

function SelectionBox({ checked, onCheckedChange, label, disabled }) {
    return <Checkbox.Root checked={checked} onCheckedChange={onCheckedChange} aria-label={label} disabled={disabled}
        className="flex h-4 w-4 items-center justify-center rounded border border-slate-400 disabled:opacity-40">
        <Checkbox.Indicator>{checked === "indeterminate" ? <Minus className="h-3 w-3" /> : <Check className="h-3 w-3" />}</Checkbox.Indicator>
    </Checkbox.Root>;
}

/** Controlled server table. Columns supply renderers/meta; pages own all business actions.
 * getRowId must identify the logical record, not its array position.
 * Pagination/sorting callbacks accept TanStack values or updater functions.
 */
export default function DataTable({ columns, data, getRowId, pagination, rowCount,
    onPaginationChange, sorting = [], onSortingChange, search, filters, toolbarActions,
    loading = false, emptyState = { title: "No records found." },
    columnVisibility, onColumnVisibilityChange, enableRowSelection = false,
    rowSelection, onRowSelectionChange, selectionActions, className, tableClassName,
    mode = "server", showToolbar = true, showPagination = true }) {
    const reduceMotion = useReducedMotion();
    const fadeTransition = { duration: reduceMotion ? 0 : 0.18, ease: "easeOut" };
    const [visibility, setVisibility] = useState({});
    const [selection, setSelection] = useState({});
    const [localPagination, setLocalPagination] = useState({ pageIndex: 0, pageSize: 15 });
    const [localSorting, setLocalSorting] = useState([]);
    const dataKey = JSON.stringify(data);
    if (mode === "client") {
        pagination = localPagination;
        onPaginationChange = setLocalPagination;
        sorting = localSorting;
        onSortingChange = update => { setLocalSorting(update); setLocalPagination(current => ({ ...current, pageIndex: 0 })); };
        rowCount = data.length;
    }
    useEffect(() => {
        if (mode === "client") setLocalPagination(current => ({ ...current, pageIndex: 0 }));
    }, [dataKey, mode]);
    const changeSelection = onRowSelectionChange || setSelection;
    const sortKey = JSON.stringify(sorting);
    useEffect(() => { changeSelection({}); }, [dataKey, pagination.pageIndex, pagination.pageSize, sortKey]);
    const configuredColumns = useMemo(() => enableRowSelection ? [{
        id: "selection", enableSorting: false, enableHiding: false,
        header: ({ table }) => <SelectionBox label="Select eligible rows on this page" disabled={loading}
            checked={table.getIsAllPageRowsSelected() || (table.getIsSomePageRowsSelected() ? "indeterminate" : false)}
            onCheckedChange={value => table.toggleAllPageRowsSelected(Boolean(value))} />,
        cell: ({ row }) => <SelectionBox label={`Select row ${row.id}`} disabled={loading || !row.getCanSelect()}
            checked={row.getIsSelected()} onCheckedChange={value => row.toggleSelected(Boolean(value))} />,
    }, ...columns] : columns, [columns, enableRowSelection, loading]);
    const table = useReactTable({
        data, columns: configuredColumns, getRowId, rowCount,
        state: { pagination, sorting, columnVisibility: columnVisibility ?? visibility, rowSelection: rowSelection ?? selection },
        onPaginationChange, onSortingChange,
        onColumnVisibilityChange: onColumnVisibilityChange || setVisibility,
        onRowSelectionChange: changeSelection, enableRowSelection,
        manualPagination: mode !== "client" || !showPagination, manualSorting: mode !== "client", manualFiltering: true,
        getPaginationRowModel: mode === "client" && showPagination ? getPaginationRowModel() : undefined,
        getSortedRowModel: mode === "client" ? getSortedRowModel() : undefined,
        enableMultiSort: false, autoResetPageIndex: false, getCoreRowModel: getCoreRowModel(),
        defaultColumn: { enableSorting: false },
    });
    return <div className={cn("min-w-0 overflow-hidden rounded-xl border bg-white", className)} aria-busy={loading}>
        {showToolbar && <DataTableToolbar table={table} search={search} filters={filters} actions={toolbarActions} />}
        <div role="status" aria-live="polite" className={cn("px-4 text-xs text-slate-500", !loading && "sr-only")}>{loading ? "Updating records..." : ""}</div>
        <motion.div initial={false} animate={{ opacity: loading ? 0.65 : 1 }} transition={fadeTransition}>
        <Table className={tableClassName}>
            <TableHeader>{table.getHeaderGroups().map(group => <TableRow key={group.id} className="bg-slate-50">
                {group.headers.map(header => <TableHead key={header.id} colSpan={header.colSpan}
                    className={header.column.columnDef.meta?.headerClassName}
                    aria-sort={header.column.getIsSorted() === "asc" ? "ascending" : header.column.getIsSorted() === "desc" ? "descending" : undefined}>
                    {!header.isPlaceholder && (typeof header.column.columnDef.header === "function"
                        ? flexRender(header.column.columnDef.header, header.getContext())
                        : <DataTableColumnHeader column={header.column} label={header.column.columnDef.header || header.column.id} disabled={loading} />)}
                </TableHead>)}
            </TableRow>)}</TableHeader>
            <TableBody>{table.getRowModel().rows.length ? table.getRowModel().rows.map(row => <AnimatedTableRow key={row.id}
                initial={reduceMotion ? false : { opacity: 0 }} animate={{ opacity: 1 }} transition={fadeTransition}
                data-state={row.getIsSelected() ? "selected" : undefined}>
                {row.getVisibleCells().map(cell => <TableCell key={cell.id} className={cell.column.columnDef.meta?.cellClassName}>
                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                </TableCell>)}
            </AnimatedTableRow>) : <TableRow><TableCell colSpan={table.getVisibleLeafColumns().length} className="h-32 text-center text-slate-500">
                {loading ? "Loading records..." : <>{emptyState.title}{emptyState.description && <p>{emptyState.description}</p>}{emptyState.action}</>}
            </TableCell></TableRow>}</TableBody>
        </Table>
        </motion.div>
        {enableRowSelection && <div className="flex items-center gap-3 border-t p-4 text-sm">
            {table.getSelectedRowModel().rows.length} row(s) selected on this page
            {selectionActions?.(table.getSelectedRowModel().rows.map(row => row.original))}
        </div>}
        {showPagination && <DataTablePagination table={table} rowCount={rowCount} loading={loading} />}
    </div>;
}

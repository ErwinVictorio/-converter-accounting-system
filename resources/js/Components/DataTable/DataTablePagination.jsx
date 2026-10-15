import { Button } from "@/Components/ui/button";
import { pageSizes } from "@/lib/dataTable";

export default function DataTablePagination({ table, rowCount, loading }) {
    const { pageIndex, pageSize } = table.getState().pagination;
    return <div className="flex flex-wrap items-center justify-between gap-4 border-t p-4 text-sm text-slate-600">
        <span>{rowCount ? pageIndex * pageSize + 1 : 0}–{Math.min((pageIndex + 1) * pageSize, rowCount)} of {rowCount} records</span>
        <div className="flex flex-wrap items-center gap-3">
            <label className="flex items-center gap-2">Rows per page
                <select className="rounded-md border border-slate-200 bg-white py-1.5 pl-2 pr-8" value={pageSize}
                    disabled={loading} onChange={event => table.setPageSize(Number(event.target.value))}>
                    {pageSizes.map(size => <option key={size} value={size}>{size}</option>)}
                </select>
            </label>
            <span>Page {rowCount ? pageIndex + 1 : 1} of {Math.max(1, table.getPageCount())}</span>
            <Button variant="outline" size="sm" disabled={loading || !table.getCanPreviousPage()} onClick={() => table.previousPage()}>Previous</Button>
            <Button variant="outline" size="sm" disabled={loading || !table.getCanNextPage()} onClick={() => table.nextPage()}>Next</Button>
        </div>
    </div>;
}

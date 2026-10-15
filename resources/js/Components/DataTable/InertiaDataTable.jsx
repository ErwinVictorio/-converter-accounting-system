import { usePage } from "@inertiajs/react";
import DataTable from "./DataTable";
import useInertiaDataTable from "@/hooks/useInertiaDataTable";

/** Adapter for existing listings whose page already owns its filter toolbar. */
export default function InertiaDataTable({ paginator, columns, ...props }) {
    const page = usePage();
    const url = new URL(page.url, window.location.origin);
    const query = { ...Object.fromEntries(url.searchParams), ...page.props.filters };
    const table = useInertiaDataTable({ url: url.pathname, paginator, query });
    return <DataTable {...props} columns={columns} data={paginator?.data || []} getRowId={row => String(row.id)}
        rowCount={paginator?.total || 0} pagination={table.pagination} onPaginationChange={table.onPaginationChange}
        sorting={table.sorting} onSortingChange={table.onSortingChange} loading={table.loading} />;
}

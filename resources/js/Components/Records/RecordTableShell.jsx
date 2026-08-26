import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from "@/Components/ui/card";
import DataTablePagination from "@/Layouts/Pagination";

/**
 * The card the four Record pages hang their table in: title, an optional filter
 * slot on the right, a horizontally scrollable table area, then pagination.
 *
 * Lifted verbatim from the combined records screen so the pages keep the look
 * they had; only the table markup differs per page, which is what `children`
 * is for.
 */
export default function RecordTableShell({
    title,
    description,
    actions,
    links,
    children,
}) {
    return (
        <Card className="w-full overflow-hidden rounded-xl border bg-white shadow-sm">
            <CardHeader className="flex flex-col items-start justify-between gap-4 border-b bg-gray-50/50 p-4 sm:p-6 md:flex-row md:items-center">
                <div className="min-w-0">
                    <CardTitle className="text-lg font-semibold text-gray-900 sm:text-xl">
                        {title}
                    </CardTitle>
                    {description && (
                        <p className="mt-1 text-xs text-slate-500 sm:text-sm">{description}</p>
                    )}
                </div>

                {actions}
            </CardHeader>

            {/* overflow-x-auto keeps a wide table inside the card instead of
                stretching the page. */}
            <CardContent className="overflow-x-auto p-0">{children}</CardContent>

            <DataTablePagination links={links} />
        </Card>
    );
}

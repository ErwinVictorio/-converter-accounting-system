import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
} from "@/components/ui/pagination"
import { Link } from "@inertiajs/react";

const DataTablePagination = ({ links }) => {
    // Wag ipakita kung walang links o isa lang ang page
    if (!links || links.length <= 3) return null;

    return (
        <div className="py-4 border-t border-slate-100 bg-white">
            <Pagination className="justify-end px-6">
                <PaginationContent>
                    {links.map((link, i) => {
                        // Linisin ang label (tinatanggal ang arrows mula sa text)
                        const label = link.label
                            .replace('&laquo; Previous', '')
                            .replace('Next &raquo;', '');

                        // 1. Handle Previous Button
                        if (link.label.includes("Previous")) {
                            return (
                                <PaginationItem key={i}>
                                    <Link
                                        href={link.url || "#"}
                                        preserveState
                                        preserveScroll
                                        // Ginagamit natin ang shadcn class para magmukhang button
                                        className={`flex h-9 items-center justify-center gap-1 pl-2.5 pr-2.5 text-sm font-medium transition-colors hover:bg-slate-100 rounded-md ${!link.url ? "pointer-events-none opacity-50 text-slate-400" : ""
                                            }`}
                                    >
                                        <span>Previous</span>
                                    </Link>
                                </PaginationItem>
                            );
                        }

                        // 2. Handle Next Button
                        if (link.label.includes("Next")) {
                            return (
                                <PaginationItem key={i}>
                                    <Link
                                        href={link.url || "#"}
                                        preserveState
                                        preserveScroll
                                        className={`flex h-9 items-center justify-center gap-1 pl-2.5 pr-2.5 text-sm font-medium transition-colors hover:bg-slate-100 rounded-md ${!link.url ? "pointer-events-none opacity-50 text-slate-400" : ""
                                            }`}
                                    >
                                        <span>Next</span>
                                    </Link>
                                </PaginationItem>
                            );
                        }

                        // 3. Handle Ellipsis (...)
                        if (link.label === "...") {
                            return (
                                <PaginationItem key={i}>
                                    <PaginationEllipsis />
                                </PaginationItem>
                            );
                        }

                        // 4. Handle Page Numbers
                        return (
                            <PaginationItem key={i}>
                                <Link
                                    href={link.url}
                                    preserveState
                                    preserveScroll
                                    className={`flex h-9 w-9 items-center justify-center rounded-md text-sm font-medium transition-colors border ${link.active
                                            ? "bg-blue-600 text-white border-blue-600 shadow-sm"
                                            : "bg-white text-slate-600 hover:bg-slate-100 border-slate-200"
                                        }`}
                                >
                                    {label}
                                </Link>
                            </PaginationItem>
                        );
                    })}
                </PaginationContent>
            </Pagination>
        </div>
    );
};

export default DataTablePagination;
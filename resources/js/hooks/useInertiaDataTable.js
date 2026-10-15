import { useEffect, useRef, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import { cleanQuery, paginationFrom, resolveUpdater } from "@/lib/dataTable";

/** Server table navigation; the renderer itself has no dependency on Inertia. */
export default function useInertiaDataTable({ url, paginator, query = {}, defaultPageSize = 15 }) {
    const pageUrl = usePage().url;
    const [value, setValue] = useState(query.search || "");
    const [loading, setLoading] = useState(false);
    const timer = useRef(null);
    const cancel = useRef(null);
    const generation = useRef(0);
    const pagination = paginationFrom(paginator, defaultPageSize);
    const sorting = query.sort ? [{ id: query.sort, desc: query.direction === "desc" }] : [];

    useEffect(() => {
        clearTimeout(timer.current);
        setValue(query.search || "");
    }, [pageUrl, query.search]);
    useEffect(() => () => { clearTimeout(timer.current); cancel.current?.cancel(); }, []);

    const visit = (changes, replace = false) => {
        clearTimeout(timer.current);
        const requestId = ++generation.current;
        cancel.current?.cancel();
        setLoading(true);
        router.get(url, cleanQuery({ ...query, search: value, per_page: pagination.pageSize, ...changes }), {
            preserveState: true, preserveScroll: true, replace,
            onCancelToken: (token) => { cancel.current = token; },
            onError: () => toast.error("Unable to update the table. Please try again."),
            onFinish: () => { if (requestId === generation.current) { setLoading(false); cancel.current = null; } },
        });
    };

    return {
        pagination, sorting, loading, query,
        search: {
            value,
            onChange: (next) => {
                setValue(next);
                clearTimeout(timer.current);
                cancel.current?.cancel();
                timer.current = setTimeout(() => visit({ search: next, page: 1 }, true), 300);
            },
        },
        setFilters: (changes) => visit({ ...changes, page: 1 }),
        onPaginationChange: (update) => {
            const next = resolveUpdater(update, pagination);
            visit({ page: next.pageSize !== pagination.pageSize ? 1 : next.pageIndex + 1, per_page: next.pageSize });
        },
        onSortingChange: (update) => {
            const [next] = resolveUpdater(update, sorting);
            visit({ sort: next?.id || "", direction: next ? (next.desc ? "desc" : "asc") : "", page: 1 });
        },
    };
}

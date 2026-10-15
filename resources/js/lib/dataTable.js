export const pageSizes = [10, 15, 25, 50, 100];
export const resolveUpdater = (update, current) => typeof update === "function" ? update(current) : update;
export const cleanQuery = (query) => Object.fromEntries(Object.entries(query).filter(([, value]) => value !== "" && value !== null && value !== undefined));
export const paginationFrom = (paginator, fallback = 15) => ({
    pageIndex: Math.max(0, Number(paginator?.current_page || 1) - 1),
    pageSize: Number(paginator?.per_page || fallback),
});

/** Keep sort/page size and workflow context while a page changes its filters. */
export const currentTableQuery = (changes = {}) => cleanQuery({
    ...Object.fromEntries(new URLSearchParams(window.location.search)), ...changes, page: 1,
});


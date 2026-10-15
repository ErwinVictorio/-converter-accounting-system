import { Search } from "lucide-react";
import { Input } from "@/Components/ui/input";
import DataTableColumnVisibility from "./DataTableColumnVisibility";

export default function DataTableToolbar({ table, search, filters, actions }) {
    return <div className="flex flex-wrap items-center gap-3 p-4">
        {search && <div className="relative w-full sm:w-72">
            <Search className="absolute left-3 top-3 h-4 w-4 text-slate-400" aria-hidden="true" />
            <Input aria-label={search.placeholder || "Search records"} placeholder={search.placeholder || "Search records..."}
                value={search.value} onChange={event => search.onChange(event.target.value)} className="pl-9" />
        </div>}
        {filters}
        <div className="ml-auto flex flex-wrap items-center gap-2">{actions}<DataTableColumnVisibility table={table} /></div>
    </div>;
}

import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import { Button } from "@/Components/ui/button";

export default function DataTableColumnHeader({ column, label, disabled }) {
    if (!column.getCanSort()) return label;
    const sorted = column.getIsSorted();
    const Icon = sorted === "asc" ? ArrowUp : sorted === "desc" ? ArrowDown : ArrowUpDown;
    return <Button type="button" variant="ghost" size="sm" disabled={disabled}
        className="-mx-2 h-8 gap-1 whitespace-nowrap font-semibold"
        aria-label={`Sort by ${label}`} onClick={column.getToggleSortingHandler()}>
        {label}<Icon className="h-3.5 w-3.5" aria-hidden="true" />
    </Button>;
}

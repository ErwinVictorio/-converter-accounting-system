import { DropdownMenu } from "radix-ui";
import { Check, ChevronDown } from "lucide-react";
import { buttonVariants } from "@/Components/ui/button";

export default function DataTableColumnVisibility({ table }) {
    return <DropdownMenu.Root>
        <DropdownMenu.Trigger className={buttonVariants({ variant: "outline" })}>Columns<ChevronDown className="h-4 w-4" /></DropdownMenu.Trigger>
        <DropdownMenu.Portal><DropdownMenu.Content align="end" sideOffset={6}
            className="z-50 min-w-48 overflow-y-auto rounded-md border bg-white p-1 text-sm text-slate-700 shadow-md"
            style={{ maxHeight: "var(--radix-dropdown-menu-content-available-height)" }} collisionPadding={8}>
            <DropdownMenu.Label className="px-2 py-1.5 font-semibold">Visible columns</DropdownMenu.Label>
            {table.getAllLeafColumns().filter(column => column.getCanHide()).map(column => (
                <DropdownMenu.CheckboxItem key={column.id} checked={column.getIsVisible()}
                    disabled={column.getIsVisible() && table.getVisibleLeafColumns().length === 1}
                    onCheckedChange={value => column.toggleVisibility(Boolean(value))}
                    onSelect={event => event.preventDefault()}
                    className="relative cursor-pointer rounded px-8 py-2 outline-none focus:bg-slate-100 data-[disabled]:opacity-40">
                    <DropdownMenu.ItemIndicator className="absolute left-2"><Check className="h-4 w-4" /></DropdownMenu.ItemIndicator>
                    {column.columnDef.meta?.label || column.id}
                </DropdownMenu.CheckboxItem>
            ))}
        </DropdownMenu.Content></DropdownMenu.Portal>
    </DropdownMenu.Root>;
}

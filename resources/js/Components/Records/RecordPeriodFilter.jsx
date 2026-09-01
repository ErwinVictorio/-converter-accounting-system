import { useEffect, useState } from "react";
import { router } from "@inertiajs/react";
import { X } from "lucide-react";

import { Button } from "@/Components/ui/button";

export default function RecordPeriodFilter({
    url,
    months = [],
    initialValue = "",
    search = "",
    queryKey = "period",
}) {
    const [period, setPeriod] = useState(initialValue || "");

    useEffect(() => {
        setPeriod(initialValue || "");
    }, [initialValue]);

    const handleChange = (value) => {
        setPeriod(value);
        router.get(
            url,
            {
                ...(value ? { [queryKey]: value } : {}),
                ...(search ? { search } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
            <select
                value={period}
                onChange={(event) => handleChange(event.target.value)}
                className="h-9 rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
                <option value="">All months</option>
                {months.map((month) => (
                    <option key={month.value} value={month.value}>
                        {month.label} ({month.records_count})
                    </option>
                ))}
            </select>
            {period && (
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => handleChange("")}
                    className="h-9"
                >
                    <X className="h-4 w-4" />
                    Clear
                </Button>
            )}
        </div>
    );
}

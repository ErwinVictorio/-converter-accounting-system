import { useWatch } from "react-hook-form";
import { countNonWhitespace } from "@/lib/masterDataText";

export default function MasterDataCharacterCount({ control, name, limit }) {
    const value = useWatch({ control, name });
    const count = countNonWhitespace(value);

    return (
        <div className="flex items-center justify-between gap-2 text-xs">
            <span className="text-slate-600">Characters (spaces excluded)</span>
            <span
                className={`rounded-md px-2 py-1 font-semibold tabular-nums ${
                    count > limit
                        ? "bg-red-50 text-red-700"
                        : "bg-slate-100 text-slate-700"
                }`}
            >
                {count} / {limit}
            </span>
        </div>
    );
}

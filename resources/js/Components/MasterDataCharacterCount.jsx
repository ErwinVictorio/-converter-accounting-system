import { useWatch } from "react-hook-form";
import { countNonWhitespace } from "@/lib/masterDataText";

export default function MasterDataCharacterCount({ control, name, limit }) {
    const value = useWatch({ control, name });
    const count = countNonWhitespace(value);

    return (
        <p className={`text-xs ${count > limit ? "text-red-500" : "text-slate-500"}`}>
            {count} / {limit} (excluding spaces)
        </p>
    );
}

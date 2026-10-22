import { Percent } from "lucide-react";
import RecordsSummarySection from "./RecordsSummarySection";
import { formatPeso } from "./format";

const TONES = { "1.00": "blue", "2.00": "emerald", "5.00": "amber", "10.00": "rose" };

export default function WithholdingTaxRateSummary({ rates = [], total = "0.00" }) {
    return <RecordsSummarySection
        title="Tax Withheld Summary by Rate"
        description="Total tax withheld amount per rate based on current filters."
        empty={rates.length === 0}
        metrics={rates.map(rate => ({
            key: rate.tax_rate,
            title: `${Number(rate.tax_rate)}% Tax Withheld Total`,
            value: formatPeso(rate.tax_withheld_total),
            icon: Percent,
            tone: TONES[rate.tax_rate] || "neutral",
        }))}
        total={{ title: "Total Tax Withheld", value: formatPeso(total), description: "All rates across the current filters." }}
    />;
}

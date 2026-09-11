import { Coins, Percent } from "lucide-react";

import { Card } from "@/Components/ui/card";
import { formatCurrency } from "@/Components/Records/format";

/**
 * Tax Withheld totalled per EWT rate, above the Expanded WTAX table.
 *
 * Every figure here is read from the server's withholdingTaxRateSummary prop and
 * never from the rows on screen: the table pages fifteen consolidated lines at a
 * time, so totalling what is rendered would quietly answer a different question
 * than the one the heading asks. Search and month changes reload both together;
 * paging leaves these amounts alone.
 */

/*
 * Keyed by rate rather than by position, so 1% keeps its colour when a filter
 * drops 2% out of the set and cards do not reshuffle colours between requests.
 * Rates outside the four common ones read neutral, and the label and amount are
 * legible without the accent either way.
 */
const TONES = {
    "1.00": "bg-blue-50 text-[#0344a4]",
    "2.00": "bg-emerald-50 text-emerald-600",
    "5.00": "bg-amber-50 text-amber-600",
    "10.00": "bg-rose-50 text-rose-600",
};

const NEUTRAL_TONE = "bg-slate-100 text-slate-500";

// "1.00" -> "1%", "12.50" -> "12.5%". A fractional rate keeps its decimals.
const rateLabel = (rate) => {
    const value = Number(rate);

    return `${Number.isFinite(value) ? String(value) : rate}%`;
};

/*
 * A rate whose rows include a reversal can total negative, so the minus sign
 * goes outside the peso sign the way the dashboard writes it. The digits still
 * come from the shared formatter, so the cards and the table agree on grouping
 * and decimals.
 */
const peso = (amount) => {
    const value = Number(amount) || 0;

    return `${value < 0 ? "-" : ""}₱${formatCurrency(Math.abs(value))}`;
};

export default function WithholdingTaxRateSummary({ rates = [] }) {
    return (
        <Card className="w-full rounded-xl border bg-white p-4 shadow-sm sm:p-6">
            <div className="min-w-0">
                <div className="flex items-center gap-2.5">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-[#0344a4]">
                        <Coins className="h-4 w-4" />
                    </span>
                    <h2 className="text-lg font-semibold text-gray-900 sm:text-xl">
                        Tax Withheld Summary by Rate
                    </h2>
                </div>
                <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                    Total tax withheld amount per rate based on current filters.
                </p>
            </div>

            {rates.length > 0 ? (
                <>
                    {/* One column on a phone, two from sm, four from lg; further
                        rates wrap onto the next row of the same grid. */}
                    <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
                        {rates.map((rate) => (
                            <div
                                key={rate.tax_rate}
                                className="flex min-w-0 items-start gap-3 rounded-xl border border-slate-100 bg-slate-50/60 p-4"
                            >
                                <span
                                    className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${
                                        TONES[rate.tax_rate] ?? NEUTRAL_TONE
                                    }`}
                                >
                                    <Percent className="h-4 w-4" />
                                </span>

                                <div className="min-w-0 flex-1">
                                    <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">
                                        {rateLabel(rate.tax_rate)} Tax Withheld Total
                                    </p>
                                    {/* truncate + title: four across from lg is the
                                        narrowest these get, and a seven-digit total
                                        would otherwise push past the card. */}
                                    <p
                                        className="mt-1 truncate text-lg font-extrabold tracking-tight tabular-nums text-slate-900 sm:text-xl"
                                        title={peso(rate.tax_withheld_total)}
                                    >
                                        {peso(rate.tax_withheld_total)}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>

                    <p className="mt-3 text-[11px] font-medium text-slate-500">
                        Amounts cover all filtered records across all pages.
                    </p>
                </>
            ) : (
                <p className="mt-4 text-sm text-slate-500">
                    No records match the current filters.
                </p>
            )}
        </Card>
    );
}

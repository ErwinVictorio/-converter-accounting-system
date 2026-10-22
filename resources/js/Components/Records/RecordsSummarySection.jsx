import { useEffect, useRef, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { Coins } from "lucide-react";
import { Card } from "@/Components/ui/card";
import SummaryCard from "@/Components/Cards/SummaryCard";

// Track only this listing's visits and mutations that redirect back to it.
function useSummaryLoading() {
    const { url } = usePage();
    const path = url.split("?")[0];
    const pending = useRef(new Set());
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        const start = router.on("start", ({ detail: { visit } }) => {
            const target = visit.url.pathname;
            const mutation = visit.method !== "get" && (
                (path === "/records/purchases" && /^\/records\/\d+/.test(target)) ||
                (path === "/records/importations" && /^\/importation\/\d+/.test(target))
            );
            if (target === path || mutation) {
                pending.current.add(visit);
                setLoading(true);
            }
        });
        const finish = router.on("finish", ({ detail: { visit } }) => {
            pending.current.delete(visit);
            setLoading(pending.current.size > 0);
        });
        return () => { start(); finish(); pending.current.clear(); };
    }, [path]);
    return loading;
}

export default function RecordsSummarySection({ title, description, icon: Icon = Coins, metrics = [], total, empty = false, loading = false, footer }) {
    const navigating = useSummaryLoading();
    const busy = loading || navigating;
    return (
        <section aria-label={title} aria-busy={busy} className="grid min-w-0 grid-cols-1 gap-4 2xl:grid-cols-[minmax(0,1fr)_18rem]">
            <Card className="min-w-0 w-full rounded-xl border bg-white p-4 shadow-sm sm:p-6">
                <div className="flex items-center gap-2.5">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-[#0344a4]"><Icon aria-hidden="true" className="h-4 w-4" /></span>
                    <h2 className="text-lg font-semibold text-gray-900 sm:text-xl">{title}</h2>
                </div>
                <p className="mt-1 text-xs text-slate-500 sm:text-sm">{description}</p>
                {metrics.length > 0 && <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
                    {metrics.map(({ key, ...metric }) => <SummaryCard key={key} {...metric} appearance="recordMetric" />)}
                </div>}
                {empty && <p className="mt-4 text-sm text-slate-500">No records match the current filters.</p>}
                <p className="mt-3 text-[11px] font-medium text-slate-500">Amounts cover all filtered records across all pages.</p>
                {footer && <p className="mt-1 text-[11px] text-slate-500">{footer}</p>}
                <span role="status" className="text-xs text-slate-500">{busy ? "Updating summary…" : ""}</span>
            </Card>
            <SummaryCard {...total} appearance="recordTotal" />
        </section>
    );
}

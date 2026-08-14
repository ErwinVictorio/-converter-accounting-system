import React, { useEffect, useMemo } from "react";
import { useForm, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import { motion } from "framer-motion";

import MainLayout from "@/Layouts/MainLayout";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";

function GenerateDatFile() {
    const { flash, availablePeriods = [], periodIssues = {} } = usePage().props;
    const currentMonth = new Date().toISOString().slice(0, 7);
    const defaultPeriod = availablePeriods[0]?.value || currentMonth;

    const { data, setData, processing, errors } = useForm({
        period: defaultPeriod,
    });

    const selectedPeriod = useMemo(() => {
        return availablePeriods.find((period) => period.value === data.period);
    }, [availablePeriods, data.period]);

    const selectedIssues = periodIssues[data.period] || {
        invalid_count: 0,
        errors: [],
    };

    useEffect(() => {
        if (flash?.error) toast.error(flash.error);
        if (flash?.success) toast.success(flash.success);
    }, [flash]);

    const handleDownload = (e) => {
        e.preventDefault();

        if (!data.period) {
            toast.error("Please select a reporting month.");
            return;
        }

        if (availablePeriods.length > 0 && !selectedPeriod) {
            toast.error("No VAT input records found for the selected reporting month.");
            return;
        }

        if (selectedIssues.invalid_count > 0) {
            toast.error("Please fix invalid VAT input rows before downloading the DAT file.");
            return;
        }

        const params = new URLSearchParams({
            period: data.period,
        });

        window.location.href = `/download-datfile?${params.toString()}`;
    };

    return (
        <section className="w-full max-w-3xl space-y-6">
            <motion.div
                initial={{ opacity: 0, y: 15 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3, ease: "easeOut" }}
                className="space-y-6 rounded-xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6"
            >
                <div>
                    <h2 className="text-lg font-semibold text-gray-800">
                        Generate RELIEF Purchases DAT
                    </h2>
                    <p className="text-xs text-gray-500">
                        Select a reporting month to download one DAT file from VAT input records.
                    </p>
                </div>

                <form onSubmit={handleDownload} className="space-y-6">
                    <div className="space-y-1.5">
                        <label className="text-xs font-medium text-gray-600">
                            Reporting Month
                        </label>
                        {availablePeriods.length > 0 ? (
                            <select
                                value={data.period}
                                onChange={(e) => setData("period", e.target.value)}
                                className="flex h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-gray-700 shadow-xs transition-colors focus-visible:border-ring focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            >
                                {availablePeriods.map((period) => (
                                    <option key={period.value} value={period.value}>
                                        {period.label} ({period.records_count} rows)
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <Input
                                type="month"
                                value={data.period}
                                onChange={(e) => setData("period", e.target.value)}
                                className="h-11 rounded-lg border-slate-300 text-gray-700"
                            />
                        )}
                        {selectedPeriod && (
                            <p className={`text-xs ${selectedIssues.invalid_count > 0 ? "text-amber-600" : "text-emerald-600"}`}>
                                {selectedPeriod.records_count} VAT input rows found.
                                {selectedIssues.invalid_count > 0
                                    ? ` ${selectedIssues.invalid_count} need BIR info fixes.`
                                    : " Ready for DAT generation."}
                            </p>
                        )}
                        {availablePeriods.length === 0 && (
                            <p className="text-xs text-amber-600">
                                No imported VAT input records yet.
                            </p>
                        )}
                        {errors.period && (
                            <p className="text-xs text-red-500">{errors.period}</p>
                        )}
                    </div>

                    {selectedIssues.invalid_count > 0 && (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            <p className="font-semibold">
                                Fix VAT input rows before downloading DAT
                            </p>
                            <ul className="mt-2 space-y-1 text-xs">
                                {selectedIssues.errors.map((error) => (
                                    <li key={error}>{error}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="flex justify-end border-t pt-4">
                        <motion.div whileHover={{ scale: 1.02 }} whileTap={{ scale: 0.98 }}>
                            <Button
                                type="submit"
                                disabled={processing || selectedIssues.invalid_count > 0}
                                className="h-11 rounded-lg bg-blue-600 px-8 font-medium text-white shadow-sm transition-all hover:bg-blue-700"
                            >
                                Download DAT
                            </Button>
                        </motion.div>
                    </div>
                </form>
            </motion.div>
        </section>
    );
}

GenerateDatFile.layout = (page) => (
    <MainLayout title="Generate DAT File">{page}</MainLayout>
);

export default GenerateDatFile;

import React, { useEffect, useMemo, useState } from "react";
import { useForm, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import { motion } from "framer-motion";
import MainLayout from "@/Layouts/MainLayout";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";

function GenerateDatFile() {
    const { flash, defaultCompany, availablePeriods = [], periodIssues = {} } = usePage().props;
    const [companyLookupStatus, setCompanyLookupStatus] = useState("idle");
    const [companyLookupSource, setCompanyLookupSource] = useState("");

    const currentMonth = new Date().toISOString().slice(0, 7);
    const defaultPeriod = availablePeriods[0]?.value || currentMonth;
    const companyDefaults = defaultCompany || {
        tin: "008791976",
        name: "FORTRESS STEEL INC.",
        registered_name: "FORTRESS STEEL INC.",
        address1: "LOT 433 J.P RIZAL NANGKA",
        address2: " MARIKINA 1808",
        rdo_code: "045",
    };

    const { data, setData, processing, errors } = useForm({
        period: defaultPeriod,
        non_creditable_input_vat: "0.00",
        company_tin: companyDefaults.tin,
        company_name: companyDefaults.name,
        registered_name: companyDefaults.registered_name,
        company_address1: companyDefaults.address1,
        company_address2: companyDefaults.address2,
        rdo_code: companyDefaults.rdo_code,
    });

    const creditablePreview = useMemo(() => {
        const value = Number(data.non_creditable_input_vat || 0);
        return Number.isFinite(value) ? value.toFixed(2) : "0.00";
    }, [data.non_creditable_input_vat]);

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

    useEffect(() => {
        const tin = data.company_tin.replace(/\D/g, "");

        if (tin.length !== 9) {
            setCompanyLookupStatus("idle");
            setCompanyLookupSource("");
            return;
        }

        const timeout = setTimeout(async () => {
            setCompanyLookupStatus("loading");

            try {
                const response = await fetch(`/bir/company/${tin}`, {
                    headers: {
                        Accept: "application/json",
                    },
                });

                if (!response.ok) {
                    setCompanyLookupStatus("not-found");
                    return;
                }

                const company = await response.json();

                setData({
                    ...data,
                    company_tin: company.tin || tin,
                    company_name: company.name || "",
                    registered_name: company.registered_name || company.name || "",
                    company_address1: company.address1 || "",
                    company_address2: company.address2 || "",
                    rdo_code: company.rdo_code || data.rdo_code,
                });
                setCompanyLookupSource(company.source || "company profile");
                setCompanyLookupStatus("found");
            } catch (error) {
                setCompanyLookupStatus("error");
            }
        }, 250);

        return () => clearTimeout(timeout);
    }, [data.company_tin]);

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

        if (!/^\d{9}$/.test(data.company_tin)) {
            toast.error("Company TIN must contain exactly 9 digits.");
            return;
        }

        if (!/^\d{3}$/.test(data.rdo_code)) {
            toast.error("RDO code must contain exactly 3 digits.");
            return;
        }

        const params = new URLSearchParams(data);
        window.location.href = `/download-datfile?${params.toString()}`;
    };

    return (
        <section className="w-full max-w-5xl space-y-6">
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
                        Generate one BIR-compatible purchases file for the selected reporting month.
                    </p>
                </div>

                <form onSubmit={handleDownload} className="space-y-6">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
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

                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-gray-600">
                                Non-Creditable Input VAT
                            </label>
                            <Input
                                type="number"
                                min="0"
                                step="0.01"
                                value={data.non_creditable_input_vat}
                                onChange={(e) => setData("non_creditable_input_vat", e.target.value)}
                                className="h-11 rounded-lg border-slate-300 text-gray-700"
                            />
                            {errors.non_creditable_input_vat && (
                                <p className="text-xs text-red-500">{errors.non_creditable_input_vat}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-gray-600">
                                RDO Code
                            </label>
                            <Input
                                value={data.rdo_code}
                                onChange={(e) => setData("rdo_code", e.target.value.replace(/\D/g, "").slice(0, 3))}
                                className="h-11 rounded-lg border-slate-300 text-gray-700"
                            />
                            {errors.rdo_code && (
                                <p className="text-xs text-red-500">{errors.rdo_code}</p>
                            )}
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-gray-600">
                                Taxpayer / Company TIN
                            </label>
                            <Input
                                value={data.company_tin}
                                onChange={(e) => {
                                    setCompanyLookupStatus("idle");
                                    setCompanyLookupSource("");
                                    setData("company_tin", e.target.value.replace(/\D/g, "").slice(0, 9));
                                }}
                                className="h-11 rounded-lg border-slate-300 text-gray-700"
                            />
                            {companyLookupStatus === "loading" && (
                                <p className="text-xs text-slate-500">Looking up company...</p>
                            )}
                            {companyLookupStatus === "found" && (
                                <p className="text-xs text-emerald-600">
                                    Details auto-filled from {companyLookupSource}.
                                </p>
                            )}
                            {companyLookupStatus === "not-found" && (
                                <p className="text-xs text-amber-600">TIN not found. Fill company details manually.</p>
                            )}
                            {companyLookupStatus === "error" && (
                                <p className="text-xs text-red-500">Unable to lookup company TIN.</p>
                            )}
                            <p className="text-xs text-slate-500">
                                This becomes the DAT header TIN. Vendor TINs are taken from VAT input rows.
                            </p>
                            {errors.company_tin && (
                                <p className="text-xs text-red-500">{errors.company_tin}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-gray-600">
                                Company Name
                            </label>
                            <Input
                                value={data.company_name}
                                onChange={(e) => setData("company_name", e.target.value)}
                                className="h-11 rounded-lg border-slate-300 text-gray-700"
                            />
                            {errors.company_name && (
                                <p className="text-xs text-red-500">{errors.company_name}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-gray-600">
                                Registered Name
                            </label>
                            <Input
                                value={data.registered_name}
                                onChange={(e) => setData("registered_name", e.target.value)}
                                className="h-11 rounded-lg border-slate-300 text-gray-700"
                            />
                        </div>

                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-gray-600">
                                Address 1
                            </label>
                            <Input
                                value={data.company_address1}
                                onChange={(e) => setData("company_address1", e.target.value)}
                                className="h-11 rounded-lg border-slate-300 text-gray-700"
                            />
                            {errors.company_address1 && (
                                <p className="text-xs text-red-500">{errors.company_address1}</p>
                            )}
                        </div>

                        <div className="space-y-1.5 md:col-span-2">
                            <label className="text-xs font-medium text-gray-600">
                                Address 2
                            </label>
                            <Input
                                value={data.company_address2}
                                onChange={(e) => setData("company_address2", e.target.value)}
                                className="h-11 rounded-lg border-slate-300 text-gray-700"
                            />
                        </div>
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

                    <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="text-xs text-slate-500">
                            Non-creditable input VAT entered:{" "}
                            <span className="font-mono font-semibold text-slate-800">
                                {creditablePreview}
                            </span>
                        </div>

                        <motion.div whileHover={{ scale: 1.02 }} whileTap={{ scale: 0.98 }}>
                            <Button
                                type="submit"
                                disabled={processing || selectedIssues.invalid_count > 0}
                                className="h-11 w-full rounded-lg bg-blue-600 px-8 font-medium text-white shadow-sm transition-all hover:bg-blue-700 sm:w-auto"
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

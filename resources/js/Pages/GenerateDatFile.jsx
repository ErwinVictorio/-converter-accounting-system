import React, { useEffect, useMemo } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import { motion } from "framer-motion";

import MainLayout from "@/Layouts/MainLayout";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";

const DAT_TYPES = {
    purchase: { heading: "RELIEF Purchases", rows: "VAT input" },
    sales: { heading: "RELIEF Sales", rows: "Sales VAT" },
    importation: { heading: "RELIEF Importations", rows: "importation" },
    // 1601EQ/QAP rather than RELIEF: a different form, and a different file layout.
    expanded: { heading: "1601EQ Expanded WTAX", rows: "expanded withholding tax" },
};

function GenerateDatFile() {
    const {
        flash,
        recordType = "purchase",
        availablePeriods = [],
        periodIssues = {},
        birCompanies = [],
        selectedWithholdingAgent = null,
    } = usePage().props;
    const currentMonth = new Date().toISOString().slice(0, 7);
    const defaultPeriod = availablePeriods[0]?.value || currentMonth;
    const defaultAgent = selectedWithholdingAgent || birCompanies[0] || {
        tin: "008791976",
        branch_code: "0000",
        name: "FORTRESS STEEL INC.",
    };

    const { data, setData, processing, errors } = useForm({
        period: defaultPeriod,
        record_type: recordType,
        withholding_agent_tin: defaultAgent.tin,
        withholding_agent_branch_code: defaultAgent.branch_code || "0000",
    });

    const datType = DAT_TYPES[data.record_type] || DAT_TYPES.purchase;

    const selectedPeriod = useMemo(() => {
        return availablePeriods.find((period) => period.value === data.period);
    }, [availablePeriods, data.period]);

    const selectedIssues = periodIssues[data.period] || {
        invalid_count: 0,
        errors: [],
    };
    const selectedBirCompanyKey = `${data.withholding_agent_tin}|${data.withholding_agent_branch_code}`;

    useEffect(() => {
        if (flash?.error) toast.error(flash.error);
        if (flash?.success) toast.success(flash.success);
    }, [flash]);

    useEffect(() => {
        setData("record_type", recordType);
        setData("period", availablePeriods[0]?.value || currentMonth);
        setData("withholding_agent_tin", defaultAgent.tin);
        setData("withholding_agent_branch_code", defaultAgent.branch_code || "0000");
    }, [recordType, availablePeriods, selectedWithholdingAgent]);

    const handleRecordTypeChange = (value) => {
        setData("record_type", value);

        router.get(
            "/generate-datfile",
            { record_type: value },
            {
                preserveScroll: true,
                replace: true,
            }
        );
    };

    const handleWithholdingAgentChange = (value) => {
        const [tin, branchCode] = value.split("|");
        setData((current) => ({
            ...current,
            withholding_agent_tin: tin,
            withholding_agent_branch_code: branchCode || "0000",
            period: currentMonth,
        }));

        router.get(
            "/generate-datfile",
            {
                record_type: "expanded",
                withholding_agent_tin: tin,
                withholding_agent_branch_code: branchCode || "0000",
            },
            {
                preserveScroll: true,
                replace: true,
            }
        );
    };

    const handleDownload = (e) => {
        e.preventDefault();

        if (!data.period) {
            toast.error("Please select a reporting month.");
            return;
        }

        if (!data.record_type) {
            toast.error("Please select the DAT file type.");
            return;
        }

        if (availablePeriods.length > 0 && !selectedPeriod) {
            toast.error(`No ${datType.rows} records found for the selected reporting month.`);
            return;
        }

        if (selectedIssues.invalid_count > 0) {
            toast.error(`Please fix invalid ${datType.rows} rows before downloading the DAT file.`);
            return;
        }

        const params = new URLSearchParams({
            period: data.period,
            record_type: data.record_type,
        });

        if (data.record_type === "expanded") {
            params.set("withholding_agent_tin", data.withholding_agent_tin);
            params.set("withholding_agent_branch_code", data.withholding_agent_branch_code);
        }

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
                        Generate {datType.heading} DAT
                    </h2>
                    <p className="text-xs text-gray-500">
                        Select a type and reporting month to download one DAT file from your uploaded records.
                    </p>
                </div>

                <form onSubmit={handleDownload} className="space-y-6">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-gray-600">
                                DAT Type
                            </label>
                            <select
                                value={data.record_type}
                                onChange={(e) => handleRecordTypeChange(e.target.value)}
                                className={`flex h-11 w-full rounded-lg border bg-white px-3 py-2 text-sm text-gray-700 shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-[3px] ${
                                    errors.record_type
                                        ? "border-red-500 focus-visible:ring-red-500/20"
                                        : "border-slate-300 focus-visible:border-ring focus-visible:ring-ring/50"
                                }`}
                            >
                                <option value="purchase">Purchase</option>
                                <option value="sales">Sales</option>
                                <option value="importation">Importation</option>
                                <option value="expanded">Expanded WTAX</option>
                            </select>
                            {errors.record_type && (
                                <p className="text-xs text-red-500">{errors.record_type}</p>
                            )}
                        </div>

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
                            {errors.period && (
                                <p className="text-xs text-red-500">{errors.period}</p>
                            )}
                        </div>
                    </div>

                    {data.record_type === "expanded" && (
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div className="space-y-1.5">
                                <label className="text-xs font-medium text-gray-600">
                                    Known Company
                                </label>
                                <select
                                    value={selectedBirCompanyKey}
                                    onChange={(e) => handleWithholdingAgentChange(e.target.value)}
                                    className={`flex h-11 w-full rounded-lg border bg-white px-3 py-2 text-sm text-gray-700 shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-[3px] ${
                                        errors.withholding_agent_tin
                                            ? "border-red-500 focus-visible:ring-red-500/20"
                                            : "border-slate-300 focus-visible:border-ring focus-visible:ring-ring/50"
                                    }`}
                                >
                                    {birCompanies.map((company) => (
                                        <option
                                            key={`${company.tin}|${company.branch_code}`}
                                            value={`${company.tin}|${company.branch_code}`}
                                        >
                                            {company.name} ({company.tin}-{company.branch_code})
                                        </option>
                                    ))}
                                </select>
                                {errors.withholding_agent_tin && (
                                    <p className="text-xs text-red-500">{errors.withholding_agent_tin}</p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-xs font-medium text-gray-600">
                                    Company TIN
                                </label>
                                <Input
                                    value={data.withholding_agent_tin}
                                    onChange={(e) => setData("withholding_agent_tin", e.target.value.replace(/\D/g, "").slice(0, 9))}
                                    className={`h-11 rounded-lg border-slate-300 text-gray-700 ${
                                        errors.withholding_agent_tin ? "border-red-500 focus-visible:ring-red-500" : ""
                                    }`}
                                />
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-xs font-medium text-gray-600">
                                    Branch Code
                                </label>
                                <Input
                                    value={data.withholding_agent_branch_code}
                                    onChange={(e) => setData("withholding_agent_branch_code", e.target.value.replace(/\D/g, "").slice(0, 4))}
                                    className={`h-11 rounded-lg border-slate-300 text-gray-700 ${
                                        errors.withholding_agent_branch_code ? "border-red-500 focus-visible:ring-red-500" : ""
                                    }`}
                                />
                                {errors.withholding_agent_branch_code && (
                                    <p className="text-xs text-red-500">{errors.withholding_agent_branch_code}</p>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="space-y-1.5">
                        {selectedPeriod && (
                            <p className={`text-xs ${selectedIssues.invalid_count > 0 ? "text-amber-600" : "text-emerald-600"}`}>
                                {selectedPeriod.records_count} {datType.rows} rows found.
                                {selectedIssues.invalid_count > 0
                                    ? ` ${selectedIssues.invalid_count} need BIR info fixes.`
                                    : " Ready for DAT generation."}
                            </p>
                        )}
                        {availablePeriods.length === 0 && (
                            <p className="text-xs text-amber-600">
                                No {datType.rows} records yet.
                            </p>
                        )}
                    </div>

                    {selectedIssues.invalid_count > 0 && (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            <p className="font-semibold">
                                Fix {datType.rows} rows before downloading DAT
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

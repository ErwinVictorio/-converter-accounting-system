import React, { useEffect } from "react";
import { useForm, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import { motion } from "framer-motion";
import MainLayout from "@/Layouts/MainLayout";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";

function GenerateDatFile() {
    const { flash } = usePage().props;

    const { data, setData, processing, errors } = useForm({
        startDate: "",
        endDate: "",
    });

    useEffect(() => {
        if (flash?.error) toast.error(flash.error);
        if (flash?.success) toast.success(flash.success);
    }, [flash]);

    const handleDownload = (e) => {
        e.preventDefault();

        if (!data.startDate || !data.endDate) {
            toast.error("Please select both Start Date and End Date.");
            return;
        }

        if (data.startDate > data.endDate) {
            toast.error("Start Date cannot be after End Date.");
            return;
        }

        const params = new URLSearchParams({
            startDate: data.startDate,
            endDate: data.endDate,
        });

        window.location.href = `/download-datfile?${params.toString()}`;
    };

    return (
        <section className="p-8 max-w-4xl">
            {/* Animated Card Container */}
            <motion.div
                initial={{ opacity: 0, y: 15 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3, ease: "easeOut" }}
                className="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm space-y-6"
            >
                <div>
                    <h2 className="text-lg font-semibold text-gray-800">
                        Generate DAT File
                    </h2>
                    <p className="text-xs text-gray-500">
                        Select a date range to filter purchase records for file generation.
                    </p>
                </div>

                <form onSubmit={handleDownload} className="flex flex-wrap items-end gap-4">
                    {/* Start Date Field */}
                    <div className="space-y-1.5 flex-1 min-w-[200px]">
                        <label className="text-xs font-medium text-gray-600">
                            Start Date
                        </label>
                        <Input
                            type="date"
                            value={data.startDate}
                            onChange={(e) => setData("startDate", e.target.value)}
                            className="h-11 rounded-xl border-purple-300 text-gray-700 focus-visible:ring-purple-500"
                        />
                        {errors.startDate && (
                            <p className="text-xs text-red-500">{errors.startDate}</p>
                        )}
                    </div>

                    {/* End Date Field */}
                    <div className="space-y-1.5 flex-1 min-w-[200px]">
                        <label className="text-xs font-medium text-gray-600">
                            End Date
                        </label>
                        <Input
                            type="date"
                            value={data.endDate}
                            onChange={(e) => setData("endDate", e.target.value)}
                            className="h-11 rounded-xl border-purple-300 text-gray-700 focus-visible:ring-purple-500"
                        />
                        {errors.endDate && (
                            <p className="text-xs text-red-500">{errors.endDate}</p>
                        )}
                    </div>

                    {/* Animated Download Button */}
                    <motion.div whileHover={{ scale: 1.02 }} whileTap={{ scale: 0.98 }}>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="h-11 rounded-xl bg-blue-500 px-8 font-medium text-white shadow-sm transition-all hover:bg-blue-600"
                        >
                            Download
                        </Button>
                    </motion.div>
                </form>
            </motion.div>
        </section>
    );
}

GenerateDatFile.layout = (page) => (
    <MainLayout title="Generate DAT File">{page}</MainLayout>
);

export default GenerateDatFile;
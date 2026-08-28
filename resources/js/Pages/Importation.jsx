import { useEffect, useState } from "react";
import { usePage, router } from "@inertiajs/react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { Download, FileSpreadsheet, Loader2, Plus, UploadCloud } from "lucide-react";
import { motion } from "framer-motion";

import MainLayout from "@/Layouts/MainLayout";
import { Button } from "@/Components/ui/button";
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from "@/Components/ui/card";
import ImportationFormFields, {
    defaultValues,
    useComputedVat,
} from "@/Components/Importation/ImportationFormFields";
import { importationSchema } from "@/lib/FormSchema";

const containerVariants = {
    hidden: { opacity: 0, y: 15 },
    visible: {
        opacity: 1,
        y: 0,
        transition: { duration: 0.4, staggerChildren: 0.08 },
    },
};

const itemVariants = {
    hidden: { opacity: 0, y: 10 },
    visible: { opacity: 1, y: 0, transition: { duration: 0.3 } },
};

/**
 * Keying a new importation, and nothing else -- the stored rows are browsed and
 * maintained under Record > Importation Records, so this screen no longer
 * carries the listing. Saving redirects back here with a flash message.
 */
function Importation() {
    const { flash } = usePage().props;
    const [activeTab, setActiveTab] = useState("manual");
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isUploading, setIsUploading] = useState(false);
    const [selectedFile, setSelectedFile] = useState(null);
    const [uploadProgress, setUploadProgress] = useState(null);

    const {
        register,
        handleSubmit,
        reset,
        setError,
        setValue,
        watch,
        formState: { errors },
    } = useForm({
        resolver: zodResolver(importationSchema),
        defaultValues,
    });

    useComputedVat(watch, setValue);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    const onSubmit = (formData) => {
        setIsSubmitting(true);

        router.post("/importation", formData, {
            preserveScroll: true,
            onSuccess: () => {
                reset(defaultValues);
                setIsSubmitting(false);
            },
            onError: (err) => {
                setIsSubmitting(false);
                Object.keys(err || {}).forEach((key) => {
                    setError(key, { message: err[key] });
                });
            },
        });
    };

    const onUpload = (event) => {
        event.preventDefault();

        if (!selectedFile) {
            toast.error("Please select an Importation Excel file.");
            return;
        }

        const payload = new FormData();
        payload.append("excel_file", selectedFile);

        setIsUploading(true);
        setUploadProgress(null);

        router.post("/importation/upload", payload, {
            forceFormData: true,
            preserveScroll: true,
            onProgress: (progress) => setUploadProgress(progress?.percentage ?? null),
            onSuccess: () => {
                setSelectedFile(null);
                setUploadProgress(null);
                event.target.reset();
            },
            onFinish: () => setIsUploading(false),
        });
    };

    const tabClass = (tab) =>
        `inline-flex h-9 items-center gap-2 rounded-md px-4 text-sm font-medium transition ${
            activeTab === tab
                ? "bg-[#0344a4] text-white shadow-sm"
                : "bg-white text-slate-600 hover:bg-slate-100"
        }`;

    return (
        <motion.section
            className="mx-auto max-w-7xl space-y-6"
            initial="hidden"
            animate="visible"
            variants={containerVariants}
        >
            <motion.h2
                variants={itemVariants}
                className="text-2xl font-bold tracking-tight text-slate-800"
            >
                Importation
            </motion.h2>

            <motion.div
                variants={itemVariants}
                className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1"
            >
                <button
                    type="button"
                    className={tabClass("manual")}
                    onClick={() => setActiveTab("manual")}
                >
                    <Plus className="h-4 w-4" />
                    Manual Entry
                </button>
                <button
                    type="button"
                    className={tabClass("upload")}
                    onClick={() => setActiveTab("upload")}
                >
                    <UploadCloud className="h-4 w-4" />
                    Upload Data
                </button>
            </motion.div>

            {activeTab === "manual" && (
            <motion.div variants={itemVariants}>
                <Card className="w-full overflow-hidden rounded-xl border border-slate-100 bg-white shadow-sm">
                    <CardHeader className="border-b border-slate-100 bg-slate-50/50 py-4">
                        <CardTitle className="text-lg font-medium text-slate-800">
                            Add Importation Entry
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-6">
                        <form id="importation-form" onSubmit={handleSubmit(onSubmit)}>
                            <ImportationFormFields
                                errors={errors}
                                register={register}
                                watch={watch}
                                setValue={setValue}
                            />
                        </form>
                    </CardContent>

                    <CardFooter className="flex justify-end border-t border-slate-100 bg-slate-50/50 p-4">
                        <Button
                            type="submit"
                            form="importation-form"
                            disabled={isSubmitting}
                            className="min-w-[110px] bg-[#0344a4] px-6 text-white hover:bg-[#023384]"
                        >
                            {isSubmitting ? (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            ) : (
                                <span className="flex items-center gap-1.5">
                                    <Plus className="h-4 w-4" /> Save Entry
                                </span>
                            )}
                        </Button>
                    </CardFooter>
                </Card>
            </motion.div>
            )}

            {activeTab === "upload" && (
            <motion.div variants={itemVariants}>
                <Card className="w-full overflow-hidden rounded-xl border border-slate-100 bg-white shadow-sm">
                    <CardHeader className="border-b border-slate-100 bg-slate-50/50 py-4">
                        <CardTitle className="flex items-center gap-2 text-lg font-medium text-slate-800">
                            <FileSpreadsheet className="h-5 w-5 text-[#0344a4]" />
                            Upload Importation Data
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-6">
                        <form id="importation-upload-form" onSubmit={onUpload} className="space-y-5">
                            <div className="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_auto_auto]">
                                <div className="space-y-2">
                                    <label className="text-sm font-medium text-slate-700">
                                        Excel File <span className="text-red-500">*</span>
                                    </label>
                                    <input
                                        type="file"
                                        accept=".xlsx,.xls,.csv"
                                        onChange={(event) => setSelectedFile(event.target.files?.[0] ?? null)}
                                        className="block w-full rounded-md border border-slate-200 bg-white text-sm text-slate-700 shadow-sm file:mr-4 file:h-9 file:border-0 file:bg-slate-100 file:px-4 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#0344a4]"
                                    />
                                    <p className="text-xs text-slate-500">
                                        Template: Importation_Upload_Template_Updated.xlsx
                                    </p>
                                </div>

                                <div className="flex items-end">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        asChild
                                        className="h-10 min-w-[165px] border-slate-200 bg-white px-4 text-slate-700 hover:bg-slate-50"
                                    >
                                        <a href="/importation/template">
                                            <Download className="h-4 w-4" />
                                            Download Template
                                        </a>
                                    </Button>
                                </div>

                                <div className="flex items-end">
                                    <Button
                                        type="submit"
                                        disabled={isUploading}
                                        className="h-10 min-w-[130px] bg-[#0344a4] px-6 text-white hover:bg-[#023384]"
                                    >
                                        {isUploading ? (
                                            <Loader2 className="h-4 w-4 animate-spin" />
                                        ) : (
                                            <span className="flex items-center gap-1.5">
                                                <UploadCloud className="h-4 w-4" /> Upload
                                            </span>
                                        )}
                                    </Button>
                                </div>
                            </div>

                            {selectedFile && (
                                <div className="rounded-md border border-slate-100 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                                    {selectedFile.name}
                                </div>
                            )}

                            {uploadProgress !== null && (
                                <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        className="h-full bg-[#0344a4] transition-all"
                                        style={{ width: `${uploadProgress}%` }}
                                    />
                                </div>
                            )}
                        </form>
                    </CardContent>
                </Card>
            </motion.div>
            )}
        </motion.section>
    );
}

Importation.layout = (page) => (
    <MainLayout title="Importation">{page}</MainLayout>
);

export default Importation;

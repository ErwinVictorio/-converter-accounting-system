import { useEffect } from "react";
import { useForm } from "@inertiajs/react";

import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import { formatTinInput } from "@/Components/Records/format";

/**
 * The BIR vendor identity a purchase row is filed under.
 *
 * Only the purchase table offers this -- the DAT detail line needs a TIN plus
 * either a company name or a full individual name, and the uploaded workbook
 * carries neither reliably. Saving PUTs to the same endpoint the combined
 * records screen used; nothing about the DAT layout changes here.
 */
export default function BirVendorDialog({ record, onClose }) {
    const { data, setData, put, processing, errors, reset, clearErrors } = useForm({
        vendor_type: "company",
        tin_number: "",
        company_name: "",
        last_name: "",
        first_name: "",
        middle_name: "",
        address1: "",
        address2: "",
    });

    // Refill whenever a different row opens the dialog.
    useEffect(() => {
        if (!record) return;

        clearErrors();
        setData({
            vendor_type: record.vendor_type || "company",
            tin_number: formatTinInput(record.tin_number || ""),
            company_name: record.company_name || record.supplier_name || "",
            last_name: record.last_name || "",
            first_name: record.first_name || "",
            middle_name: record.middle_name || "",
            address1: record.address1 || "",
            address2: record.address2 || "",
        });
    }, [record?.id]);

    const handleClose = () => {
        reset();
        clearErrors();
        onClose();
    };

    const handleSubmit = (event) => {
        event.preventDefault();

        if (!record) return;

        put(`/records/${record.id}/bir-info`, {
            preserveScroll: true,
            onSuccess: handleClose,
        });
    };

    return (
        <Dialog open={Boolean(record)} onOpenChange={(open) => !open && handleClose()}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader className="border-b border-slate-100 pb-4 pr-8">
                    <DialogTitle className="text-base font-semibold text-slate-900">
                        BIR Vendor Information
                    </DialogTitle>
                </DialogHeader>

                <form
                    id="bir-info-form"
                    onSubmit={handleSubmit}
                    className="grid grid-cols-1 gap-4 md:grid-cols-2"
                >
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-slate-700">Vendor Type</label>
                        <select
                            value={data.vendor_type}
                            onChange={(e) => setData("vendor_type", e.target.value)}
                            className="flex h-10 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-xs transition-colors focus-visible:border-blue-500 focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-blue-500/20"
                        >
                            <option value="company">Company</option>
                            <option value="individual">Individual</option>
                        </select>
                        {errors.vendor_type && (
                            <p className="text-xs font-medium text-red-500">{errors.vendor_type}</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <label className="text-sm font-medium text-slate-700">
                            TIN Number <span className="text-red-500">*</span>
                        </label>
                        <Input
                            value={data.tin_number}
                            onChange={(e) => setData("tin_number", formatTinInput(e.target.value))}
                            placeholder="000-000-000-000"
                            className={`h-10 bg-white ${errors.tin_number ? "border-red-500 focus-visible:ring-red-500" : ""}`}
                        />
                        {errors.tin_number && (
                            <p className="text-xs font-medium text-red-500">{errors.tin_number}</p>
                        )}
                    </div>

                    {data.vendor_type === "company" ? (
                        <div className="space-y-2 md:col-span-2">
                            <label className="text-sm font-medium text-slate-700">
                                Company Name <span className="text-red-500">*</span>
                            </label>
                            <Input
                                value={data.company_name}
                                onChange={(e) => setData("company_name", e.target.value)}
                                className={`h-10 bg-white ${errors.company_name ? "border-red-500 focus-visible:ring-red-500" : ""}`}
                            />
                            {errors.company_name && (
                                <p className="text-xs font-medium text-red-500">{errors.company_name}</p>
                            )}
                        </div>
                    ) : (
                        <>
                            <div className="space-y-2">
                                <label className="text-sm font-medium text-slate-700">
                                    Last Name <span className="text-red-500">*</span>
                                </label>
                                <Input
                                    value={data.last_name}
                                    onChange={(e) => setData("last_name", e.target.value)}
                                    className={`h-10 bg-white ${errors.last_name ? "border-red-500 focus-visible:ring-red-500" : ""}`}
                                />
                                {errors.last_name && (
                                    <p className="text-xs font-medium text-red-500">{errors.last_name}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <label className="text-sm font-medium text-slate-700">
                                    First Name <span className="text-red-500">*</span>
                                </label>
                                <Input
                                    value={data.first_name}
                                    onChange={(e) => setData("first_name", e.target.value)}
                                    className={`h-10 bg-white ${errors.first_name ? "border-red-500 focus-visible:ring-red-500" : ""}`}
                                />
                                {errors.first_name && (
                                    <p className="text-xs font-medium text-red-500">{errors.first_name}</p>
                                )}
                            </div>

                            <div className="space-y-2 md:col-span-2">
                                <label className="text-sm font-medium text-slate-700">
                                    Middle Name <span className="text-red-500">*</span>
                                </label>
                                <Input
                                    value={data.middle_name}
                                    onChange={(e) => setData("middle_name", e.target.value)}
                                    className={`h-10 bg-white ${errors.middle_name ? "border-red-500 focus-visible:ring-red-500" : ""}`}
                                />
                                {errors.middle_name && (
                                    <p className="text-xs font-medium text-red-500">{errors.middle_name}</p>
                                )}
                            </div>
                        </>
                    )}

                    <div className="space-y-2">
                        <label className="text-sm font-medium text-slate-700">Address 1</label>
                        <Input
                            value={data.address1}
                            onChange={(e) => setData("address1", e.target.value)}
                            className={`h-10 bg-white ${errors.address1 ? "border-red-500 focus-visible:ring-red-500" : ""}`}
                        />
                        <p className="text-xs text-slate-500">
                            Street/building/barangay only. Do not include comma.
                        </p>
                    </div>

                    <div className="space-y-2">
                        <label className="text-sm font-medium text-slate-700">Address 2 / City</label>
                        <Input
                            value={data.address2}
                            onChange={(e) => setData("address2", e.target.value)}
                            className={`h-10 bg-white ${errors.address2 ? "border-red-500 focus-visible:ring-red-500" : ""}`}
                        />
                        <p className="text-xs text-slate-500">
                            City/province goes here as a separate DAT field.
                        </p>
                    </div>
                </form>

                <DialogFooter className="border-t border-slate-100 pt-4">
                    <Button type="button" variant="outline" onClick={handleClose}>
                        Cancel
                    </Button>
                    <Button type="submit" form="bir-info-form" disabled={processing}>
                        {processing ? "Saving..." : "Save"}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

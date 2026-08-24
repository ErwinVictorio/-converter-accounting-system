import { useEffect, useState } from "react";
import { usePage, router } from "@inertiajs/react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { Loader2, Pencil, Plus, Trash2, X } from "lucide-react";
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
import { Input } from "@/Components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/Components/ui/dialog";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/Components/ui/table";
import DataTablePagination from "@/Layouts/Pagination";
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

// The reporting month is always the one just closed: filing in August 2026
// covers July 2026.
const previousMonth = () => {
  const now = new Date();
  const previous = new Date(now.getFullYear(), now.getMonth() - 1, 1);

  return `${previous.getFullYear()}-${String(previous.getMonth() + 1).padStart(2, "0")}`;
};

// The entry screen mirrors the customs paperwork: users key total landed cost,
// and "all charges before release" + "taxable goods" are derived from it. Those
// two are shown read-only here and computed server-side, never posted.
const defaultValues = {
  tax_month: previousMonth(),
  import_entry_no: "",
  assessment_date: "",
  supplier: "",
  importation_date: "",
  country: "",
  total_landed_cost: "",
  dutiable_value: "0",
  exempt: "0",
  vat_rate: "12",
  vat_payable: "0.00",
  or_number: "000",
  payment_date: "",
};

const money = (value) =>
  Number(value ?? 0).toLocaleString("en-US", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

// Same two formulas the controller uses; null while the inputs are incomplete.
const derive = (landedRaw, subtractRaw) => {
  const landed = Number(landedRaw);
  const subtract = Number(subtractRaw ?? 0);

  if (String(landedRaw ?? "").trim() === "" || Number.isNaN(landed) || Number.isNaN(subtract)) {
    return null;
  }

  return Math.round((landed - subtract) * 100) / 100;
};

// VAT is taxable goods x VAT rate, never keyed. Installed on both the add and
// the edit form, so re-opening a stored row also refreshes its VAT.
function useComputedVat(watch, setValue) {
  const landed = watch("total_landed_cost");
  const exempt = watch("exempt");
  const vatRate = watch("vat_rate");

  useEffect(() => {
    const taxable = derive(landed, exempt);
    const rate = Number(vatRate);

    if (taxable === null || String(vatRate ?? "").trim() === "" || Number.isNaN(rate)) {
      return;
    }

    setValue("vat_payable", (Math.round(taxable * rate) / 100).toFixed(2), {
      shouldValidate: false,
    });
  }, [landed, exempt, vatRate, setValue]);
}

function Importation() {
  const { flash, entries, months = [], filters = {} } = usePage().props;
  const rows = entries?.data || [];
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isUpdating, setIsUpdating] = useState(false);
  const [editingEntry, setEditingEntry] = useState(null);
  const [monthFilter, setMonthFilter] = useState(filters.tax_month || "");

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

  const {
    register: registerEdit,
    handleSubmit: handleEditSubmit,
    reset: resetEdit,
    setError: setEditError,
    setValue: setEditValue,
    watch: watchEdit,
    formState: { errors: editErrors },
  } = useForm({
    resolver: zodResolver(importationSchema),
    defaultValues,
  });

  useComputedVat(watch, setValue);
  useComputedVat(watchEdit, setEditValue);

  useEffect(() => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
  }, [flash]);

  useEffect(() => {
    setMonthFilter(filters.tax_month || "");
  }, [filters.tax_month]);

  const applyServerErrors = (err, applyError) => {
    Object.keys(err || {}).forEach((key) => {
      applyError(key, { message: err[key] });
    });
  };

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
        applyServerErrors(err, setError);
      },
    });
  };

  const onEditSubmit = (formData) => {
    if (!editingEntry) return;

    setIsUpdating(true);

    router.put(`/importation/${editingEntry.id}`, formData, {
      preserveScroll: true,
      onSuccess: () => {
        setIsUpdating(false);
        handleCloseEdit();
      },
      onError: (err) => {
        setIsUpdating(false);
        applyServerErrors(err, setEditError);
      },
    });
  };

  const handleDelete = (id) => {
    if (confirm("Delete this importation entry? The linked DAT record will also be removed.")) {
      router.delete(`/importation/${id}`, { preserveScroll: true });
    }
  };

  const handleMonthChange = (value) => {
    setMonthFilter(value);
    router.get(
      "/importation",
      value ? { tax_month: value } : {},
      { preserveState: true, preserveScroll: true, replace: true }
    );
  };

  const handleOpenEdit = (entry) => {
    setEditingEntry(entry);
    resetEdit({
      tax_month: (entry.tax_month || "").slice(0, 7),
      import_entry_no: entry.import_entry_no || "",
      assessment_date: (entry.assessment_date || "").slice(0, 10),
      supplier: entry.supplier || "",
      importation_date: (entry.importation_date || "").slice(0, 10),
      country: entry.country || "",
      total_landed_cost: String(entry.total_landed_cost ?? "0"),
      dutiable_value: String(entry.dutiable_value ?? "0"),
      exempt: String(entry.exempt ?? "0"),
      vat_rate: String(entry.vat_rate ?? "12"),
      vat_payable: String(entry.vat_payable ?? "0"),
      or_number: entry.or_number || "",
      payment_date: (entry.payment_date || "").slice(0, 10),
    });
  };

  const handleCloseEdit = () => {
    if (isUpdating) return;
    setEditingEntry(null);
    resetEdit(defaultValues);
  };

  const renderField = (
    field,
    label,
    { type = "text", placeholder = "", step, suffix, readOnly = false, hint, onValueChange } = {},
    fieldErrors,
    fieldRegister
  ) => {
    const registration = fieldRegister(field);

    return (
      <div className="space-y-2">
        <label className={`text-sm font-medium ${readOnly ? "text-slate-500" : "text-slate-700"}`}>
          {label} {!readOnly && <span className="text-red-500">*</span>}
        </label>
        <div className="flex items-center gap-2">
          <Input
            type={type}
            step={step}
            placeholder={placeholder}
            readOnly={readOnly}
            tabIndex={readOnly ? -1 : undefined}
            {...registration}
            onChange={(event) => {
              registration.onChange(event);
              onValueChange?.(event.target.value);
            }}
            className={`${readOnly ? "bg-slate-100 text-slate-600 cursor-not-allowed" : ""} ${
              fieldErrors[field] ? "border-red-500 focus-visible:ring-red-500" : ""
            }`}
          />
          {suffix && <span className="text-sm text-slate-500">{suffix}</span>}
        </div>
        {hint && <p className="text-xs text-slate-400">{hint}</p>}
        {fieldErrors[field] && (
          <p className="text-xs text-red-500 font-medium">{fieldErrors[field].message}</p>
        )}
      </div>
    );
  };

  // Read-only twin of renderField for the two amounts the system computes --
  // the greyed boxes on the old entry screen.
  const renderDerivedField = (label, value, formula) => (
    <div className="space-y-2">
      <label className="text-sm font-medium text-slate-500">{label}</label>
      <Input
        readOnly
        tabIndex={-1}
        value={value === null ? "" : money(value)}
        placeholder="0.00"
        className={`bg-slate-100 text-slate-600 cursor-not-allowed ${
          value !== null && value < 0 ? "text-red-600 font-medium" : ""
        }`}
      />
      <p className="text-xs text-slate-400">{formula}</p>
    </div>
  );

  // Field order and wording follow the old Importation Data Entry Screen.
  // Sequence number is intentionally absent -- it is assigned server-side.
  const renderFormFields = (fieldErrors, fieldRegister, fieldWatch, fieldSetValue) => {
    const landed = fieldWatch("total_landed_cost");
    const charges = derive(landed, fieldWatch("dutiable_value"));
    const taxableGoods = derive(landed, fieldWatch("exempt"));

    const amount = { type: "number", step: "0.01", placeholder: "0.00" };

    return (
      <div className="space-y-6">
        <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
          {renderField("tax_month", "Tax Month", { type: "month" }, fieldErrors, fieldRegister)}
          {renderField("import_entry_no", "Import Entry No.", { placeholder: "e.g. C-12345" }, fieldErrors, fieldRegister)}
          {renderField("supplier", "Name of Seller", { placeholder: "Foreign supplier name" }, fieldErrors, fieldRegister)}
          {renderField("assessment_date", "Assessment / Release Date", { type: "date" }, fieldErrors, fieldRegister)}
          {renderField("importation_date", "Date of Importation", { type: "date" }, fieldErrors, fieldRegister)}
          {renderField("country", "Country of Origin", { placeholder: "e.g. CHINA" }, fieldErrors, fieldRegister)}
        </div>

        <div className="rounded-lg border border-slate-100 bg-slate-50/40 p-4">
          <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
            {renderField("vat_rate", "VAT rate", { ...amount, placeholder: "12", suffix: "%" }, fieldErrors, fieldRegister)}
            {renderField(
              "total_landed_cost",
              "Total Landed Cost",
              {
                ...amount,
                // Dutiable value tracks the landed cost. Editing it afterwards is
                // still allowed -- that is what makes charges non-zero.
                onValueChange: (value) =>
                  fieldSetValue("dutiable_value", value, { shouldValidate: false }),
              },
              fieldErrors,
              fieldRegister
            )}
          </div>
        </div>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div className="grid grid-cols-1 gap-5 rounded-lg border border-slate-100 bg-slate-50/40 p-4 sm:grid-cols-2">
            {renderField("dutiable_value", "Dutiable Value", amount, fieldErrors, fieldRegister)}
            {renderDerivedField(
              "All Charges Before Release from Custom's Custody",
              charges,
              "Total Landed Cost − Dutiable Value"
            )}
          </div>

          <div className="grid grid-cols-1 gap-5 rounded-lg border border-slate-100 bg-slate-50/40 p-4 sm:grid-cols-2">
            {renderField("exempt", "Exempt", amount, fieldErrors, fieldRegister)}
            {renderDerivedField("Taxable Goods", taxableGoods, "Total Landed Cost − Exempt")}
          </div>
        </div>

        <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
          {renderField("or_number", "OR Number", { placeholder: "e.g. 987654" }, fieldErrors, fieldRegister)}
          {renderField("payment_date", "Date of VAT Payment", { type: "date" }, fieldErrors, fieldRegister)}
          {renderField(
            "vat_payable",
            "VAT",
            { ...amount, readOnly: true, hint: "Taxable Goods × VAT rate" },
            fieldErrors,
            fieldRegister
          )}
        </div>
      </div>
    );
  };

  return (
    <motion.section
      className="space-y-6 p-6 max-w-7xl mx-auto"
      initial="hidden"
      animate="visible"
      variants={containerVariants}
    >
      <motion.h2
        variants={itemVariants}
        className="text-2xl font-bold tracking-tight text-slate-800"
      >
        Importation Manual Entry
      </motion.h2>

      <motion.div variants={itemVariants}>
        <Card className="w-full shadow-sm border border-slate-100 rounded-xl bg-white overflow-hidden">
          <CardHeader className="py-4 border-b border-slate-100 bg-slate-50/50">
            <CardTitle className="text-lg font-medium text-slate-800">
              Add Importation Entry
            </CardTitle>
          </CardHeader>
          <CardContent className="p-6">
            <form id="importation-form" onSubmit={handleSubmit(onSubmit)}>
              {renderFormFields(errors, register, watch, setValue)}
            </form>
          </CardContent>

          <CardFooter className="flex justify-end p-4 bg-slate-50/50 border-t border-slate-100">
            <Button
              type="submit"
              form="importation-form"
              disabled={isSubmitting}
              className="bg-[#0344a4] hover:bg-[#023384] text-white px-6 min-w-[110px]"
            >
              {isSubmitting ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <span className="flex items-center gap-1.5">
                  <Plus className="w-4 h-4" /> Save Entry
                </span>
              )}
            </Button>
          </CardFooter>
        </Card>
      </motion.div>

      <motion.div variants={itemVariants}>
        <Card className="w-full shadow-sm border border-slate-100 rounded-xl overflow-hidden bg-white">
          <CardHeader className="py-4 border-b border-slate-100 bg-slate-50/50 flex flex-row items-center justify-between gap-4">
            <CardTitle className="text-lg font-medium text-slate-800">
              Importation Records
            </CardTitle>
            <div className="flex items-center gap-2">
              <select
                value={monthFilter}
                onChange={(event) => handleMonthChange(event.target.value)}
                className="h-9 rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">All months</option>
                {months.map((month) => (
                  <option key={month.value} value={month.value}>
                    {month.label} ({month.records_count})
                  </option>
                ))}
              </select>
              {monthFilter && (
                <Button type="button" variant="ghost" onClick={() => handleMonthChange("")} className="h-9">
                  <X className="h-4 w-4" />
                  Clear
                </Button>
              )}
            </div>
          </CardHeader>

          <CardContent className="p-0 overflow-x-auto">
            <Table>
              <TableHeader className="bg-slate-50/70">
                <TableRow>
                  <TableHead className="font-semibold text-slate-700 pl-6">#</TableHead>
                  <TableHead className="font-semibold text-slate-700">Tax Month</TableHead>
                  <TableHead className="font-semibold text-slate-700">Import Entry No.</TableHead>
                  <TableHead className="font-semibold text-slate-700">Name of Seller</TableHead>
                  <TableHead className="font-semibold text-slate-700">Country</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Landed Cost</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Dutiable</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Charges</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Exempt</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">Taxable Goods</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">VAT Rate</TableHead>
                  <TableHead className="font-semibold text-slate-700 text-right">VAT</TableHead>
                  <TableHead className="font-semibold text-slate-700">OR No.</TableHead>
                  <TableHead className="font-semibold text-slate-700">VAT Payment</TableHead>
                  <TableHead className="text-right pr-6 font-semibold text-slate-700">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.length > 0 ? (
                  rows.map((entry) => (
                    <TableRow key={entry.id} className="hover:bg-slate-50/50 transition-colors">
                      <TableCell className="pl-6 py-3 text-slate-500">{entry.sequence_number}</TableCell>
                      <TableCell className="whitespace-nowrap text-slate-700">
                        {(entry.tax_month || "").slice(0, 7)}
                      </TableCell>
                      <TableCell className="whitespace-nowrap font-medium text-slate-900">
                        {entry.import_entry_no}
                      </TableCell>
                      <TableCell className="min-w-[200px] text-slate-700">{entry.supplier}</TableCell>
                      <TableCell className="whitespace-nowrap text-slate-600">{entry.country}</TableCell>
                      <TableCell className="text-right whitespace-nowrap text-slate-700">{money(entry.total_landed_cost)}</TableCell>
                      <TableCell className="text-right whitespace-nowrap text-slate-600">{money(entry.dutiable_value)}</TableCell>
                      <TableCell className="text-right whitespace-nowrap text-slate-600">{money(entry.charges)}</TableCell>
                      <TableCell className="text-right whitespace-nowrap text-slate-600">{money(entry.exempt)}</TableCell>
                      <TableCell className="text-right whitespace-nowrap text-slate-700">{money(entry.taxable_goods)}</TableCell>
                      <TableCell className="text-right whitespace-nowrap text-slate-600">{money(entry.vat_rate)}</TableCell>
                      <TableCell className="text-right whitespace-nowrap text-slate-700">{money(entry.vat_payable)}</TableCell>
                      <TableCell className="whitespace-nowrap text-slate-600">{entry.or_number}</TableCell>
                      <TableCell className="whitespace-nowrap text-slate-600">
                        {(entry.payment_date || "").slice(0, 10)}
                      </TableCell>
                      <TableCell className="text-right pr-6 py-3 whitespace-nowrap">
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          onClick={() => handleOpenEdit(entry)}
                          className="h-8 w-8 text-blue-600 hover:text-blue-700 hover:bg-blue-50 cursor-pointer rounded-lg"
                        >
                          <Pencil className="h-4 w-4" />
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          onClick={() => handleDelete(entry.id)}
                          className="h-8 w-8 text-red-500 hover:text-red-600 hover:bg-red-50 cursor-pointer rounded-lg"
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={15} className="text-center py-8 text-slate-400">
                      No importation entries found.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>

          {entries?.links && <DataTablePagination links={entries.links} />}
        </Card>
      </motion.div>

      <Dialog open={Boolean(editingEntry)} onOpenChange={(open) => !open && handleCloseEdit()}>
        <DialogContent className="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Edit Importation Entry</DialogTitle>
            <DialogDescription>
              Update the manual importation record. Saving also re-syncs the linked purchase DAT row.
            </DialogDescription>
          </DialogHeader>

          <form id="edit-importation-form" onSubmit={handleEditSubmit(onEditSubmit)}>
            {renderFormFields(editErrors, registerEdit, watchEdit, setEditValue)}
          </form>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={handleCloseEdit} disabled={isUpdating}>
              Cancel
            </Button>
            <Button
              type="submit"
              form="edit-importation-form"
              disabled={isUpdating}
              className="bg-[#0344a4] hover:bg-[#023384] text-white"
            >
              {isUpdating ? <Loader2 className="w-4 h-4 animate-spin" /> : "Save Changes"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </motion.section>
  );
}

Importation.layout = (page) => (
  <MainLayout title="Importation">{page}</MainLayout>
);

export default Importation;

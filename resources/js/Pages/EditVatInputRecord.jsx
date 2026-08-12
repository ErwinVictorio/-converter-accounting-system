import React, { useEffect, useMemo } from "react";
import { Link, useForm, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import { ArrowLeft, Loader2, Save } from "lucide-react";

import MainLayout from "@/Layouts/MainLayout";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { Badge } from "@/Components/ui/badge";
import {
  Card,
  CardContent,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/Components/ui/card";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/Components/ui/table";

function EditVatInputRecord() {
  const { flash, vatInput } = usePage().props;

  const { data, setData, put, processing, errors } = useForm({
    supplier_name: "",
    tin_number: "",
    is_imported: Number(vatInput?.is_imported) === 1 ? "1" : "0",
    purchase_imported: "",
    purchase_local: "",
    services: "",
    others: "",
  });

  useEffect(() => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
  }, [flash]);

  const formatCurrency = (val) => {
    return new Intl.NumberFormat("en-US", {
      style: "decimal",
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(val || 0);
  };

  const amountFields = [
    { name: "purchase_imported", label: "Purchase Imported" },
    { name: "purchase_local", label: "Purchase Local" },
    { name: "services", label: "Services" },
    { name: "others", label: "Others" },
  ];

  const adjustmentTotal = useMemo(() => {
    return amountFields.reduce((sum, field) => {
      const value = Number(data[field.name]);
      return sum + (Number.isFinite(value) ? value : 0);
    }, 0);
  }, [data.purchase_imported, data.purchase_local, data.services, data.others]);

  const remainingTotal = useMemo(() => {
    return amountFields.reduce((sum, field) => {
      const original = Number(vatInput?.[field.name] || 0);
      const adjustment = Number(data[field.name] || 0);
      return sum + Math.max(original - adjustment, 0);
    }, 0);
  }, [data.purchase_imported, data.purchase_local, data.services, data.others, vatInput]);

  const handleAmountChange = (field, value) => {
    const max = Number(vatInput?.[field] || 0);
    const numeric = Number(value);

    if (value !== "" && Number.isFinite(numeric) && numeric > max) {
      setData(field, String(max));
      return;
    }

    setData(field, value);
  };

  const handleSubmit = (e) => {
    e.preventDefault();

    put(`/records/${vatInput.id}`, {
      preserveScroll: true,
    });
  };

  return (
    <section className="space-y-6 w-full max-w-full overflow-hidden">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p className="text-sm font-medium text-indigo-600">Edit Vat Inputs Record</p>
          <h2 className="text-2xl font-bold tracking-tight text-slate-900">
            Broker Record Adjustment
          </h2>
        </div>

        <Button asChild variant="outline" className="w-full sm:w-auto gap-2">
          <Link href="/records">
            <ArrowLeft className="h-4 w-4" />
            Back
          </Link>
        </Button>
      </div>

      <Card className="w-full shadow-sm border rounded-xl overflow-hidden bg-white">
        <CardHeader className="p-4 sm:p-6 border-b bg-gray-50/50">
          <CardTitle className="text-lg sm:text-xl font-semibold text-gray-900">
            Original Broker Record
          </CardTitle>
        </CardHeader>

        <CardContent className="p-0 overflow-x-auto">
          <Table className="min-w-[800px]">
            <TableHeader>
              <TableRow className="bg-slate-50 hover:bg-slate-50">
                <TableHead className="font-semibold text-slate-700">Supplier Name</TableHead>
                <TableHead className="font-semibold text-slate-700">TIN Number</TableHead>
                <TableHead className="font-semibold text-slate-700">Imported</TableHead>
                <TableHead className="font-semibold text-slate-700 text-right">Purchase Imported</TableHead>
                <TableHead className="font-semibold text-slate-700 text-right">Purchase Local</TableHead>
                <TableHead className="font-semibold text-slate-700 text-right">Services</TableHead>
                <TableHead className="font-semibold text-slate-700 text-right">Others</TableHead>
                <TableHead className="font-semibold text-slate-700 text-right">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow>
                <TableCell className="font-medium text-slate-900 whitespace-nowrap">
                  {vatInput.supplier_name}
                </TableCell>
                <TableCell className="text-slate-600 font-mono text-xs whitespace-nowrap">
                  {vatInput.tin_number || "-"}
                </TableCell>
                <TableCell>
                  <Badge
                    variant={Number(vatInput.is_imported) === 1 ? "default" : "secondary"}
                    className={
                      Number(vatInput.is_imported) === 1
                        ? "bg-amber-100 text-amber-800 hover:bg-amber-100 border-amber-200"
                        : "bg-slate-100 text-slate-700 hover:bg-slate-100"
                    }
                  >
                    {Number(vatInput.is_imported) === 1 ? "Yes" : "No"}
                  </Badge>
                </TableCell>
                <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                  {formatCurrency(vatInput.purchase_imported)}
                </TableCell>
                <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                  {formatCurrency(vatInput.purchase_local)}
                </TableCell>
                <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                  {formatCurrency(vatInput.services)}
                </TableCell>
                <TableCell className="text-right font-mono text-xs text-slate-700 whitespace-nowrap">
                  {formatCurrency(vatInput.others)}
                </TableCell>
                <TableCell className="text-right font-mono text-xs font-bold text-slate-900 whitespace-nowrap">
                  {formatCurrency(vatInput.total)}
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Card className="w-full shadow-sm border rounded-xl overflow-hidden bg-white">
        <CardHeader className="p-4 sm:p-6 border-b bg-gray-50/50">
          <CardTitle className="text-lg sm:text-xl font-semibold text-gray-900">
            New Adjusted Record
          </CardTitle>
        </CardHeader>

        <CardContent className="p-4 sm:p-6">
          <form id="vat-input-edit-form" onSubmit={handleSubmit} className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
              <div className="space-y-2 md:col-span-2">
                <label className="text-sm font-medium text-slate-700">
                  Supplier Name <span className="text-red-500">*</span>
                </label>
                <Input
                  value={data.supplier_name}
                  onChange={(e) => setData("supplier_name", e.target.value)}
                  placeholder="Enter supplier name"
                  className={errors.supplier_name ? "border-red-500 focus-visible:ring-red-500" : ""}
                />
                {errors.supplier_name && (
                  <p className="text-xs text-red-500 font-medium">{errors.supplier_name}</p>
                )}
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium text-slate-700">TIN Number</label>
                <Input
                  value={data.tin_number}
                  onChange={(e) => setData("tin_number", e.target.value)}
                  placeholder="000-000-000-000"
                  className={errors.tin_number ? "border-red-500 focus-visible:ring-red-500" : ""}
                />
                {errors.tin_number && (
                  <p className="text-xs text-red-500 font-medium">{errors.tin_number}</p>
                )}
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium text-slate-700">Imported</label>
                <select
                  value={data.is_imported}
                  onChange={(e) => setData("is_imported", e.target.value)}
                  className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors focus-visible:border-ring focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                  <option value="0">No</option>
                  <option value="1">Yes</option>
                </select>
                {errors.is_imported && (
                  <p className="text-xs text-red-500 font-medium">{errors.is_imported}</p>
                )}
              </div>

              {amountFields.map((field) => (
                <div key={field.name} className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">{field.label}</label>
                  <Input
                    type="number"
                    min="0"
                    max={vatInput?.[field.name] || 0}
                    step="0.01"
                    value={data[field.name]}
                    onChange={(e) => handleAmountChange(field.name, e.target.value)}
                    placeholder="0.00"
                    className={errors[field.name] ? "border-red-500 focus-visible:ring-red-500" : ""}
                  />
                  <p className="text-xs text-slate-400">
                    Available: {formatCurrency(vatInput?.[field.name])}
                  </p>
                  {errors[field.name] && (
                    <p className="text-xs text-red-500 font-medium">{errors[field.name]}</p>
                  )}
                </div>
              ))}
            </div>

            {errors.total && (
              <p className="text-xs text-red-500 font-medium">{errors.total}</p>
            )}
          </form>
        </CardContent>

        <CardFooter className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between p-4 sm:p-6 border-t bg-gray-50/50">
          <div className="grid w-full grid-cols-1 gap-2 text-sm sm:w-auto sm:grid-cols-2 sm:gap-6">
            <div>
              <span className="text-slate-500">New record total: </span>
              <span className="font-mono font-bold text-slate-900">
                {formatCurrency(adjustmentTotal)}
              </span>
            </div>
            <div>
              <span className="text-slate-500">Broker remaining total: </span>
              <span className="font-mono font-bold text-slate-900">
                {formatCurrency(remainingTotal)}
              </span>
            </div>
          </div>

          <Button
            type="submit"
            form="vat-input-edit-form"
            disabled={processing}
            className="w-full sm:w-auto bg-slate-900 text-white hover:bg-slate-800 px-6 min-w-[130px] gap-2"
          >
            {processing ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                Saving...
              </>
            ) : (
              <>
                <Save className="h-4 w-4" />
                Save
              </>
            )}
          </Button>
        </CardFooter>
      </Card>
    </section>
  );
}

EditVatInputRecord.layout = (page) => (
  <MainLayout title="Edit VAT Record">{page}</MainLayout>
);

export default EditVatInputRecord;

import React, { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { Eye, Pencil, Trash2 } from "lucide-react";

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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/Components/ui/table";
import { recordFormSchema } from "@/lib/FormSchema";
import { router, usePage } from "@inertiajs/react";
import DataTablePagination from "@/Layouts/Pagination";

function RecordEntry() {
  const [records, setRecords] = useState([]);
  const [editingId, setEditingId] = useState(null);
  const { flash, recordList } = usePage().props





  useEffect(() => {
    if (flash?.success) {
      toast.success(flash.success)
    }
    if (flash?.error) {
      toast.error(flash.error)
    }
  }, [flash])


  const {
    register,
    handleSubmit,
    reset,
    setValue,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(recordFormSchema),
    defaultValues: {
      registeredName: "",
      supplierName: "",
      supplierAddress: "",
      grossPurchase: "",
      exemptPurchase: "",
    },
  });

  // CREATE OR UPDATE FUNCTION
  function onSubmit(data) {
    const formattedData = {
      ...data,
      grossPurchase: Number(data.grossPurchase).toFixed(2),
      exemptPurchase: data.exemptPurchase
        ? Number(data.exemptPurchase).toFixed(2)
        : "0.00",
    };

    // create request for create Record

    router.post('/create-record', formattedData, {

      onSuccess: (e) => {
        console.log(e)
      },

      onError: (err) => {
        console.log(err, 'error')
      }

    })


  }

  console.log(recordList)



  return (
    <section className="space-y-6 p-6">
      {/* Form Card */}
      <Card className="w-full shadow-sm border rounded-xl overflow-hidden bg-white">
        <CardHeader className="p-6 border-b bg-gray-50/50">
          <CardTitle className="text-xl font-semibold text-gray-900">
            {editingId ? "Edit Record Entry" : "Record Entry Form"}
          </CardTitle>
        </CardHeader>

        <CardContent className="p-6">
          <form id="record-entry-form" onSubmit={handleSubmit(onSubmit)}>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
              {/* Registered Name */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Registered Name
                </label>
                <Input
                  {...register("registeredName")}
                  placeholder="Enter registered name"
                  className="w-full"
                />
                {errors.registeredName && (
                  <p className="text-xs text-red-500">
                    {errors.registeredName.message}
                  </p>
                )}
              </div>

              {/* Name of Supplier */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Name of Supplier
                </label>
                <Input
                  {...register("supplierName")}
                  placeholder="Enter supplier name"
                  className="w-full"
                />
                {errors.supplierName && (
                  <p className="text-xs text-red-500">
                    {errors.supplierName.message}
                  </p>
                )}
              </div>

              {/* Supplier Address */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Supplier Address
                </label>
                <Input
                  {...register("supplierAddress")}
                  placeholder="Enter supplier address"
                  className="w-full"
                />
                {errors.supplierAddress && (
                  <p className="text-xs text-red-500">
                    {errors.supplierAddress.message}
                  </p>
                )}
              </div>

              {/* Amount of Gross Purchase */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Amount of Gross Purchase
                </label>
                <Input
                  type="number"
                  step="0.01"
                  {...register("grossPurchase")}
                  placeholder="0.00"
                  className="w-full"
                />
                {errors.grossPurchase && (
                  <p className="text-xs text-red-500">
                    {errors.grossPurchase.message}
                  </p>
                )}
              </div>

              {/* Amount of Exempt Purchase */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Amount of Exempt Purchase
                </label>
                <Input
                  type="number"
                  step="0.01"
                  {...register("exemptPurchase")}
                  placeholder="0.00"
                  className="w-full"
                />
                {errors.exemptPurchase && (
                  <p className="text-xs text-red-500">
                    {errors.exemptPurchase.message}
                  </p>
                )}
              </div>
            </div>
          </form>
        </CardContent>

        <CardFooter className="flex justify-end gap-3 p-6 border-t bg-gray-50/50">
          <Button
            type="button"
            variant="outline"
            onClick={editingId ? handleCancelEdit : () => reset()}
            className="px-5"
          >
            {editingId ? "Cancel" : "Reset"}
          </Button>
          <Button
            type="submit"
            form="record-entry-form"
            className="bg-slate-900 text-white hover:bg-slate-800 px-5"
          >
            {editingId ? "Update Record" : "Submit"}
          </Button>
        </CardFooter>
      </Card>

      {/* List of Record Section */}
      <div className="space-y-3">
        <h3 className="text-lg font-semibold text-gray-900">List of Record</h3>
        <Card className="w-full shadow-sm border rounded-xl overflow-hidden bg-white">
          <Table>
            <TableHeader>
              <TableRow className="bg-gray-50/80 hover:bg-gray-50/80">
                <TableHead className="font-semibold text-gray-900">Registered Name</TableHead>
                <TableHead className="font-semibold text-gray-900">Supplier</TableHead>
                <TableHead className="font-semibold text-gray-900">Address</TableHead>
                <TableHead className="text-right font-semibold text-gray-900">Gross Purchase</TableHead>
                <TableHead className="text-right font-semibold text-gray-900">Exempt Purchase</TableHead>
                <TableHead className="text-center font-semibold text-gray-900">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {recordList?.data?.length > 0 ? (
                recordList?.data.map((record) => (
                  <TableRow key={record.id}>
                    <TableCell className="font-medium text-gray-900">
                      {record.resgister_name}
                    </TableCell>
                    <TableCell>{record.supplier_name}</TableCell>
                    <TableCell>{record.supplier_address}</TableCell>
                    <TableCell className="text-right">
                      ₱{record.amount_of_gross_purchase}
                    </TableCell>
                    <TableCell className="text-right">
                      ₱{record.exempt_purchase}
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center justify-center gap-2">
                        {/* VIEW BUTTON */}
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          onClick={() => handleView(record)}
                          className="h-8 text-blue-600 border-blue-200 hover:bg-blue-50"
                        >
                          <Eye className="h-3.5 w-3.5 mr-1" />
                          View
                        </Button>

                        {/* EDIT BUTTON */}
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          onClick={() => handleEdit(record)}
                          className="h-8 text-amber-600 border-amber-200 hover:bg-amber-50"
                        >
                          <Pencil className="h-3.5 w-3.5 mr-1" />
                          Edit
                        </Button>

                        {/* DELETE BUTTON */}
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          onClick={() => handleDelete(record.id)}
                          className="h-8 text-red-600 border-red-200 hover:bg-red-50"
                        >
                          <Trash2 className="h-3.5 w-3.5 mr-1" />
                          Delete
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              ) : (
                <TableRow>
                  <TableCell
                    colSpan={6}
                    className="h-32 text-center text-muted-foreground"
                  >
                    No records found. Submit the form above to add an entry.
                  </TableCell>
                </TableRow>
              )}

              <DataTablePagination links={recordList.links} />
            </TableBody>
          </Table>
        </Card>
      </div>
    </section>
  );
}

RecordEntry.layout = (page) => (
  <MainLayout title="Purchase Entries">{page}</MainLayout>
);

export default RecordEntry;
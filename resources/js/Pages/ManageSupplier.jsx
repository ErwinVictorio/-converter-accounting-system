import React, { useEffect, useState } from "react";
import { usePage, router } from "@inertiajs/react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { Loader2, Pencil, Plus, Search, Trash2, X } from "lucide-react";
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
import { supplierSchema } from "@/lib/FormSchema";

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

function ManageSupplier() {
  const { flash, supplierList = [], filters = {} } = usePage().props;
  const suppliers = supplierList?.data || [];
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isUpdating, setIsUpdating] = useState(false);
  const [editingSupplier, setEditingSupplier] = useState(null);
  const [filterValues, setFilterValues] = useState({
    tin: filters.tin || "",
    name: filters.name || "",
  });

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(supplierSchema),
    defaultValues: {
      tin: "",
      name: "",
      addr: "",
      city: "",
    },
  });

  const {
    register: registerEdit,
    handleSubmit: handleEditSubmit,
    reset: resetEdit,
    setError: setEditError,
    formState: { errors: editErrors },
  } = useForm({
    resolver: zodResolver(supplierSchema),
    defaultValues: {
      tin: "",
      name: "",
      addr: "",
      city: "",
    },
  });

  useEffect(() => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
  }, [flash]);

  useEffect(() => {
    setFilterValues({
      tin: filters.tin || "",
      name: filters.name || "",
    });
  }, [filters.tin, filters.name]);

  const onSubmit = (formData) => {
    setIsSubmitting(true);

    router.post("/suppliers", formData, {
      onSuccess: () => {
        reset();
        setIsSubmitting(false);
      },
      onError: (err) => {
        setIsSubmitting(false);
        if (err) {
          Object.keys(err).forEach((key) => {
            setError(key, { message: err[key] });
          });
        }
      },
    });
  };

  const handleDelete = (id) => {
    if (confirm("Are you sure you want to delete this supplier?")) {
      router.delete(`/suppliers/${id}`);
    }
  };

  const handleFilterChange = (field, value) => {
    setFilterValues((current) => ({
      ...current,
      [field]: value,
    }));
  };

  const handleFilterSubmit = (event) => {
    event.preventDefault();

    router.get(
      "/suppliers",
      {
        tin: filterValues.tin,
        name: filterValues.name,
      },
      {
        preserveState: true,
        preserveScroll: true,
        replace: true,
      }
    );
  };

  const handleClearFilters = () => {
    setFilterValues({ tin: "", name: "" });

    router.get(
      "/suppliers",
      {},
      {
        preserveState: true,
        preserveScroll: true,
        replace: true,
      }
    );
  };

  const handleOpenEdit = (supplier) => {
    setEditingSupplier(supplier);
    resetEdit({
      tin: supplier.tin || "",
      name: supplier.name || "",
      addr: supplier.addr || "",
      city: supplier.city || "",
    });
  };

  const handleCloseEdit = () => {
    if (isUpdating) return;
    setEditingSupplier(null);
    resetEdit({
      tin: "",
      name: "",
      addr: "",
      city: "",
    });
  };

  const onEditSubmit = (formData) => {
    if (!editingSupplier) return;

    setIsUpdating(true);

    router.put(`/suppliers/${editingSupplier.id}`, formData, {
      preserveScroll: true,
      onSuccess: () => {
        setIsUpdating(false);
        handleCloseEdit();
      },
      onError: (err) => {
        setIsUpdating(false);
        if (err) {
          Object.keys(err).forEach((key) => {
            setEditError(key, { message: err[key] });
          });
        }
      },
    });
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
        Supplier Management
      </motion.h2>

      <motion.div variants={itemVariants}>
        <Card className="w-full shadow-sm border border-slate-100 rounded-xl bg-white overflow-hidden">
          <CardContent className="p-6">
            <form id="supplier-form" onSubmit={handleSubmit(onSubmit)}>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">
                    TIN <span className="text-red-500">*</span>
                  </label>
                  <Input
                    type="text"
                    placeholder="000-000-000-000"
                    {...register("tin")}
                    className={errors.tin ? "border-red-500 focus-visible:ring-red-500" : ""}
                  />
                  {errors.tin && (
                    <p className="text-xs text-red-500 font-medium">
                      {errors.tin.message}
                    </p>
                  )}
                </div>

                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">
                    Supplier Name <span className="text-red-500">*</span>
                  </label>
                  <Input
                    type="text"
                    placeholder="Enter supplier name"
                    {...register("name")}
                    className={errors.name ? "border-red-500 focus-visible:ring-red-500" : ""}
                  />
                  {errors.name && (
                    <p className="text-xs text-red-500 font-medium">
                      {errors.name.message}
                    </p>
                  )}
                </div>

                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">
                    Address <span className="text-red-500">*</span>
                  </label>
                  <Input
                    type="text"
                    placeholder="Enter address"
                    {...register("addr")}
                    className={errors.addr ? "border-red-500 focus-visible:ring-red-500" : ""}
                  />
                  {errors.addr && (
                    <p className="text-xs text-red-500 font-medium">
                      {errors.addr.message}
                    </p>
                  )}
                </div>

                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">
                    City <span className="text-red-500">*</span>
                  </label>
                  <Input
                    type="text"
                    placeholder="Enter city"
                    {...register("city")}
                    className={errors.city ? "border-red-500 focus-visible:ring-red-500" : ""}
                  />
                  {errors.city && (
                    <p className="text-xs text-red-500 font-medium">
                      {errors.city.message}
                    </p>
                  )}
                </div>
              </div>
            </form>
          </CardContent>

          <CardFooter className="flex justify-end p-4 bg-slate-50/50 border-t border-slate-100">
            <Button
              type="submit"
              form="supplier-form"
              disabled={isSubmitting}
              className="bg-[#0344a4] hover:bg-[#023384] text-white px-6 min-w-[110px]"
            >
              {isSubmitting ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <span className="flex items-center gap-1.5">
                  <Plus className="w-4 h-4" /> Submit
                </span>
              )}
            </Button>
          </CardFooter>
        </Card>
      </motion.div>

      <motion.div variants={itemVariants}>
        <Card className="w-full shadow-sm border border-slate-100 rounded-xl overflow-hidden bg-white">
          <CardHeader className="py-4 border-b border-slate-100 bg-slate-50/50">
            <CardTitle className="text-lg font-medium text-slate-800">
              List of Suppliers
            </CardTitle>
          </CardHeader>

          <div className="border-b border-slate-100 bg-white p-4">
            <form
              onSubmit={handleFilterSubmit}
              className="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,220px)_minmax(0,1fr)_auto_auto]"
            >
              <Input
                type="text"
                value={filterValues.tin}
                onChange={(event) => handleFilterChange("tin", event.target.value)}
                placeholder="Filter by TIN"
                className="h-9"
              />
              <Input
                type="text"
                value={filterValues.name}
                onChange={(event) => handleFilterChange("name", event.target.value)}
                placeholder="Filter by company name"
                className="h-9"
              />
              <Button
                type="submit"
                variant="outline"
                className="h-9"
              >
                <Search className="h-4 w-4" />
                Filter
              </Button>
              <Button
                type="button"
                variant="ghost"
                onClick={handleClearFilters}
                className="h-9"
              >
                <X className="h-4 w-4" />
                Clear
              </Button>
            </form>
          </div>

          <CardContent className="p-0 overflow-x-auto">
            <Table>
              <TableHeader className="bg-slate-50/70">
                <TableRow>
                  <TableHead className="font-semibold text-slate-700 pl-6">
                    TIN
                  </TableHead>
                  <TableHead className="font-semibold text-slate-700">
                    Supplier Name
                  </TableHead>
                  <TableHead className="font-semibold text-slate-700">
                    Address
                  </TableHead>
                  <TableHead className="font-semibold text-slate-700">
                    City
                  </TableHead>
                  <TableHead className="text-right pr-6 font-semibold text-slate-700">
                    Actions
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {suppliers.length > 0 ? (
                  suppliers.map((supplier) => (
                    <TableRow
                      key={supplier.id}
                      className="hover:bg-slate-50/50 transition-colors"
                    >
                      <TableCell className="font-medium text-slate-900 pl-6 py-4 whitespace-nowrap">
                        {supplier.tin || 'N/A'}
                      </TableCell>
                      <TableCell className="text-slate-700 min-w-[180px]">
                        {supplier.name || 'N/A'}
                      </TableCell>
                      <TableCell className="text-slate-600 min-w-[260px]">
                        {supplier.addr || 'N/A'}
                      </TableCell>
                      <TableCell className="text-slate-600 whitespace-nowrap">
                        {supplier.city || 'N/A'}
                      </TableCell>
                      <TableCell className="text-right pr-6 py-4">
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          onClick={() => handleOpenEdit(supplier)}
                          className="h-8 w-8 text-blue-600 hover:text-blue-700 hover:bg-blue-50 cursor-pointer rounded-lg"
                        >
                          <Pencil className="h-4 w-4" />
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          onClick={() => handleDelete(supplier.id)}
                          className="h-8 w-8 text-red-500 hover:text-red-600 hover:bg-red-50 cursor-pointer rounded-lg"
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell
                      colSpan={5}
                      className="text-center py-8 text-slate-400"
                    >
                      No suppliers found.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>

          {supplierList?.links && (
            <DataTablePagination links={supplierList.links} />
          )}
        </Card>
      </motion.div>

      <Dialog open={Boolean(editingSupplier)} onOpenChange={(open) => !open && handleCloseEdit()}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Edit Supplier</DialogTitle>
            <DialogDescription>
              Update supplier TIN, company name, address, and city.
            </DialogDescription>
          </DialogHeader>

          <form id="edit-supplier-form" onSubmit={handleEditSubmit(onEditSubmit)}>
            <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
              <div className="space-y-2">
                <label className="text-sm font-medium text-slate-700">
                  TIN <span className="text-red-500">*</span>
                </label>
                <Input
                  type="text"
                  placeholder="000-000-000-000"
                  {...registerEdit("tin")}
                  className={editErrors.tin ? "border-red-500 focus-visible:ring-red-500" : ""}
                />
                {editErrors.tin && (
                  <p className="text-xs text-red-500 font-medium">
                    {editErrors.tin.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium text-slate-700">
                  Supplier Name <span className="text-red-500">*</span>
                </label>
                <Input
                  type="text"
                  placeholder="Enter supplier name"
                  {...registerEdit("name")}
                  className={editErrors.name ? "border-red-500 focus-visible:ring-red-500" : ""}
                />
                {editErrors.name && (
                  <p className="text-xs text-red-500 font-medium">
                    {editErrors.name.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium text-slate-700">
                  Address <span className="text-red-500">*</span>
                </label>
                <Input
                  type="text"
                  placeholder="Enter address"
                  {...registerEdit("addr")}
                  className={editErrors.addr ? "border-red-500 focus-visible:ring-red-500" : ""}
                />
                {editErrors.addr && (
                  <p className="text-xs text-red-500 font-medium">
                    {editErrors.addr.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium text-slate-700">
                  City <span className="text-red-500">*</span>
                </label>
                <Input
                  type="text"
                  placeholder="Enter city"
                  {...registerEdit("city")}
                  className={editErrors.city ? "border-red-500 focus-visible:ring-red-500" : ""}
                />
                {editErrors.city && (
                  <p className="text-xs text-red-500 font-medium">
                    {editErrors.city.message}
                  </p>
                )}
              </div>
            </div>
          </form>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={handleCloseEdit}
              disabled={isUpdating}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              form="edit-supplier-form"
              disabled={isUpdating}
              className="bg-[#0344a4] hover:bg-[#023384] text-white"
            >
              {isUpdating ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                "Save Changes"
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </motion.section>
  );
}

ManageSupplier.layout = (page) => (
  <MainLayout title="Manage Suppliers">{page}</MainLayout>
);

export default ManageSupplier;

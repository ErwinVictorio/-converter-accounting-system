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
import { customerSchema } from "@/lib/FormSchema";

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

function ManageCustomer() {
  const { flash, customerList = [], filters = {} } = usePage().props;
  const customers = customerList?.data || [];
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isUpdating, setIsUpdating] = useState(false);
  const [editingCustomer, setEditingCustomer] = useState(null);
  const [filterValues, setFilterValues] = useState({
    tin: filters.tin || "",
    name: filters.name || "",
  });

  const defaultValues = {
    tin: "",
    name: "",
    addr: "",
    city: "",
  };

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(customerSchema),
    defaultValues,
  });

  const {
    register: registerEdit,
    handleSubmit: handleEditSubmit,
    reset: resetEdit,
    setError: setEditError,
    formState: { errors: editErrors },
  } = useForm({
    resolver: zodResolver(customerSchema),
    defaultValues,
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

    router.post("/customers", formData, {
      onSuccess: () => {
        reset();
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

  const onEditSubmit = (formData) => {
    if (!editingCustomer) return;

    setIsUpdating(true);

    router.put(`/customers/${editingCustomer.id}`, formData, {
      preserveScroll: true,
      onSuccess: () => {
        setIsUpdating(false);
        handleCloseEdit();
      },
      onError: (err) => {
        setIsUpdating(false);
        Object.keys(err || {}).forEach((key) => {
          setEditError(key, { message: err[key] });
        });
      },
    });
  };

  const handleDelete = (id) => {
    if (confirm("Are you sure you want to delete this customer?")) {
      router.delete(`/customers/${id}`);
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
      "/customers",
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
      "/customers",
      {},
      {
        preserveState: true,
        preserveScroll: true,
        replace: true,
      }
    );
  };

  const handleOpenEdit = (customer) => {
    setEditingCustomer(customer);
    resetEdit({
      tin: customer.tin || "",
      name: customer.name || "",
      addr: customer.addr || "",
      city: customer.city || "",
    });
  };

  const handleCloseEdit = () => {
    if (isUpdating) return;
    setEditingCustomer(null);
    resetEdit(defaultValues);
  };

  const renderField = (field, label, placeholder, fieldErrors, fieldRegister) => (
    <div className="space-y-2">
      <label className="text-sm font-medium text-slate-700">
        {label} <span className="text-red-500">*</span>
      </label>
      <Input
        type="text"
        placeholder={placeholder}
        {...fieldRegister(field)}
        className={fieldErrors[field] ? "border-red-500 focus-visible:ring-red-500" : ""}
      />
      {fieldErrors[field] && (
        <p className="text-xs text-red-500 font-medium">
          {fieldErrors[field].message}
        </p>
      )}
    </div>
  );

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
        Customer Management
      </motion.h2>

      <motion.div variants={itemVariants}>
        <Card className="w-full shadow-sm border border-slate-100 rounded-xl bg-white overflow-hidden">
          <CardContent className="p-6">
            <form id="customer-form" onSubmit={handleSubmit(onSubmit)}>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                {renderField("tin", "TIN", "000-000-000-000", errors, register)}
                {renderField("name", "Customer Name", "Enter customer name", errors, register)}
                {renderField("addr", "Address", "Enter address", errors, register)}
                {renderField("city", "City", "Enter city", errors, register)}
              </div>
            </form>
          </CardContent>

          <CardFooter className="flex justify-end p-4 bg-slate-50/50 border-t border-slate-100">
            <Button
              type="submit"
              form="customer-form"
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
              List of Customers
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
                placeholder="Filter by customer name"
                className="h-9"
              />
              <Button type="submit" variant="outline" className="h-9">
                <Search className="h-4 w-4" />
                Filter
              </Button>
              <Button type="button" variant="ghost" onClick={handleClearFilters} className="h-9">
                <X className="h-4 w-4" />
                Clear
              </Button>
            </form>
          </div>

          <CardContent className="p-0 overflow-x-auto">
            <Table>
              <TableHeader className="bg-slate-50/70">
                <TableRow>
                  <TableHead className="font-semibold text-slate-700 pl-6">TIN</TableHead>
                  <TableHead className="font-semibold text-slate-700">Customer Name</TableHead>
                  <TableHead className="font-semibold text-slate-700">Address</TableHead>
                  <TableHead className="font-semibold text-slate-700">City</TableHead>
                  <TableHead className="text-right pr-6 font-semibold text-slate-700">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {customers.length > 0 ? (
                  customers.map((customer) => (
                    <TableRow key={customer.id} className="hover:bg-slate-50/50 transition-colors">
                      <TableCell className="font-medium text-slate-900 pl-6 py-4 whitespace-nowrap">
                        {customer.tin}
                      </TableCell>
                      <TableCell className="text-slate-700 min-w-[220px]">
                        {customer.name}
                      </TableCell>
                      <TableCell className="text-slate-600 min-w-[300px]">
                        {customer.addr}
                      </TableCell>
                      <TableCell className="text-slate-600 whitespace-nowrap">
                        {customer.city}
                      </TableCell>
                      <TableCell className="text-right pr-6 py-4">
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          onClick={() => handleOpenEdit(customer)}
                          className="h-8 w-8 text-blue-600 hover:text-blue-700 hover:bg-blue-50 cursor-pointer rounded-lg"
                        >
                          <Pencil className="h-4 w-4" />
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          onClick={() => handleDelete(customer.id)}
                          className="h-8 w-8 text-red-500 hover:text-red-600 hover:bg-red-50 cursor-pointer rounded-lg"
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={5} className="text-center py-8 text-slate-400">
                      No customers found.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>

          {customerList?.links && (
            <DataTablePagination links={customerList.links} />
          )}
        </Card>
      </motion.div>

      <Dialog open={Boolean(editingCustomer)} onOpenChange={(open) => !open && handleCloseEdit()}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Edit Customer</DialogTitle>
            <DialogDescription>
              Update customer TIN, name, address, and city used for Sales DAT matching.
            </DialogDescription>
          </DialogHeader>

          <form id="edit-customer-form" onSubmit={handleEditSubmit(onEditSubmit)}>
            <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
              {renderField("tin", "TIN", "000-000-000-000", editErrors, registerEdit)}
              {renderField("name", "Customer Name", "Enter customer name", editErrors, registerEdit)}
              {renderField("addr", "Address", "Enter address", editErrors, registerEdit)}
              {renderField("city", "City", "Enter city", editErrors, registerEdit)}
            </div>
          </form>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={handleCloseEdit} disabled={isUpdating}>
              Cancel
            </Button>
            <Button
              type="submit"
              form="edit-customer-form"
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

ManageCustomer.layout = (page) => (
  <MainLayout title="Manage Customers">{page}</MainLayout>
);

export default ManageCustomer;

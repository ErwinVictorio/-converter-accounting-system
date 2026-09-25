import React, { useEffect, useState } from "react";
import { usePage, router } from "@inertiajs/react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { AlertTriangle, CheckCircle2, Eye, Loader2, Pencil, Plus, Search, Trash2, X } from "lucide-react";
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/Components/ui/select";
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
import MasterDataCharacterCount from "@/Components/MasterDataCharacterCount";
import ViewInfoDialog from "@/Components/Records/ViewInfoDialog";
import { birFieldLimits, customerSchema } from "@/lib/FormSchema";

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
  const { flash, customerList = [], filters = {}, customerFixContext = null } = usePage().props;
  const customers = customerList?.data || [];
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isUpdating, setIsUpdating] = useState(false);
  const [editingCustomer, setEditingCustomer] = useState(null);
  const [selectedInfoRecord, setSelectedInfoRecord] = useState(null);
  const [isRetryingUpload, setIsRetryingUpload] = useState(false);
  const [filterValues, setFilterValues] = useState({
    tin: filters.tin || "",
    name: filters.name || "",
    address_status: filters.address_status || "all",
  });

  const defaultValues = {
    tin: "",
    name: "",
    addr: "",
    city: "",
  };

  const {
    register,
    control,
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
    control: editControl,
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
      address_status: filters.address_status || "all",
    });
  }, [filters.tin, filters.name, filters.address_status]);

  useEffect(() => {
    const item = customerFixContext?.current_item;
    if (!item) {
      setEditingCustomer(null);
      return;
    }
    const values = {
      tin: item.prefill?.tin || "",
      name: item.prefill?.name || "",
      addr: item.prefill?.addr || "",
      city: item.prefill?.city || "",
    };
    if (item.mode === "edit" && item.customer_id) {
      setEditingCustomer({ id: item.customer_id, ...values });
      resetEdit(values);
    } else {
      setEditingCustomer(null);
      reset(values);
    }
  }, [customerFixContext, reset, resetEdit]);

  const onSubmit = (formData) => {
    setIsSubmitting(true);

    router.post("/customers", formData, {
      onSuccess: () => {
        if (!customerFixContext) reset();
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
        if (!customerFixContext) handleCloseEdit();
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
        address_status: filterValues.address_status,
      },
      {
        preserveState: true,
        preserveScroll: true,
        replace: true,
      }
    );
  };

  const handleClearFilters = () => {
    setFilterValues({ tin: "", name: "", address_status: "all" });

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

  const retryPendingUpload = () => {
    const pending = customerFixContext?.pending_upload;
    if (!pending?.ready_to_retry) return;
    setIsRetryingUpload(true);
    router.post(pending.retry_url, {}, { onFinish: () => setIsRetryingUpload(false) });
  };

  const cancelPendingUpload = () => {
    const pending = customerFixContext?.pending_upload;
    if (!pending || !confirm("Cancel this pending Sales upload? The retained workbook will be removed.")) return;
    router.delete(pending.cancel_url);
  };

  const renderField = (field, label, placeholder, fieldErrors, fieldRegister, limit) => (
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
      {limit && <MasterDataCharacterCount control={fieldRegister === registerEdit ? editControl : control} name={field} limit={limit} />}
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

      {customerFixContext && (
        <motion.div variants={itemVariants}>
          <Card className="border-amber-200 bg-amber-50/70 shadow-sm">
            <CardContent className="space-y-4 p-5">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex items-start gap-3">
                  {customerFixContext.pending_upload.ready_to_retry ? (
                    <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                  ) : (
                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                  )}
                  <div>
                    <p className="font-semibold text-slate-900">
                      Sales upload fix queue: {customerFixContext.pending_upload.original_name}
                    </p>
                    <p className="text-sm text-slate-600">
                      Reporting month {customerFixContext.pending_upload.reporting_period} ·{" "}
                      {customerFixContext.pending_upload.resolved_customers} fixed ·{" "}
                      {customerFixContext.pending_upload.remaining_customers} remaining
                    </p>
                  </div>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button type="button" variant="outline" onClick={() => router.get("/records")}>Back to Import Data</Button>
                  <Button type="button" variant="ghost" onClick={cancelPendingUpload} className="text-red-600 hover:text-red-700">Cancel Queue</Button>
                  <Button type="button" onClick={retryPendingUpload}
                    disabled={!customerFixContext.pending_upload.ready_to_retry || isRetryingUpload}
                    className="bg-[#0344a4] text-white hover:bg-[#023384]">
                    {isRetryingUpload && <Loader2 className="h-4 w-4 animate-spin" />}
                    Retry Upload
                  </Button>
                </div>
              </div>

              {customerFixContext.pending_upload.has_workbook_issues && (
                <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
                  The retained workbook now has amount or workbook-only issues. Correct the workbook and upload it again.
                </div>
              )}

              {customerFixContext.current_item ? (
                <div className="rounded-lg border border-amber-200 bg-white p-4 text-sm text-slate-700">
                  <p className="font-semibold text-slate-900">Fixing: {customerFixContext.current_item.display_name}</p>
                  <p>Worksheet rows: {customerFixContext.current_item.affected_rows.join(", ")}</p>
                  <p>Fields to fix: {customerFixContext.current_item.missing_fields.join(", ")}</p>
                  {customerFixContext.current_item.problems.map((problem) => (
                    <p key={problem} className="mt-1 text-red-700">{problem}</p>
                  ))}
                </div>
              ) : !customerFixContext.pending_upload.has_workbook_issues && (
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                  All Customer issues are fixed. Retry the original workbook when ready.
                </div>
              )}

              {customerFixContext.pending_upload.items.length > 1 && (
                <div className="flex flex-wrap gap-2">
                  {customerFixContext.pending_upload.items.map((item) => (
                    <Button key={item.queue_key} type="button" size="sm"
                      variant={item.queue_key === customerFixContext.current_item?.queue_key ? "default" : "outline"}
                      onClick={() => router.get(item.fix_url)}>
                      {item.display_name}
                    </Button>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </motion.div>
      )}

      <motion.div variants={itemVariants}>
        <Card className="w-full shadow-sm border border-slate-100 rounded-xl bg-white overflow-hidden">
          <CardContent className="p-6">
            <form id="customer-form" onSubmit={handleSubmit(onSubmit)}>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                {renderField("tin", "TIN", "000-000-000-000", errors, register)}
                {renderField("name", "Customer Name", "Enter customer name", errors, register, birFieldLimits.companyName)}
                {renderField("addr", "Address", "Enter address", errors, register, birFieldLimits.address1)}
                {renderField("city", "City", "Enter city", errors, register, birFieldLimits.city)}
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
                  <Plus className="w-4 h-4" /> {customerFixContext ? "Save and Check Again" : "Submit"}
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
              className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,220px)_minmax(0,1fr)_minmax(0,240px)_auto] lg:items-end"
            >
              <div className="space-y-1.5">
                <label className="text-xs font-medium text-slate-600">Filter by TIN</label>
                <Input
                  type="text"
                  value={filterValues.tin}
                  onChange={(event) => handleFilterChange("tin", event.target.value)}
                  placeholder="Enter TIN..."
                  className="h-9"
                />
              </div>
              <div className="space-y-1.5">
                <label className="text-xs font-medium text-slate-600">Filter by customer name</label>
                <Input
                  type="text"
                  value={filterValues.name}
                  onChange={(event) => handleFilterChange("name", event.target.value)}
                  placeholder="Enter customer name..."
                  className="h-9"
                />
              </div>
              <div className="space-y-1.5">
                <label className="text-xs font-medium text-slate-600">Missing Information</label>
                <Select
                  value={filterValues.address_status}
                  onValueChange={(value) => handleFilterChange("address_status", value)}
                >
                  <SelectTrigger className="h-9 w-full bg-white">
                    <SelectValue placeholder="All records" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All records</SelectItem>
                    <SelectItem value="missing_any">Missing address or city</SelectItem>
                    <SelectItem value="missing_address">Missing address</SelectItem>
                    <SelectItem value="missing_city">Missing city</SelectItem>
                    <SelectItem value="missing_both">Missing both</SelectItem>
                    <SelectItem value="complete">Complete records</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="flex gap-3 sm:col-span-2 lg:col-span-1">
                <Button type="submit" className="h-9 min-w-[110px] flex-1 bg-[#0344a4] text-white hover:bg-[#023384]">
                  <Search className="h-4 w-4" />
                  Search
                </Button>
                <Button type="button" variant="outline" onClick={handleClearFilters} className="h-9 min-w-[100px] flex-1">
                  <X className="h-4 w-4" />
                  Clear
                </Button>
              </div>
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
                        {customer.tin || 'N/A'}
                      </TableCell>
                      <TableCell className="text-slate-700 min-w-[220px]">
                        {customer.name}
                      </TableCell>
                      <TableCell className="text-slate-600 min-w-[300px]">
                        {customer.addr}
                      </TableCell>
                      <TableCell className="text-slate-600 whitespace-nowrap">
                        {customer.city || 'N/A'}
                      </TableCell>
                      <TableCell className="text-right pr-6 py-4">
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          onClick={() => setSelectedInfoRecord(customer)}
                          className="mr-1 h-8 gap-1.5"
                        >
                          <Eye className="h-3.5 w-3.5" />
                          View Info
                        </Button>
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
              {renderField("name", "Customer Name", "Enter customer name", editErrors, registerEdit, birFieldLimits.companyName)}
              {renderField("addr", "Address", "Enter address", editErrors, registerEdit, birFieldLimits.address1)}
              {renderField("city", "City", "Enter city", editErrors, registerEdit, birFieldLimits.city)}
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
                customerFixContext ? "Save and Check Again" : "Save Changes"
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <ViewInfoDialog
        open={Boolean(selectedInfoRecord)}
        onOpenChange={(open) => !open && setSelectedInfoRecord(null)}
        resourceType="customer"
        recordId={selectedInfoRecord?.id}
        fallbackTitle="Customer Information"
        fallbackSubtitle={selectedInfoRecord?.name}
      />
    </motion.section>
  );
}

ManageCustomer.layout = (page) => (
  <MainLayout title="Manage Customers">{page}</MainLayout>
);

export default ManageCustomer;

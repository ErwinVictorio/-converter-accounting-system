import React, { useEffect, useState } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import { toast } from "sonner";
import {
  Building,
  CheckCircle2,
  Loader2,
  Lock,
  Pencil,
  Plus,
  RotateCcw,
  Search,
  Trash2,
  XCircle,
  X,
} from "lucide-react";
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

const emptyCompany = {
  registered_name: "",
  trade_name: "",
  tin: "",
  branch_code: "0000",
  rdo_code: "",
  address1: "",
  address2: "",
  is_active: true,
};

/**
 * Master Data > Companies.
 *
 * These are the withholding agent companies an Expanded WTAX upload is filed
 * under and whose TIN, branch, registered name and RDO the 1601EQ DAT header
 * carries. Nothing here changes a DAT layout, and nothing here touches Sales,
 * Purchases or Importation.
 *
 * Two rules come from the server and are explained in the UI rather than being
 * sprung as a validation error:
 *
 *  - a company that already has Expanded WTAX rows filed under it cannot have its
 *    TIN or branch code edited (the rows carry that identity themselves), so those
 *    two inputs are locked and the row shows a padlock;
 *  - the same company cannot be deleted either -- it is deactivated instead, which
 *    hides it from the upload dropdown while keeping already-filed months
 *    regenerable.
 */
function WithholdingCompanies() {
  const { flash, companies, filters = {} } = usePage().props;
  const rows = companies?.data || [];

  const [editing, setEditing] = useState(null);
  const [search, setSearch] = useState(filters.search || "");

  const addForm = useForm({ ...emptyCompany });
  const editForm = useForm({ ...emptyCompany });

  useEffect(() => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
  }, [flash]);

  useEffect(() => {
    setSearch(filters.search || "");
  }, [filters.search]);

  const handleAdd = (event) => {
    event.preventDefault();

    addForm.post("/withholding-companies", {
      preserveScroll: true,
      onSuccess: () => addForm.reset(),
    });
  };

  const handleSearch = (event) => {
    event.preventDefault();

    router.get(
      "/withholding-companies",
      { search },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  };

  const handleClearSearch = () => {
    setSearch("");

    router.get(
      "/withholding-companies",
      {},
      { preserveState: true, preserveScroll: true, replace: true }
    );
  };

  const handleOpenEdit = (company) => {
    setEditing(company);
    editForm.clearErrors();
    // setData with a whole object, not setDefaults + reset: setDefaults is state,
    // so a reset() in the same handler would still see the previous row's values.
    editForm.setData({
      registered_name: company.registered_name || "",
      trade_name: company.trade_name || "",
      tin: company.tin || "",
      branch_code: company.branch_code || "0000",
      rdo_code: company.rdo_code || "",
      address1: company.address1 || "",
      address2: company.address2 || "",
      is_active: Boolean(company.is_active),
    });
  };

  const handleCloseEdit = () => {
    if (editForm.processing) return;
    setEditing(null);
    editForm.clearErrors();
    editForm.setData({ ...emptyCompany });
  };

  const handleEdit = (event) => {
    event.preventDefault();
    if (!editing) return;

    editForm.put(`/withholding-companies/${editing.id}`, {
      preserveScroll: true,
      onSuccess: () => setEditing(null),
    });
  };

  const handleDeactivate = (company) => {
    router.patch(
      `/withholding-companies/${company.id}/deactivate`,
      {},
      { preserveScroll: true }
    );
  };

  const handleActivate = (company) => {
    router.patch(
      `/withholding-companies/${company.id}/activate`,
      {},
      { preserveScroll: true }
    );
  };

  const handleDelete = (company) => {
    if (company.has_filed_rows) {
      toast.error(
        `Expanded WTAX records were filed under ${company.tin}-${company.branch_code}. Deactivate it instead of deleting it.`
      );
      return;
    }

    if (
      confirm(
        `Delete ${company.registered_name}? Use Deactivate instead if you may still need to regenerate a DAT for it.`
      )
    ) {
      router.delete(`/withholding-companies/${company.id}`, {
        preserveScroll: true,
      });
    }
  };

  return (
    <motion.section
      className="space-y-6 p-6 max-w-7xl mx-auto"
      initial="hidden"
      animate="visible"
      variants={containerVariants}
    >
      <motion.div variants={itemVariants} className="space-y-1">
        <h2 className="text-2xl font-bold tracking-tight text-slate-800">
          Manage Companies
        </h2>
        <p className="text-sm text-slate-500">
          The withholding agent companies the Expanded WTAX upload and the 1601EQ
          DAT are filed for. Active companies appear in the Known Company
          dropdowns on Import Data and Generate DAT File.
        </p>
      </motion.div>

      <motion.div variants={itemVariants}>
        <Card className="w-full shadow-sm border border-slate-100 rounded-xl bg-white overflow-hidden">
          <CardHeader className="py-4 px-6 border-b border-slate-100 bg-slate-50/50">
            <CardTitle className="text-lg font-medium text-slate-800">
              Add Company
            </CardTitle>
          </CardHeader>

          <CardContent className="p-6">
            <form id="company-form" onSubmit={handleAdd}>
              <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                <FormField
                  label="Registered Name"
                  required
                  error={addForm.errors.registered_name}
                >
                  <Input
                    type="text"
                    placeholder="FORTRESS STEEL INC."
                    value={addForm.data.registered_name}
                    onChange={(event) =>
                      addForm.setData("registered_name", event.target.value)
                    }
                    className={addForm.errors.registered_name ? errorClass : ""}
                  />
                </FormField>

                <FormField label="Trade Name" error={addForm.errors.trade_name}>
                  <Input
                    type="text"
                    placeholder="Optional"
                    value={addForm.data.trade_name}
                    onChange={(event) =>
                      addForm.setData("trade_name", event.target.value)
                    }
                    className={addForm.errors.trade_name ? errorClass : ""}
                  />
                </FormField>

                <FormField
                  label="Company TIN"
                  required
                  hint="9 digits. Dashes and spaces are removed on save."
                  error={addForm.errors.tin}
                >
                  <Input
                    type="text"
                    inputMode="numeric"
                    placeholder="008791976"
                    value={addForm.data.tin}
                    onChange={(event) => addForm.setData("tin", event.target.value)}
                    className={addForm.errors.tin ? errorClass : ""}
                  />
                </FormField>

                <FormField
                  label="Branch Code"
                  required
                  hint="4 digits. Head office is 0000."
                  error={addForm.errors.branch_code}
                >
                  <Input
                    type="text"
                    inputMode="numeric"
                    placeholder="0000"
                    value={addForm.data.branch_code}
                    onChange={(event) =>
                      addForm.setData("branch_code", event.target.value)
                    }
                    className={addForm.errors.branch_code ? errorClass : ""}
                  />
                </FormField>

                <FormField
                  label="RDO Code"
                  hint="3 digits. Written to the DAT header when set."
                  error={addForm.errors.rdo_code}
                >
                  <Input
                    type="text"
                    inputMode="numeric"
                    placeholder="045"
                    value={addForm.data.rdo_code}
                    onChange={(event) =>
                      addForm.setData("rdo_code", event.target.value)
                    }
                    className={addForm.errors.rdo_code ? errorClass : ""}
                  />
                </FormField>

                <FormField label="Address 1" error={addForm.errors.address1}>
                  <Input
                    type="text"
                    placeholder="LOT 433 J.P RIZAL NANGKA"
                    value={addForm.data.address1}
                    onChange={(event) =>
                      addForm.setData("address1", event.target.value)
                    }
                    className={addForm.errors.address1 ? errorClass : ""}
                  />
                </FormField>

                <FormField label="Address 2" error={addForm.errors.address2}>
                  <Input
                    type="text"
                    placeholder="MARIKINA 1808"
                    value={addForm.data.address2}
                    onChange={(event) =>
                      addForm.setData("address2", event.target.value)
                    }
                    className={addForm.errors.address2 ? errorClass : ""}
                  />
                </FormField>
              </div>
            </form>
          </CardContent>

          <CardFooter className="flex justify-end p-4 bg-slate-50/50 border-t border-slate-100">
            <Button
              type="submit"
              form="company-form"
              disabled={addForm.processing}
              className="bg-[#0344a4] hover:bg-[#023384] text-white px-6 min-w-[110px]"
            >
              {addForm.processing ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <span className="flex items-center gap-1.5">
                  <Plus className="w-4 h-4" /> Add Company
                </span>
              )}
            </Button>
          </CardFooter>
        </Card>
      </motion.div>

      <motion.div variants={itemVariants}>
        <Card className="w-full shadow-sm border border-slate-100 rounded-xl overflow-hidden bg-white">
          <CardHeader className="py-4 px-6 border-b border-slate-100 bg-slate-50/50">
            <CardTitle className="text-lg font-medium text-slate-800">
              Companies
            </CardTitle>
          </CardHeader>

          <div className="border-b border-slate-100 bg-white p-4">
            <form
              onSubmit={handleSearch}
              className="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_auto_auto]"
            >
              <Input
                type="text"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search by registered name, trade name, or TIN"
                className="h-9"
              />
              <Button type="submit" variant="outline" className="h-9">
                <Search className="h-4 w-4" />
                Search
              </Button>
              <Button
                type="button"
                variant="ghost"
                onClick={handleClearSearch}
                className="h-9"
              >
                <X className="h-4 w-4" />
                Clear
              </Button>
            </form>
          </div>

          <CardContent className="p-0 overflow-x-auto">
            <Table className="min-w-[900px]">
              <TableHeader className="bg-slate-50/70">
                <TableRow>
                  <TableHead className="font-semibold text-slate-700 pl-6">
                    Registered Name
                  </TableHead>
                  <TableHead className="font-semibold text-slate-700">
                    TIN / Branch
                  </TableHead>
                  <TableHead className="font-semibold text-slate-700">
                    RDO
                  </TableHead>
                  <TableHead className="font-semibold text-slate-700">
                    Address
                  </TableHead>
                  <TableHead className="font-semibold text-slate-700">
                    Status
                  </TableHead>
                  <TableHead className="text-right pr-6 font-semibold text-slate-700">
                    Actions
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.length > 0 ? (
                  rows.map((company) => (
                    <TableRow
                      key={company.id}
                      className="hover:bg-slate-50/50 transition-colors"
                    >
                      <TableCell className="pl-6 py-4 min-w-[220px]">
                        <div className="font-medium text-slate-900">
                          {company.registered_name}
                        </div>
                        {company.trade_name && (
                          <div className="text-xs text-slate-500">
                            {company.trade_name}
                          </div>
                        )}
                      </TableCell>
                      <TableCell className="text-slate-700 whitespace-nowrap">
                        <span className="font-mono">
                          {company.tin}-{company.branch_code}
                        </span>
                        {company.has_filed_rows && (
                          <span
                            className="ml-2 inline-flex items-center gap-1 text-xs text-slate-400"
                            title="Expanded WTAX records were filed under this TIN and branch, so they can no longer be edited."
                          >
                            <Lock className="h-3 w-3" />
                            locked
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="text-slate-600 whitespace-nowrap">
                        {company.rdo_code || (
                          <span className="text-slate-400">—</span>
                        )}
                      </TableCell>
                      <TableCell className="text-slate-600 min-w-[220px]">
                        {[company.address1, company.address2]
                          .filter(Boolean)
                          .join(", ") || <span className="text-slate-400">—</span>}
                      </TableCell>
                      <TableCell className="whitespace-nowrap">
                        {company.is_active ? (
                          <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">
                            <CheckCircle2 className="h-3.5 w-3.5" />
                            Active
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500">
                            <XCircle className="h-3.5 w-3.5" />
                            Inactive
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="text-right pr-6 py-4 whitespace-nowrap">
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          title="Edit"
                          onClick={() => handleOpenEdit(company)}
                          className="h-8 w-8 text-blue-600 hover:text-blue-700 hover:bg-blue-50 cursor-pointer rounded-lg"
                        >
                          <Pencil className="h-4 w-4" />
                        </Button>
                        {company.is_active ? (
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            title="Deactivate (hides it from the Known Company dropdowns)"
                            onClick={() => handleDeactivate(company)}
                            className="h-8 w-8 text-amber-600 hover:text-amber-700 hover:bg-amber-50 cursor-pointer rounded-lg"
                          >
                            <XCircle className="h-4 w-4" />
                          </Button>
                        ) : (
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            title="Reactivate"
                            onClick={() => handleActivate(company)}
                            className="h-8 w-8 text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 cursor-pointer rounded-lg"
                          >
                            <RotateCcw className="h-4 w-4" />
                          </Button>
                        )}
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          title={
                            company.has_filed_rows
                              ? "Cannot delete: records were already filed under this company"
                              : "Delete"
                          }
                          onClick={() => handleDelete(company)}
                          className={`h-8 w-8 rounded-lg ${
                            company.has_filed_rows
                              ? "text-slate-300 hover:bg-transparent hover:text-slate-300 cursor-not-allowed"
                              : "text-red-500 hover:text-red-600 hover:bg-red-50 cursor-pointer"
                          }`}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={6} className="py-10 text-center">
                      <Building className="mx-auto mb-2 h-8 w-8 text-slate-300" />
                      <p className="text-sm text-slate-400">
                        No companies yet. Add the company you file Expanded WTAX
                        for above.
                      </p>
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>

          {companies?.links && <DataTablePagination links={companies.links} />}
        </Card>
      </motion.div>

      <Dialog
        open={Boolean(editing)}
        onOpenChange={(open) => !open && handleCloseEdit()}
      >
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Edit Company</DialogTitle>
            <DialogDescription>
              {editing?.has_filed_rows
                ? "Expanded WTAX records were already filed under this TIN and branch code, so those two fields are locked. Add a new company if the identity really changed."
                : "Update the company details the 1601EQ DAT header is built from."}
            </DialogDescription>
          </DialogHeader>

          <form id="edit-company-form" onSubmit={handleEdit}>
            <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
              <FormField
                label="Registered Name"
                required
                error={editForm.errors.registered_name}
              >
                <Input
                  type="text"
                  value={editForm.data.registered_name}
                  onChange={(event) =>
                    editForm.setData("registered_name", event.target.value)
                  }
                  className={editForm.errors.registered_name ? errorClass : ""}
                />
              </FormField>

              <FormField label="Trade Name" error={editForm.errors.trade_name}>
                <Input
                  type="text"
                  value={editForm.data.trade_name}
                  onChange={(event) =>
                    editForm.setData("trade_name", event.target.value)
                  }
                  className={editForm.errors.trade_name ? errorClass : ""}
                />
              </FormField>

              <FormField
                label="Company TIN"
                required
                hint={
                  editing?.has_filed_rows
                    ? "Locked: records were filed under this TIN."
                    : undefined
                }
                error={editForm.errors.tin}
              >
                <Input
                  type="text"
                  inputMode="numeric"
                  readOnly={Boolean(editing?.has_filed_rows)}
                  value={editForm.data.tin}
                  onChange={(event) => editForm.setData("tin", event.target.value)}
                  className={`${editForm.errors.tin ? errorClass : ""} ${
                    editing?.has_filed_rows ? "bg-slate-100 text-slate-500" : ""
                  }`}
                />
              </FormField>

              <FormField
                label="Branch Code"
                required
                hint={
                  editing?.has_filed_rows
                    ? "Locked: records were filed under this branch."
                    : undefined
                }
                error={editForm.errors.branch_code}
              >
                <Input
                  type="text"
                  inputMode="numeric"
                  readOnly={Boolean(editing?.has_filed_rows)}
                  value={editForm.data.branch_code}
                  onChange={(event) =>
                    editForm.setData("branch_code", event.target.value)
                  }
                  className={`${editForm.errors.branch_code ? errorClass : ""} ${
                    editing?.has_filed_rows ? "bg-slate-100 text-slate-500" : ""
                  }`}
                />
              </FormField>

              <FormField label="RDO Code" error={editForm.errors.rdo_code}>
                <Input
                  type="text"
                  inputMode="numeric"
                  value={editForm.data.rdo_code}
                  onChange={(event) =>
                    editForm.setData("rdo_code", event.target.value)
                  }
                  className={editForm.errors.rdo_code ? errorClass : ""}
                />
              </FormField>

              <FormField label="Address 1" error={editForm.errors.address1}>
                <Input
                  type="text"
                  value={editForm.data.address1}
                  onChange={(event) =>
                    editForm.setData("address1", event.target.value)
                  }
                  className={editForm.errors.address1 ? errorClass : ""}
                />
              </FormField>

              <FormField label="Address 2" error={editForm.errors.address2}>
                <Input
                  type="text"
                  value={editForm.data.address2}
                  onChange={(event) =>
                    editForm.setData("address2", event.target.value)
                  }
                  className={editForm.errors.address2 ? errorClass : ""}
                />
              </FormField>

              <div className="flex items-center gap-2 md:pt-7">
                <input
                  id="edit-company-active"
                  type="checkbox"
                  checked={Boolean(editForm.data.is_active)}
                  onChange={(event) =>
                    editForm.setData("is_active", event.target.checked)
                  }
                  className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                />
                <label
                  htmlFor="edit-company-active"
                  className="text-sm font-medium text-slate-700"
                >
                  Active
                  <span className="ml-1 font-normal text-slate-400">
                    (shown in the Known Company dropdowns)
                  </span>
                </label>
              </div>
            </div>
          </form>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={handleCloseEdit}
              disabled={editForm.processing}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              form="edit-company-form"
              disabled={editForm.processing}
              className="bg-[#0344a4] hover:bg-[#023384] text-white"
            >
              {editForm.processing ? (
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

const errorClass = "border-red-500 focus-visible:ring-red-500";

function FormField({ label, required = false, hint, error, children }) {
  return (
    <div className="space-y-2">
      <label className="text-sm font-medium text-slate-700">
        {label} {required && <span className="text-red-500">*</span>}
      </label>
      {children}
      {error ? (
        <p className="text-xs font-medium text-red-500">{error}</p>
      ) : (
        hint && <p className="text-xs text-slate-400">{hint}</p>
      )}
    </div>
  );
}

WithholdingCompanies.layout = (page) => (
  <MainLayout title="Manage Companies">{page}</MainLayout>
);

export default WithholdingCompanies;

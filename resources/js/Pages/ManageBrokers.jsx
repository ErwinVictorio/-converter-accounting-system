import React, { useEffect, useState } from "react";
import { usePage, router } from "@inertiajs/react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { Pencil, Trash2, Loader2, Plus, RefreshCw } from "lucide-react";
import { motion, AnimatePresence } from "framer-motion";

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
import DataTablePagination from "@/Layouts/Pagination";
import { brokerSchema } from "@/lib/FormSchema";

const containerVariants = {
  hidden: { opacity: 0, y: 15 },
  visible: {
    opacity: 1,
    y: 0,
    transition: { duration: 0.4, staggerChildren: 0.1 },
  },
};

const itemVariants = {
  hidden: { opacity: 0, y: 10 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.3 } },
};

function ManageBrokers() {
  const { flash, brokerList = [] } = usePage().props;
  const [editingId, setEditingId] = useState(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    setError,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(brokerSchema),
    defaultValues: {
      broker_name: "",
      tin: "",
    },
  });

  useEffect(() => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
  }, [flash]);

  // Submit Handler gamit ang direktang URLs
  const onSubmit = (formData) => {
    setIsSubmitting(true);

    if (editingId) {
      // Direct URL para sa UPDATE
      router.put(`/brokers/${editingId}`, formData, {
        onSuccess: () => {
          handleCancelEdit();
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
    } else {
      // Direct URL para sa CREATE
      router.post("/create", formData, {
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
    }
  };

  const handleEdit = (broker) => {
    setEditingId(broker.id);
    setValue("broker_name", broker.broker_name || "");
    setValue("tin", broker.tin_number || "");
  };

  const handleCancelEdit = () => {
    setEditingId(null);
    reset({
      broker_name: "",
      tin: "",
    });
  };

  // Direct URL para sa DELETE
  const handleDelete = (id) => {
    if (confirm("Are you sure you want to delete this broker?")) {
      router.delete(`/brokers/${id}`, {
        onSuccess: () => {
          if (editingId === id) {
            handleCancelEdit();
          }
        },
      });
    }
  };

  return (
    <motion.section
      className="space-y-6 p-6 max-w-6xl mx-auto"
      initial="hidden"
      animate="visible"
      variants={containerVariants}
    >
      <motion.h2
        variants={itemVariants}
        className="text-2xl font-bold tracking-tight text-slate-800"
      >
        Broker Management
      </motion.h2>

      {/* Form Card */}
      <motion.div variants={itemVariants}>
        <Card className="w-full shadow-sm border border-slate-100 rounded-xl bg-white overflow-hidden">
          <CardContent className="p-6">
            <form id="broker-form" onSubmit={handleSubmit(onSubmit)}>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">
                    Broker Name <span className="text-red-500">*</span>
                  </label>
                  <Input
                    type="text"
                    placeholder="Enter Broker Name"
                    {...register("broker_name")}
                    className={`w-full ${
                      errors.broker_name ? "border-red-500 focus-visible:ring-red-500" : ""
                    }`}
                  />
                  {errors.broker_name && (
                    <p className="text-xs text-red-500 font-medium">
                      {errors.broker_name.message}
                    </p>
                  )}
                </div>

                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">TIN</label>
                  <Input
                    type="text"
                    placeholder="000-000-000-000"
                    {...register("tin")}
                    className={`w-full ${
                      errors.tin ? "border-red-500 focus-visible:ring-red-500" : ""
                    }`}
                  />
                  {errors.tin && (
                    <p className="text-xs text-red-500 font-medium">
                      {errors.tin.message}
                    </p>
                  )}
                </div>
              </div>
            </form>
          </CardContent>

          <CardFooter className="flex justify-end gap-3 p-4 bg-slate-50/50 border-t border-slate-100">
            <AnimatePresence>
              {editingId && (
                <motion.div
                  initial={{ opacity: 0, scale: 0.9 }}
                  animate={{ opacity: 1, scale: 1 }}
                  exit={{ opacity: 0, scale: 0.9 }}
                >
                  <Button
                    type="button"
                    variant="outline"
                    onClick={handleCancelEdit}
                    disabled={isSubmitting}
                  >
                    Cancel
                  </Button>
                </motion.div>
              )}
            </AnimatePresence>

            <Button
              type="submit"
              form="broker-form"
              disabled={isSubmitting}
              className="bg-[#0344a4] hover:bg-[#023384] text-white px-6 min-w-[110px]"
            >
              {isSubmitting ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : editingId ? (
                <span className="flex items-center gap-1.5">
                  <RefreshCw className="w-4 h-4" /> Update
                </span>
              ) : (
                <span className="flex items-center gap-1.5">
                  <Plus className="w-4 h-4" /> Submit
                </span>
              )}
            </Button>
          </CardFooter>
        </Card>
      </motion.div>

      {/* Table Card */}
      <motion.div variants={itemVariants}>
        <Card className="w-full shadow-sm border border-slate-100 rounded-xl overflow-hidden bg-white">
          <CardHeader className="py-4 border-b border-slate-100 bg-slate-50/50">
            <CardTitle className="text-lg font-medium text-slate-800">
              List of Brokers
            </CardTitle>
          </CardHeader>

          <CardContent className="p-0">
            <Table>
              <TableHeader className="bg-slate-50/70">
                <TableRow>
                  <TableHead className="font-semibold text-slate-700 pl-6">
                    Broker Name
                  </TableHead>
                  <TableHead className="font-semibold text-slate-700">
                    TIN
                  </TableHead>
                  <TableHead className="text-right pr-6 font-semibold text-slate-700">
                    Actions
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {brokerList?.length > 0 ? (
                  brokerList.map((broker) => (
                    <TableRow
                      key={broker.id}
                      className="hover:bg-slate-50/50 transition-colors"
                    >
                      <TableCell className="font-medium text-slate-900 pl-6 py-4">
                        {broker.broker_name}
                      </TableCell>
                      <TableCell className="text-slate-600">
                        {broker.tin_number || "-"}
                      </TableCell>
                      <TableCell className="text-right pr-6 py-4 space-x-1">
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          onClick={() => handleEdit(broker)}
                          className="h-8 w-8 text-blue-600 hover:text-blue-700 hover:bg-blue-50 cursor-pointer rounded-lg"
                        >
                          <Pencil className="h-4 w-4" />
                        </Button>

                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          onClick={() => handleDelete(broker.id)}
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
                      colSpan={3}
                      className="text-center py-8 text-slate-400"
                    >
                      No brokers found.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>

          {brokerList?.links && (
            <div className="p-4 border-t border-slate-100">
              <DataTablePagination links={brokerList.links} />
            </div>
          )}
        </Card>
      </motion.div>
    </motion.section>
  );
}

ManageBrokers.layout = (page) => (
  <MainLayout title="Manage Brokers">{page}</MainLayout>
);

export default ManageBrokers;
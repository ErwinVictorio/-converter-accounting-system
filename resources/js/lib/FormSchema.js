import * as z from "zod";
export const recordFormSchema = z.object({
    registeredName: z.string().min(1, "Registered name is required."),
    supplierName: z.string().min(1, "Supplier name is required."),
    supplierAddress: z.string().min(1, "Supplier address is required."),
    grossPurchase: z
        .string()
        .min(1, "Gross purchase amount is required.")
        .refine((val) => !isNaN(Number(val)),  "Must be a valid number"),
    exemptPurchase: z
        .string()
        .optional()
        .refine((val) => !val || !isNaN(Number(val)), "Must be a valid number"),
});



export const brokerSchema = z.object({
    broker_name: z
        .string()
        .nonempty({ message: 'broker name is required' })
        .max(100, { message: "Broker name must not exceed 100 characters." }),
    tin: z
        .string()
        .nonempty({ message: 'TIN is required' })
       
});

export const supplierSchema = z.object({
    tin: z
        .string()
        .nonempty({ message: "TIN is required" })
        .max(20, { message: "TIN must not exceed 20 characters." }),
    name: z
        .string()
        .nonempty({ message: "Supplier name is required" })
        .max(60, { message: "Supplier name must not exceed 60 characters." }),
    addr: z
        .string()
        .nonempty({ message: "Address is required" })
        .max(100, { message: "Address must not exceed 100 characters." }),
    city: z
        .string()
        .nonempty({ message: "City is required" })
        .max(100, { message: "City must not exceed 100 characters." }),
});

export const customerSchema = z.object({
    tin: z
        .string()
        .nonempty({ message: "TIN is required" })
        .max(20, { message: "TIN must not exceed 20 characters." }),
    name: z
        .string()
        .nonempty({ message: "Customer name is required" })
        .max(300, { message: "Customer name must not exceed 300 characters." }),
    addr: z
        .string()
        .nonempty({ message: "Address is required" })
        .max(500, { message: "Address must not exceed 500 characters." }),
    city: z
        .string()
        .nonempty({ message: "City is required" })
        .max(100, { message: "City must not exceed 100 characters." }),
});

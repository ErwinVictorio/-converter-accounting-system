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

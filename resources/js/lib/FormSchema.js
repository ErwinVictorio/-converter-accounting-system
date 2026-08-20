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

// Amount fields follow the BIR Excel rule: plain number, no commas, >= 0.
const amountField = (label) =>
    z
        .string()
        .nonempty({ message: `${label} is required.` })
        .refine((val) => !isNaN(Number(val)) && Number(val) >= 0, {
            message: `${label} must be a number of 0 or more (no commas).`,
        });

// Mirrors the customs paperwork: users key total landed cost, and the entry
// screen derives "all charges before release" and "taxable goods" from it.
// Those two are never posted -- ImportationController computes them.
export const importationSchema = z
    .object({
        tax_month: z.string().nonempty({ message: "Tax month is required." }),
        import_entry_no: z
            .string()
            .nonempty({ message: "Import entry no. is required." })
            .max(100, { message: "Import entry no. must not exceed 100 characters." }),
        assessment_date: z.string().nonempty({ message: "Assessment / release date is required." }),
        supplier: z
            .string()
            .nonempty({ message: "Name of seller is required." })
            .max(255, { message: "Name of seller must not exceed 255 characters." }),
        importation_date: z.string().nonempty({ message: "Date of importation is required." }),
        country: z
            .string()
            .nonempty({ message: "Country of origin is required." })
            .max(100, { message: "Country of origin must not exceed 100 characters." }),
        total_landed_cost: amountField("Total landed cost"),
        dutiable_value: amountField("Dutiable value"),
        exempt: amountField("Exempt"),
        vat_rate: amountField("VAT rate"),
        vat_payable: amountField("VAT"),
        or_number: z
            .string()
            .nonempty({ message: "OR number is required." })
            .max(100, { message: "OR number must not exceed 100 characters." }),
        payment_date: z.string().nonempty({ message: "Date of VAT payment is required." }),
    })
    // Both derived amounts must stay >= 0, so neither input may exceed the landed cost.
    .refine((data) => Number(data.dutiable_value) <= Number(data.total_landed_cost), {
        path: ["dutiable_value"],
        message: "Dutiable value cannot be more than the total landed cost.",
    })
    .refine((data) => Number(data.exempt) <= Number(data.total_landed_cost), {
        path: ["exempt"],
        message: "Exempt cannot be more than the total landed cost.",
    });

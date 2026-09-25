import * as z from "zod";

export const countNonWhitespace = (value = "") =>
    Array.from(value.replace(/\p{White_Space}/gu, "")).length;

export const masterDataText = (label, limit, storageLimit) => z.string()
    .refine((value) => countNonWhitespace(value) > 0, `${label} is required.`)
    .refine((value) => countNonWhitespace(value) <= limit,
        `${label} must not exceed ${limit} characters, excluding spaces.`)
    .refine((value) => Array.from(value).length <= storageLimit,
        `${label} must not exceed ${storageLimit} total characters, including spaces.`);

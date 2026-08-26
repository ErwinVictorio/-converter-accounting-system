/**
 * Formatting shared by the Record pages.
 *
 * These were inline helpers on the old combined records screen; the three
 * Excel-backed Record pages all need the same two, so they live here rather
 * than being copied per page.
 */

// Amounts are displayed, never recomputed -- what was uploaded is what shows.
export const formatCurrency = (value) =>
    new Intl.NumberFormat("en-US", {
        style: "decimal",
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value || 0);

// reporting_period arrives as a month-end date; only the month is meaningful.
export const formatMonth = (value) => {
    if (!value) return "—";

    const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);

    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleDateString("en-US", { month: "short", year: "numeric" });
};

// 12 digits at most, shown in the dashed groups the BIR forms use.
export const formatTinInput = (value) => {
    const digits = String(value || "").replace(/\D/g, "").slice(0, 12);
    const parts = [
        digits.slice(0, 3),
        digits.slice(3, 6),
        digits.slice(6, 9),
        digits.slice(9, 12),
    ].filter(Boolean);

    return parts.join("-");
};

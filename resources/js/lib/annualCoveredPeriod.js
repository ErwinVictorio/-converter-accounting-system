/**
 * The Annual Expanded WTAX covered period rule, repeated on screen so the user is
 * told before submitting instead of after.
 *
 * The backend stays the source of truth -- app/Services/BIR/AnnualCoveredPeriodValidator.php
 * holds the same rule and refuses the upload and the download regardless of what
 * happens here. Keep the two in step.
 */

export const ANNUAL_FULL_YEAR_MESSAGE =
    "Annual Expanded WTAX must cover one full taxable year: January 1 to December 31 of the same year.";

export const ANNUAL_FULL_YEAR_HINT =
    "Annual filing must cover one full taxable year: January 1 to December 31.";

/**
 * @param {string} startDate `YYYY-MM-DD` from a date input
 * @param {string} endDate `YYYY-MM-DD` from a date input
 * @returns {string} the message to show, empty when the period is one full taxable year
 */
export function annualCoveredPeriodError(startDate, endDate) {
    if (!startDate || !endDate) {
        return "Please select the annual start date and end date.";
    }

    if (endDate < startDate) {
        return "Annual end date cannot be before the start date.";
    }

    const sameYear = startDate.slice(0, 4) === endDate.slice(0, 4);

    if (!sameYear || !startDate.endsWith("-01-01") || !endDate.endsWith("-12-31")) {
        return ANNUAL_FULL_YEAR_MESSAGE;
    }

    return "";
}

export function isFullTaxableYear(startDate, endDate) {
    return annualCoveredPeriodError(startDate, endDate) === "";
}

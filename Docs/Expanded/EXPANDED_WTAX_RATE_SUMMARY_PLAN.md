# Expanded WTAX Records Rate Summary - Implementation Plan

## Goal

Add a compact **Tax Withheld Summary by Rate** above the Expanded WTAX Records table. Each card shows the sum of Tax Withheld amounts for its rate across all records matching the current search and month filters.

## Summation rule

Use the complete filtered, consolidated `$expandedRows` collection in `RecordController::expandedWtax()` before `forPage()` pagination. Group by `tax_rate` and sum each group's existing `tax_withheld` values, including records with validation warnings as the table does.

- **1% Tax Withheld Total** = sum of `tax_withheld` where `tax_rate = 1.00`.
- **2% Tax Withheld Total** = sum of `tax_withheld` where `tax_rate = 2.00`.
- Apply the same rule to 5%, 10%, and every other available rate.

Sum each consolidated row once; do not multiply by `merged_rows` or recalculate withholding from income payment and rate. Preserve stored values and existing consolidation/rounding rules. Accumulate summary amounts in integer centavos to avoid floating-point drift, then return two-decimal amount strings.

## Implementation steps

1. **Backend - `app/Http/Controllers/RecordController.php`**
   - Reuse the existing search and `period` filters and complete `$expandedRows` collection; no extra query or endpoint is needed.
   - Normalize rate grouping keys to two decimal places and sum `tax_withheld` per group before pagination.
   - Return the Inertia prop `withholdingTaxRateSummary` with `rates: [{ tax_rate: "1.00", tax_withheld_total: "1688.81" }]`.
   - Sort rates numerically ascending. Include all available rates, including fractional rates, rather than hardcoding 1%, 2%, 5%, and 10%.
   - Return an empty `rates` array for no matches. Keep cards for rates with matching records whose amounts sum to zero.

2. **UI - `resources/js/Pages/Records/ExpandedWtaxRecords.jsx`**
   - Render the summary immediately above `RecordTableShell`, outside its horizontally scrolling table area.
   - Use the heading **Tax Withheld Summary by Rate** and description "Total tax withheld amount per rate based on current filters."
   - Label cards **1% Tax Withheld Total**, **2% Tax Withheld Total**, etc., with the peso amount below, such as **₱1,688.81**. Preserve fractional rate labels. Show thousands separators and two decimals, reusing the existing currency formatter where suitable.
   - Follow the reference image using existing Card components, Tailwind styling, and Lucide icons. Use subtle blue, green, amber, and rose accents with a neutral fallback for other rates and readable text independent of color.
   - Use one column on narrow screens, two on small screens, and four on large screens; wrap additional rates. Match existing spacing, rounded corners, and light borders.
   - Display "Amounts cover all filtered records across all pages." For no matches, show "No records match the current filters."
   - Read amounts from the server summary prop, never from `expandedWtaxEntries.data`. Existing search/month navigation refreshes the summary with the table; pagination preserves the same totals.

3. **Verification - `tests/Feature/RecordPagesTest.php`**
   - Assert exact monetary sums for multiple records per rate, fractional rates, numeric ordering, zero totals, and empty results.
   - Verify merged source rows contribute their consolidated Tax Withheld amount exactly once. Include centavo values to verify monetary precision.
   - Seed more than 15 consolidated records and assert identical rate totals on pages 1 and 2, including amounts outside the current page.
   - Verify payee/TIN/ATC searches, month filtering, and combined search/month filtering restrict the summary and table consistently. Clearing filters must restore the corresponding totals.
   - Confirm the sum of all rate totals equals the Tax Withheld sum of the full filtered collection.
   - Run `php artisan test --filter=RecordPagesTest` and `npm run build`; visually check peso formatting, responsive layout, filter clearing, and pagination.

## Scope and format preservation

- Add only the new summary. Keep the existing table structure, column order, labels, formatting, badges, and pagination layout unchanged.
- No database/schema change is required. Preserve stored amounts, consolidation, filtering, validation, and imports.
- Do not modify any DAT format: headers, field order/count, delimiters, line endings, filenames, totals, calculations, or rounding.
- Do not modify PDF/attachment or Excel upload/export formats, including `scripts/render-dat-attachment-pdf.mjs` and the attachment renderer.
- The supplied image guides only the new summary's appearance. Record-count cards and Income Payment totals are outside this revised scope.

## Acceptance criteria

- Each card shows its rate's Tax Withheld sum across all matching records, including other pagination pages.
- Search and month changes update the summary and table together; switching pages does not change summary amounts.
- Cards use labels such as **1% Tax Withheld Total**, with the peso amount below, and remain compact and responsive for all available rates.
- Existing table, database, DAT, PDF/attachment, and Excel formats remain unchanged.

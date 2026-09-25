# Temporary Purchase Supplier Total Report Plan

## Status

Implemented. This document describes the active temporary page and report behavior.

## Goal

Add one authenticated temporary page where a user can upload a Purchases Summary Excel workbook and download a new Excel report that contains one total per supplier.

This tool is report-only:

- it must not import rows into `vat_inputs` or any other database table
- it must not update Supplier master data
- it must not change the existing Purchase upload, Purchase Records, dashboard, adjustment, DAT, or attachment calculations
- the uploaded workbook should exist only for the current request and should not be retained as a pending upload

## Sample Workbook Findings

The inspected sample is `Docs/Temporary/PURCHASES SUMMARY.xlsx`.

- It contains two visible worksheets: `Sheet1` and `Sheet2`.
- Its active worksheet is `Sheet2`, but the implementation will select it by its exact worksheet name rather than relying on the active-tab setting.
- Row 1 is the report title.
- Row 2 is the covered-period label.
- Row 3 contains `No`, `Date`, `Supplier Name`, `Particulars`, and `Amount`.
- Data starts on row 4.
- Supplier Name is column C and Amount is column E.
- The worksheets are not interchangeable duplicates: `Sheet1` has 1,960 supplier rows and a total of `1,916,259,989.30`, while active `Sheet2` has 1,812 supplier rows and a total of `1,830,486,051.13`.
- Both inspected sheets contain 124 supplier names after basic case/whitespace normalization.

The implementation prefers `Sheet2` when it contains the required headings. If `Sheet2` is absent or does not contain them, it uses the first worksheet containing `Supplier Name` and `Amount` on the same row. It detects the header row and both source columns rather than assuming fixed cells, and processes only the selected worksheet to avoid double counting.

## User Flow

1. Open the new **Supplier Total Report** item under **Data & Transactions** in the sidebar.
2. Select one `.xlsx` workbook.
3. Click **Generate and Download Report**.
4. The server detects one worksheet containing `Supplier Name` and `Amount`, preferring `Sheet2` when valid.
5. If valid, the response immediately downloads the summarized `.xlsx` report.
6. If invalid, stay on the page and show a concise, actionable validation error without generating a partial report.

The page should also explain that it automatically detects the worksheet and the `Supplier Name` and `Amount` columns. No records are saved.

## Supplier Grouping Rules

Group using a comparison-only normalized supplier key:

- trim leading and trailing whitespace
- collapse repeated internal whitespace to one space
- compare case-insensitively

Use the first nonblank cleaned spelling encountered as the report's display name. Do not perform fuzzy matching, punctuation removal, alias matching, or Supplier master-data lookup. For example, a punctuation difference such as `ABC INC.` versus `ABC, INC.` remains two suppliers unless a later scope explicitly adds alias rules.

Reject rows with a blank Supplier Name when another value is present on that data row. Completely blank rows may be skipped.

## Amount Rules

- Read the calculated numeric value from column E.
- Accept positive, zero, and negative numeric amounts so legitimate reversal rows remain part of the supplier total.
- Reject a nonblank, nonnumeric amount and report its worksheet row number.
- Do not parse a number out of free text.
- Sum with decimal/cents-safe arithmetic and round only the final supplier totals to two decimal places; do not use binary floating-point as the accounting accumulator.
- Skip the existing grand-total/footer row because it has no Supplier Name and would otherwise count the workbook total again.
- Verify that the sum of all supplier totals equals the sum of all accepted source amounts before returning the download.

## Workbook Validation

Accept only `.xlsx` for version 1 because the supplied format is XLSX and active-sheet/formula behavior should remain predictable. Keep the existing project upload limit of 10 MB unless a real workbook requires a larger documented limit.

Validation should fail atomically when:

- the upload is missing or is not a readable XLSX workbook
- no worksheet contains `Supplier Name` and `Amount` headings on the same row within its first 25 rows
- there are no valid data rows
- a populated data row has a blank supplier
- an amount is nonnumeric or a formula resolves to an Excel error
- the generated grouped total fails the source-total integrity check

Errors should identify `Sheet2` and the row when applicable, such as: `Sheet2 row 18: Amount must be numeric.`

## Downloaded Report

Return an XLSX file named in this pattern:

`PURCHASE_SUPPLIER_TOTALS_YYYYMMDD_HHMMSS.xlsx`

Create one worksheet named `Supplier Totals` with:

1. Report title: `PURCHASE SUPPLIER TOTALS`
2. Source filename
3. Source worksheet name
4. Source covered-period text copied from row 2 when present
5. Generated date and time
6. A table with these columns:
   - `No`
   - `Supplier Name`
   - `Transaction Count`
   - `Total Amount`
7. One row per normalized supplier, sorted A-Z by Supplier Name
8. A final `GRAND TOTAL` row containing the total transaction count and total amount

Format Total Amount as a numeric Excel currency-style value with comma separators and two decimal places. Keep cells numeric so users can sort, filter, and calculate with them. Apply an autofilter, freeze the table heading, use readable column widths, and emphasize the heading and grand-total rows.

Do not include the original `No`, `Date`, or `Particulars` transaction details in this summary report.

## Planned Application Changes

### Backend

Add a narrowly scoped controller, for example:

- `app/Http/Controllers/TemporaryPurchaseSupplierTotalController.php`

Responsibilities:

- render the temporary Inertia page
- validate the uploaded file
- prefer a valid `Sheet2`, otherwise select the first worksheet containing both required headings
- detect the header row and the `Supplier Name` and `Amount` columns, then parse the rows below it
- normalize supplier grouping keys and total amounts
- create and return the XLSX download
- release workbook objects and temporary resources after the response is built

Keep parsing/grouping outside the controller in a small service if needed for focused unit testing, for example:

- `app/Services/TemporaryPurchaseSupplierTotalService.php`

Add authenticated routes in `routes/web.php`:

- `GET /temporary/purchase-supplier-totals` to show the page
- `POST /temporary/purchase-supplier-totals` to generate the download

Use named routes for both endpoints. No migration, model, job, queue, or scheduled cleanup command is required.

### Frontend

Add an Inertia page, for example:

- `resources/js/Pages/Temporary/PurchaseSupplierTotals.jsx`

The page should contain:

- a short purpose and no-storage notice
- an XLSX-only file picker
- the selected filename
- a `Generate and Download Report` button with loading/disabled state
- inline validation feedback

Use the existing `MainLayout` and current form/button/card components so it matches the rest of the application. Submit as a normal download-capable form rather than expecting Inertia to interpret a binary XLSX response.

### Sidebar

Update `resources/js/Components/app-sidebar.jsx`:

- add **Supplier Total Report** under **Data & Transactions**
- point it to `/temporary/purchase-supplier-totals`
- use an existing report/spreadsheet-style Lucide icon
- preserve the sidebar's current longest-path active-state behavior

The label makes the page's temporary business purpose clear without exposing internal implementation wording in the UI.

## Test Plan

Add a focused feature test, for example `tests/Feature/TemporaryPurchaseSupplierTotalReportTest.php`, covering:

1. Guest access redirects to login for both routes.
2. An authenticated user can open the Inertia page.
3. Missing, oversized, non-XLSX, corrupt, and wrong-heading files are rejected.
4. A valid `Sheet2` is preferred when a workbook contains multiple sheets.
5. A workbook without `Sheet2` uses the first other worksheet containing both required headings.
6. Supplier Name and Amount are detected when their columns move, including the original C/E layout and the STC B/D layout.
7. Same-name suppliers with case or whitespace differences consolidate into one row.
8. Punctuation-different supplier names remain separate.
9. Numeric, zero, and negative amounts are included correctly.
10. Blank supplier and nonnumeric/error amounts return row-specific errors.
11. Footer/grand-total rows are not counted as transactions.
12. The response is an XLSX attachment with the expected filename pattern and worksheet headings.
13. Supplier rows are sorted A-Z and transaction counts are correct.
14. The downloaded grand total exactly matches the accepted source-row total.
15. Generating the report creates no rows in Purchase or Supplier database tables and retains no uploaded workbook.
16. Both supplied Purchase Summary workbooks generate successfully without double-counting another sheet.

Suggested verification:

```bash
php -l app/Http/Controllers/TemporaryPurchaseSupplierTotalController.php
php artisan test --filter=TemporaryPurchaseSupplierTotalReportTest
php artisan test --filter=RecordPagesTest
vendor/bin/pint --test
git diff --check
```

Frontend build verification should be run only if Node/npm is available in the environment.

## Acceptance Criteria

- The sidebar opens a dedicated authenticated Supplier Total Report page.
- Uploading a workbook automatically detects one worksheet and the Supplier Name and Amount columns; a valid `Sheet2` is preferred but not required.
- Every accepted transaction belongs to exactly one normalized supplier group.
- Each supplier's Total Amount is the exact sum of its accepted source amounts.
- The report is downloaded as a usable XLSX file with numeric totals, A-Z supplier order, transaction counts, and a matching grand total.
- Invalid workbooks produce clear errors and no partial report.
- No uploaded data is persisted and no existing Purchase, Supplier, DAT, or accounting workflow changes.

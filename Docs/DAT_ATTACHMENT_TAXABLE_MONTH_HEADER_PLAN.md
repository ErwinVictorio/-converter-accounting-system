# DAT Attachment Taxable Month Header Plan

Status: Implemented on 2026-09-09. Visual React PDF verification remains pending in a Node-enabled environment.

## Implementation verification

- Removed the repeated month column and values from Sales, Purchase, and Importation attachment report data.
- Added `TAXABLE MONTH` below the owner's address in both React PDF and fallback PDF headers, using the existing report period.
- DAT generators, file format, filenames, ordering, and ZIP packaging code were not changed.
- Focused attachment, validation, alphabetical ordering, Importation download, and Purchase/Sales/Importation generator suites: **57 passed (622 assertions)**.
- Tests check remaining row/total alignment and values, month-end header metadata, fallback PDF header placement, DAT package contents, and existing DAT format expectations.
- Node is not available in this shell; visual React PDF and multi-page layout checks could not be completed here.

## Requested result

Remove the repeated `Taxable Month` column from the attachment table and display the selected period once in the report header, below the owner's address:

```text
SALES TRANSACTION
RECONCILIATION OF LISTING FOR ENFORCEMENT

TIN : 008-791-976
OWNER'S NAME: FORTRESS STEEL INC.
OWNER'S TRADE NAME : FORTRESS STEEL INC.
OWNER'S ADDRESS: ...
TAXABLE MONTH: 07/31/2026

[Transaction table without the Taxable Month column]
```

Keep the existing MM/DD/YYYY month-end date format. Use the selected reporting period, not the current date or an individual transaction date.

## Scope

Apply consistently to Sales, Purchase, and Importation PDF attachments, which currently all repeat Taxable Month in every detail row. Expanded WTAX is outside this attachment change.

Preserve all other column labels and their relative order, transaction rows, alphabetical ordering, amounts, calculations, totals, company information, and page size/orientation. DAT file contents, validation rules, filenames, ZIP packaging, and stored records remain unchanged.

## Implementation

1. In `app/Services/BIR/DatAttachmentReportBuilder.php`, remove the `Taxable Month` column and the corresponding first value from each Sales, Purchase, and Importation detail row. Remove unused closure captures of `$period` where applicable. Keep the existing report-level `period` field, which already contains the required date.
2. In `scripts/render-dat-attachment-pdf.mjs`, display `TAXABLE MONTH: ${report.period}` below the owner's address using the existing metadata styling. Display it once in the report header. The remaining table columns will share the available width using the existing layout.
3. In `app/Services/BIR/DatAttachmentPdfRenderer.php`, add the same header line to `fallbackLines()` so fallback PDFs also show the period.
4. Verify the totals array remains aligned with the shortened rows. The current totals builder derives positions from row values, so no calculation changes are expected.

Expected table column counts: Purchase 13, Sales 10, Importation 13.

## Verification

- Check report data for all three types: period remains correct, Taxable Month is absent from columns/detail cells, and columns, rows, and totals have matching lengths.
- Confirm amounts and totals remain identical under their respective column labels.
- Generate and visually inspect a sample PDF: date appears under the owner's address, the repeated month column is gone, and table/totals alignment remains readable. Check a multi-page sample for layout regressions.
- Check the fallback PDF header separately.
- Run relevant DAT download/package and alphabetical ordering tests; confirm the ZIP still contains its DAT and PDF and the DAT contents remain unchanged.

## Acceptance criteria

- Taxable Month is shown once in the report header instead of in every transaction row.
- All three attachment types follow the same presentation.
- No other transaction data or DAT output behavior changes.

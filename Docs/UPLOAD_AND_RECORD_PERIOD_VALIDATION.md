# Upload and Record Period Validation

## Purpose

This note documents two related safeguards:

- Upload files are checked before import so a Sales workbook cannot be uploaded as Purchase, and a Purchase workbook cannot be uploaded as Sales.
- Record pages now have a month selector so users can filter each table by the reporting period stored for that module.

No DAT generator or DAT file layout was changed.

## Upload Preflight Validation

Runtime files:

- `app/Imports/UploadWorkbookTypePreflight.php`
- `app/Http/Controllers/VatInputController.php`

Before importing Sales or Purchase uploads, the controller reads the workbook and checks:

- selected file type matches the detected workbook type
- selected reporting month matches the workbook period when the workbook exposes one

The preflight detects workbook type from headers and title rows:

- Sales Summary: `SALES SUMMARY BY DOCUMENT NUMBER`, `Document No`, `Customer Name`
- BIR Sales: `CLIENT TIN`
- Purchase: `supplier_name`, `vendor_tin`, `purchase_imported`, `purchase_local`, `input_vat`, `total_purchases`
- Expanded WTAX remains protected by `ExpandedWtaxUploadPreflight`

Example rejection:

```text
Upload rejected. The selected file type is Purchase, but the workbook appears to be a Sales file.
```

Example period rejection:

```text
Upload rejected. The workbook period is May 2026, but the selected reporting month is September 2026.
```

The upload is rejected before any import or database write runs.

## Record Period Filters

Runtime files:

- `app/Http/Controllers/RecordController.php`
- `resources/js/Components/Records/RecordPeriodFilter.jsx`
- `resources/js/Pages/Records/PurchaseRecords.jsx`
- `resources/js/Pages/Records/SalesRecords.jsx`
- `resources/js/Pages/Records/ExpandedWtaxRecords.jsx`
- `resources/js/Pages/Records/ImportationRecords.jsx`

Each Record page now exposes an `All months` selector with available months and record counts.

The period source per table is:

| Page | Query key | Database field |
| --- | --- | --- |
| Purchase Records | `period` | `vat_inputs.date_uploaded` |
| Sales Records | `period` | `sales_vatsinputs.reporting_period` |
| Expanded WTAX Records | `period` | `expanded_wtax_entries.reporting_period` |
| Importation Records | `tax_month` | `importation_entries.tax_month` |

Search and period filters work together. Pagination preserves the selected filters through `withQueryString()`.

## Verification

Focused tests:

```bash
php artisan test tests\Feature\RecordPagesTest.php tests\Feature\UploadWorkbookTypePreflightTest.php
```

Verified result:

```text
19 passed (384 assertions)
```

PHP syntax check:

```bash
php -l app\Http\Controllers\RecordController.php
```

Frontend build was not verified because `npm` was not available in the shell.

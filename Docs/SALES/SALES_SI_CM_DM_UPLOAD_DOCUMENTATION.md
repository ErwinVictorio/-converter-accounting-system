# Sales SI/CM/DM Upload Documentation

## Purpose

This document describes the implemented Sales upload behavior for Sales Invoice (`SI`), Credit Memo (`CM`), and Debit Memo (`DM`) rows from the Sales Summary workbook.

This applies only to:
- Sales upload through `record_type=sales`
- Sales records stored in `sales_vatsinputs`
- Sales DAT period validation and download
- Sales Records listing totals

This does not change:
- Purchase upload
- Purchase DAT generation
- Importation workflows
- Expanded WTAX workflows

## Source Workbook

The Sales Summary workbook uses document numbers such as:

```text
SI#14529
CM#003180 (REG)
DM#00001
```

The implemented importer reads the `Document No` column from the Sales Summary layout and classifies each row before storage.

## Document Type Rules

Sales rows are classified by `document_no`:

| Prefix | Stored Type | Behavior |
| --- | --- | --- |
| `SI`, `SI#`, `SI-` | `SI` | Stored and included as positive Sales Invoice amount. |
| `CM`, `CM#`, `CM-` | `CM` | Stored and subtracted from SI totals during consolidation. |
| `DM`, `DM#`, `DM-` | none | Skipped during upload and not stored. |
| Other | `OTHER` | Stored as fallback for old or unusual rows and treated like normal positive Sales. |
| BIR R_Sales rows | `BIR` | Stored from BIR Sales format and treated like normal positive Sales. |

## DM Upload Warning

DM rows are not uploaded into `sales_vatsinputs`.

When a workbook contains valid SI/CM rows and also DM rows:

```text
Sales VAT report successfully imported!
Sales upload completed, but {count} DM row(s) were skipped because Debit Memo rows are not included in Sales VAT upload.
```

When a workbook contains only DM rows:

```text
Sales upload skipped {count} DM row(s). No importable SI/CM Sales rows were found.
```

This prevents a DM-only workbook from looking like a normal successful Sales upload.

## Storage

The `sales_vatsinputs` table now has:

```text
document_type
```

The migration also backfills existing rows:
- `document_no` starting with `SI` becomes `SI`
- `document_no` starting with `CM` becomes `CM`

DM rows are not backfilled as stored Sales rows because they are skipped at upload time.

## Sales Consolidation Rule

Sales DAT generation and Sales Records listing use the same consolidation service:

```text
App\Services\BIR\SalesSiCmConsolidator
```

Rows are grouped by customer identity:
- first 9 digits of customer TIN
- customer type
- company/customer name
- individual name fields
- address fields

Within each customer group:

```text
Final Sales = SI total + OTHER/BIR total - CM total
```

For the DAT fields:

```text
taxable_sales    = SI taxable + OTHER/BIR taxable - absolute CM taxable
output_vat       = SI VAT + OTHER/BIR VAT - absolute CM VAT
exempt_sales     = SI exempt + OTHER/BIR exempt - absolute CM exempt
zero_rated_sales = SI zero-rated + OTHER/BIR zero-rated - absolute CM zero-rated
```

CM values are subtracted using their absolute amount, so both positive CM values and parenthesized/negative CM values reduce Sales correctly.

## Example

If one customer has:

```text
10 SI rows total taxable sales = 1,000,000.00
4 CM rows total taxable sales  =   200,000.00
```

The Sales DAT uses one customer detail group:

```text
taxable_sales = 800,000.00
```

If a customer has only CM rows:

```text
SI total = 0.00
CM total = 200,000.00
Final taxable_sales = -200,000.00
```

The row is not dropped.

## Sales Records Page

The Sales Records page now shows consolidated customer rows using the same SI/CM netting rule as Sales DAT generation.

Additional columns:
- `SI Rows`
- `CM Rows`

These counts help explain why one customer line may represent several uploaded SI and CM source rows.

## Purchase Safeguard

Purchase upload remains separate:

```text
record_type=purchase -> VatInputImport -> vat_inputs
```

The SI/CM/DM rules apply only to:

```text
record_type=sales -> SalesVatInputImport -> sales_vatsinputs
```

Purchase DAT generation still uses:

```text
DatFileController::downloadPurchase()
ReliefPurchaseDatGenerator
```

## Main Files

Backend:
- `database/migrations/2026_09_02_000000_add_document_type_to_sales_vatsinputs_table.php`
- `app/Models/SalesVatInput.php`
- `app/Imports/SalesVatInputImport.php`
- `app/Services/BIR/SalesSiCmConsolidator.php`
- `app/Http/Controllers/VatInputController.php`
- `app/Http/Controllers/DatFileController.php`
- `app/Http/Controllers/RecordController.php`
- `app/Http/Middleware/HandleInertiaRequests.php`

Frontend:
- `resources/js/Pages/RecordEntry.jsx`
- `resources/js/Pages/Records/SalesRecords.jsx`

Tests:
- `tests/Feature/UploadWorkbookTypePreflightTest.php`
- `tests/Unit/SalesSiCmConsolidatorTest.php`
- `tests/Unit/ReliefSalesDatGeneratorTest.php`
- `tests/Unit/ReliefPurchaseDatGeneratorTest.php`
- `tests/Feature/RecordPagesTest.php`
- `tests/Feature/DashboardTest.php`

## Verification

Focused verification used during implementation:

```text
php artisan test tests\Feature\UploadWorkbookTypePreflightTest.php tests\Unit\ReliefSalesDatGeneratorTest.php tests\Unit\SalesSiCmConsolidatorTest.php tests\Unit\ReliefPurchaseDatGeneratorTest.php
```

Result:

```text
20 passed
```

Additional regression checks:

```text
php artisan test tests\Feature\RecordPagesTest.php
php artisan test tests\Feature\DashboardTest.php
```

Results:

```text
14 passed
16 passed
```

PHP lint passed for the changed backend files.

Frontend build note:

```text
npm run build
```

could not be run in the current shell because `npm` is not available.

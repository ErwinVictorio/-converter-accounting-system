# DAT Generation Change Log

## 2026-09-04 - Sales BIR Rounding And Alphabetical DAT Details

### Summary

Two DAT-generation behaviors were updated:

1. Sales DAT consolidated amounts now follow the BIR checking basis.
2. Generated DAT detail rows are now alphabetical where applicable.

The changes are limited to DAT generation and tests. No upload layout, database storage, DAT field layout, filename format, header format, or BIR validation rule was changed.

## Sales DAT BIR Rounding Alignment

### Problem

For Sales Summary uploads, the workbook stores invoice-level rounded values for `VAT` and `Net of VAT`.

The previous DAT generation flow summed those already-rounded row values after grouping by customer. This can differ by a few centavos from BIR's checker, which recomputes from the consolidated net amount.

Example from:

```text
Docs/SALES/RevisiseDatfileLogic/SALES SUMMARY BY DOCUMENT NUMBER (1).xlsx
```

NCR CONSTRUCTION SUPPLY INC.:

```text
Current row-sum result:
Net of VAT: 1,536,995.68
VAT:        184,439.47

BIR-style consolidated result:
Net Amount: 1,721,435.15
Net of VAT: 1,721,435.15 / 1.12 = 1,536,995.67
VAT:        1,721,435.15 - 1,536,995.67 = 184,439.48
```

### Implemented Behavior

`SalesSiCmConsolidator` now computes DAT-facing Sales taxable amount and output VAT from the consolidated net amount.

Formula:

```text
vatable_gross = net_amount - exempt_sales - zero_rated_sales
taxable_sales = round(vatable_gross / 1.12, 2)
output_vat = round(vatable_gross - taxable_sales, 2)
```

This preserves exempt and zero-rated values and prevents them from being treated as VAT-inclusive taxable gross.

### Files Changed

```text
app/Services/BIR/SalesSiCmConsolidator.php
tests/Unit/SalesSiCmConsolidatorTest.php
tests/Unit/ReliefSalesDatGeneratorTest.php
```

### Preserved Behavior

- Uploaded `sales_vatsinputs` values are not rewritten.
- Sales DAT layout remains unchanged.
- Sales DAT field count remains unchanged.
- Sales filename remains unchanged.
- SI rows still add.
- CM rows still subtract by absolute amount.
- DM rows remain excluded by upload/import behavior.

## Alphabetical DAT Detail Ordering

### Problem

Some generated DAT files followed insert/order sequence instead of alphabetical taxpayer/payee/supplier names.

### Implemented Behavior

Generated detail rows are now ordered alphabetically by the business name used in the DAT detail line.

| DAT Type | Ordering Key |
| --- | --- |
| Purchase | `supplier_name` |
| Sales | consolidated `company_name` / `customer_name` |
| Importation | `supplier` |
| Expanded WTAX Quarterly | `payee_name` |
| Expanded WTAX Annual | `payee_name` |

Purchase, Sales, and Importation now sort during download preparation in `DatFileController`.

Expanded WTAX already ordered by payee name, so its runtime logic was preserved and annual order coverage was added.

### Files Changed

```text
app/Http/Controllers/DatFileController.php
tests/Feature/DatFileAlphabeticalOrderingTest.php
tests/Feature/ImportationDatFileTest.php
tests/Feature/ExpandedWtaxDatFileTest.php
```

### Preserved Behavior

- DAT detail field order is unchanged.
- Header totals are unchanged except where they naturally reflect the Sales BIR rounding correction.
- Purchase calculations are unchanged.
- Importation calculations are unchanged.
- Expanded WTAX consolidation and calculations are unchanged.
- Database row order and stored sequence numbers are unchanged.
- Record page ordering is unchanged by this change.

## Verification

Syntax checks:

```powershell
php -l app\Services\BIR\SalesSiCmConsolidator.php
php -l app\Http\Controllers\DatFileController.php
php -l tests\Feature\DatFileAlphabeticalOrderingTest.php
```

Focused Sales rounding verification:

```powershell
php artisan test tests\Unit\SalesSiCmConsolidatorTest.php tests\Unit\ReliefSalesDatGeneratorTest.php
```

Broader Sales upload/DAT verification:

```powershell
php artisan test tests\Feature\UploadWorkbookTypePreflightTest.php tests\Unit\SalesSiCmConsolidatorTest.php tests\Unit\ReliefSalesDatGeneratorTest.php
```

Alphabetical DAT verification:

```powershell
php artisan test tests\Feature\DatFileAlphabeticalOrderingTest.php tests\Feature\ImportationDatFileTest.php tests\Feature\ExpandedWtaxDatFileTest.php tests\Unit\SalesSiCmConsolidatorTest.php tests\Unit\ReliefPurchaseDatGeneratorTest.php tests\Unit\ReliefSalesDatGeneratorTest.php
```

Final focused result:

```text
53 passed (277 assertions)
```

Whitespace check:

```powershell
git diff --check
```

Result:

```text
Passed
```

# Sales DAT BIR Rounding Alignment Plan

## Goal

Align the generated Sales DAT computation with the BIR checking behavior for consolidated customer totals.

This addresses small decimal differences like the NCR CONSTRUCTION SUPPLY INC. case:

| Source | Taxable / Net of VAT | Output VAT |
| --- | ---: | ---: |
| Current generated DAT | 1,536,995.68 | 184,439.47 |
| BIR checking | 1,536,995.67 | 184,439.48 |

The mismatch is caused by a rounding-basis difference, not by DAT decimal formatting.

## Evidence From Actual File

Actual workbook:

`Docs/SALES/RevisiseDatfileLogic/SALES SUMMARY BY DOCUMENT NUMBER (1).xlsx`

The NCR rows in the workbook contain invoice-level rounded values. The current app imports those rounded row values, then sums them during DAT generation.

For NCR CONSTRUCTION SUPPLY INC.:

```text
Consolidated Net Amount: 1,721,435.15
Current app-style sum of Net of VAT rows: 1,536,995.68
Current app-style sum of VAT rows:        184,439.47
```

The BIR checking appears to recompute after consolidation:

```text
Net of VAT = 1,721,435.15 / 1.12 = 1,536,995.67
VAT        = 1,721,435.15 - 1,536,995.67 = 184,439.48
```

This matches the BIR image.

## Current Behavior

Sales Summary import currently reads these workbook columns directly:

| Field | Source Column |
| --- | --- |
| `net_amount` | Net Amount |
| `output_vat` | VAT |
| `taxable_net_of_vat` | Net of VAT |

Relevant code:

- `app/Imports/SalesVatInputImport.php`
- `app/Services/BIR/SalesSiCmConsolidator.php`
- `app/Services/BIR/ReliefSalesDatGenerator.php`

The current flow is:

```text
Excel row values
-> stored in sales_vatsinputs
-> grouped by customer/TIN/address
-> sum taxable_net_of_vat
-> sum output_vat
-> format to 2 decimals in DAT
```

Because each invoice row is already rounded, summing those row-level rounded values can differ by a few centavos from BIR's consolidated recomputation.

## Target Behavior

Keep imported Excel values unchanged for records/audit.

Only during Sales DAT consolidation, compute VATable totals from the consolidated net amount.

Recommended formula:

```text
exempt_sales     = consolidated exempt sales
zero_rated_sales = consolidated zero-rated sales
net_amount       = consolidated net amount
vatable_gross    = net_amount - exempt_sales - zero_rated_sales
taxable_sales    = round(vatable_gross / 1.12, 2)
output_vat       = round(vatable_gross - taxable_sales, 2)
```

For the current Sales Summary file, exempt and zero-rated sales are zero, so this becomes:

```text
taxable_sales = round(net_amount / 1.12, 2)
output_vat    = round(net_amount - taxable_sales, 2)
```

## Files To Change

Primary file:

```text
app/Services/BIR/SalesSiCmConsolidator.php
```

Test files:

```text
tests/Unit/SalesSiCmConsolidatorTest.php
tests/Unit/ReliefSalesDatGeneratorTest.php
```

No planned changes:

```text
app/Imports/SalesVatInputImport.php
app/Services/BIR/ReliefSalesDatGenerator.php
config/bir.php
```

The DAT layout, field order, quoting, TIN formatting, filename format, and line endings should remain unchanged.

## Implementation Steps

1. Update `SalesSiCmConsolidator::consolidateGroup()`.

2. Preserve the existing grouping identity:

```text
Customer TIN first 9 digits
Customer type
Company/name fields
Address fields
```

3. Preserve existing document-type netting:

```text
SI rows    = add
CM rows    = subtract absolute value
OTHER rows = add
DM rows    = skipped during import
```

4. Compute consolidated `net_amount`, `exempt_sales`, and `zero_rated_sales` first.

5. Derive DAT-facing taxable sales and output VAT from the consolidated net amount:

```php
$exemptSales = $netAmount('exempt_sales');
$zeroRatedSales = $netAmount('zero_rated_sales');
$netAmountValue = $netAmount('net_amount');

$vatableGross = round($netAmountValue - $exemptSales - $zeroRatedSales, 2);
$taxableSales = round($vatableGross / 1.12, 2);
$outputVat = round($vatableGross - $taxableSales, 2);
```

6. Return the recomputed DAT fields:

```php
'exempt_sales' => $exemptSales,
'zero_rated_sales' => $zeroRatedSales,
'taxable_sales' => $taxableSales,
'taxable_net_of_vat' => $taxableSales,
'output_vat' => $outputVat,
'net_amount' => $netAmountValue,
```

7. Keep `gross_amount` and diagnostic SI/CM fields unless there is a separate reason to revise them:

```php
'gross_amount' => $netAmount('gross_amount'),
'si_taxable_sales' => round($this->sumSigned($siRows, 'taxable_net_of_vat'), 2),
'cm_taxable_sales' => round($this->sumCreditMemo($cmRows, 'taxable_net_of_vat'), 2),
'si_output_vat' => round($this->sumSigned($siRows, 'output_vat'), 2),
'cm_output_vat' => round($this->sumCreditMemo($cmRows, 'output_vat'), 2),
```

## Test Plan

### 1. NCR Regression Test

Add a test in `tests/Unit/SalesSiCmConsolidatorTest.php` using the actual NCR workbook rows.

SI rows:

| Document | Net Amount |
| --- | ---: |
| SI#15683 | 124,800.00 |
| SI#15684 | 600,000.00 |
| SI#15685 | 10,000.00 |
| SI#15686 | 100,000.00 |
| SI#15687 | 414,000.00 |
| SI#15694 | 239,430.00 |
| SI#15695 | 306,820.00 |

CM rows:

| Document | Net Amount |
| --- | ---: |
| CM#003203 | 21,908.00 |
| CM#003204 | 11,000.00 |
| CM#003231 | 16,706.85 |
| CM#003239 | 24,000.00 |

Expected consolidated output:

```text
net_amount:     1,721,435.15
taxable_sales:  1,536,995.67
output_vat:       184,439.48
```

### 2. Existing SI/CM Netting Tests

Keep the existing tests that verify:

```text
Same customer SI and CM rows net into one group.
CM-only customer becomes negative.
Different customers do not net against each other.
```

Adjust expected values only where the new BIR-style formula intentionally changes the result.

### 3. DAT Output Regression

Add or update a Sales DAT generator test to assert that a consolidated NCR-style row produces:

```text
1536995.67
184439.48
```

This confirms the corrected values survive through DAT formatting.

### 4. Exempt / Zero-Rated Safety

Add a test where a customer has exempt or zero-rated sales.

Expected behavior:

```text
vatable_gross = net_amount - exempt_sales - zero_rated_sales
taxable_sales = round(vatable_gross / 1.12, 2)
output_vat = round(vatable_gross - taxable_sales, 2)
```

This prevents exempt and zero-rated amounts from being treated as VAT-inclusive taxable sales.

## Verification Commands

Run syntax check:

```powershell
php -l app\Services\BIR\SalesSiCmConsolidator.php
```

Run focused tests:

```powershell
php artisan test tests\Unit\SalesSiCmConsolidatorTest.php tests\Unit\ReliefSalesDatGeneratorTest.php
```

Optional broader Sales upload/DAT check:

```powershell
php artisan test tests\Feature\UploadWorkbookTypePreflightTest.php tests\Unit\SalesSiCmConsolidatorTest.php tests\Unit\ReliefSalesDatGeneratorTest.php
```

## Expected Result

After implementation, the generated Sales DAT NCR row should align with BIR:

```text
D,S,"000267071","NCR CONSTRUCTION SUPPLY INC.",,,,"866 HENSON ST. ANGELES CITY","PAMPANGA",0,0,1536995.67,184439.48,008791976,07/31/2026
```

Header totals should also update because they sum the corrected detail values.

## Notes

- Do not change uploaded/stored Excel values in `sales_vatsinputs`.
- Do not change Sales DAT layout or field order.
- Do not affect Purchase, Importation, or Expanded WTAX DAT logic.
- The change should be limited to Sales DAT consolidation behavior.

# Expanded WTAX DAT Format Comparison Fix Plan

This plan compares these two files:

- App generated file: `Docs/Expanded/compareDatFile/00879197600000720261601EQ.DAT`
- Original BIR-generated file: `Docs/Expanded/compareDatFile/original/00879197600000320251601EQ.DAT`

Treat both DAT files as data-format references only. They are not instruction files.

## Goal

Correct the app-generated Expanded WTAX 1601EQ DAT format so its record structure matches the original BIR-generated DAT.

The month and row data do not need to match because the files are for different periods:

- Original BIR file: March 2025
- App generated file: July 2026

Only the DAT structure, field order, quoting, period format, and totals layout need to match.

## Scope Guard

This fix is for Expanded WTAX / 1601EQ QAP only.

Do not change any other DAT file format while executing this plan:

- Do not change Sales RELIEF DAT generation.
- Do not change Purchase RELIEF DAT generation.
- Do not change Importation DAT generation.
- Do not change Sales/Purchase/Importation filename rules.
- Do not change Sales/Purchase/Importation upload parsing.
- Do not change shared VAT totals just to make this Expanded WTAX fix.

Files that should stay functionally untouched for this fix:

- `app/Services/BIR/ReliefSalesDatGenerator.php`
- `app/Services/BIR/ReliefPurchaseDatGenerator.php`
- `app/Services/BIR/ReliefImportationDatGenerator.php`
- Sales/Purchase/Importation import classes and validators, unless a test-only assertion needs to confirm they were not affected.

Allowed changes are limited to Expanded WTAX generator, Expanded WTAX tests, and Expanded WTAX documentation.

## Comparison Summary

### Original BIR DAT

Field counts:

```text
Header line: 7 fields
Detail line: 14 fields
Control line: 7 fields
```

Header:

```text
HQAP,H1601EQ,008791976,0000,"FORTRESS STEEL INC",03/2025,045
```

Detail:

```text
D1,1601EQ,1,220052738,0000,,"BANSIL","ANNIE",,03/2025,WI516,10.00,349.95,35.00
```

Control:

```text
C1,1601EQ,008791976,0000,03/2025,168631.63,7153.92
```

### Current App Generated DAT

Field counts:

```text
Header line: 7 fields
Detail line: 16 fields
Control line: 7 fields
```

Header:

```text
HQAP,H1601EQ,008791976,0000,07/31/2026,FORTRESS STEEL INC,045
```

Detail:

```text
D1,1601EQ,008791976,0000,07/31/2026,1,236791864,0000,"A ZINC INDUSTRIAL GALVANIZING PHILIPPINES",,,,WC160,8145.00,2.00,162.90
```

Control:

```text
C1,1601EQ,008791976,0000,07/31/2026,9186973.40,127776.44
```

## Exact Format Differences

### 1. Header Field Order Is Wrong

Original BIR:

```text
HQAP,H1601EQ,{TIN},{BRANCH},"{WITHHOLDING_AGENT_NAME}",{MM/YYYY},{RDO}
```

Current app:

```text
HQAP,H1601EQ,{TIN},{BRANCH},{MM/DD/YYYY},{WITHHOLDING_AGENT_NAME},{RDO}
```

Fix:

- Move withholding agent name before period.
- Quote withholding agent name.
- Use `MM/YYYY`, not `MM/DD/YYYY`.

Expected app header for July 2026:

```text
HQAP,H1601EQ,008791976,0000,"FORTRESS STEEL INC",07/2026,045
```

### 2. Detail Rows Have Too Many Fields

Original BIR detail has 14 fields:

```text
D1,1601EQ,{SEQUENCE},{PAYEE_TIN},{PAYEE_BRANCH},{COMPANY_NAME},{SURNAME},{FIRST_NAME},{MIDDLE_NAME},{MM/YYYY},{ATC},{EWT_RATE},{INCOME_PAYMENT},{TAX_AMOUNT}
```

Current app detail has 16 fields:

```text
D1,1601EQ,{AGENT_TIN},{AGENT_BRANCH},{MM/DD/YYYY},{SEQUENCE},{PAYEE_TIN},{PAYEE_BRANCH},{COMPANY_NAME},{SURNAME},{FIRST_NAME},{MIDDLE_NAME},{ATC},{INCOME_PAYMENT},{EWT_RATE},{TAX_AMOUNT}
```

Fix:

- Remove withholding agent TIN from detail rows.
- Remove withholding agent branch from detail rows.
- Remove month-end date from the early detail fields.
- Put sequence as field 3.
- Put reporting period as `MM/YYYY` after `middleName`.
- Put fields after period in this order: `ATC`, `EWT rate`, `income payment`, `tax amount`.

Expected app detail example:

```text
D1,1601EQ,1,236791864,0000,"A ZINC INDUSTRIAL GALVANIZING PHILIPPINES",,,,07/2026,WC160,2.00,8145.00,162.90
```

### 3. Control Period Format Is Wrong

Original BIR:

```text
C1,1601EQ,{TIN},{BRANCH},{MM/YYYY},{TOTAL_INCOME_PAYMENT},{TOTAL_TAX_AMOUNT}
```

Current app:

```text
C1,1601EQ,{TIN},{BRANCH},{MM/DD/YYYY},{TOTAL_INCOME_PAYMENT},{TOTAL_TAX_AMOUNT}
```

Fix:

- Keep 7 fields.
- Change period from month-end date to `MM/YYYY`.

Expected app control example:

```text
C1,1601EQ,008791976,0000,07/2026,9186973.40,127776.44
```

## Code Changes

Update `app/Services/BIR/ReliefExpandedWtaxDatGenerator.php`.

Do not edit the Sales, Purchase, or Importation DAT generators for this task.

### Header Method

Change field order to:

```php
[
    'HQAP',
    'H1601EQ',
    $tin,
    $branch,
    $this->quote($withholdingAgentName),
    $period->format('m/Y'),
    $rdoCode,
]
```

Header must still have exactly 7 fields.

### Detail Method

Change field order to:

```php
[
    'D1',
    '1601EQ',
    $sequence,
    $payeeTin,
    $payeeBranch,
    $companyName,
    $surname,
    $firstName,
    $middleName,
    $period->format('m/Y'),
    $atc,
    $rate,
    $incomePayment,
    $taxAmount,
]
```

Detail must have exactly 14 fields.

### Control Method

Change period format to:

```php
$period->format('m/Y')
```

Control field order stays:

```php
[
    'C1',
    '1601EQ',
    $tin,
    $branch,
    $period->format('m/Y'),
    $totalIncomePayment,
    $totalTaxAmount,
]
```

Control must still have exactly 7 fields.

## Tests To Update

Update:

- `tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php`
- `tests/Feature/ExpandedWtaxDatFileTest.php`

Do not rewrite Sales/Purchase/Importation DAT tests. At most, run existing focused tests if needed to prove there was no regression.

Required assertions:

- Header equals:

```text
HQAP,H1601EQ,008791976,0000,"FORTRESS STEEL INC",07/2026,045
```

- Detail has exactly 14 CSV fields.
- Detail field positions:

```text
0  D1
1  1601EQ
2  sequence
3  payee TIN
4  payee branch
5  company name
6  surname
7  first name
8  middle name
9  MM/YYYY
10 ATC
11 EWT rate
12 income payment
13 tax amount
```

- Control equals:

```text
C1,1601EQ,008791976,0000,07/2026,{TOTAL_INCOME_PAYMENT},{TOTAL_TAX_AMOUNT}
```

- Filename remains:

```text
00879197600000720261601EQ.DAT
```

## Documentation To Update

Update:

- `Docs/Expanded/EXPANDED_WTAX_FORMAT_GUIDE.md`
- `Docs/Expanded/1601EQ_QAP_AVS_FIX_PLAN.md`

Make both docs show the original BIR format:

```text
HQAP,H1601EQ,{TIN},{BRANCH},"{WA_NAME}",{MM/YYYY},{RDO}
D1,1601EQ,{SEQ},{PAYEE_TIN},{PAYEE_BRANCH},{COMPANY},{SURNAME},{FIRST},{MIDDLE},{MM/YYYY},{ATC},{RATE},{INCOME},{TAX}
C1,1601EQ,{TIN},{BRANCH},{MM/YYYY},{TOTAL_INCOME},{TOTAL_TAX}
```

## Verification

Run:

```bash
php -l app/Services/BIR/ReliefExpandedWtaxDatGenerator.php
```

Run focused tests:

```bash
php artisan test tests/Feature/ExpandedWtaxImportTest.php tests/Feature/ExpandedWtaxDatFileTest.php tests/Feature/ExpandedWtaxConsolidationTest.php tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php tests/Unit/BirExpandedWtaxRowValidatorTest.php
```

Try frontend build only if `npm` is available:

```bash
npm run build
```

## Manual Validation

After implementation:

1. Generate July 2026 Expanded WTAX DAT from the app.
2. Compare the first line, first detail line, and control line against the original BIR format.
3. Validate the generated DAT in AVS.
4. Save any new AVS error TXT under:

```text
Docs/Expanded/error/
```

## Done Criteria

The fix is done when:

- App header matches original BIR field order and `MM/YYYY` period format.
- App detail rows have 14 fields, not 16.
- App detail rows put sequence in field 3 and payee TIN in field 4.
- App detail rows put `MM/YYYY` before ATC.
- App control row uses `MM/YYYY`.
- Focused Expanded WTAX tests pass.
- AVS no longer reports format-wide detail/control column errors.

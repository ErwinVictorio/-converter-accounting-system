# Expanded WTAX Attachment PDF Plan

## Execution Status

Implemented on 2026-09-10 for Quarterly Expanded WTAX (`1601EQ`) downloads.
Annual Expanded WTAX (`1604E`) is intentionally unchanged and still downloads
as a direct DAT file unless a separate annual attachment sample is approved.

## Goal

Add the readable PDF attachment for Expanded WTAX downloads, using the sample report:

```text
Docs/attachment/EXPANDED.xlsx
```

The output should join the existing DAT + attachment ZIP behavior already used by Purchase, Sales, and Importation, so the user receives the official Expanded WTAX `.DAT` and the readable PDF attachment together from the Generate DAT download action.

## Non-Negotiable Boundary

Do not change the Expanded WTAX DAT format.

No changes to:

- `1601EQ` DAT field count, field order, headers, details, control rows, delimiters, line endings, filenames, period formatting, or row ordering.
- `1604E` Annual DAT field count, field order, headers, details, control rows, delimiters, line endings, filenames, period formatting, or row ordering.
- Expanded WTAX upload parsing, ATC mapping, rate validation, consolidation rules, selected-company filtering, or annual full-year validation.

This plan adds only the readable PDF attachment and ZIP packaging for Expanded WTAX where a matching readable report layout exists.

## Sample Report Findings

`Docs/attachment/EXPANDED.xlsx` has one worksheet named `Sheet1`.

Header area:

- Row 1: `BIR FORM 1702Q`
- Row 2: `SUMMARY ALPHALIST OF WITHHOLDING TAXES (SAWT)`
- Row 3: `FOR THE MONTH OF MAY, 2026`
- Row 6: `TIN : 008791976-0000`
- Row 7: `PAYEE'S NAME: FORTRESS STEEL INC.`

Detail table header:

- Row 11 to 14 contain multi-line column headings.
- Row 15 is a separator row.
- Detail rows start on row 16.
- The inspected sample has 79 detail rows.
- Rows 95 to 97 contain separator and grand total lines.
- Row 98: `END OF REPORT`

Grand totals in the sample:

- Amount of Income Payment: `39,519,121.91`
- Amount of Tax Withheld: `395,643.54`

The sample has static total values, not formulas, so the app should compute PDF totals from the same selected/consolidated backend rows used for DAT generation.

## Required Expanded Attachment Columns

Use these columns in the PDF detail table:

1. Seq No
2. Taxpayer Identification Number
3. Corporation Registered Name
4. Individual Name
5. ATC Code
6. Nature of Payment
7. Amount of Income Payment
8. Tax Rate
9. Amount of Tax Withheld

## Taxable Month / Period Placement

Do not add `Taxable Month` as a repeated detail column.

Follow the current attachment direction from `Docs/DAT_ATTACHMENT_TAXABLE_MONTH_HEADER_PLAN.md`: show the selected reporting period once in the report header.

Suggested header:

```text
BIR FORM 1702Q
SUMMARY ALPHALIST OF WITHHOLDING TAXES (SAWT)
FOR THE MONTH OF MAY, 2026

TIN : 008791976-0000
PAYEE'S NAME: FORTRESS STEEL INC.
```

Use the selected Expanded WTAX reporting month for the `FOR THE MONTH OF ...` line.

## Scope Decision

Apply this first to Quarterly Expanded WTAX only:

```text
record_type=expanded
report_type=quarterly
```

Reason: the sample says `FOR THE MONTH OF MAY, 2026` and uses the monthly SAWT layout.

Leave Annual `1604E` attachment generation unchanged/unsupported unless a separate annual readable attachment sample is provided or the user confirms this same SAWT layout should be used for annual downloads.

## Data Source

Use the same selected rows as `DatFileController::downloadExpanded()`.

Current Expanded quarterly flow:

1. Filter `ExpandedWtaxEntry` by:
   - `withholding_agent_tin`
   - `withholding_agent_branch_code`
   - `report_type = quarterly`
   - selected `reporting_period` month
2. Order by:
   - `payee_name`
   - `tax_rate`
   - `id`
3. Validate rows with `BirExpandedWtaxRowValidator`.
4. Consolidate through:

```php
ExpandedWtaxEntry::consolidate($records)
```

5. Generate the existing `1601EQ` DAT from the consolidated collection.

The attachment must use the same consolidated collection as the DAT so row counts, income totals, and tax withheld totals cannot disagree.

## Field Mapping

### Seq No

Use the attachment row sequence after consolidation:

```text
1, 2, 3, ...
```

This should match the generated DAT detail sequence order.

### Taxpayer Identification Number

Use consolidated row:

```php
payee_tin
payee_branch_code
```

Display with branch in the sample style:

```text
229-563-116-0000
```

The DAT continues to write the BIR-required payee TIN and branch fields exactly as before.

### Corporation Registered Name

For company payees:

```php
company_name
```

For individual payees, leave this cell blank.

### Individual Name

For individual payees:

```php
last_name, first_name, middle_name
```

For company payees, leave this cell blank.

### ATC Code

Use:

```php
atc_code
```

Do not derive ATC from rate. The current Expanded WTAX flow treats the workbook ATC as authoritative.

### Nature of Payment

Add an ATC description map for attachment display only.

Suggested config location:

```php
config/bir.php
expanded_wtax.atc_descriptions
```

Initial descriptions needed from the sample:

```php
'WC158' => 'Income payment made by top withholding agents to their local/resident supplier of goods other than those covered by other rates of withholding tax - Corporate',
'WC160' => 'Income payment made by top withholding agents to their local/resident supplier of services other than those covered by other rates of withholding tax - Corporate',
```

If existing data contains other allowed ATCs, either add descriptions for them or display a safe fallback:

```text
ATC {code}
```

Do not make missing description block DAT or PDF generation.

### Amount of Income Payment

Use consolidated row:

```php
income_payment
```

### Tax Rate

Use consolidated row:

```php
tax_rate
```

Display like the sample:

```text
1
2
5
10
```

No percent sign.

### Amount of Tax Withheld

Use consolidated row:

```php
tax_withheld
```

## Company/Header Mapping

Use the same selected withholding agent as the Expanded DAT:

```php
$this->selectedWithholdingAgent($request)
$this->companyForExpandedDownload($withholdingAgent)
```

Header display:

- `TIN : {agent_tin}-{agent_branch_code}`
- `PAYEE'S NAME: {agent registered/name}`

Use the selected month for:

- `FOR THE MONTH OF {MONTH}, {YEAR}`

## Implementation Plan

1. Extend `DatAttachmentReportBuilder`.

Add support for:

```php
recordType = expanded
```

Suggested method:

```php
private function expanded(Collection $records, array $company, Carbon $period): array
```

This method should build the SAWT-style report payload from consolidated Expanded WTAX rows.

2. Extend the report payload shape only where needed.

The existing Purchase/Sales/Importation payload is generic. Expanded can add optional metadata:

```php
'form' => 'BIR FORM 1702Q',
'title' => 'SUMMARY ALPHALIST OF WITHHOLDING TAXES (SAWT)',
'period_label' => 'FOR THE MONTH OF MAY, 2026',
'company_label' => "PAYEE'S NAME",
```

3. Update React PDF renderer.

Current renderer can stay generic if it reads optional labels from the report payload.

Needed behavior:

- For Expanded, render `BIR FORM 1702Q` then `SUMMARY ALPHALIST OF WITHHOLDING TAXES (SAWT)`.
- Render `FOR THE MONTH OF ...`.
- Render `TIN : ...`.
- Render `PAYEE'S NAME: ...`.
- Render the 9-column SAWT table.
- Render grand totals under income payment and tax withheld.
- Render `END OF REPORT`.

4. Update fallback PDF renderer.

Add support for the same optional header labels in `DatAttachmentPdfRenderer::fallbackLines()`.

5. Update `DatFileController::downloadExpanded()`.

After the existing validation and consolidation:

- Generate the DAT content exactly as today.
- Build the Expanded attachment report from the same consolidated rows.
- Render the PDF.
- Return a ZIP containing:
  - `{TIN}{BRANCH}{MMYYYY}1601EQ.DAT`
  - `{TIN}{BRANCH}{MMYYYY}1601EQ-ATTACHMENT.pdf`

6. Do not change `downloadExpandedAnnual()` yet.

Annual should keep returning the current `1604E` DAT response until an annual attachment layout is confirmed.

7. Update Generate DAT UI copy.

For:

```text
record_type=expanded
report_type=quarterly
```

button can say:

```text
Download DAT + Attachment
```

For annual Expanded WTAX, keep:

```text
Download DAT
```

## Testing Plan

Add focused tests for:

1. Quarterly Expanded WTAX ZIP contains:
   - existing `1601EQ.DAT`
   - `1601EQ-ATTACHMENT.pdf`
2. DAT extracted from the ZIP has the same content as the current direct Expanded DAT output.
3. PDF payload columns match the sample:
   - Seq No
   - Taxpayer Identification Number
   - Corporation Registered Name
   - Individual Name
   - ATC Code
   - Nature of Payment
   - Amount of Income Payment
   - Tax Rate
   - Amount of Tax Withheld
4. PDF report uses consolidated rows, not raw uploaded rows.
5. Totals equal the consolidated income payment and tax withheld totals.
6. Different ATC or tax rate rows remain separate.
7. Individual payees populate the Individual Name column and leave Corporation Registered Name blank.
8. Annual Expanded WTAX still returns the existing direct `1604E` DAT unless separately changed.
9. Existing Expanded WTAX DAT generator unit and feature tests still pass.

Suggested commands:

```bash
php artisan test tests/Feature/ExpandedWtaxDatFileTest.php
php artisan test tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php
php artisan test tests/Unit/ReliefExpandedWtaxAnnualDatGeneratorTest.php
php artisan test tests/Feature/DatFileAlphabeticalOrderingTest.php
```

If Node/npm is available:

```bash
npm install
npm run build
```

## Acceptance Criteria

- Quarterly Expanded WTAX downloads include both the official `1601EQ.DAT` and readable SAWT PDF attachment in one ZIP.
- Expanded attachment columns match `Docs/attachment/EXPANDED.xlsx`.
- The attachment uses the same consolidated rows as the DAT.
- Grand totals match the consolidated income payment and tax withheld values.
- No DAT format, DAT generator, upload parser, ATC validation, or consolidation rule changes are made.
- Annual Expanded WTAX remains unchanged unless a separate annual attachment format is approved.

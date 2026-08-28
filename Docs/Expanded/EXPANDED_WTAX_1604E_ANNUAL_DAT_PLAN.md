# Expanded WTAX 1604E Annual DAT Generation Plan

## Goal

Enable `Annual` Expanded WTAX DAT generation using the confirmed annual Schedule 3 template:

- `Docs/Expanded/Anual-format/1604E_Schedule_3_template.xls`

The annual generator must be separate from the existing quarterly `1601EQ` generator so the current quarterly DAT output does not change.

## Implementation Status

Status: implemented.

Implemented:

- Added a separate `ReliefExpandedWtaxAnnualDatGenerator`.
- Annual download now emits `H1604E`, `D3`, and `C3` records.
- Annual filename now follows `{TIN}{BRANCH}{MMDDYYYY}1604E.dat`.
- Annual generation validates raw rows before download.
- Annual generation requires the covered period to be one full taxable year, January 1 to December 31 of the same year, and refuses a partial period instead of widening it.
- Annual generation consolidates rows across the selected taxable year before writing detail lines.
- Quarterly `1601EQ` generation remains on the existing generator.
- Generate DAT UI now enables annual download when valid annual rows exist.
- Unit tests verify the annual generator against `Docs/Expanded/0087919760000123120251604E.dat`.

## Template Findings

Workbook:

- File: `Docs/Expanded/Anual-format/1604E_Schedule_3_template.xls`
- Sheet: `1604E_s3_v7`
- Data range in the sample template: `A1:K3`
- Header row: row `1`
- Data rows start: row `2`

Columns:

| Column | Header | Meaning |
| --- | --- | --- |
| A | `Reporting_Month` | Covered date/month for the annual schedule row |
| B | `Vendor_TIN` | Payee TIN, expected as 9-digit BIR TIN |
| C | `branchCode` | Payee branch code |
| D | `companyName` | Payee company name, for company payees |
| E | `surName` | Individual payee surname |
| F | `firstName` | Individual payee first name |
| G | `middleName` | Individual payee middle name |
| H | `ATC` | BIR ATC code |
| I | `income_payment` | Income payment amount |
| J | `ewt_rate` | Expanded withholding tax rate |
| K | `tax_amount` | Tax withheld amount |

Sample rows show the annual template already contains both `income_payment` and `tax_amount`, so Annual import/generation should not recompute the income payment from tax withheld unless a future source workbook requires it.

## Existing Annual DAT Sample

Reference file:

- `Docs/Expanded/0087919760000123120251604E.dat`

Observed format:

```text
H1604E,008791976,0000,12/31/2025
D3,1604E,008791976,0000,12/31/2025,1,007086184,0000,"ACERSTEEL INDUSTRIAL SALES INC",,,,WC158,3682716.00,1.00,36827.16
C3,1604E,008791976,0000,12/31/2025,241326.68
```

Observed annual filename pattern:

```text
{WITHHOLDING_AGENT_TIN}{BRANCH_CODE}{MMDDYYYY}1604E.dat
```

Example:

```text
0087919760000123120251604E.dat
```

## Annual DAT Records

### Header

```text
H1604E,{agent_tin},{agent_branch_code},{period_end}
```

Fields:

1. `H1604E`
2. Withholding agent TIN
3. Withholding agent branch code
4. Period end date, formatted `MM/DD/YYYY`

### Detail

```text
D3,1604E,{agent_tin},{agent_branch_code},{period_end},{sequence},{payee_tin},{payee_branch_code},{company_name},{surname},{first_name},{middle_name},{atc},{income_payment},{ewt_rate},{tax_amount}
```

Fields:

1. Record type: `D3`
2. Form type: `1604E`
3. Withholding agent TIN
4. Withholding agent branch code
5. Period end date, formatted `MM/DD/YYYY`
6. Sequence number
7. Payee TIN
8. Payee branch code
9. Company name
10. Surname
11. First name
12. Middle name
13. ATC
14. Income payment amount, two decimals
15. EWT rate, two decimals
16. Tax amount, two decimals

### Control

```text
C3,1604E,{agent_tin},{agent_branch_code},{period_end},{total_tax_amount}
```

Fields:

1. Record type: `C3`
2. Form type: `1604E`
3. Withholding agent TIN
4. Withholding agent branch code
5. Period end date, formatted `MM/DD/YYYY`
6. Total tax amount, two decimals

## Implementation Plan

### 1. Keep Quarterly Unchanged

Do not modify the current `1601EQ` quarterly generator behavior:

- Quarterly still uses one selected reporting month.
- Quarterly still emits `1601EQ` DAT.
- Quarterly validation remains based on `BirExpandedWtaxRowValidator`.

### 2. Add Annual Generator Class

Create a dedicated annual generator, for example:

```text
app/Services/BIR/ReliefExpandedWtaxAnnualDatGenerator.php
```

Responsibilities:

- Generate `H1604E`, `D3`, and `C3` records.
- Format annual period end date as `MM/DD/YYYY`.
- Quote company and individual name fields the same way the sample DAT does.
- Use CRLF line endings and a trailing line terminator.
- Generate filename using `{TIN}{BRANCH}{MMDDYYYY}1604E.dat`.

### 3. Add Annual Validation Path

Add or extend validation for annual rows so generation is blocked when:

- Payee TIN is missing, too short, or all zeroes.
- Payee branch code is missing or invalid.
- Both company name and individual name fields are missing.
- Company and individual name fields are both filled on one row.
- ATC is missing or not configured.
- Rate is missing or non-positive.
- Income payment or tax amount is not numeric.
- Tax amount does not match `income_payment * ewt_rate`.

### 4. Wire `downloadExpandedAnnual`

Update `app/Http/Controllers/DatFileController.php`:

- Replace the current annual guard message with real annual generation.
- Refuse the download before generating when the covered period is not one full taxable year. Annual Expanded WTAX requires a full taxable year. Select January 1 as Start Date and December 31 of the same year as End Date. The rule lives in `app/Services/BIR/AnnualCoveredPeriodValidator.php` and is shared with the upload path.
- Filter rows by:
  - selected withholding agent TIN
  - selected withholding agent branch code
  - `report_type = annual`
  - the selected taxable year, January 1 to December 31
- Validate raw rows before generation.
- Consolidate rows before generation using annual-compatible rules.
- Pass the consolidated rows to the new annual generator.

### 5. Confirm Consolidation Rule

Use the same grouping identity, except annual rows use the selected annual period end instead of their stored row month:

- selected annual period end
- withholding agent TIN
- withholding agent branch code
- report type
- payee identity
- ATC
- EWT rate

Important:

- Keep rows separate when ATC or rate differs.
- Sum only `income_payment` and `tax_amount`.
- Do not recompute uploaded amounts during consolidation.

### 6. Update UI Messaging

Update `resources/js/Pages/GenerateDatFile.jsx`:

- Enable the Annual `Download DAT` button when the covered period is one full taxable year, annual records exist, and no validation issues are found.
- State the covered period rule under the date fields:

```text
Annual filing must cover one full taxable year: January 1 to December 31.
```

- Show status based on row validity, for example:

```text
{count} annual expanded withholding tax rows found for the selected covered period.
```

If invalid rows exist, guide the user to the Expanded WTAX Records table status indicators.

### 7. Update Documentation

Update these docs after implementation:

- `Docs/Expanded/EXPANDED_WTAX_ANNUAL_QUARTERLY_GUIDE.md`
- `Docs/Expanded/EXPANDED_WTAX_FORMAT_GUIDE.md`

The docs should clearly state:

- Quarterly uses `1601EQ`.
- Annual uses `1604E Schedule 3`.
- Annual source template columns are `A:K`.
- Annual output filename ends in `1604E.dat`.
- Annual Expanded WTAX requires a full taxable year. Select January 1 as Start Date and December 31 of the same year as End Date.

## Test Plan

Add focused tests for:

- Annual generator emits the exact `H1604E`, `D3`, and `C3` record shapes.
- Annual filename follows `{TIN}{BRANCH}{MMDDYYYY}1604E.dat`.
- Annual totals use the sum of `tax_amount`.
- Annual detail sequence starts at `1` and increments per filed row.
- Company rows fill only the company name field.
- Individual rows fill surname, first name, and middle name fields.
- Annual generation blocks invalid rows and names the affected payee.
- Annual generation returns an error when no rows exist for the selected period.
- Annual generation refuses a partial or cross-year covered period instead of widening it to `12/31/YYYY`, and writes no file.
- Annual upload refuses the same periods before reading the workbook, leaving already stored annual rows untouched.
- Quarterly `1601EQ` DAT tests still pass unchanged.

## Answered Questions

**Should the annual covered period always end on `12/31/YYYY`, or should the selected end date be used exactly?**

Neither. The selected period must *be* the taxable year, so the two are the same date.

The DAT always carries `12/31/YYYY` because the AVS 1604E validator compares the file date with the Tax Year on its validation screen. Silently widening `01/01/2026 - 07/31/2026` to `12/31/2026` would file a full-year return holding seven months of payees, with nothing in the file to show for it. So the covered period is validated instead: January 1 to December 31 of one year, or the upload and the download are both refused.

```text
Annual Expanded WTAX must cover one full taxable year: January 1 to December 31 of the same year.
```

Enforced in `app/Services/BIR/AnnualCoveredPeriodValidator.php`, used by both `VatInputController::store()` and `DatFileController::download()`, and mirrored on screen by `resources/js/lib/annualCoveredPeriod.js`.

## Open Questions

1. Should Annual generation include only rows uploaded as `report_type = annual`, or should it also allow generating from existing quarterly uploads inside the selected covered period?
2. Should the annual generator accept negative reversal rows exactly like quarterly?
3. Should `Multiple TINs` groups be allowed when one valid TIN exists, or should annual generation block them for manual review?

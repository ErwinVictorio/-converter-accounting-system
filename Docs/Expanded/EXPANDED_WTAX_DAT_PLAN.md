# Expanded Withholding Tax DAT Upload Plan

## Goal

Build an Expanded Withholding Tax module that can upload the system Excel file in `Docs/Expanded/EXPANDED WTAX.xlsx` and generate a BIR-style `1604E` DAT file like `Docs/Expanded/0087919760000123120251604E.dat`.

This should follow the same local pattern already used by Purchases, Sales, and Importation:

- Upload Excel into a dedicated table.
- Validate and normalize BIR-safe fields before DAT generation.
- Show available reporting months in the Generate DAT screen.
- Download one month-level DAT file.
- Add focused unit/feature tests using the sample DAT as the reference.

## Sample Findings

### DAT Sample

Reference file:

- `Docs/Expanded/0087919760000123120251604E.dat`
- Header: `H1604E,008791976,0000,12/31/2025`
- Detail row starts with `D3,1604E,...`
- Control/trailer row starts with `C3,1604E,...`
- Filename pattern appears to be:
  - `{company_tin}{branch_code}{period_mmddyyyy_compact}1604E.dat`
  - Sample: `0087919760000123120251604E.dat`

Observed detail columns:

1. Record type: `D3`
2. Form type: `1604E`
3. Withholding agent TIN: `008791976`
4. Withholding agent branch code: `0000`
5. Period end date: `12/31/2025`
6. Sequence number
7. Payee TIN, first 9 digits
8. Payee branch code
9. Company name
10. Last name
11. First name
12. Middle name
13. ATC/tax code
14. Income payment amount
15. Tax rate
16. Tax withheld

Trailer columns:

1. Record type: `C3`
2. Form type: `1604E`
3. Withholding agent TIN
4. Withholding agent branch code
5. Period end date
6. Total tax withheld

### Excel Sample

Source file:

- `Docs/Expanded/EXPANDED WTAX.xlsx`
- Sheet: `Sheet1`
- Header row: row 3
- Data rows start at row 4

Columns:

| Excel Column | Header | Meaning |
| --- | --- | --- |
| A | No | Payment voucher/reference number |
| B | Date | Transaction date |
| C | Supplier Name | Payee name |
| D | TIN | Payee TIN |
| E | Reference | Invoice/SI/reference |
| F | `(1%)` | Tax withheld at 1% |
| G | `(2%)` | Tax withheld at 2% |
| H | `(5%)` | Tax withheld at 5% |
| I | `(10%)` | Tax withheld at 10% |
| J | `(15%)` | Tax withheld at 15% |
| K | Total | Total withholding tax |

Important rule:

- The Excel gives tax withheld amounts.
- The DAT requires income payment amount plus tax rate plus tax withheld.
- For every non-zero rate column, compute:
  - `income_payment = tax_withheld / (rate / 100)`

Example:

- Excel 1% withheld: `1340.13`
- DAT income payment should be `134013.00`
- DAT tax rate: `1.00`
- DAT withheld: `1340.13`

## Open Business Rule To Confirm

The sample DAT has different ATC/tax codes using the same rate:

| Tax Code | Rate | Observed Count |
| --- | ---: | ---: |
| WC158 | 1% | 28 |
| WC160 | 2% | 18 |
| WC100 | 5% | 2 |
| WI010 | 5% | 1 |
| WC139 | 10% | 4 |
| WI516 | 10% | 6 |

The Excel only provides rate columns, not ATC/tax code columns. Because of that, implementation must not blindly infer every tax code from the rate only unless the business confirms the mapping.

Recommended first version:

- Add an ATC mapping rule table or config.
- Default suggested mappings:
  - 1% => `WC158`
  - 2% => `WC160`
  - 5% => require user selection or supplier mapping
  - 10% => require user selection or supplier mapping
  - 15% => require user selection or supplier mapping
- Allow payee-level override so individual payees can use `WI...` codes while company payees use `WC...` codes.

## Proposed Data Model

Create a dedicated table so Expanded does not mix with VAT Purchase/Sales records.

Table: `expanded_wtax_entries`

Suggested columns:

- `id`
- `reporting_period` date, saved as month-end
- `transaction_date` date
- `source_no` string, from Excel `No`
- `reference_no` string, from Excel `Reference`
- `payee_name` string
- `payee_type` enum/string: `company`, `individual`
- `payee_tin` string, formatted or raw normalized
- `payee_branch_code` string default `0000`
- `company_name` string nullable
- `last_name` string nullable
- `first_name` string nullable
- `middle_name` string nullable
- `atc_code` string
- `tax_rate` decimal
- `income_payment` decimal
- `tax_withheld` decimal
- `source_row` integer nullable
- `created_at`
- `updated_at`

Indexes:

- `reporting_period`
- `payee_tin`
- `atc_code`
- unique duplicate guard if appropriate:
  - `reporting_period + source_no + reference_no + payee_tin + atc_code + tax_rate`

## Backend Implementation

### 1. Model

Create:

- `app/Models/ExpandedWtaxEntry.php`

Responsibilities:

- Cast dates and decimals.
- Add `toBirExpandedRow()` method returning the generator-ready array.
- Keep BIR field shaping close to the model like existing VAT/Input models.

### 2. Migration

Create migration for `expanded_wtax_entries`.

Use decimal precision similar to existing money fields:

- `decimal('income_payment', 15, 2)`
- `decimal('tax_withheld', 15, 2)`
- `decimal('tax_rate', 5, 2)`

### 3. Importer

Create:

- `app/Imports/ExpandedWtaxImport.php`

Pattern:

- Implement `OnEachRow`, `WithHeadingRow`, `SkipsEmptyRows`.
- `headingRow()` returns `3`.
- Constructor accepts `reportingPeriod`.
- Read rate columns from headers:
  - `(1%)`
  - `(2%)`
  - `(5%)`
  - `(10%)`
  - `(15%)`
- For each Excel row, create one database entry per non-zero rate column.
- Skip rows with empty supplier/payee name and empty TIN.
- Skip total/subtotal rows.
- Normalize TIN into:
  - first 9 digits for DAT payee TIN
  - last 3 digits as branch, or `0000` when missing
- Normalize BIR text:
  - uppercase
  - `&` to `AND`
  - remove commas
  - collapse spaces
  - reject/clean unsupported punctuation

Handling ATC:

- Resolve ATC from a config or payee mapping.
- If ATC cannot be resolved, store the row as invalid or reject the upload with a helpful error.
- Do not silently pick `WC100`, `WI010`, `WC139`, or `WI516` from rate alone.

### 4. Validator

Create:

- `app/Services/BIR/BirExpandedWtaxRowValidator.php`

Validation rules:

- Payee TIN must have at least 9 digits and cannot be `000000000`.
- Branch code must be present; default `0000`.
- Company entry requires `company_name`.
- Individual entry requires `last_name`, `first_name`, and preferably `middle_name`.
- ATC code is required and must match allowed codes.
- Tax rate must be numeric and greater than 0.
- Income payment must be numeric.
- Tax withheld must be numeric.
- Tax withheld must equal `income_payment * rate / 100` within 0.01 tolerance.
- BIR text fields must not contain comma or ampersand.

### 5. DAT Generator

Create:

- `app/Services/BIR/ReliefExpandedWtaxDatGenerator.php`

Methods:

- `generate(array $company, Collection $transactions, Carbon $period): string`
- `filename(array $company, Carbon $period): string`

Output rules:

- Header:
  - `H1604E,{tin},{branch},{m/d/Y}`
- Detail:
  - `D3,1604E,{company_tin},{company_branch},{m/d/Y},{sequence},{payee_tin},{payee_branch},{company_name},{last_name},{first_name},{middle_name},{atc_code},{income_payment},{tax_rate},{tax_withheld}`
- Trailer:
  - `C3,1604E,{company_tin},{company_branch},{m/d/Y},{total_tax_withheld}`
- Use CRLF line endings.
- Add trailing CRLF.
- Preserve CSV quoting behavior for names.
- Format amounts with two decimals.
- Sequence details from 1 in final generation order.

Suggested filename:

- `{$companyTin}{$companyBranch}{$period->format('mdY')}1604E.dat`

For Fortress sample:

- `0087919760000123120251604E.dat`

### 6. Controller Changes

Update:

- `app/Http/Controllers/VatInputController.php`

Upload:

- Allow `record_type = expanded`.
- Route expanded uploads to `ExpandedWtaxImport`.
- Success message: `Expanded withholding tax report successfully imported!`

Update:

- `app/Http/Controllers/DatFileController.php`

Index:

- Allow `record_type = expanded`.
- Add `expandedPeriods(BirExpandedWtaxRowValidator $validator)`.

Download:

- Allow `record_type = expanded`.
- Inject `ReliefExpandedWtaxDatGenerator`.
- Add `downloadExpanded(...)`.

Query:

- Load `ExpandedWtaxEntry` rows for the selected reporting month.
- Validate all rows before generation.
- Generate and return DAT with `text/plain`.

## Frontend Implementation

### 1. Upload Screen

Update:

- `resources/js/Pages/RecordEntry.jsx`

Changes:

- Add `Expanded WTAX` option to File Type selector.
- Keep explicit file type selection; do not infer type from Excel shape.
- Adjust table title/state to show expanded entries when selected.
- Add paginated expanded rows to the page props.
- Display columns:
  - Payee
  - TIN
  - ATC
  - Rate
  - Income Payment
  - Tax Withheld
  - Reporting Month
  - Reference

### 2. Generate DAT Screen

Update:

- `resources/js/Pages/GenerateDatFile.jsx`

Changes:

- Add `expanded` to `DAT_TYPES`.
- Add selector option: `Expanded WTAX`.
- Show available months and validation issues like purchase/sales/importation.
- Download using `record_type=expanded`.

## Routes

Existing routes can stay the same if we extend current controllers:

- `POST /vat-import`
- `GET /generate-datfile`
- `GET /download-datfile`

No new route is required for the first version.

Optional later route:

- `PUT /expanded-wtax/{entry}/bir-info`

Use this only if users need to fix payee type/name/ATC after upload.

## Config / Mapping

Create:

- `config/bir.php` addition, or a new `config/expanded_wtax.php`

Suggested structure:

```php
'expanded_wtax' => [
    'allowed_atc_codes' => [
        'WC158' => ['rate' => 1.00, 'payee_type' => 'company'],
        'WC160' => ['rate' => 2.00, 'payee_type' => 'company'],
        'WC100' => ['rate' => 5.00, 'payee_type' => 'company'],
        'WI010' => ['rate' => 5.00, 'payee_type' => 'individual'],
        'WC139' => ['rate' => 10.00, 'payee_type' => 'company'],
        'WI516' => ['rate' => 10.00, 'payee_type' => 'individual'],
    ],
    'default_rate_codes' => [
        '1.00' => 'WC158',
        '2.00' => 'WC160',
    ],
],
```

Add a payee/supplier override table later if the rate-only default is not enough.

## Tests

Add focused tests:

- `tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php`
- `tests/Unit/BirExpandedWtaxRowValidatorTest.php`
- `tests/Feature/ExpandedWtaxImportTest.php`
- `tests/Feature/ExpandedWtaxDatFileTest.php`

Test cases:

- Generated DAT matches `Docs/Expanded/0087919760000123120251604E.dat` when using rows parsed from the sample file.
- Header, detail, and trailer field counts are fixed.
- Filename matches `0087919760000123120251604E.dat`.
- Trailer total equals the sum of detail tax withheld.
- Excel import creates one row per non-zero rate column.
- Income payment is computed from withholding amount and rate.
- Invalid/missing ATC mapping blocks DAT generation.
- BIR-safe text removes commas and converts `&` to `AND`.
- TIN parsing splits 9-digit TIN and branch code correctly.

Run focused checks:

```bash
php artisan test tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php
php artisan test tests/Unit/BirExpandedWtaxRowValidatorTest.php
php artisan test tests/Feature/ExpandedWtaxImportTest.php
php artisan test tests/Feature/ExpandedWtaxDatFileTest.php
```

Do not treat unrelated baseline auth route failures as Expanded WTAX failures.

## Implementation Order

1. Create migration and `ExpandedWtaxEntry` model.
2. Build `ReliefExpandedWtaxDatGenerator` from the sample DAT format.
3. Build `BirExpandedWtaxRowValidator`.
4. Add generator unit tests against the sample DAT.
5. Build `ExpandedWtaxImport` for the Excel structure.
6. Extend `VatInputController::import()` with `record_type=expanded`.
7. Extend `DatFileController` period listing and download flow.
8. Update `RecordEntry.jsx` upload selector/table.
9. Update `GenerateDatFile.jsx` DAT type selector.
10. Add feature tests for upload and download.
11. Run focused test suite.

## Notes

- Keep Expanded WTAX storage separate from VAT Purchases, VAT Sales, and Importation.
- Keep explicit `record_type` in upload and DAT generation.
- Do not infer Expanded upload automatically from file shape.
- Do not merge Expanded WTAX with VAT input totals.
- The main unresolved decision is ATC mapping for 5%, 10%, and 15% rows because the Excel has only rate columns while the DAT needs exact tax codes.

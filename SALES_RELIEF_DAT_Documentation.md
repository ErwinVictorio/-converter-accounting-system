# BIR RELIEF Sales DAT Documentation

## Purpose

This document explains the observed Sales `.DAT` format from the sample file:

```text
Sales/008791976S052026.DAT
```

and the source Excel file:

```text
Sales/SALES SUMMARY BY DOCUMENT NUMBER.xlsx
```

The current target is:

```text
RELIEF Sales
Transaction Type: S
```

Example filename:

```text
008791976S052026.DAT
```

Meaning:

```text
008791976 = Taxpayer / company TIN
S         = Sales
05        = Reporting month
2026      = Reporting year
```

---

## Confirmed DAT Structure

The sample Sales DAT contains:

```text
Header rows: 1
Detail rows: 132
Total rows: 133
Header field count: 17
Detail field count: 15
Transaction marker: S
Reporting date format: MM/DD/YYYY
Observed reporting date: 05/31/2026
```

The file structure is:

```text
H,S,...
D,S,...
D,S,...
```

The header totals match the sum of all detail rows:

```text
Exempt Sales:       0.00
Zero-rated Sales:   0.00
Taxable Sales:      122,661,477.24
Output VAT:         14,719,377.32
```

---

## Header Record Format

Observed sample:

```text
H,S,"008791976","FORTRESS STEEL INC.","","","","FORTRESS STEEL INC.","LOT 433 J.P RIZAL NANGKA"," MARIKINA 1808",0.00,0.00,122661477.24,14719377.32,045,05/31/2026,12
```

Header fields:

| Position | Field | Sample Value | Notes |
| --- | --- | --- | --- |
| 1 | Record Type | H | Header record |
| 2 | Transaction Type | S | Sales |
| 3 | Taxpayer TIN | 008791976 | Company TIN, 9 digits |
| 4 | Registered Name | FORTRESS STEEL INC. | Company registered name |
| 5 | Last Name | blank | Used only if individual taxpayer |
| 6 | First Name | blank | Used only if individual taxpayer |
| 7 | Middle Name | blank | Used only if individual taxpayer |
| 8 | Trade Name | FORTRESS STEEL INC. | Company trade/business name |
| 9 | Address 1 | LOT 433 J.P RIZAL NANGKA | Registered address line 1 |
| 10 | Address 2 | MARIKINA 1808 | Registered address line 2 |
| 11 | Total Exempt Sales | 0.00 | Sum from detail field 10 |
| 12 | Total Zero-rated Sales | 0.00 | Sum from detail field 11 |
| 13 | Total Taxable Sales | 122661477.24 | Sum from detail field 12 |
| 14 | Total Output VAT | 14719377.32 | Sum from detail field 13 |
| 15 | RDO Code | 045 | Taxpayer/company RDO |
| 16 | Reporting Date | 05/31/2026 | Month-end date |
| 17 | VAT Rate | 12 | VAT rate percentage |

---

## Detail Record Format

Observed company customer sample:

```text
D,S,"743020291","1HB CONSTRUCTION CORP.",,,,,,0,0,106910.71,12829.29,008791976,05/31/2026
```

Observed individual customer sample:

```text
D,S,"166416216",,"LARGA","RAMON","HIZOLE","AMOINGON BOAC","MARINDUQUE",0,0,481600.00,57792.00,008791976,05/31/2026
```

Detail fields:

| Position | Field | Sample Value | Notes |
| --- | --- | --- | --- |
| 1 | Record Type | D | Detail record |
| 2 | Transaction Type | S | Sales |
| 3 | Customer TIN | 743020291 | Customer TIN, 9 digits |
| 4 | Customer Registered Name | 1HB CONSTRUCTION CORP. | Used for company customer |
| 5 | Customer Last Name | LARGA | Used for individual customer |
| 6 | Customer First Name | RAMON | Used for individual customer |
| 7 | Customer Middle Name | HIZOLE | Used for individual customer |
| 8 | Address 1 | AMOINGON BOAC | Customer address line 1 |
| 9 | Address 2 | MARINDUQUE | Customer address line 2 |
| 10 | Exempt Sales | 0 | Sales amount exempt from VAT |
| 11 | Zero-rated Sales | 0 | Sales amount zero-rated |
| 12 | Taxable Sales | 106910.71 | Net of VAT / taxable base |
| 13 | Output VAT | 12829.29 | 12% output VAT |
| 14 | Seller TIN | 008791976 | Taxpayer/company TIN |
| 15 | Reporting Date | 05/31/2026 | Month-end date |

---

## Excel Source Columns

The internal Sales Excel file has one sheet:

```text
Sheet1
```

Title rows:

```text
Row 1: SALES SUMMARY BY DOCUMENT NUMBER
Row 2: Period Covered: May 1, 2026 - May 30, 2026
Row 3: Column headers
```

Excel columns:

| Column | Header | DAT Usage |
| --- | --- | --- |
| A | Document No | Source invoice/document reference only; not present in DAT |
| B | Date | Source transaction date; DAT uses month-end reporting date instead |
| C | Terms | Not present in DAT |
| D | Days | Not present in DAT |
| E | Due Date | Not present in DAT |
| F | Agent | Not present in DAT |
| G | Customer Name | Used to group sales and match customer master/BIR info |
| H | SO/DR/SI | Source document references only; not present in DAT |
| I | Gross Amount | Source gross amount; not directly written to DAT |
| J | Discount | Not directly written to DAT |
| K | Charges | Not directly written to DAT |
| L | Net Amount | Source net amount; not directly written to DAT |
| M | VAT | Maps to detail Output VAT |
| N | Net of VAT | Maps to detail Taxable Sales |

Important: the Excel file does not contain customer TIN, address, or individual name split fields. Those DAT fields must come from a customer master table or manual BIR information setup.

---

## BIR Uploader Sales Excel Columns

The BIR Excel uploader Sales template uses an `R_Sales` sheet and already matches the DAT fields more closely.

Expected columns:

| Column | Header | Sales Table Field | DAT Usage |
| --- | --- | --- | --- |
| A | client_TIN | customer_tin | Detail field 3 |
| B | companyName | company_name, customer_name | Detail field 4 for company |
| C | lastName | last_name, customer_name fallback | Detail field 5 for individual |
| D | firstName | first_name | Detail field 6 for individual |
| E | middleName | middle_name | Detail field 7 for individual |
| F | address1 | address1 | Detail field 8 |
| G | address2 | address2 | Detail field 9 |
| H | exempt | exempt_sales | Detail field 10 |
| I | zeroRated | zero_rated_sales | Detail field 11 |
| J | taxableNetOfVat | taxable_net_of_vat | Detail field 12 |
| K | vatRate | not stored separately yet | Expected 12.00 |
| L | outputVat | output_vat | Detail field 13 |
| M | totalSales | net_amount | Supporting total |
| N | grossTaxable | gross_amount | Supporting gross taxable amount |

Important BIR uploader rules from the template:

```text
All number data should be Number format, not Comma format.
All number data should be rounded to 2 decimal places only.
```

For upload, rows with missing `client_TIN` are still imported when they have a customer name and amount. They must be fixed before final DAT generation because the DAT detail row requires a valid 9-digit customer TIN.

---

## Observed Excel Totals

From the Excel source:

```text
Invoice rows:       588
Unique customers:   203
Gross Amount:       140,947,468.66
Net Amount:         140,530,735.44
VAT:                14,983,937.91
Net of VAT:         125,546,797.53
```

Rows with VAT greater than zero:

```text
Rows:               575
Unique customers:   201
VAT:                14,983,937.91
Net of VAT:         124,866,149.53
```

The sample DAT totals are lower:

```text
Taxable Sales:      122,661,477.24
Output VAT:         14,719,377.32
```

This means the sample DAT is not a direct export of every Excel row as-is. It appears to be a grouped and filtered output where only customers with usable BIR/customer master information are included.

---

## Expected Transformation Flow

Recommended Sales DAT generation flow:

```text
Upload Sales Excel
    ->
Read rows from Sheet1, starting after the header row
    ->
Keep valid sales invoice rows
    ->
Normalize customer names
    ->
Group rows by matched customer / TIN
    ->
Sum Net of VAT into Taxable Sales
    ->
Sum VAT into Output VAT
    ->
Load customer BIR info: TIN, company/individual type, address
    ->
Validate all required DAT fields
    ->
Generate one H,S header row
    ->
Generate one D,S row per grouped customer/TIN
    ->
Write CRLF-delimited DAT content
    ->
Download filename {TIN}S{MM}{YYYY}.DAT
```

---

## Grouping Rules

The Excel source is by document number, but the DAT output is by customer/TIN.

Observed counts:

```text
Excel invoice rows:     588
Excel unique customers: 203
DAT detail rows:        132
```

Recommended grouping key:

```text
Customer TIN + reporting month
```

Fallback matching can start from normalized customer name, but the final DAT row should be keyed by customer TIN because the DAT detail requires the TIN.

---

## Validation Rules

Before generating the Sales DAT:

```text
Customer TIN must be exactly 9 digits.
Customer TIN must not be 000000000 unless intentionally configured for a valid BIR-accepted summary customer such as END USER.
Company customers should use field 4 and leave fields 5-7 blank.
Individual customers should leave field 4 blank and use fields 5-7.
Customer address fields 8 and 9 are required when available.
Commas should not be kept inside text values because commas are DAT separators.
Ampersands should be normalized to AND.
Unsupported punctuation should be removed or normalized.
Amounts should be numeric and formatted with 2 decimals for header totals.
The header totals must equal the sum of all detail amount fields.
The reporting date should be the month-end date.
```

---

## Sales DAT Column Mapping Summary

| DAT Record | DAT Field | Source |
| --- | --- | --- |
| H | Taxpayer TIN | Company/BIR settings |
| H | Taxpayer names/address/RDO | Company/BIR settings |
| H | Total Exempt Sales | Sum of D field 10 |
| H | Total Zero-rated Sales | Sum of D field 11 |
| H | Total Taxable Sales | Sum of D field 12 |
| H | Total Output VAT | Sum of D field 13 |
| H | Reporting Date | Selected month-end |
| D | Customer TIN | Customer master/BIR info |
| D | Customer company or individual name | Customer master/BIR info |
| D | Customer address | Customer master/BIR info |
| D | Exempt Sales | Computed/imported sales classification |
| D | Zero-rated Sales | Computed/imported sales classification |
| D | Taxable Sales | Excel column N, grouped |
| D | Output VAT | Excel column M, grouped |
| D | Seller TIN | Company/BIR settings |
| D | Reporting Date | Selected month-end |

---

## Notes For Implementation

The Sales import cannot rely on the Excel file alone because the Excel file lacks required DAT fields:

```text
Customer TIN
Customer address
Customer type: company or individual
Individual last/first/middle name split
```

Those fields should be maintained in a Sales customer/BIR info table or reused from an existing customer master table if one exists.

The current sample DAT should be treated as the format reference for Sales:

```text
H,S has 17 fields.
D,S has 15 fields.
Header totals must balance against detail totals.
```

---

## Current Implementation Status

Implemented Sales upload/generation pieces:

```text
resources/js/Pages/RecordEntry.jsx
app/Http/Controllers/VatInputController.php
app/Imports/SalesVatInputImport.php
app/Models/SalesVatInput.php
database/migrations/2026_08_18_055337_create_sales_vatsinputs_table.php
database/migrations/2026_08_18_070000_add_upload_fields_to_sales_vatsinputs_table.php
```

Implemented customer master pieces:

```text
resources/js/Pages/ManageCustomer.jsx
app/Http/Controllers/CustomerController.php
app/Models/Customer.php
database/migrations/2026_08_18_073614_create_customers_table.php
database/migrations/2026_08_18_080000_add_matching_fields_to_customers_table.php
database/seeders/CustomerSeeder.php
Db/customers.sql
```

Implemented Sales DAT pieces:

```text
resources/js/Pages/GenerateDatFile.jsx
app/Http/Controllers/DatFileController.php
app/Services/BIR/ReliefSalesDatGenerator.php
app/Services/BIR/BirSalesRowValidator.php
tests/Unit/ReliefSalesDatGeneratorTest.php
```

Purchase behavior was not changed at the domain level:

```text
app/Imports/VatInputImport.php was not modified.
app/Services/BIR/ReliefPurchaseDatGenerator.php was not modified.
app/Services/BIR/BirPurchaseRowValidator.php was not modified.
tests/Unit/ReliefPurchaseDatGeneratorTest.php was not modified.
```

Only shared entry points were updated with `record_type` branching:

```text
Upload:
record_type = purchase -> VatInputImport -> vat_inputs
record_type = sales    -> SalesVatInputImport -> sales_vatsinputs

Generate DAT:
record_type = purchase -> vat_inputs -> ReliefPurchaseDatGenerator -> H,P / {TIN}P{MM}{YYYY}.DAT
record_type = sales    -> sales_vatsinputs -> ReliefSalesDatGenerator -> H,S / {TIN}S{MM}{YYYY}.DAT
```

Verification already performed:

```text
php artisan migrate
php artisan db:seed --class=CustomerSeeder
Customer seed loaded 10,853 customers from Db/customers.sql.
Existing sales rows synced: 418 sales rows matched customer TIN/address/city.
Sales period count: May 2026 = 635 rows.
Purchase period count: May 2026 = 48 rows.
/generate-datfile?record_type=sales returned HTTP 200.
Rollback-only valid Sales DAT download returned filename 008791976S062026.DAT and header H,S.
php artisan test tests\Unit\ReliefSalesDatGeneratorTest.php tests\Unit\ReliefPurchaseDatGeneratorTest.php -> 10 passed, 15 assertions.
```

Sales table display behavior:

```text
Uploaded Sales rows remain stored as invoice-level rows in sales_vatsinputs.
The Record Entry Sales table groups matching customer rows for easier review.
Grouped display sums exempt_sales, zero_rated_sales, taxable_net_of_vat, output_vat, net_amount, and gross_amount.
The grouped table also shows records_count so the user can see how many rows were combined.
Sales DAT generation also groups rows before creating D,S detail records.
```

Sales import format detection:

```text
SalesVatInputImport supports two Sales upload formats: internal Sales Summary and BIR R_Sales.
The importer now detects the active format from the header row.
Document No header -> Sales Summary format, customer name comes from column G.
client_TIN header -> BIR R_Sales format, customer name comes from companyName or last/first/middle name.
This prevents Sales Summary rows with blank document numbers from being misread as BIR rows.
Previously, 22 rows were misread with customer_name set to dates like 05/04/2026 and the real customer in address2.
Those 22 corrupted rows were removed and the May 2026 Sales Summary was re-imported; remaining date-as-customer rows: 0.
```

Frontend build remains unverified because `npm` is unavailable in the current PowerShell environment.

---

## Implementation Plan

This plan keeps Sales and Purchases in separate database tables and separate domain classes. The shared part is only the user flow: the upload screen and the DAT generation screen both ask the user to select `Sales` or `Purchase`.

### 1. Database

Continue the new migration:

```text
database/migrations/2026_08_18_055337_create_sales_vatsinputs_table.php
```

Target table:

```text
sales_vatsinputs
```

The table should store the Excel source fields and the BIR-ready fields needed for DAT generation.

Recommended columns:

| Column | Purpose |
| --- | --- |
| id | Primary key |
| document_no | Excel column A |
| document_date | Excel column B |
| terms | Excel column C |
| days | Excel column D |
| due_date | Excel column E |
| agent_name | Excel column F |
| customer_name | Excel column G |
| document_refs | Excel column H, SO/DR/SI |
| gross_amount | Excel column I |
| discount | Excel column J |
| charges | Excel column K |
| net_amount | Excel column L |
| output_vat | Excel column M |
| taxable_net_of_vat | Excel column N |
| customer_tin | BIR DAT customer TIN |
| customer_type | company or individual |
| company_name | DAT detail field 4 |
| last_name | DAT detail field 5 |
| first_name | DAT detail field 6 |
| middle_name | DAT detail field 7 |
| address1 | DAT detail field 8 |
| address2 | DAT detail field 9 |
| exempt_sales | DAT detail field 10 |
| zero_rated_sales | DAT detail field 11 |
| reporting_period | Month-end date used in DAT |
| is_adjusted | Optional future adjustment marker |
| timestamps | Laravel timestamps |

Reason for a separate table:

```text
Purchases use supplier/vendor fields and purchase categories.
Sales use customer fields and sales categories.
Putting both in vat_inputs would make the table and import logic too wide and confusing.
```

### 2. Model

Create a dedicated model:

```text
app/Models/SalesVatInput.php
```

Responsibilities:

```text
Use table sales_vatsinputs.
Cast document_date, due_date, and reporting_period as dates.
Cast amount fields as decimal values.
Expose a toBirSalesRow() method for DAT generation.
```

### 3. Upload UI

Update:

```text
resources/js/Pages/RecordEntry.jsx
```

Add a required selector beside `Reporting Month`:

```text
Type: Purchase / Sales
```

Upload behavior:

| Selected Type | Import Target |
| --- | --- |
| Purchase | Existing `vat_inputs` table through existing `VatInputImport` |
| Sales | New `sales_vatsinputs` table through new `SalesVatInputImport` |

The form should submit:

```text
excel_file
reporting_month
record_type = purchase or sales
```

The list/table shown below the upload card can stay as Purchase records first. Sales listing can be added after the Sales import and DAT generation are stable.

### 4. Upload Controller Routing

Update:

```text
app/Http/Controllers/VatInputController.php
```

The current `import()` method should validate `record_type`.

Routing decision:

```text
record_type = purchase -> Excel::import(new VatInputImport($reportingPeriod), ...)
record_type = sales    -> Excel::import(new SalesVatInputImport($reportingPeriod), ...)
```

Recommended validation:

```text
excel_file required xlsx/xls/csv
reporting_month required date
record_type required in:purchase,sales
```

### 5. Sales Excel Import

Create:

```text
app/Imports/SalesVatInputImport.php
```

Expected Sales sheet structure:

```text
Sheet name: Sheet1
Heading row: 3
Data starts: row 4
```

Import mapping:

| Excel Header | Sales Column |
| --- | --- |
| Document No | document_no |
| Date | document_date |
| Terms | terms |
| Days | days |
| Due Date | due_date |
| Agent | agent_name |
| Customer Name | customer_name |
| SO/DR/SI | document_refs |
| Gross Amount | gross_amount |
| Discount | discount |
| Charges | charges |
| Net Amount | net_amount |
| VAT | output_vat |
| Net of VAT | taxable_net_of_vat |

Import rules:

```text
Skip blank rows.
Skip TOTAL, GRAND TOTAL, and SUBTOTAL rows.
Normalize customer name for matching.
Store invoice-level rows first so the upload matches the Excel source.
Use reporting_period as the selected month-end date, not the invoice date.
```

### 6. Customer BIR Info

Sales DAT needs data not present in the Excel file:

```text
Customer TIN
Customer type
Company name or individual name split
Address 1
Address 2
```

Implementation options:

```text
Option A: Keep those fields directly on sales_vatsinputs, editable per imported row/group.
Option B: Add a customer BIR info table and use it to auto-fill sales_vatsinputs during import.
```

Preferred first implementation:

```text
Use fields directly on sales_vatsinputs first.
Add a Sales BIR Info edit modal similar to the Purchase BIR Info modal.
When saving BIR info for a customer, update matching sales rows for the same reporting period and normalized customer name.
```

### 7. Generate DAT UI

Update:

```text
resources/js/Pages/GenerateDatFile.jsx
```

Add a required selector:

```text
Type: Purchase / Sales
```

Generate behavior:

| Selected Type | Data Source | DAT Format |
| --- | --- | --- |
| Purchase | vat_inputs | H,P and D,P |
| Sales | sales_vatsinputs | H,S and D,S |

The screen should load available months based on selected type:

```text
purchase -> available periods from vat_inputs.date_uploaded/reporting date
sales    -> available periods from sales_vatsinputs.reporting_period
```

Download request should include:

```text
period
record_type = purchase or sales
```

### 8. DAT Controller

Update or split:

```text
app/Http/Controllers/DatFileController.php
```

Recommended approach:

```text
Keep one page route for the Generate DAT screen.
Use record_type to choose the correct period list, validator, generator, and model.
Keep purchase logic untouched except for the selector branch.
```

Download decision:

```text
record_type = purchase -> ReliefPurchaseDatGenerator
record_type = sales    -> ReliefSalesDatGenerator
```

### 9. Sales DAT Generator

Create:

```text
app/Services/BIR/ReliefSalesDatGenerator.php
```

Responsibilities:

```text
Generate one H,S header record with 17 fields.
Generate one D,S detail record with 15 fields per grouped customer/TIN.
Use CRLF line endings.
Use month-end date in MM/DD/YYYY format.
Use filename {TIN}S{MM}{YYYY}.DAT.
Make header totals equal detail totals.
```

Sales grouping:

```text
Group by customer_tin + reporting_period.
If customer_tin is missing, group by normalized customer_name only for preview/error reporting, not for final valid DAT.
Sum taxable_net_of_vat into Taxable Sales.
Sum output_vat into Output VAT.
Default exempt_sales and zero_rated_sales to 0 unless the source/import later supports those classifications.
```

### 10. Sales DAT Validator

Create:

```text
app/Services/BIR/BirSalesRowValidator.php
```

Validation rules:

```text
TIN must be 9 digits.
Customer type must be company or individual.
Company customers require company_name.
Individual customers require last_name, first_name, and middle_name.
Address fields should be present before download.
Text fields must not contain commas.
Taxable sales and output VAT must be numeric and non-negative.
Header totals must match detail totals.
Generated detail rows must have exactly 15 fields.
Generated header row must have exactly 17 fields.
```

### 11. Tests

Add focused tests instead of relying on the full app suite:

```text
tests/Unit/ReliefSalesDatGeneratorTest.php
tests/Unit/BirSalesRowValidatorTest.php
tests/Feature/SalesVatInputImportTest.php
```

Test cases:

```text
Generates filename 008791976S052026.DAT.
Generates H,S with 17 fields.
Generates D,S with 15 fields.
Uses CRLF line endings.
Balances header totals against detail sums.
Groups multiple invoices under the same customer/TIN.
Blocks DAT download when required BIR customer info is missing.
```

### 12. Execution Order

Recommended build order:

```text
1. Finalize sales_vatsinputs migration.
2. Add SalesVatInput model.
3. Add SalesVatInputImport.
4. Add record_type selector to upload UI.
5. Branch VatInputController import logic by record_type.
6. Add Sales BIR info edit/save flow.
7. Add ReliefSalesDatGenerator.
8. Add BirSalesRowValidator.
9. Add record_type selector to Generate DAT UI.
10. Branch DatFileController period list and download logic.
11. Add focused tests for Sales import and Sales DAT generation.
12. Compare generated May 2026 DAT against Sales/008791976S052026.DAT.
```

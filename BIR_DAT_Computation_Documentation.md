# BIR RELIEF Purchases DAT Documentation

## Purpose

This document explains how this Laravel system generates a BIR RELIEF Purchases `.DAT` file from the `vat_inputs` table.

The current implementation is for:

```text
RELIEF Purchases
Transaction Type: P
```

The known-valid DAT reference structure is:

```text
H,P,...
D,P,...
D,P,...
```

Rules confirmed from the sample DAT:

```text
Header record count: 1
Header field count: 21
Detail field count: 17
Line ending: CRLF
Reporting date format: MM/DD/YYYY
Filename format: {TIN}P{MM}{YYYY}.DAT
```

Example filename:

```text
008791976P042026.DAT
```

Meaning:

```text
008791976 = Taxpayer / company TIN
P         = Purchases
04        = Reporting month
2026      = Reporting year
```

---

## Important Principle

The DAT detail rows are generated from:

```text
vat_inputs
```

The `suppliers` table and manual BIR Info form are used only to complete or correct the data saved in `vat_inputs`.

Final DAT generation should not read supplier rows directly as transaction rows.

---

## Meaning of Important Terms

## TIN

TIN means:

```text
Taxpayer Identification Number
```

For RELIEF DAT detail rows, the vendor TIN must be:

```text
Exactly 9 digits
Numbers only
Cannot be 000000000
```

Examples:

```text
Valid:   159039012
Invalid: 159-039-012-000
Invalid: 000000000
Invalid: ABC123456
```

The system strips dashes and branch code from uploaded TIN values.

Example:

```text
159-039-012-000
```

becomes:

```text
159039012
```

## RDO

RDO means:

```text
Revenue District Office
```

The RDO code identifies the BIR district where the taxpayer/company is registered.

In the DAT header, the RDO code belongs to the taxpayer/company generating the file, not to each supplier.

Example from the reference DAT:

```text
045
```

Important:

```text
RDO is a 3-digit code.
```

The system validates:

```text
Required
Exactly 3 digits
```

## Reporting Period

The DAT uses the last day of the selected reporting month.

Example:

```text
Selected month: April 2026
DAT period:     04/30/2026
```

Formula:

```php
$period = Carbon::parse($selectedMonth)->endOfMonth();
$formattedPeriod = $period->format('m/d/Y');
```

Do not use:

```text
2026-04-30
```

Use:

```text
04/30/2026
```

---

## Source Tables

## vat_inputs

This is the source of the DAT detail rows.

Important columns:

```text
supplier_name
tin_number
vendor_type
company_name
last_name
first_name
middle_name
address1
address2
exempt
zero_rated
purchase_imported
purchase_local
services
capital_goods
other_than_capital_goods
taxable_net_of_vat
vat_rate
input_vat
total_purchases
others
date_uploaded
```

## suppliers

This table is used to auto-complete vendor information during import.

Important columns:

```text
name
payee
addr
tin
```

Mapping during import:

```text
suppliers.tin   -> vat_inputs.tin_number
suppliers.payee -> vat_inputs.company_name
suppliers.name  -> fallback company name
suppliers.addr  -> vat_inputs.address1
```

After import, the DAT uses the completed `vat_inputs` row.

---

## Uploaded Excel Source

The uploaded Excel file from the company system is not the BIR template. It is the VAT input report.

Expected header row:

```text
Row 3
```

Expected columns:

```text
No
Date
Supplier Name
TIN
Reference
PurchaseImported
PurchaseLocal
Services
Others
TOTAL
```

Example:

```text
Supplier Name: ORION METAL MANUFACTURING & TRADING
TIN:           159-039-012-000
PurchaseLocal: 19,371.43
TOTAL:         19,371.43
```

The importer reads the Excel transaction amounts, then enriches vendor identity from the `suppliers` table when possible.

---

## Vendor Type

Each `vat_inputs` row has:

```text
vendor_type
```

Allowed values:

```text
company
individual
```

## Company Vendor

Required:

```text
company_name
tin_number
```

DAT detail fields:

```text
Company Name = filled
Last Name    = blank
First Name   = blank
Middle Name  = blank
```

Example DAT:

```text
D,P,"159039012","ORION METAL MANUFACTURING & TRADING",,,,...
```

## Individual Vendor

Required:

```text
last_name
first_name
middle_name
tin_number
```

DAT detail fields:

```text
Company Name = blank
Last Name    = filled
First Name   = filled
Middle Name  = filled
```

Example DAT shape:

```text
D,P,"008566545",,"RIVERA","MARY GRACE","A",...
```

---

## DAT Header Record

Header starts with:

```text
H,P
```

Reference header:

```text
H,P,"008791976","FORTRESS STEEL INC.","","","","FORTRESS STEEL INC.","LOT 433 J.P RIZAL NANGKA"," MARIKINA 1808",0.00,0.00,1007512.17,0.00,8038050.69,1085467.54,1085467.54,0.00,045,04/30/2026,12
```

Header field count:

```text
21
```

## Header Field Map

| Position | Field | Source |
|---:|---|---|
| 1 | Record Type | Fixed `H` |
| 2 | Transaction Type | Fixed `P` |
| 3 | Taxpayer TIN | Generate DAT form |
| 4 | Taxpayer / Company Name | Generate DAT form |
| 5 | Last Name | Blank for company |
| 6 | First Name | Blank for company |
| 7 | Middle Name | Blank for company |
| 8 | Registered Name | Generate DAT form |
| 9 | Address 1 | Generate DAT form |
| 10 | Address 2 | Generate DAT form |
| 11 | Total Exempt | Sum of `vat_inputs.exempt` |
| 12 | Total Zero-Rated | Sum of `vat_inputs.zero_rated` |
| 13 | Total Services | Sum of `vat_inputs.services` |
| 14 | Total Capital Goods | Sum of `vat_inputs.capital_goods` |
| 15 | Total Other Than Capital Goods | Sum of `vat_inputs.other_than_capital_goods` |
| 16 | Total Input VAT | Sum of `vat_inputs.input_vat` |
| 17 | Creditable Input VAT | Total Input VAT - Non-Creditable Input VAT |
| 18 | Non-Creditable Input VAT | Generate DAT form |
| 19 | RDO Code | Generate DAT form |
| 20 | Reporting Period | Last day of selected month |
| 21 | Final Header Field | Fixed `12` in current sample |

---

## DAT Detail Record

Detail starts with:

```text
D,P
```

Reference detail:

```text
D,P,"236791864","A ZINC INDUSTRIAL GALVANIZING PHILIPPINES",,,,"668 B 6TH ST 7TH AVE","CALOOCAN CITY",0,0,17697.17,0,0,2123.66,008791976,04/30/2026
```

Detail field count:

```text
17
```

## Detail Field Map

| Position | Field | Source |
|---:|---|---|
| 1 | Record Type | Fixed `D` |
| 2 | Transaction Type | Fixed `P` |
| 3 | Vendor TIN | `vat_inputs.tin_number` |
| 4 | Company Name | `vat_inputs.company_name` or `supplier_name` |
| 5 | Last Name | `vat_inputs.last_name` |
| 6 | First Name | `vat_inputs.first_name` |
| 7 | Middle Name | `vat_inputs.middle_name` |
| 8 | Address 1 | `vat_inputs.address1` |
| 9 | Address 2 | `vat_inputs.address2` |
| 10 | Exempt | `vat_inputs.exempt` |
| 11 | Zero-Rated | `vat_inputs.zero_rated` |
| 12 | Services | `vat_inputs.services` |
| 13 | Capital Goods | `vat_inputs.capital_goods` |
| 14 | Other Than Capital Goods | `vat_inputs.other_than_capital_goods` |
| 15 | Input VAT | `vat_inputs.input_vat` |
| 16 | Buyer / Taxpayer TIN | Generate DAT form company TIN |
| 17 | Reporting Period | Last day of selected month |

---

## Computation Rules

## Taxable Net of VAT

For the current import flow:

```text
taxable_net_of_vat = capital_goods + other_than_capital_goods + services
```

Fallback from system VAT report:

```text
capital_goods = PurchaseImported
other_than_capital_goods = PurchaseLocal + Others
services = Services
```

Therefore:

```text
taxable_net_of_vat = PurchaseImported + PurchaseLocal + Services + Others
```

Note:

```text
Exempt and zero-rated purchases are separate categories and are not included in taxable VAT computation.
```

## VAT Rate

Current VAT rate used by the system:

```text
12%
```

Stored as:

```text
vat_rate = 12.00
```

## Input VAT

Formula:

```text
input_vat = taxable_net_of_vat × 12%
```

Or:

```text
input_vat = taxable_net_of_vat × (vat_rate / 100)
```

Example:

```text
taxable_net_of_vat = 17,697.17
vat_rate = 12%

input_vat = 17,697.17 × 0.12
input_vat = 2,123.6604
rounded = 2,123.66
```

DAT output:

```text
2123.66
```

## Total Purchases

For display/import reference:

```text
total_purchases = exempt
                + zero_rated
                + services
                + capital_goods
                + other_than_capital_goods
```

For the uploaded VAT report:

```text
total_purchases = PurchaseImported + PurchaseLocal + Services + Others
```

If Excel has `TOTAL`, the importer can store it as `total_purchases`.

## Header Totals

The header totals are computed from all valid detail rows for the selected reporting month.

```text
Header exempt total =
SUM(vat_inputs.exempt)
```

```text
Header zero-rated total =
SUM(vat_inputs.zero_rated)
```

```text
Header services total =
SUM(vat_inputs.services)
```

```text
Header capital goods total =
SUM(vat_inputs.capital_goods)
```

```text
Header other than capital goods total =
SUM(vat_inputs.other_than_capital_goods)
```

```text
Header total input VAT =
SUM(vat_inputs.input_vat)
```

## Creditable and Non-Creditable Input VAT

The Generate DAT form asks for:

```text
Non-Creditable Input VAT
```

Formula:

```text
creditable_input_vat = total_input_vat - non_creditable_input_vat
```

Validation:

```text
non_creditable_input_vat >= 0
non_creditable_input_vat <= total_input_vat
```

Example:

```text
total_input_vat = 1,085,467.54
non_creditable_input_vat = 0.00

creditable_input_vat = 1,085,467.54 - 0.00
creditable_input_vat = 1,085,467.54
```

Header output:

```text
1085467.54,1085467.54,0.00
```

---

## Numeric Formatting Rules

Use decimal point:

```text
.
```

Do not use thousand separators in DAT:

Wrong:

```text
1,357,592.77
```

Correct:

```text
1357592.77
```

Header numbers use two decimals:

```text
0.00
1007512.17
```

Detail zero values use:

```text
0
```

Detail non-zero values use two decimals:

```text
17697.17
2123.66
```

---

## Validation Before Download

The DAT file cannot be generated if any selected `vat_inputs` row has critical errors.

Critical errors include:

```text
Missing vendor TIN
Vendor TIN is not exactly 9 digits
Vendor TIN is 000000000
Company vendor without company name
Individual vendor without last name, first name, or middle name
Invalid numeric values
Non-creditable input VAT greater than total input VAT
No vat_inputs rows for selected reporting month
```

The Generate DAT page displays invalid rows before download.

Fix invalid rows using:

```text
VAT Input Records -> BIR Info
```

---

## Current Application Flow

```text
Upload VAT INPUT REPORT Excel
        ↓
Read Row 3 headers
        ↓
Import transaction amounts into vat_inputs
        ↓
Lookup supplier info from suppliers table
        ↓
Save completed/enriched row into vat_inputs
        ↓
User manually fixes missing/individual info using BIR Info
        ↓
Generate DAT page selects reporting month
        ↓
Validate all vat_inputs rows for that month
        ↓
Compute header totals from vat_inputs
        ↓
Generate H record
        ↓
Generate D records from vat_inputs
        ↓
Join lines using CRLF
        ↓
Download {TIN}P{MM}{YYYY}.DAT
```

---

## Example From Reference DAT

Detail row:

```text
D,P,"236791864","A ZINC INDUSTRIAL GALVANIZING PHILIPPINES",,,,"668 B 6TH ST 7TH AVE","CALOOCAN CITY",0,0,17697.17,0,0,2123.66,008791976,04/30/2026
```

Meaning:

```text
Vendor TIN:                 236791864
Company Name:               A ZINC INDUSTRIAL GALVANIZING PHILIPPINES
Address 1:                  668 B 6TH ST 7TH AVE
Address 2:                  CALOOCAN CITY
Exempt:                     0
Zero-Rated:                 0
Services:                   17697.17
Capital Goods:              0
Other Than Capital Goods:   0
Input VAT:                  2123.66
Buyer / Taxpayer TIN:       008791976
Reporting Period:           04/30/2026
```

VAT check:

```text
17697.17 × 12% = 2123.6604
rounded to 2 decimals = 2123.66
```

---

## Source References

The implementation is primarily based on:

```text
Known-valid DAT file
008791976P042026.DAT
```

Supporting references:

```text
BIR eRegistration FAQ:
RDO codes are assigned based on taxpayer registration context.

BIR VAT page:
Value-Added Tax reference.

RELIEF guide/workflow references:
RELIEF validates sales, purchases, and importation data before submission.
```

Important:

```text
The official BIR RELIEF validation module remains the final validation authority.
```


# BIR Excel to DAT Converter

## Project Goal

Build a Laravel + Inertia React system that converts Excel transaction data into a BIR-compatible `.DAT` file.

The initial implementation should focus on:

```text
RELIEF Purchases
```

The generated DAT file should match the structure and formatting of a known-valid BIR DAT reference file as closely and exactly as possible.

Reference filename:

```text
008791976P042026.DAT
```

Reference website workflow:

```text
https://bir-excel-uploader.com/excel-file-to-bir-dat-format/
```

How-to reference:

```text
https://bir-excel-uploader.com/excel-file-to-bir-dat-format/how-to/
```

> Important: The system should not invent a DAT structure. The actual valid BIR DAT file and official BIR technical specifications should remain the source of truth.

---

# Current Confirmed Findings

From the valid DAT sample and the analyzed conversion workflow:

```text
Header Record Count = 1
Header Field Count  = 21

Detail Record Count = Multiple
Detail Field Count  = 17

Header Record Type = H
Detail Record Type = D
Purchase Type      = P

Line Ending = CRLF (\r\n)
```

Example:

```text
H,P,...
D,P,...
D,P,...
D,P,...
```

---

# Reference DAT Filename

Example:

```text
008791976P042026.DAT
```

Observed filename structure:

```text
{TIN}{TYPE}{MM}{YYYY}.DAT
```

Breakdown:

```text
008791976 = Taxpayer TIN
P         = Purchases
04        = Reporting Month
2026      = Reporting Year
```

Laravel example:

```php
$filename =
    $company['tin']
    . 'P'
    . $period->format('mY')
    . '.DAT';
```

Expected result:

```text
008791976P042026.DAT
```

---

# Reporting Period Rule

The reporting period should use the **last day of the month**.

Example:

```text
April 2026
```

should become:

```text
04/30/2026
```

Use:

```php
$reportingPeriod = Carbon::create(
    $year,
    $month,
    1
)->endOfMonth();
```

Then:

```php
$formattedPeriod = $reportingPeriod->format('m/d/Y');
```

Do not use:

```text
2026-04-30
```

when the DAT format expects:

```text
04/30/2026
```

---

# Excel Template Rules

The converter should treat the Excel template as a fixed structure.

Important rules:

```text
Row 1 = Headers
Row 2+ = Data
```

Do not silently accept modified headers.

The system should validate:

```text
Expected sheet name
Expected header names
Expected header order
Required columns
```

The selected reporting type must match the uploaded Excel template.

Example:

```text
Selected Type:
RELIEF Purchases

Uploaded Template:
RELIEF Purchases
```

If the sheet/template does not match:

```text
Stop processing
Return validation error
Do not generate DAT
```

---

# Important Excel Fields

Observed/expected purchase template fields include:

```text
Vendor_TIN
companyName
lastName
firstName
middleName
address1
address2
purchase amount fields
input VAT
```

The exact numeric column names should be taken from the official RELIEF Purchases template or official BIR technical specification.

---

# Vendor TIN Validation

`Vendor_TIN` should be validated before DAT generation.

Rules:

```text
Required
Exactly 9 digits
Numbers only
Cannot be 000000000
```

Example valid:

```text
236791864
```

Example invalid:

```text
236-791-864
12345
ABC123456
000000000
```

Laravel example:

```php
'vendor_tin' => [
    'required',
    'regex:/^\d{9}$/',
    'not_in:000000000',
],
```

---

# Vendor Name Validation

## Company / Non-Individual Vendor

For a non-individual vendor:

```text
companyName = required
```

Example:

```text
A ZINC INDUSTRIAL GALVANIZING PHILIPPINES
```

## Individual Vendor

For an individual vendor, validate:

```text
lastName
firstName
middleName
```

according to the actual BIR template requirements.

The converter should avoid invalid special characters that may cause validation failure.

Do not silently alter legal names unless normalization rules are explicitly defined.

---

# Address Fields

Observed fields:

```text
address1
address2
```

These may be optional depending on the BIR template.

Example:

```text
address1:
668 B 6TH ST 7TH AVE

address2:
CALOOCAN CITY
```

---

# Numeric Field Rules

Numeric purchase columns should not be blank.

When there is no value, use:

```text
0
```

instead of an empty Excel cell.

Examples:

```text
0
17697.17
2123.66
1357592.77
162911.13
```

Do not output thousand separators:

Wrong:

```text
1,357,592.77
```

Correct:

```text
1357592.77
```

Use decimal point:

```text
.
```

not comma.

---

# Numeric Formatting

Recommended helper:

```php
private function number($value): string
{
    $value = $value ?? 0;

    if ((float) $value == 0) {
        return '0';
    }

    return number_format(
        (float) $value,
        2,
        '.',
        ''
    );
}
```

However:

> The generator must match the reference DAT exactly.

If the Header uses:

```text
0.00
```

while Detail uses:

```text
0
```

then use separate formatters for Header and Detail.

Example:

```php
private function headerNumber($value): string
{
    return number_format(
        (float) ($value ?? 0),
        2,
        '.',
        ''
    );
}
```

Example output:

```text
0.00
1007512.17
1085467.54
```

---

# Reference Header Record

Observed reference Header:

```text
H,P,"008791976","FORTRESS STEEL INC.","","","","FORTRESS STEEL INC.","LOT 433 J.P RIZAL NANGKA"," MARIKINA 1808",0.00,0.00,1007512.17,0.00,8038050.69,1085467.54,1085467.54,0.00,045,04/30/2026,12
```

Confirmed:

```text
Header field count = 21
```

---

# Header Field Map

Current working map:

| Position | Meaning | Status |
|---|---|---|
| 1 | Record Type = `H` | Confirmed |
| 2 | Transaction Type = `P` | Confirmed |
| 3 | Taxpayer TIN | Confirmed |
| 4 | Taxpayer / Company Name | Strongly confirmed |
| 5 | Name field | Needs official confirmation |
| 6 | Name field | Needs official confirmation |
| 7 | Name field | Needs official confirmation |
| 8 | Registered / Business Name field | Needs official confirmation |
| 9 | Address 1 | Strongly confirmed |
| 10 | Address 2 | Strongly confirmed |
| 11 | Purchase Total Field 1 | Needs official field name |
| 12 | Purchase Total Field 2 | Needs official field name |
| 13 | Purchase Total Field 3 | Needs official field name |
| 14 | Purchase Total Field 4 | Needs official field name |
| 15 | Purchase Total Field 5 | Needs official field name |
| 16 | Total Input VAT | Strongly confirmed |
| 17 | VAT-related total | Needs official confirmation |
| 18 | VAT-related total | Needs official confirmation |
| 19 | RDO Code | Strongly confirmed |
| 20 | Reporting Period | Confirmed |
| 21 | Final Header Field (`12` in sample) | Needs official confirmation |

Do not hard-code assumptions for fields marked:

```text
Needs official confirmation
```

without checking the official BIR specification.

---

# Header Totals

The reference Header numeric totals match the sums of the Detail numeric columns.

Observed Detail sums:

```text
Detail Field 10 = 0.00
Detail Field 11 = 0.00
Detail Field 12 = 1007512.17
Detail Field 13 = 0.00
Detail Field 14 = 8038050.69
Detail Field 15 = 1085467.54
```

Reference Header includes:

```text
0.00
0.00
1007512.17
0.00
8038050.69
1085467.54
```

Therefore:

> The Header is not static. It contains summary totals calculated from the Detail rows.

Implementation example:

```php
$totalField10 = $transactions->sum('purchase_field_1');
$totalField11 = $transactions->sum('purchase_field_2');
$totalField12 = $transactions->sum('purchase_field_3');
$totalField13 = $transactions->sum('purchase_field_4');
$totalField14 = $transactions->sum('purchase_field_5');
$totalInputVat = $transactions->sum('input_vat');
```

Use proper final field names after official mapping is confirmed.

---

# Non-Creditable Input VAT

The reference converter asks about:

```text
Non-Creditable Input VAT
```

before final DAT generation.

In the sample Header:

```text
1085467.54,
1085467.54,
0.00
```

A likely interpretation is:

```text
Total Input VAT
Creditable Input VAT
Non-Creditable Input VAT
```

Example:

```text
Total Input VAT          = 1085467.54
Creditable Input VAT     = 1085467.54
Non-Creditable Input VAT = 0.00
```

This is a strong inference but should still be confirmed against the official BIR RELIEF technical specification.

Possible formula:

```php
$totalInputVat = $transactions->sum('input_vat');

$nonCreditableInputVat = $request->non_creditable_input_vat ?? 0;

$creditableInputVat =
    $totalInputVat - $nonCreditableInputVat;
```

Do not allow:

```text
Non-Creditable Input VAT > Total Input VAT
```

---

# Reference Detail Record

Example:

```text
D,P,"236791864","A ZINC INDUSTRIAL GALVANIZING PHILIPPINES",,,,"668 B 6TH ST 7TH AVE","CALOOCAN CITY",0,0,17697.17,0,0,2123.66,008791976,04/30/2026
```

Confirmed:

```text
Detail field count = 17
```

---

# Detail Field Map

Current working map:

| Position | Meaning | Status |
|---|---|---|
| 1 | Record Type = `D` | Confirmed |
| 2 | Transaction Type = `P` | Confirmed |
| 3 | Vendor TIN | Confirmed |
| 4 | Company Name | Strongly confirmed |
| 5 | Last Name | Strongly confirmed |
| 6 | First Name | Strongly confirmed |
| 7 | Middle Name | Strongly confirmed |
| 8 | Address 1 | Strongly confirmed |
| 9 | Address 2 | Strongly confirmed |
| 10 | Purchase Numeric Field 1 | Needs official field name |
| 11 | Purchase Numeric Field 2 | Needs official field name |
| 12 | Purchase Numeric Field 3 | Needs official field name |
| 13 | Purchase Numeric Field 4 | Needs official field name |
| 14 | Purchase Numeric Field 5 | Needs official field name |
| 15 | Input VAT | Strongly confirmed |
| 16 | Buyer / Taxpayer TIN | Confirmed |
| 17 | Reporting Period | Confirmed |

---

# Detail VAT Verification

Example Detail:

```text
Taxable / purchase amount:
17697.17

Input VAT:
2123.66
```

Calculation:

```text
17697.17 × 12%
= 2123.6604
≈ 2123.66
```

This supports the interpretation of field 15 as Input VAT.

However, the exact names and classifications of fields 10–14 must still be confirmed from BIR documentation.

---

# DAT String Rules

Use a controlled string formatter.

Example:

```php
private function quote(?string $value): string
{
    $value = trim($value ?? '');

    $value = str_replace('"', '""', $value);

    return '"' . $value . '"';
}
```

Important:

```text
Do not automatically quote all fields.
```

Match the reference DAT.

Examples:

Quoted:

```text
"008791976"
"FORTRESS STEEL INC."
"A ZINC INDUSTRIAL GALVANIZING PHILIPPINES"
```

Unquoted:

```text
0
17697.17
2123.66
008791976
04/30/2026
```

The exact quote behavior should be reproduced from the known-valid DAT.

---

# Empty Field Rules

Do not remove empty fields.

Example:

```text
"COMPANY NAME",,,,"ADDRESS"
```

The commas preserve field positions.

Never convert:

```text
A,,,B
```

into:

```text
A,B
```

because that changes the DAT field layout.

---

# CRLF Line Endings

Use Windows-style CRLF:

```php
"\r\n"
```

Example:

```php
$content = implode("\r\n", $lines);
```

Do not rely on:

```php
PHP_EOL
```

because output may change depending on the operating system.

The DAT generator should explicitly control the line ending.

---

# Recommended System Flow

```text
User selects RELIEF Purchases
        ↓
Upload Excel
        ↓
Validate Sheet Name
        ↓
Validate Row 1 Headers
        ↓
Read Rows 2+
        ↓
Normalize Excel Values
        ↓
Validate Vendor TIN
        ↓
Validate Vendor Name
        ↓
Validate Numeric Fields
        ↓
Validate Reporting Period
        ↓
Show Preview
        ↓
Calculate Detail Totals
        ↓
Enter Non-Creditable Input VAT
        ↓
Calculate Header Totals
        ↓
Generate Header
        ↓
Generate Detail Records
        ↓
Join Using CRLF
        ↓
Generate Filename
        ↓
Download .DAT
        ↓
Validate in Official BIR Validation Module
```

---

# Recommended Architecture

Do not generate DAT directly from raw Excel rows.

Use:

```text
Excel
↓
Excel Parser
↓
Normalized DTO / Array
↓
Validator
↓
Preview
↓
DAT Generator
↓
DAT File
```

---

# Recommended Laravel Structure

```text
app/
├── DTOs/
│   └── BirPurchaseRow.php
│
├── Http/
│   └── Controllers/
│       ├── BirExcelImportController.php
│       └── BirDatGeneratorController.php
│
├── Imports/
│   └── BirPurchaseImport.php
│
└── Services/
    └── BIR/
        ├── BirExcelValidationService.php
        ├── BirPurchaseNormalizerService.php
        └── BirDatGeneratorService.php
```

Optional:

```text
app/
└── Services/
    └── BIR/
        └── Formats/
            └── ReliefPurchaseDatGenerator.php
```

This is recommended if more BIR formats will be added later.

---

# Normalized Purchase DTO

Example:

```php
[
    'vendor_tin' => '236791864',

    'company_name' =>
        'A ZINC INDUSTRIAL GALVANIZING PHILIPPINES',

    'last_name' => '',
    'first_name' => '',
    'middle_name' => '',

    'address_1' =>
        '668 B 6TH ST 7TH AVE',

    'address_2' =>
        'CALOOCAN CITY',

    'purchase_field_1' => 0,
    'purchase_field_2' => 0,
    'purchase_field_3' => 17697.17,
    'purchase_field_4' => 0,
    'purchase_field_5' => 0,

    'input_vat' => 2123.66,
]
```

Rename:

```text
purchase_field_1
purchase_field_2
purchase_field_3
purchase_field_4
purchase_field_5
```

once the official BIR field names are confirmed.

---

# DAT Generator Service

Create:

```text
app/Services/BIR/ReliefPurchaseDatGenerator.php
```

Example:

```php
<?php

namespace App\Services\BIR;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReliefPurchaseDatGenerator
{
    public function generate(
        array $company,
        Collection $transactions,
        Carbon $period,
        float $nonCreditableInputVat = 0
    ): string {
        $lines = [];

        $lines[] = $this->generateHeader(
            $company,
            $transactions,
            $period,
            $nonCreditableInputVat
        );

        foreach ($transactions as $transaction) {
            $lines[] = $this->generateDetail(
                $transaction,
                $company,
                $period
            );
        }

        return implode("\r\n", $lines);
    }
}
```

---

# Detail Generator Example

Use a positional array so the number of fields is easy to verify.

```php
private function generateDetail(
    array $transaction,
    array $company,
    Carbon $period
): string {
    $fields = [
        'D',
        'P',

        $this->quote(
            $transaction['vendor_tin']
        ),

        $this->quote(
            $transaction['company_name']
        ),

        $this->optionalQuotedName(
            $transaction['last_name']
        ),

        $this->optionalQuotedName(
            $transaction['first_name']
        ),

        $this->optionalQuotedName(
            $transaction['middle_name']
        ),

        $this->quote(
            $transaction['address_1']
        ),

        $this->quote(
            $transaction['address_2']
        ),

        $this->detailNumber(
            $transaction['purchase_field_1']
        ),

        $this->detailNumber(
            $transaction['purchase_field_2']
        ),

        $this->detailNumber(
            $transaction['purchase_field_3']
        ),

        $this->detailNumber(
            $transaction['purchase_field_4']
        ),

        $this->detailNumber(
            $transaction['purchase_field_5']
        ),

        $this->detailNumber(
            $transaction['input_vat']
        ),

        $company['tin'],

        $period->copy()
            ->endOfMonth()
            ->format('m/d/Y'),
    ];

    if (count($fields) !== 17) {
        throw new RuntimeException(
            'RELIEF Purchase Detail must contain exactly 17 fields.'
        );
    }

    return implode(',', $fields);
}
```

Important:

The quote behavior for empty personal-name fields must be matched to the actual DAT reference.

Example reference:

```text
"COMPANY",,,,"ADDRESS"
```

Therefore empty fields may need to return:

```text
''
```

instead of:

```text
""
```

depending on the exact field.

---

# Header Generator

The Header should:

```text
Use company information
Calculate totals from Detail rows
Include Input VAT totals
Include Non-Creditable Input VAT logic
Include RDO
Include Reporting Period
Have exactly 21 fields
```

Example guard:

```php
if (count($fields) !== 21) {
    throw new RuntimeException(
        'RELIEF Purchase Header must contain exactly 21 fields.'
    );
}
```

---

# Validation Service

Create:

```text
BirExcelValidationService
```

Validate each row.

Example:

```php
[
    'vendor_tin' => [
        'required',
        'regex:/^\d{9}$/',
        'not_in:000000000',
    ],

    'company_name' => [
        'nullable',
        'string',
    ],

    'purchase_field_1' => [
        'required',
        'numeric',
    ],

    'purchase_field_2' => [
        'required',
        'numeric',
    ],

    'purchase_field_3' => [
        'required',
        'numeric',
    ],

    'purchase_field_4' => [
        'required',
        'numeric',
    ],

    'purchase_field_5' => [
        'required',
        'numeric',
    ],

    'input_vat' => [
        'required',
        'numeric',
    ],
]
```

---

# Validation Error Format

Return errors by Excel row.

Example:

```text
Row 7:
Vendor TIN must contain exactly 9 digits.

Row 11:
Company Name is required for a non-individual vendor.

Row 18:
Input VAT must be numeric.

Row 23:
Purchase amount cannot be blank. Use 0 when there is no amount.
```

Do not generate the DAT while critical validation errors exist.

---

# Preview Page

Show:

```text
Vendor TIN
Vendor Name
Purchase Amount Fields
Input VAT
Validation Status
```

Summary:

```text
Total Excel Rows
Valid Rows
Invalid Rows
Purchase Totals
Total Input VAT
```

Actions:

```text
Upload New File
Fix Errors
Generate DAT
```

Disable:

```text
Generate DAT
```

while invalid rows exist.

---

# Non-Creditable Input VAT UI

Before generating:

```text
Non-Creditable Input VAT
[ 0.00 ]
```

Validation:

```text
Required numeric value
Minimum = 0
Maximum = Total Input VAT
```

Show:

```text
Total Input VAT
Non-Creditable Input VAT
Calculated Creditable Input VAT
```

Example:

```text
Total Input VAT:
1,085,467.54

Non-Creditable:
0.00

Creditable:
1,085,467.54
```

---

# DAT Download

Example controller:

```php
public function generate(
    Request $request,
    ReliefPurchaseDatGenerator $generator
) {
    $company = ...;
    $transactions = ...;

    $period = Carbon::parse(
        $request->period
    )->endOfMonth();

    $content = $generator->generate(
        $company,
        $transactions,
        $period,
        (float) $request->non_creditable_input_vat
    );

    $filename =
        $company['tin']
        . 'P'
        . $period->format('mY')
        . '.DAT';

    return response($content)
        ->header(
            'Content-Type',
            'text/plain'
        )
        ->header(
            'Content-Disposition',
            'attachment; filename="' .
            $filename .
            '"'
        );
}
```

---

# Automated Tests

This feature should have strict tests.

## Header Field Count

```php
$this->assertCount(
    21,
    $headerFields
);
```

## Detail Field Count

```php
$this->assertCount(
    17,
    $detailFields
);
```

---

# Exact DAT Comparison

When equivalent source data is available, compare against the known-valid reference DAT.

```php
public function test_generated_dat_matches_reference()
{
    $expected = file_get_contents(
        base_path(
            'tests/fixtures/008791976P042026.DAT'
        )
    );

    $actual = app(
        ReliefPurchaseDatGenerator::class
    )->generate(
        $company,
        $transactions,
        $period,
        0
    );

    $this->assertSame(
        $expected,
        $actual
    );
}
```

Goal:

```text
Byte-for-byte equality
```

not only visual similarity.

---

# Test CRLF

Example:

```php
$this->assertStringContainsString(
    "\r\n",
    $actual
);
```

Also ensure no unintended LF-only line breaks.

---

# Test Filename

Expected:

```text
008791976P042026.DAT
```

Test:

```php
$this->assertSame(
    '008791976P042026.DAT',
    $filename
);
```

---

# Test Reporting Period

Input:

```text
April 2026
```

Expected:

```text
04/30/2026
```

---

# Test Vendor TIN

Valid:

```text
236791864
```

Invalid:

```text
000000000
12345678
1234567890
ABC791864
```

---

# Important Implementation Rule

Do NOT:

```text
Create JSON and rename it .DAT

Create a normal CSV and assume it is BIR-compatible

Change DAT field order

Remove empty fields

Add thousand separators

Use random quote behavior

Use YYYY-MM-DD

Use PHP_EOL

Guess unknown field names

Put all logic inside the Controller

Generate DAT before Excel validation
```

---

# Current Confidence Level

## Confirmed

```text
H = Header
D = Detail
P = Purchases

Header = 21 fields
Detail = 17 fields

Vendor TIN position
Company Name position
Personal name positions
Address positions

Buyer / taxpayer TIN
Reporting Period

Reporting Period = End of Month
Date format = MM/DD/YYYY

CRLF line endings

Header totals are calculated from Detail totals

Filename pattern:
{TIN}P{MM}{YYYY}.DAT
```

---

# Still Needs Official BIR Confirmation

Do not treat these as final until the official RELIEF technical specification is reviewed:

```text
Exact official names of Detail fields 10–14

Exact official definitions of Header fields 5–8

Exact meaning of Header fields 16–18

Exact meaning of final Header field 21
(sample value = 12)

Exact quote rules for every optional field

Exact official special-character rules

Exact relationship between:
Total Input VAT
Creditable Input VAT
Non-Creditable Input VAT
```

---

# Recommended Codex Task

Give Codex this priority:

```text
Implement RELIEF Purchases only.

Do not create a generic BIR converter yet.

Use the known-valid DAT as the output reference.

Generate exactly:
1 Header record with 21 fields
N Detail records with 17 fields each

Use CRLF.
Use month-end reporting date.
Validate the Excel template.
Validate 9-digit Vendor TIN.
Require numeric cells.
Use zero instead of blank numeric values.
Calculate Header totals from Detail rows.
Generate {TIN}P{MM}{YYYY}.DAT.
Create byte-for-byte output tests.
Do not guess unidentified BIR fields.
```

---

# Final Target Flow

```text
RELIEF Purchases Excel
        ↓
Template Validation
        ↓
Row Validation
        ↓
Normalization
        ↓
Preview
        ↓
Input VAT Summary
        ↓
Non-Creditable Input VAT
        ↓
Header Calculation
        ↓
Detail Generation
        ↓
CRLF DAT Content
        ↓
008791976P042026.DAT
        ↓
Official BIR Validation
```

---

# Development Priority

## Phase 1

```text
RELIEF Purchases only
Excel template validation
Excel parsing
Vendor validation
Numeric validation
Preview
Header generation
Detail generation
DAT download
Exact output testing
```

## Phase 2

```text
Import history
Generated DAT history
Duplicate detection
Improved error reporting
Downloadable Excel template
```

## Phase 3

Only after RELIEF Purchases is verified:

```text
RELIEF Sales
SAWT
MAP
QAP
Alphalist
Other BIR DAT formats
```

Each format should have its own generator class.

---

# Main Principle

The system must prioritize:

```text
Correct BIR structure
over
generic conversion
```

The DAT generator should be based on:

```text
Known-valid DAT reference
Official BIR technical specification
Official BIR validation module
Known-valid company submission files
```

The third-party converter should be used only as a workflow reference, not as the final authority for BIR file specifications.

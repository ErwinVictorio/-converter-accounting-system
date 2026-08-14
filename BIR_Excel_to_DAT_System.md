# BIR Excel to DAT File Converter

## Project Overview

This system converts Excel-based accounting or tax data into a BIR-compatible `.DAT` file format.

The project is inspired by the workflow of:

`https://bir-excel-uploader.com/excel-file-to-bir-dat-format/`

The goal is to allow users to upload an Excel file, validate its contents, preview the transactions, and generate the appropriate BIR `.DAT` file.

> Important: Generated DAT files should still be validated using the appropriate official BIR validation module before submission.

---

## Initial Target

The initial implementation can focus on **RELIEF Purchases**.

A sample DAT filename may look like:

```text
008791976P042026.DAT
```

Possible filename structure:

```text
008791976 P 04 2026
│         │ │  │
│         │ │  └── Year
│         │ └───── Month
│         └─────── Purchases
└───────────────── Taxpayer TIN
```

---

## Sample DAT Structure

A BIR DAT file may contain a header record followed by multiple detail records.

Example:

```text
H,P,"008791976","FORTRESS STEEL INC.",...,04/30/2026,12

D,P,"236791864","A ZINC INDUSTRIAL GALVANIZING PHILIPPINES",...,17697.17,...,2123.66,008791976,04/30/2026

D,P,"007086184","ACERSTEEL INDUSTRIAL SALES INC",...,1357592.77,162911.13,008791976,04/30/2026
```

### Record Types

```text
H = Header Record
D = Detail Record
P = Purchases
```

---

## Suggested System Flow

```text
Upload Excel
    ↓
Read Excel Rows
    ↓
Validate Columns
    ↓
Validate TIN / Dates / Amounts
    ↓
Preview Transactions
    ↓
Calculate Totals
    ↓
Generate Header Record
    ↓
Generate Detail Records
    ↓
Create DAT Content
    ↓
Generate DAT Filename
    ↓
Download .DAT File
    ↓
Validate Using BIR Validation Module
```

---

## Suggested Modules

### 1. Company / BIR Settings

Store information used when generating DAT files.

Suggested fields:

```text
Company Name
TIN
Branch Code
RDO Code
Registered Address
Taxpayer Type
Default Reporting Period
```

Possible database table:

```text
bir_settings
```

Example fields:

```text
id
company_name
tin
branch_code
rdo_code
address
created_at
updated_at
```

---

### 2. Excel Import

The user uploads an Excel file containing transaction data.

Recommended workflow:

```text
Choose Excel File
↓
Upload
↓
Parse Rows
↓
Validate Required Columns
↓
Display Preview
```

Possible Excel columns for purchases:

```text
Supplier TIN
Supplier Registered Name
Last Name
First Name
Middle Name
Address
City
Exempt Purchase
Zero Rated Purchase
Taxable Purchase
Capital Goods
Other Taxable Purchase
Input VAT
Transaction Date
```

The exact columns should follow the applicable official BIR technical specification.

---

## DAT Detail Record

A detail record may conceptually contain fields similar to:

```text
D
P
Supplier TIN
Supplier Registered Name
Last Name
First Name
Middle Name
Address Line 1
Address Line 2
Exempt Purchase
Zero Rated Purchase
Taxable Purchase
Capital Goods
Other Taxable Purchase
Input VAT
Purchaser TIN
Reporting Period
```

Example:

```text
D,P,"236791864","A ZINC INDUSTRIAL GALVANIZING PHILIPPINES",,,,
"668 B 6TH ST 7TH AVE",
"CALOOCAN CITY",
0,
0,
17697.17,
0,
0,
2123.66,
008791976,
04/30/2026
```

> The mapping above should be treated as a working reference only until every field position is confirmed against the official BIR format specification.

---

## Recommended Technology Stack

### Backend

```text
Laravel 12
PHP 8.2+
MySQL
Maatwebsite/Laravel-Excel
PhpSpreadsheet
```

### Frontend

```text
React
Inertia.js
Tailwind CSS
shadcn/ui
React Hook Form
Zod
```

---

## Suggested Laravel Structure

```text
app/
├── Http/
│   └── Controllers/
│       ├── BirSettingController.php
│       ├── ExcelImportController.php
│       └── DatGeneratorController.php
│
├── Imports/
│   └── PurchaseImport.php
│
├── Models/
│   ├── BirSetting.php
│   └── PurchaseTransaction.php
│
└── Services/
    └── BirDatGeneratorService.php
```

---

## Suggested Routes

```php
Route::get('/bir/settings', [BirSettingController::class, 'index']);
Route::post('/bir/settings', [BirSettingController::class, 'store']);

Route::post('/bir/import', [ExcelImportController::class, 'store']);

Route::post('/bir/generate-dat', [DatGeneratorController::class, 'generate']);
```

---

## Excel Import Example

Using `maatwebsite/excel`:

```php
use Maatwebsite\Excel\Facades\Excel;

Excel::import(
    new PurchaseImport(),
    $request->file('file')
);
```

Example import class:

```php
namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class PurchaseImport implements ToCollection
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            // Validate and map Excel columns here.
        }
    }
}
```

---

## DAT Generator Service

Recommended service:

```text
BirDatGeneratorService
```

Responsibilities:

```text
Generate Header
Generate Detail Rows
Escape Text Values
Format Decimal Values
Format Dates
Generate Filename
Return DAT Content
```

Example:

```php
class BirDatGeneratorService
{
    public function generateHeader(
        $company,
        $transactions,
        $period
    ): string {
        // Build H record.
    }

    public function generateDetail(
        $transaction,
        $company,
        $period
    ): string {
        // Build D record.
    }
}
```

---

## DAT Generation Example

```php
$transactions = $purchaseTransactions;

$header = $generator->generateHeader(
    $company,
    $transactions,
    $period
);

$details = $transactions->map(function ($transaction) use (
    $generator,
    $company,
    $period
) {
    return $generator->generateDetail(
        $transaction,
        $company,
        $period
    );
});

$content = $header
    . "\r\n"
    . $details->implode("\r\n");
```

---

## Download DAT File

Example Laravel response:

```php
$filename = '008791976P042026.DAT';

return response($content)
    ->header('Content-Type', 'text/plain')
    ->header(
        'Content-Disposition',
        'attachment; filename="' . $filename . '"'
    );
```

---

## Suggested Validation Rules

Before generating the DAT file, validate:

```text
TIN format
Required supplier name
Required reporting period
Valid transaction dates
Numeric purchase amounts
Numeric VAT amount
No invalid Excel rows
No duplicate rows when applicable
Correct number of DAT fields
Correct field sequence
```

Example Laravel validation:

```php
$request->validate([
    'file' => [
        'required',
        'file',
        'mimes:xlsx,xls,csv',
    ],
]);
```

---

## Frontend Pages

Recommended screens:

```text
Dashboard
BIR Settings
Excel Upload
Import Preview
Validation Errors
DAT Generator
Generated DAT History
```

---

## Excel Upload Page

Suggested UI:

```text
--------------------------------------------------
BIR Excel to DAT Converter
--------------------------------------------------

Reporting Type:
[ RELIEF Purchases ▼ ]

Reporting Period:
[ April 2026 ]

Excel File:
[ Choose File ]

[ Upload & Validate ]
--------------------------------------------------
```

---

## Import Preview

After upload:

```text
Supplier TIN | Supplier Name | Taxable Purchase | VAT
-------------------------------------------------------
236791864     | A Zinc        | 17,697.17        | 2,123.66
007086184     | Acersteel     | 1,357,592.77     | 162,911.13
```

Actions:

```text
Back
Fix Errors
Generate DAT
```

---

## Validation Error Example

```text
Row 8:
Invalid supplier TIN.

Row 12:
Supplier registered name is required.

Row 17:
Input VAT must be numeric.
```

The system should prevent DAT generation until critical validation errors are resolved.

---

## Generated DAT History

Recommended table:

```text
Filename
Type
Period
Generated By
Generated At
Status
```

Example:

```text
008791976P042026.DAT
RELIEF Purchases
April 2026
Admin
2026-05-05 10:35 AM
Generated
```

---

## Future Supported Formats

After RELIEF Purchases is stable, the system may support:

```text
RELIEF Sales
RELIEF Purchases
SAWT
MAP
QAP
Alphalist
Other BIR DAT Formats
```

Each format should preferably have its own generator class.

Example:

```text
app/Services/BIR/

├── ReliefPurchaseGenerator.php
├── ReliefSalesGenerator.php
├── SawtGenerator.php
├── MapGenerator.php
└── QapGenerator.php
```

This makes the system easier to maintain because each BIR format can have a different field structure.

---

## Recommended Architecture

```text
Excel File
   ↓
Import Service
   ↓
Normalized Transaction Data
   ↓
Validation Service
   ↓
Database / Preview
   ↓
BIR Format Generator
   ↓
DAT File
```

Avoid generating DAT records directly from raw Excel rows.

First normalize the Excel data into a predictable internal structure.

Example:

```php
[
    'supplier_tin' => '236791864',
    'supplier_name' => 'A ZINC INDUSTRIAL GALVANIZING PHILIPPINES',
    'address' => '668 B 6TH ST 7TH AVE',
    'city' => 'CALOOCAN CITY',
    'exempt_purchase' => 0,
    'zero_rated_purchase' => 0,
    'taxable_purchase' => 17697.17,
    'capital_goods' => 0,
    'other_taxable_purchase' => 0,
    'input_vat' => 2123.66,
]
```

Then pass normalized data to the DAT generator.

---

## Important Development Rule

Do not rely only on the output of third-party BIR converter websites.

The system should be implemented based on:

```text
Official BIR technical specifications
Actual BIR sample DAT files
Official BIR validation tools
Known-valid company DAT files
```

A generated file should always be tested in the applicable BIR validation module before production use.

---

## Development Roadmap

### Phase 1

```text
Company / BIR Settings
Excel Upload
Excel Parsing
RELIEF Purchase Mapping
Validation
Preview
DAT Generation
DAT Download
```

### Phase 2

```text
Import History
Generated DAT History
Duplicate Detection
Better Error Reporting
Excel Template Download
```

### Phase 3

```text
RELIEF Sales
SAWT
MAP
QAP
Alphalist
Multiple Companies
Multiple Branches
User Permissions
```

---

## Main Goal

The final system should allow a user to perform:

```text
Excel
  ↓
Upload
  ↓
Validate
  ↓
Preview
  ↓
Generate
  ↓
BIR DAT
```

while reducing manual encoding and preventing common DAT formatting errors.

# DAT Generation Text Validation Relaxation Plan

## Implementation Status — 2026-09-09

Implemented in `app/Http/Controllers/DatFileController.php`: Purchase/Sales period issue lists and download validation now filter only text-length and comma/ampersand errors through `datBlockingErrors()` and `isRelaxedDatTextError()`.

Shared validators, DAT generators, ordering, stored records, ZIP/PDF packaging, Importation, Expanded WTAX, and master-data form validation were not changed.

Verification:

- `DatTextValidationRelaxationTest`: 24 passed (444 assertions), covering individual name/address length and punctuation cases, period issues, DAT/PDF ZIP downloads, exact generated DAT content, unchanged stored text, and retained TIN/name/address/type blockers.
- Existing upload preflight, alphabetical DAT ordering, Importation DAT, Expanded WTAX DAT, Purchase generator, and Sales generator suites: 70 passed (426 assertions).
- `git diff --check` passed.

The original implementation plan and scope follow below.

## Goal

Remove only the text character/length blocking errors from the Generate DAT screen and DAT download flow, so rows are not blocked just because of BIR text limits such as:

- `address1 must not exceed 30 characters`
- `address2 must not exceed 30 characters`
- `company_name must not exceed 50 characters`
- `cannot contain comma or ampersand`

The DAT file format itself must not change.

## Current Problem

The upload preflight already allows character/length-only issues to pass, but Generate DAT still reports them in `periodIssues` and disables the download button.

Example current UI:

- `516 Sales VAT rows found. 160 need BIR info fixes.`
- `Fix Sales VAT rows before downloading DAT`
- Errors are mostly address length issues.

This prevents DAT + attachment download even when the user wants character validation removed from this workflow.

## Non-Negotiable Boundary

Do not change the DAT generator layout or official DAT file format.

No changes to:

- DAT field count
- DAT field order
- DAT headers
- DAT detail rows
- DAT control/trailer rows
- DAT delimiters
- DAT line endings
- DAT filenames
- DAT period formatting
- DAT row ordering
- DAT ZIP packaging
- PDF attachment packaging

This plan changes only which validation errors are considered blocking before generating/downloading.

## Validation Behavior After Change

### Still Blocking

Keep these validations blocking on Generate DAT:

- Missing or invalid TIN
- TIN equal to `000000000`
- Missing required company/customer/supplier name
- Invalid vendor/customer type
- Missing required Address1
- Non-numeric amount fields
- Importation calculation/required-field issues
- Expanded WTAX BIR/ATC/rate/amount issues
- No records for selected period
- Wrong selected period/type rules

### No Longer Blocking

Ignore only these text-format errors for Purchase and Sales during Generate DAT:

- `must not exceed ... characters`
- `cannot contain comma or ampersand`

These are the same class of errors already allowed during upload preflight.

## Scope

Apply this relaxation to:

- Purchase DAT period issue listing
- Purchase DAT download validation
- Sales DAT period issue listing
- Sales DAT download validation

Do not apply this to:

- Importation DAT, unless a specific character-only blocker is confirmed there later
- Expanded WTAX, because that flow has stricter BIR/ATC and annual/quarterly validation requirements
- Supplier/Customer create/update forms, where master data can still enforce field limits

## Implementation Plan

1. Add a small helper in `DatFileController`.

Suggested name:

```php
private function datBlockingErrors(array $errors): array
```

2. Add a second helper for the ignored text errors.

Suggested name:

```php
private function isRelaxedDatTextError(string $error): bool
```

3. Filter Purchase `periodIssues`.

Current path:

```php
purchasePeriods()
```

Instead of counting every `BirPurchaseRowValidator` error, count only errors returned by `datBlockingErrors()`.

4. Filter Sales `periodIssues`.

Current path:

```php
salesPeriods()
```

Instead of counting every `BirSalesRowValidator` error, count only errors returned by `datBlockingErrors()`.

5. Filter Purchase download validation.

Current path:

```php
downloadPurchase()
```

The row should block download only if the remaining filtered errors are not empty.

6. Filter Sales download validation.

Current path:

```php
downloadSales()
```

The sales group should block download only if the remaining filtered errors are not empty.

7. Keep the existing validator classes intact.

Do not remove these checks from:

- `BirPurchaseRowValidator`
- `BirSalesRowValidator`

Reason: those validators still document full BIR constraints and may be used for stricter checks elsewhere.

## UI Impact

After implementation:

- The Generate DAT screen should no longer show text character/length warnings as blocking issues.
- The download button should be enabled when only ignored text-format issues exist.
- The DAT + attachment ZIP should download normally for Purchase/Sales if no other blocking issue exists.

## Test Plan

Add focused tests for:

1. Purchase Generate DAT page ignores address/name length errors in `periodIssues`.
2. Sales Generate DAT page ignores address/name length errors in `periodIssues`.
3. Purchase DAT download proceeds when the only validator error is text length/punctuation.
4. Sales DAT download proceeds when the only validator error is text length/punctuation.
5. Purchase still blocks invalid/missing TIN.
6. Sales still blocks invalid/missing TIN.
7. Existing DAT generator unit tests still pass.
8. DAT ZIP package tests still pass.

Suggested commands:

```bash
php artisan test tests/Feature/UploadWorkbookTypePreflightTest.php
php artisan test tests/Feature/DatFileAlphabeticalOrderingTest.php
php artisan test tests/Unit/ReliefPurchaseDatGeneratorTest.php tests/Unit/ReliefSalesDatGeneratorTest.php
```

## Acceptance Criteria

- Generate DAT no longer blocks Purchase/Sales downloads for text length or comma/ampersand-only validation errors.
- Generate DAT still blocks missing/invalid identity and amount errors.
- Existing `.DAT` content generation remains unchanged.
- DAT + attachment ZIP behavior remains unchanged.
- Supplier and Customer master-data entry validation remains unchanged unless separately requested.

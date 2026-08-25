# Expanded WTAX Company + Rate Consolidation Plan

## Goal

Change Expanded WTAX consolidation so uploaded rows are combined when they represent the same payee/company and the same EWT percentage.

Requested rule:

- Same company/payee + same percentage/rate = one consolidated record.
- Same company/payee + different percentage/rate = separate records.

Example:

- `ACERSTEEL INDUSTRIAL SALES INC` at `1.00%` across multiple uploaded rows should become one row.
- `ACERSTEEL INDUSTRIAL SALES INC` at `1.00%` and `2.00%` should remain two rows.

Validation of the 1601EQ DAT format is already successful.

Do not change the DAT format anymore.

The only focus of this plan is consolidation/grouping: combine records with the same company/payee name and same percentage/rate, then add their values together.

## Scope Guard

This change is for Expanded WTAX only.

Do not change:

- Sales DAT format
- Purchase DAT format
- Importation DAT format
- Sales/Purchase/Importation upload parsing
- Sales/Purchase/Importation validators
- Sales/Purchase/Importation filename rules
- Dashboard VAT math

Target files are limited to:

- `app/Models/ExpandedWtaxEntry.php`
- `app/Http/Controllers/VatInputController.php`, only if record-list display needs clearer merged labels
- `app/Http/Controllers/DatFileController.php`, only if generation comments/tests need alignment
- `resources/js/Pages/RecordEntry.jsx`, only tooltip/copy if needed
- Expanded WTAX tests
- Expanded WTAX docs

Allowed changes are limited to Expanded WTAX consolidation logic, Expanded WTAX tests, and Expanded WTAX documentation.

The accepted 1601EQ DAT field order must stay exactly as-is.

## Current Behavior

Current consolidation key uses:

```text
reporting month
withholding agent TIN
withholding agent branch
payee TIN
ATC
EWT rate
```

Effect:

- Same TIN + same ATC + same rate merges.
- Same company name + same rate but different TIN does not merge.

This is why the records screen can still show repeated company names even when the percentage is the same.

## New Consolidation Rule

Use this consolidation key:

```text
reporting month
withholding agent TIN
withholding agent branch
normalized payee/company identity
ATC
EWT rate
```

The normalized payee/company identity should be based on the displayed BIR payee identity:

- For company payees: normalized `company_name`.
- For individual payees: normalized `last_name + first_name + middle_name`.
- Fallback: normalized `payee_name` if name-specific fields are blank.

Normalization should:

- uppercase text
- convert `&` to `AND`
- remove commas and unsupported punctuation
- collapse multiple spaces
- trim leading/trailing spaces

## TIN Handling Decision

A consolidated DAT detail row can contain only one payee TIN.

When multiple uploaded rows have the same company/name and same rate but different TIN values, the app must choose one TIN for the merged record.

Recommended rule:

- Keep the first non-empty valid TIN from the group.
- Keep the first non-empty valid branch code from the same row.
- Add a warning/flag in the consolidated row when the group contains multiple distinct TINs.

Reason:

- This follows the current “first row supplies unsummed fields” behavior.
- It avoids silently inventing a TIN.
- It makes possible data cleanup visible when the same company name has conflicting TINs.

Optional UI label:

```text
4 rows merged
Multiple TINs
```

## Amount Handling

For rows in the same group, add:

- `income_payment`
- `tax_withheld`

Do not recompute tax from income payment.

Keep:

- uploaded ATC
- uploaded EWT rate
- two-decimal formatting
- negative reversal rows

## DAT Output Behavior

The generated 1601EQ DAT should use the already accepted BIR format:

```text
HQAP,H1601EQ,{TIN},{BRANCH},"{WA_NAME}",{MM/YYYY},{RDO}
D1,1601EQ,{SEQ},{PAYEE_TIN},{PAYEE_BRANCH},{COMPANY},{SURNAME},{FIRST},{MIDDLE},{MM/YYYY},{ATC},{RATE},{INCOME},{TAX}
C1,1601EQ,{TIN},{BRANCH},{MM/YYYY},{TOTAL_INCOME},{TOTAL_TAX}
```

This change should affect only which rows are combined before DAT generation, not the DAT field order.

No fields should be added, removed, reordered, renamed, or reformatted in the DAT output.

The generator should receive fewer consolidated rows, but each output line must keep the same accepted format.

## Implementation Steps

### 1. Add Name-Based Consolidation Key

Update `ExpandedWtaxEntry::consolidationKey()`.

Replace payee TIN in the key with normalized payee identity.

Before:

```text
period | agent tin | agent branch | payee tin | ATC | rate
```

After:

```text
period | agent tin | agent branch | normalized payee identity | ATC | rate
```

### 2. Preserve First Valid TIN and Branch

In `ExpandedWtaxEntry::consolidate()`:

- Keep first row as the base group, as it does today.
- Sum `income_payment` and `tax_withheld`.
- Track all distinct payee TINs in the group.
- Track all distinct payee branch codes in the group.
- Add metadata fields:

```text
distinct_payee_tins
has_multiple_payee_tins
distinct_payee_branch_codes
has_multiple_payee_branch_codes
```

These metadata fields are for UI/testing only and should not be written to the DAT.

### 3. Update Records UI Badge

Update `resources/js/Pages/RecordEntry.jsx` only if needed.

Keep the existing merged badge:

```text
4 rows merged
```

If `has_multiple_payee_tins` is true, show a small warning badge:

```text
Multiple TINs
```

Tooltip should explain:

```text
Rows share the same payee name and rate but have different TINs. The DAT uses the first valid TIN in the group.
```

### 4. Keep DAT Generation Format Unchanged

Do not change `ReliefExpandedWtaxDatGenerator.php` for DAT field order, period format, filename format, line endings, quoting, or totals layout.

Only update comments if they describe the old grouping rule.

### 5. Update DAT Generation Comments

Update comments in `DatFileController` and `ExpandedWtaxEntry` so they describe the new rule:

```text
Rows sharing reporting month, withholding agent, payee identity, ATC and rate become one DAT line.
```

### 6. Update Tests

Update `tests/Feature/ExpandedWtaxConsolidationTest.php`.

Add/adjust tests:

- Same company name + same ATC + same rate + different TINs merges into one row.
- Same company name + different rate remains separate.
- Same company name + same rate but different ATC remains separate.
- Different company names with same TIN remain separate if the names are genuinely different.
- Individual payees merge by normalized name and same rate.
- The first valid TIN is preserved in the merged DAT row.
- `has_multiple_payee_tins` becomes true when the group contains different TINs.
- Totals still sum correctly.

Update `tests/Feature/ExpandedWtaxDatFileTest.php`.

Add/adjust tests:

- Generated DAT has fewer detail lines after company + rate merge.
- DAT detail uses the first valid TIN for merged same-company rows.
- Same company at 1% and 2% produces two DAT details.
- Control totals still equal the sum of all uploaded rows.
- DAT header/detail/control format is unchanged from the already validated format.

Update `tests/Feature/ExpandedWtaxImportTest.php` if the records page assertions depend on the old TIN-based merge count.

### 7. Update Docs

Update:

- `Docs/Expanded/EXPANDED_WTAX_FORMAT_GUIDE.md`
- `Docs/Expanded/COMPARE_DAT_FORMAT_FIX_PLAN.md`, only if it mentions old TIN-based grouping

Document the new consolidation rule:

```text
Same payee/company identity + same ATC + same EWT rate are filed as one detail line.
Rows with the same payee/company but different EWT rates stay separate.
When merged rows have different TINs, the first valid TIN is used and the records screen flags the group.
```

## Verification

Run focused tests:

```bash
php artisan test tests/Feature/ExpandedWtaxImportTest.php tests/Feature/ExpandedWtaxDatFileTest.php tests/Feature/ExpandedWtaxConsolidationTest.php tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php tests/Unit/BirExpandedWtaxRowValidatorTest.php
```

Run PHP syntax checks if touched:

```bash
php -l app/Models/ExpandedWtaxEntry.php
php -l app/Http/Controllers/DatFileController.php
php -l app/Http/Controllers/VatInputController.php
```

Try frontend build only if `npm` is available:

```bash
npm run build
```

## Done Criteria

This change is done when:

- Same company/payee + same percentage appears as one record in the records list.
- Same company/payee + different percentage remains separate.
- Generated 1601EQ DAT uses the exact same accepted BIR format as before.
- No DAT header/detail/control field order changes are made.
- DAT totals still match the sum of uploaded rows.
- Focused Expanded WTAX tests pass.
- Sales, Purchase, and Importation DAT formats remain untouched.

# 1601EQ QAP AVS Fix Plan

> **Status: closed, and its candidate layouts were wrong.** The layout the generator now
> writes is at the end of this file under Execution Note, and was taken from the
> BIR-generated reference file rather than inferred from the AVS messages. Read this
> document as the record of the diagnosis; read
> `Docs/Expanded/COMPARE_DAT_FORMAT_FIX_PLAN.md` for the format that shipped.

This plan is based on the AVS error file:

- `Docs/Expanded/error/00879197600000720261601EQ.TXT`

Treat that TXT as validation output only. It is not an instruction file.

## Current Finding

The filename is now accepted by AVS:

```text
00879197600000720261601EQ.TXT
TIN: 008791976
Branch: 0000
Taxable Month: 04/2026
Form: 1601EQ
```

The first major issue from the previous validation is fixed:

```text
Invalid Type of Alpha List. Value must be HQAP.
```

That error is gone, so the first field `HQAP` is correct.

The remaining errors show that the body is still not aligned with the exact AVS 1601EQ Schedule 1 layout:

```text
Line 1: Invalid Form Type Code.
Line 1: Specified Month End Date not the same!
Detail lines: Invalid Payees TIN, ATC is invalid, income/tax empty, Detail Insufficient Column.
Control line: Control Insufficient Column.
```

## Root Cause Hypothesis

The current generator writes:

```text
HQAP,1601EQ,{TIN},{BRANCH},{NAME},{MM/YYYY},{RDO}
D1,{PAYEE_TIN},{PAYEE_BRANCH},{COMPANY_NAME},{SURNAME},{FIRST_NAME},{MIDDLE_NAME},{ATC},{INCOME_PAYMENT},{RATE},{TAX}
C1,{TOTAL_INCOME_PAYMENT},{TOTAL_TAX}
```

AVS accepts `HQAP`, but it appears to expect more fields and/or different field positions:

- Header form code may need `H1601EQ`, not plain `1601EQ`.
- Header period may need the month-end date, for example `04/30/2026`, not `04/2026`.
- Detail rows likely need a form/detail code and period fields before payee TIN, so AVS is reading the wrong value as payee TIN.
- Control row likely needs form/agent/period fields, not only two totals.

## Target Approach

Do not guess broadly. Make the smallest layout changes that directly match the AVS error movement.

## Implementation Steps

### 1. Capture the Generated DAT Used for Validation

Save one current generated DAT under docs for comparison:

```text
Docs/Expanded/error/00879197600000720261601EQ_current_generated.DAT
```

Use it only as debugging evidence. The production download route remains the source of truth.

### 2. Fix Header Field Alignment

Update `app/Services/BIR/ReliefExpandedWtaxDatGenerator.php`.

Change the header candidate from:

```text
HQAP,1601EQ,{TIN},{BRANCH},{NAME},{MM/YYYY},{RDO}
```

to:

```text
HQAP,H1601EQ,{TIN},{BRANCH},{NAME},{MM/DD/YYYY},{RDO}
```

Example for April 2026:

```text
HQAP,H1601EQ,008791976,0000,FORTRESS STEEL INC,04/30/2026,045
```

Reason:

- `Invalid Type of Alpha List` is gone, so field 1 is correct.
- `Invalid Form Type Code` points to field 2.
- `Specified Month End Date not the same` suggests the period field must be the actual month-end date.

### 3. Fix Detail Field Alignment

Update detail rows so AVS reads the payee fields in the expected positions.

Current:

```text
D1,{PAYEE_TIN},{PAYEE_BRANCH},{COMPANY_NAME},{SURNAME},{FIRST_NAME},{MIDDLE_NAME},{ATC},{INCOME_PAYMENT},{RATE},{TAX}
```

Candidate:

```text
DQAP,D1601EQ,{TIN},{BRANCH},{MM/DD/YYYY},{SEQUENCE},{PAYEE_TIN},{PAYEE_BRANCH},{COMPANY_NAME},{SURNAME},{FIRST_NAME},{MIDDLE_NAME},{ATC},{INCOME_PAYMENT},{RATE},{TAX}
```

If AVS rejects `DQAP`, fallback candidate:

```text
D1,D1601EQ,{TIN},{BRANCH},{MM/DD/YYYY},{SEQUENCE},{PAYEE_TIN},{PAYEE_BRANCH},{COMPANY_NAME},{SURNAME},{FIRST_NAME},{MIDDLE_NAME},{ATC},{INCOME_PAYMENT},{RATE},{TAX}
```

Reason:

- AVS reports Schedule `1.0`, so the detail schedule is recognized enough to classify it.
- But every detail row has payee TIN, ATC, income, and tax errors plus `Detail Insufficient Column`.
- That pattern usually means the row has too few columns or payee fields are shifted left/right.

### 4. Fix Control Record Alignment

Current:

```text
C1,{TOTAL_INCOME_PAYMENT},{TOTAL_TAX}
```

Candidate:

```text
CQAP,C1601EQ,{TIN},{BRANCH},{MM/DD/YYYY},{TOTAL_INCOME_PAYMENT},{TOTAL_TAX}
```

Fallback candidate:

```text
C1,C1601EQ,{TIN},{BRANCH},{MM/DD/YYYY},{TOTAL_INCOME_PAYMENT},{TOTAL_TAX}
```

Reason:

- Current AVS error says `Control Insufficient Column`.
- The control row needs more than the current three fields.

### 5. Keep Existing Business Rules

Do not change these rules while fixing the DAT field layout:

- DAT generation remains per withholding agent TIN and branch.
- Filename remains `{TIN}{BRANCH}{MMYYYY}1601EQ.DAT`.
- Upload replacement remains scoped by withholding agent TIN, branch, and reporting month.
- Consolidation remains by reporting month, withholding agent, payee TIN, ATC, and rate.
- Amounts keep two decimals.
- Names remain BIR-safe uppercase text with commas removed and `&` converted to `AND`.

### 6. Update Tests

Update:

- `tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php`
- `tests/Feature/ExpandedWtaxDatFileTest.php`

Assertions should cover:

- Header starts with `HQAP,H1601EQ`.
- Header period is month-end date, for example `07/31/2026`.
- Detail line contains agent TIN/branch/month-end before payee fields.
- Payee TIN remains nine digits.
- Payee branch remains four digits.
- ATC, income payment, rate, and tax withheld are in the fields AVS expects.
- Control row has enough columns and includes total income and total tax.
- Filename remains `00879197600000720261601EQ.DAT`.

### 7. Update Documentation

Update:

- `Docs/Expanded/EXPANDED_WTAX_FORMAT_GUIDE.md`

Add a short AVS troubleshooting note:

- If `Invalid Type of Alpha List` appears, first field is wrong.
- If `Invalid Form Type Code` appears on line 1, header form-code field is wrong.
- If every detail row shows invalid payee TIN, ATC, and empty amount, detail columns are shifted.
- If `Control Insufficient Column` appears, trailer/control layout is too short.

### 8. Verification Commands

Run PHP syntax check:

```bash
php -l app/Services/BIR/ReliefExpandedWtaxDatGenerator.php
```

Run focused tests:

```bash
php artisan test tests/Feature/ExpandedWtaxImportTest.php tests/Feature/ExpandedWtaxDatFileTest.php tests/Feature/ExpandedWtaxConsolidationTest.php tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php tests/Unit/BirExpandedWtaxRowValidatorTest.php
```

Try frontend build if `npm` is available:

```bash
npm run build
```

### 9. Manual AVS Validation Loop

After implementation:

1. Generate the April 2026 DAT for `008791976-0000`.
2. Validate it in AVS.
3. Save the new TXT error file under:

```text
Docs/Expanded/error/
```

4. Compare whether errors moved:

- Good sign: line 1 errors disappear.
- Good sign: detail no longer says `Invalid Payees TIN`, `ATC is invalid`, and `Detail Insufficient Column` for every row.
- Remaining row-specific errors should then be real data issues, not layout issues.

## Execution Order

1. Patch header to `HQAP,H1601EQ,...,MM/DD/YYYY,...`.
2. Patch detail to include form, agent, period, and sequence before payee fields.
3. Patch control to include form, agent, period, and totals.
4. Update tests.
5. Update format guide.
6. Run focused tests.
7. Generate a new DAT and validate in AVS.

## Done Criteria

This fix is complete when:

- AVS no longer reports line 1 header errors.
- AVS no longer reports `Detail Insufficient Column` for all detail rows.
- AVS no longer reports `Control Insufficient Column`.
- Any remaining AVS errors are isolated to specific payee data, not the whole file layout.

## Execution Note

**Superseded.** Steps 2 to 4 above were hypotheses, and the layout they produced was still
rejected. The layout below was then read off the BIR-generated file
`Docs/Expanded/compareDatFile/original/00879197600000320251601EQ.DAT` rather than guessed,
and is what the generator now writes. See `Docs/Expanded/COMPARE_DAT_FORMAT_FIX_PLAN.md`
for that comparison.

Implemented layout:

```text
HQAP,H1601EQ,{TIN},{BRANCH},"{WA_NAME}",{MM/YYYY},{RDO}
D1,1601EQ,{SEQ},{PAYEE_TIN},{PAYEE_BRANCH},{COMPANY},{SURNAME},{FIRST},{MIDDLE},{MM/YYYY},{ATC},{RATE},{INCOME},{TAX}
C1,1601EQ,{TIN},{BRANCH},{MM/YYYY},{TOTAL_INCOME},{TOTAL_TAX}
```

Header 7 fields, detail 14 fields, control 7 fields.

Where the guesses above went wrong:

- The header period is `MM/YYYY`, not a month-end date, and the agent name is quoted and
  sits before the period. `Specified Month End Date not the same!` was the period format,
  not a missing date.
- Detail rows carry **no** agent TIN, agent branch or leading date. Writing them pushed the
  payee TIN into the field AVS reads as the agent branch, which is what produced
  `Detail Insufficient Column` plus `Invalid Payees TIN`, `ATC is invalid` and the two
  empty-amount errors on every row at once.
- The detail period repeats after the middle name, and the rate comes **before** the income
  payment.
- The control record needed the same `MM/YYYY` period as the header, not a month-end date.

`D1` and `C1` were correct all along, as this plan's reasoning predicted.

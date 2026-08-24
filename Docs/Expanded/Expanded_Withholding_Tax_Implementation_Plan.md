# Expanded Withholding Tax — Implementation Plan

**Status: plan only. No code, config, migration or test has been modified.**

Primary reference: `Docs/1601EQ_Schedule_1_template.xls` (analysed in
`Docs/Expanded/BIR_Excel_Guide_Analysis.md`).
Governing prompt: `Docs/Expanded_Withholding_Tax_Implementation_Plan_Prompt.md`.

The prompt settles the three questions left open by the analysis report:

| Question | Answer given |
| --- | --- |
| 1601EQ or 1604E output? | **Keep 1604E.** No separate 1601EQ generator yet. |
| Consolidate on Month + TIN + ATC + Rate? | **Yes**, summing Income Payment and Tax Amount. |
| Which figure is authoritative? | **The file's.** Import must not derive either amount. |

---

## 1. Current files and components involved

| File | Role | Change |
| --- | --- | --- |
| `app/Imports/ExpandedWtaxImport.php` | Reads the workbook into `expanded_wtax_entries` | **Rewritten** — reads the 11-column BIR layout, no derivation |
| `app/Models/ExpandedWtaxEntry.php` | Storage + `toBirExpandedRow()` | Fillable/casts trimmed; consolidation helper added |
| `app/Services/BIR/BirExpandedWtaxRowValidator.php` | Per-row filability check | Extended; no logic removed |
| `app/Services/BIR/ReliefExpandedWtaxDatGenerator.php` | Builds the 1604E DAT | **Logic unchanged**; docblock updated |
| `app/Http/Controllers/VatInputController.php` | Upload (`import`) + records list (`index`) | Upload guard added; list query consolidated |
| `app/Http/Controllers/DatFileController.php` | `expandedPeriods()`, `downloadExpanded()` | Both consolidate before validating/generating |
| `app/Services/BIR/DashboardMetrics.php` | Dashboard counts and sums | Line **count** needs the consolidated figure |
| `resources/js/Pages/RecordEntry.jsx` | Upload form + expanded table | Reference column removed; Branch Code added; hint text |
| `database/migrations/2026_08_24_000000_create_expanded_wtax_entries_table.php` | Table definition | New migration drops 4 columns, adds 1 index |
| `config/bir.php` → `expanded_wtax` | ATC allow-list + rate→code mapping | Two of three keys become dead |
| `Docs/Dashboard_Analytics_Guide.md` | Accounting-facing doc | §3 no longer true once amounts come from the file |

Tests: `tests/Feature/ExpandedWtaxImportTest.php`, `tests/Feature/ExpandedWtaxDatFileTest.php`,
`tests/Feature/DashboardTest.php`, `tests/Unit/BirExpandedWtaxRowValidatorTest.php`,
`tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php`.

---

## 2. Existing upload flow

`POST /records/import` → `VatInputController::import()`

1. Validates `excel_file` (xlsx/xls/csv, ≤10 MB), `reporting_month` (date), `record_type`.
2. `$reportingPeriod = Carbon::parse($request->reporting_month)->endOfMonth()`.
3. For `record_type=expanded`, inside a `DB::transaction`:
   `ExpandedWtaxEntry::where('reporting_period', $reportingPeriod)->delete()` —
   **re-uploading replaces the month** — then `Excel::import(new ExpandedWtaxImport($period))`.
4. Redirects back with a flash message; any exception becomes `'Import failed: …'`.

`ExpandedWtaxImport` today: `headingRow() = 3`, `OnEachRow`, `WithHeadingRow`,
`SkipsEmptyRows`. Per worksheet line it reads supplier name + TIN, splits the name,
skips totals rows, then loops the five rate columns and **creates one row per non-zero rate
column**, deriving `income_payment` and resolving `atc_code` from config.

## 3. Existing validation flow

Validation is **not** part of import — `BirExpandedWtaxRowValidator` runs on *read*:

* `DatFileController::expandedPeriods()` — loads every expanded row, validates each, and
  reports `invalid_count` + the first 10 errors per month on the Generate DAT screen.
* `DatFileController::downloadExpanded()` — validates the month's rows and **refuses the
  download** if any row fails, naming up to 5 problems.

Current checks: TIN ≥ 9 digits and not `000000000`; branch code present; payee type
`company|individual`; `company_name` for a company; `last_name` + `first_name` for an
individual (middle optional); no comma or ampersand in any name field; `tax_rate` numeric
and > 0; `income_payment` and `tax_withheld` numeric; ATC in
`bir.expanded_wtax.allowed_atc_codes`, its configured rate matching the row rate and its
`payee_type` matching when present; and **`tax_withheld` = `ROUND(income_payment × rate ÷ 100, 2)`
within 0.01**.

That last check already **reports and never overwrites**, which is exactly what the prompt
asks for — no change needed to satisfy "must not silently replace uploaded amounts".

## 4. Existing database/model behaviour

`expanded_wtax_entries`, docblocked "One row per payee per ATC per month":

`id`, `reporting_period` (date), `transaction_date` (nullable date), `source_no` (string,
default `''`), `reference_no` (string, default `''`), `payee_name`, `payee_type` (default
`company`), `payee_tin`, `payee_branch_code` (4, default `'0000'`), `company_name`,
`last_name`, `first_name`, `middle_name` (all nullable), `atc_code` (nullable),
`tax_rate` decimal(5,2), `income_payment` decimal(15,2), `tax_withheld` decimal(15,2),
`source_row` (nullable uint), timestamps. Indexes on `reporting_period`, `payee_tin`, `atc_code`.

`toBirExpandedRow()` already omits `source_no`, `reference_no`, `transaction_date` and
`source_row`, so **the Reference/PV/SI exclusion is already true on the DAT side.**

## 5. Existing records query

`VatInputController::index()` — `ExpandedWtaxEntry::query()` with an optional search across
`payee_name` / `payee_tin` / `atc_code`, ordered `reporting_period` desc, then `payee_name`,
then `tax_rate`, paginated 15 per page as `expanded_page`. **No consolidation.**

## 6. Existing DAT generation flow

`downloadExpanded()` selects the month with `whereBetween('reporting_period', [start, end])`,
orders by `payee_name`, `tax_rate`, `id`, validates every row, then calls the generator with
`config('bir.companies.008791976') + ['branch_code' => '0000']`.

The generator writes `H1604E` (4 fields), one `D3` per row (16 fields) and `C3` (6 fields),
CRLF-terminated. It strips the payee TIN to 9 digits, pads the branch code to 4, quotes a
filled company name, caps it at 50 characters, and sums `tax_withheld` for the control total.
Its docblock records: *"Rows are not aggregated per payee: the reference file lists the same
payee twice under the same ATC, so each stored row becomes one detail line."*

**Measured impact of consolidating** on `Docs/Expanded/0087919760000123120251604E.dat`:
59 detail rows over 58 distinct keys — one duplicate, `000491813 | WC160 | 2.00`
(*PRUDENTIAL GUARANTEE*): 219,023.50 / 4,380.47 plus 1,988.50 / 39.77 merges to
**221,012.00 / 4,420.24**. The file becomes 58 detail lines; the control total is unchanged
at 241,326.68; and `ROUND(221012 × 2 ÷ 100, 2) = 4420.24`, so the merged row still passes the
validator exactly.

## 7. Existing amount calculation logic to be removed

All of it lives in `ExpandedWtaxImport`:

| To remove | Why |
| --- | --- |
| `const RATE_COLUMNS` and the `foreach` over it | The BIR layout has one row per rate already |
| `'income_payment' => round($withheld * 100 / $rate, 2)` | **The forbidden derivation.** Column I supplies it |
| `resolveAtc()` | Column H supplies the ATC |
| `splitName()` and `const CORPORATE_TOKENS` | Columns D–G supply the name parts |
| `parseDate()` | No date column in the BIR layout |
| `birReference()` | No Reference/PV/SI column |
| `const TOTAL_LABELS` | The template has no totals row |
| `formatTin()` (dash formatting) | Column B is 9 plain digits; store as provided |

Nothing is removed from the validator or the generator — the K-column relationship stays a
**check**, and the generator keeps its byte-verified formatting.

Config keys `bir.expanded_wtax.default_rate_codes` and `bir.expanded_wtax.payee_atc_overrides`
become **unreferenced** once `resolveAtc()` goes. `allowed_atc_codes` stays — it is what
catches a mistyped ATC.

> **Consequence worth stating:** the 15% rate is currently unfilable because no ATC is mapped
> for it. After this change the ATC comes from the file, so a 15% row is unfilable because its
> ATC is not in `allowed_atc_codes`. The block persists, for a better reason. To file 15%,
> add that ATC to `allowed_atc_codes` with `['rate' => 15]`.

---

## 8. Exact mapping: BIR Excel column → database field

Heading keys are what `WithHeadingRow` produces (`Str::slug($header, '_')`), with
`headingRow()` moving from **3 to 1**.

| # | Excel col | Header | Heading key | DB column | Notes |
| --- | --- | --- | --- | --- | --- |
| 1 | A | `Reporting_Month` | `reporting_month` | `reporting_period` | date or serial → `endOfMonth()` |
| 2 | B | `Vendor_TIN` | `vendor_tin` | `payee_tin` | store the 9 digits as provided |
| 3 | C | `branchCode` | `branchcode` | `payee_branch_code` | as provided; blank → `'0000'` |
| 4 | D | `companyName` | `companyname` | `company_name` | normalised, capped at 50 |
| 5 | E | `surName` | `surname` | `last_name` | normalised |
| 6 | F | `firstName` | `firstname` | `first_name` | normalised |
| 7 | G | `middleName` | `middlename` | `middle_name` | normalised, optional |
| 8 | H | `ATC` | `atc` | `atc_code` | uppercased, trimmed |
| 9 | I | `income_payment` | `income_payment` | `income_payment` | **stored as provided** |
| 10 | J | `ewt_rate` | `ewt_rate` | `tax_rate` | **stored as provided** (whole percent) |
| 11 | K | `tax_amount` | `tax_amount` | `tax_withheld` | **stored as provided** (the cell's computed value) |

Two internal columns stay, both composed *purely from the BIR columns above* and holding no
extra information:

| DB column | Derived from | Needed for |
| --- | --- | --- |
| `payee_type` | `companyName` filled → `company`; name parts filled → `individual` | The validator decides which name fields are required |
| `payee_name` | `company_name`, or `"LAST, FIRST MIDDLE"` | UI display, SQL ordering, and readable row errors |

`birName()` normalisation is **kept** — uppercase, `&` → ` AND `, punctuation → space, spaces
collapsed — because the DAT is comma-delimited and the validator rejects commas and
ampersands in name fields.

## 9. Fields that should no longer be stored or used

| Field | Verdict | Currently used at |
| --- | --- | --- |
| `reference_no` | **Drop** — this is the Reference/PV/SI | `RecordEntry.jsx:507`, `ExpandedWtaxImportTest:98`, `DashboardTest:131` |
| `source_no` | **Drop** — the PV/voucher number, same category | `RecordEntry.jsx:507`, `DashboardTest:130` |
| `transaction_date` | **Drop** — no such BIR column; the reporting month is the only date | `DashboardTest:129`, `ExpandedWtaxImportTest:97` |
| `source_row` | **Drop** — worksheet row number, diagnostic only | `ExpandedWtaxImportTest:319,346,359` |
| `payee_name` | **Keep**, documented as a derived display label | UI, ordering, error messages |
| `payee_type` | **Keep**, documented as derived from the filled name columns | Validator |
| `default_rate_codes` (config) | **Deprecate** — dead once ATC comes from the file | — |
| `payee_atc_overrides` (config) | **Deprecate** — same | — |

> **Decision A — strictness of the `payee_name` / `payee_type` call.** The prompt says only
> BIR-required fields should be stored. These two are computed from BIR columns and add no
> information, but they are not themselves BIR columns. Keeping them costs nothing and avoids
> touching ordering, pagination and error messages. The strict alternative is to drop both and
> compute them as model accessors, ordering by `COALESCE(company_name, last_name)` instead.
> **Recommended: keep them, documented as derived.** Say the word if you want them dropped.

## 10. Where consolidation should be applied

The prompt's flow is *Store → Consolidate → Display → Generate*, and it also says amounts must
be stored as provided. Those two together mean **consolidation happens on read, not on import**
— the table keeps the file's literal values and every reader consolidates through one shared
rule.

The precedent already in the codebase is `VatInput::scopeExcludingImportationMirrors()`, whose
whole reason for existing is that the dashboard and the DAT download must never disagree.
Consolidation gets the same treatment: **one method, three callers.**

```
ExpandedWtaxEntry::consolidate(Collection $rows): Collection
```

| Caller | Change |
| --- | --- |
| `DatFileController::downloadExpanded()` | Validate raw rows → consolidate → generate |
| `DatFileController::expandedPeriods()` | Consolidate before counting, so `records_count` equals the DAT's detail-line count |
| `VatInputController::index()` | Consolidate before paginating the records list |
| `DashboardMetrics` | Withholding **line count** must use the consolidated figure |

> **Decision B — consolidate on read or on import?** Read-time (above) is recommended: it
> stores exactly what the file says, and one rule serves every screen. The alternative is
> merging at import so the table holds one row per key — simpler downstream, but the stored
> amount is then a sum rather than the cell value, which sits against the Main Rule. I have
> planned for read-time.

> **Decision C — how the records list paginates.** Read-time consolidation means the list
> either (i) loads all expanded rows and paginates the consolidated collection with
> `LengthAwarePaginator`, or (ii) consolidates in SQL with `GROUP BY` + `SUM`, the way the
> sales list in the same controller already does at `VatInputController.php:60-83`.
> Option (i) keeps a single consolidation rule; option (ii) is faster but is a second
> implementation that must be kept in step. **Recommended: (i)**, since
> `expandedPeriods()` already loads every expanded row and documents that as acceptable.
> If (ii) is preferred, add a test asserting both paths return identical rows for a month.

## 11. How duplicate grouping will work

Group key — **exactly four fields, nothing else:**

```
reporting_period (as Y-m) | payee_tin (9 digits) | atc_code | tax_rate (2dp)
```

Normalise each part before keying, so formatting differences do not prevent a legitimate
merge: `reporting_period` to `Y-m`, `payee_tin` to its 9 digits, `atc_code` uppercased and
trimmed, `tax_rate` to `number_format($rate, 2, '.', '')`.

A different reporting month, TIN, ATC **or** rate produces a different key and therefore no
merge — including the case the prompt calls out explicitly, same company name with a different
TIN, since the name is not part of the key.

A `null` `atc_code` keys as its own value and never merges with a real code; such rows are
unfilable anyway and the validator reports them.

**Which row's non-summed fields survive:** the **first row in the group** supplies
`payee_name`, `payee_type`, `payee_branch_code` and the four name columns. If two rows share a
key but spell the payee differently, the first spelling wins and the other is dropped from the
DAT — worth a note in the UI hint, since it is a data-hygiene signal rather than an error.

## 12. How Income Payment and Tax Amount totals are calculated

```
consolidated.income_payment = round(Σ income_payment of the group, 2)
consolidated.tax_withheld   = round(Σ tax_withheld   of the group, 2)
```

Plain summation of stored values. No rate is applied, and nothing is re-derived — the sums are
the only arithmetic, which is what the Consolidation section asks for.

Negative rows are summed as-is; the generator already passes signs through for the reversal
case in the reference file, and the control total remains `Σ tax_withheld`, unchanged by
grouping.

**Ordering of validation vs consolidation.** Validate the **raw** rows, then consolidate. Each
raw row is checked against `ROUND(income × rate ÷ 100, 2)` within 0.01; merging preserves that
relationship (the PRUDENTIAL case merges to an exact match). Across many merged rows the
rounding can theoretically drift, so if the consolidated row is re-checked, scale the tolerance
to `0.01 × rows merged` rather than tightening the raw-row check.

## 13. Required UI changes — `resources/js/Pages/RecordEntry.jsx`

1. **Remove the Reference column** — header at `:464` and the cell at `:506-508` that renders
   `[item.source_no, item.reference_no]`. Reduce the empty-state `colSpan={8}` at `:513` to `7`.
2. **Add a Branch Code column** beside TIN. It is now file-driven and it changes the DAT, so it
   must be visible; today it is invisible and always `0000`.
3. **Rewrite the expanded-mode upload hint** at `:314-316`. It should state: the BIR
   1601EQ Schedule 1 layout with headers on **row 1** and 11 columns in order; amounts must be
   already computed; numbers in Number format, not comma format; and that re-uploading a month
   replaces it.
4. **Note consolidation** in that hint or above the table — rows sharing Reporting Month + TIN +
   ATC + Rate are shown and filed as one line.
5. **TIN display.** Storing 9 plain digits (per the template) means the table shows `007086184`
   instead of `000-227-599-000`. Either accept that — it is what the file holds — or add a
   small display formatter. **Recommended: accept it**, and revisit if Accounting objects.
6. The table can keep `min-w-[1100px]`: one column out, one in.

## 14. Required backend changes

**`app/Imports/ExpandedWtaxImport.php` — rewritten.** `headingRow()` returns 1. `onRow()` reads
the eleven keys, normalises names, derives `payee_type` from which name side is filled, composes
`payee_name`, and creates **exactly one** row per worksheet line with `income_payment`,
`tax_rate` and `tax_withheld` taken verbatim from columns I, J and K. Skips a row only when it
carries neither a TIN nor any name. Keeps `birName()`, `parseNumber()`, `value()`, `digits()`.
Deletes everything listed in §7.

**`app/Models/ExpandedWtaxEntry.php`.** Drop the four removed fields from `$fillable` and
`$casts`. Add the `consolidate()` helper from §10-11. `toBirExpandedRow()` is unchanged — it
already returns exactly the right twelve keys.

**`app/Http/Controllers/VatInputController.php`.** `import()` keeps its
delete-then-import transaction. `index()` consolidates the expanded list per Decision C.

**`app/Http/Controllers/DatFileController.php`.** `downloadExpanded()` — validate raw, then
consolidate, then generate, keeping the payee-order sort applied **after** consolidation so
detail lines stay in payee order. `expandedPeriods()` — consolidate before setting
`records_count`.

**`app/Services/BIR/ReliefExpandedWtaxDatGenerator.php`.** No logic change. Update the docblock:
the no-aggregation paragraph is now wrong, and it should record that rows arrive pre-consolidated
and that this makes the December 2025 file 58 detail lines instead of 59.

**`app/Services/BIR/DashboardMetrics.php`.** The withholding **count** must become the
consolidated count. `income_payment` and `tax_withheld` sums need no change — summation is
unaffected by grouping. Keep it driver-portable: count in PHP or via a `DISTINCT` subquery,
**not** a multi-column `COUNT(DISTINCT a, b, c, d)`, which is MySQL-only and would break the
sqlite test suite.

**`config/bir.php`.** Mark `default_rate_codes` and `payee_atc_overrides` deprecated in a
comment; do not delete yet, so a rollback does not need a config edit. Consider whether
`allowed_atc_codes` needs 15% and any WI codes Accounting actually files.

**`Docs/Dashboard_Analytics_Guide.md`.** §3 "Income Payments" and the §8 Quick Reference both
say income payments are back-computed as *Tax Withheld ÷ Rate*. After this change they are read
from the file. Both must be corrected, and the §3 note about inherited rounding removed.

## 15. Required validation changes

Additions to `BirExpandedWtaxRowValidator` (or to a new import-time pre-check — see below):

| Check | Behaviour |
| --- | --- |
| All 11 required headers present | **Fail the whole upload**, naming the missing columns |
| Every row's `Reporting_Month` is in the selected month | **Fail the upload**, naming the row — prevents silent misfiling |
| Exactly one of `companyName` / name-parts filled | Error when both or neither are filled |
| `ATC` present | Error when blank — it is no longer resolvable from config |
| `income_payment`, `ewt_rate`, `tax_amount` numeric | Already present; keep |
| `tax_amount` = `ROUND(income × rate ÷ 100, 2)` ± 0.01 | Already present; **keep as a report-only check** |

**Nothing gets rewritten by validation.** A row that fails is stored as uploaded and reported;
the DAT download stays blocked until it is fixed, exactly as today.

> **Where the two upload-level checks live.** Header and month checks want to fail the whole
> file, which `OnEachRow` cannot do cleanly. Add them as a small pre-flight read in
> `VatInputController::import()` (or a `WithValidation`/`onFailure` path) **before** the delete
> step, so a bad file never wipes a good month.

That last point matters: today the delete happens first inside the transaction. The transaction
rolls back on an exception, so the month survives — but making the header check explicit and
early makes the failure message useful instead of a raw `Import failed: …`.

## 16. Database migration requirements

One new migration, `drop_non_bir_columns_from_expanded_wtax_entries`:

```
down four columns:  transaction_date, source_no, reference_no, source_row
add one index:      (reporting_period, payee_tin, atc_code, tax_rate)   -- the consolidation key
```

* `payee_tin` stays `string`; only the value format changes (9 digits, not dashed). No schema
  change and the generator strips to digits either way, so old and new rows both file correctly.
* No renames. `tax_withheld` ↔ `tax_amount`, `tax_rate` ↔ `ewt_rate` and `reporting_period` ↔
  `Reporting_Month` are synonyms; renaming would touch the model, validator, generator,
  `DashboardMetrics`, two React pages, five test files and the Accounting guide for no
  functional gain. **The mapping in §8 is the documentation.** Flag if you would rather rename.
* `down()` restores the four columns as nullable so the migration is reversible in structure,
  though the dropped values are not recoverable.
* Also update `database/migrations/2026_08_24_000000_create_expanded_wtax_entries_table.php`'s
  docblock? **No** — leave shipped migrations alone; the new migration is the record of the change.

## 17. Backward compatibility concerns

1. **The old workbook stops uploading.** `Docs/Expanded/EXPANDED WTAX.xlsx` (headers on row 3,
   tax withheld inside rate columns) will no longer import. This is the intended consequence of
   removing the derivation. Accounting must move to the BIR template layout.
2. **Existing rows survive but are not equivalent.** The ~125 rows currently stored under
   `reporting_period = 2026-08-31` hold **back-computed** income payments and config-resolved
   ATCs. They will still validate, still consolidate and still generate — but they are not
   file-sourced. **Recommended: re-upload each affected month** from a BIR-format workbook after
   deploying. Re-upload already replaces a month, so no cleanup script is needed.
3. **Dropped column values are gone.** `transaction_date`, `source_no`, `reference_no` and
   `source_row` are unrecoverable after the migration. None ever reached a DAT. If you want them
   kept for audit, say so and the migration becomes a no-op with the fields simply unused —
   though that leaves the table wider than the BIR format requires.
4. **The reference DAT no longer matches byte-for-byte.** `tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php`
   compares against `Docs/Expanded/0087919760000123120251604E.dat`. The generator itself does not
   change, so that unit test — which feeds it hand-built rows — still passes. Only a
   consolidation-fed comparison would differ, by the one PRUDENTIAL line.
5. **Dashboard figure changes.** Withholding **income payments** will change for any re-uploaded
   month, because they stop being back-computed. The **line count** will drop wherever rows merge.
   Tax withheld totals are unaffected.
6. **`php artisan migrate` is a state change** against the dev MySQL database — listed in the
   checklist for you to run, not run as part of implementation.

## 18. Required tests

### New — `tests/Feature/ExpandedWtaxBirFormatImportTest.php`

Needs a **new fixture workbook in the BIR layout**; build it from
`Docs/1601EQ_Schedule_1_template.xls` and store it under `Docs/Expanded/` next to the existing
samples.

| # | Test | Asserts |
| --- | --- | --- |
| 1 | BIR-format Excel imports successfully | Row count and a flash success |
| 2 | Income Payment stored as provided | Cell value, byte-equal, no rounding drift |
| 3 | EWT Rate stored as provided | `1.00` stays `1.00`, not `0.01` |
| 4 | Tax Amount stored as provided | Column K's **computed value**, not recomputed |
| 5 | Import does not recalculate Income Payment | A row whose K ≠ I×J/100 keeps **both** values as uploaded and is reported, not fixed |
| 6 | Import does not recalculate Tax Amount | Same row, from the other side |
| 7 | Reference/PV/SI is not required | A sheet with no such column imports cleanly |
| 8 | Only required BIR data is stored | The four dropped columns do not exist on the table |
| 9 | Payee type is derived from the filled columns | `companyName` → company; name parts → individual |
| 10 | A month mismatch fails the upload and keeps the existing month | Pre-flight check; no rows deleted |
| 11 | A missing required column fails the upload by name | Header check |

### New — `tests/Feature/ExpandedWtaxConsolidationTest.php`

| # | Test | Asserts |
| --- | --- | --- |
| 12 | Same Month + TIN + ATC + Rate merges | 2 rows → 1 |
| 13 | Same TIN, different ATC does not merge | 2 rows stay 2 |
| 14 | Same TIN, different rate does not merge | 2 rows stay 2 |
| 15 | Same company name, different TIN does not merge | 2 rows stay 2 |
| 16 | Different reporting month does not merge | 2 rows stay 2 |
| 17 | Consolidated Income Payment total is correct | Sum, to the centavo |
| 18 | Consolidated Tax Amount total is correct | Sum, to the centavo |
| 19 | The records list shows consolidated rows | Inertia prop count |
| 20 | The Generate DAT screen's `records_count` equals the DAT detail lines | Keeps the two screens honest |

### New in `tests/Feature/ExpandedWtaxDatFileTest.php`

| # | Test | Asserts |
| --- | --- | --- |
| 21 | The 1604E DAT uses consolidated stored values | One `D3` line for the merged key, carrying the summed amounts |
| 22 | The control total is unchanged by consolidation | `C3` equals `Σ tax_withheld` either way |
| 23 | Detail lines stay in payee order after consolidation | Sort applied post-merge |

### Rewritten or deleted

`tests/Feature/ExpandedWtaxImportTest.php` — 13 tests against the old layout:

| Test | Action |
| --- | --- |
| `…stores_one_row_per_rate_column_and_derives_the_income_payment` | **Delete** — the behaviour is being removed |
| `…splits_an_individual_payee_and_picks_the_wi_code` | **Delete** — no splitting, no resolution |
| `…a_company_name_containing_a_comma_stays_a_company` | **Delete** — no comma parsing |
| `…an_unmappable_rate_is_stored_with_no_atc…` | **Replace** — a blank ATC in the file is stored null and reported |
| `…a_per_payee_override_beats_the_default_code…` | **Delete** — config overrides become dead |
| `…skips_the_totals_row_and_blank_payees` | **Adapt** — no totals row; keep the blank-row case |
| `…normalises_names_to_bir_safe_text` | **Keep** — `birName()` survives |
| `…truncates_a_long_company_name_to_fifty_characters` | **Keep** |
| `…keeps_a_negative_reversal` | **Keep** |
| `…re_uploading_a_month_replaces_it_rather_than_doubling_the_tax` | **Keep** |
| `…expanded_rows_stay_out_of_the_vat_tables` | **Keep** |
| `…the_records_page_lists_the_imported_expanded_rows` | **Adapt** — Reference column gone, consolidated rows |
| `…imports_the_sample_workbook_and_only_blank_tin_rows_are_unfilable` | **Rewrite** against the new BIR-format fixture; drops its `source_row` lookups |

Fixture updates: `tests/Feature/DashboardTest.php:126-142` (`withholding()` sets
`transaction_date`, `source_no`, `reference_no`) and
`tests/Feature/ExpandedWtaxDatFileTest.php:30-52` (`entry()` sets all four). Plus a
`DashboardTest` case asserting the withholding **line count** is the consolidated one.
`tests/Unit/BirExpandedWtaxRowValidatorTest.php` gains cases for the new checks.

## 19. Recommended implementation order

1. **Settle Decisions A, B and C** (§9, §10) — they change what gets written.
2. **Migration** — drop the four columns, add the consolidation index. Run `php artisan migrate`.
3. **Model** — trim `$fillable`/`$casts`; add `consolidate()`. Unit-test the grouping in
   isolation, before any caller uses it.
4. **Importer** — rewrite for the 11-column layout. Build the BIR-format fixture workbook first,
   so the tests have something to read.
5. **Validator** — add the ATC-present and one-name-side checks.
6. **Upload pre-flight** — header and reporting-month checks in `VatInputController::import()`,
   ahead of the delete.
7. **Read paths** — `downloadExpanded()`, `expandedPeriods()`, `index()`, then `DashboardMetrics`.
   Do the DAT path first: it is the one with a reference artefact to compare against.
8. **Generator docblock** — record the new aggregation rule and the 59→58 consequence.
9. **UI** — `RecordEntry.jsx`: Reference column out, Branch Code in, hint text rewritten.
10. **Config** — deprecation comments; add any missing ATC codes Accounting needs.
11. **Docs** — correct `Dashboard_Analytics_Guide.md` §3 and §8.
12. **Full suite** — `php artisan test`. Known baseline is 118 passed / 21 failed, the 21 being
    pre-existing Breeze `Auth/*` and `ProfileTest` failures unrelated to this work.
13. **Re-upload** the affected months from BIR-format workbooks, then reconcile the Dashboard
    against a generated DAT.

---

## Implementation Checklist

**Decide first**

- [x] **A** — keep `payee_name` / `payee_type` as derived columns, or drop them for accessors?
      *Kept as stored columns: the records list searches them in SQL.*
- [x] **B** — consolidate on read (planned) or at import?
      *On read, through `ExpandedWtaxEntry::consolidate()`. The uploaded rows stay as filed.*
- [x] **C** — paginate the records list from the consolidated collection, or `GROUP BY` in SQL?
      *From the collection, via a manual `LengthAwarePaginator`. `GROUP BY` could not normalise
      dashed TINs or lowercase ATC codes the way the key does.*
- [x] Confirm the December 2025 reference DAT may go from 59 to 58 detail lines.
- [x] Confirm the old workbook layout may stop importing.
- [x] Confirm `transaction_date` / `source_no` / `reference_no` / `source_row` may be dropped.
- [x] Confirm no column renames (`tax_withheld` stays, not `tax_amount`).

**Build**

- [x] Migration: drop 4 columns, add the `(reporting_period, payee_tin, atc_code, tax_rate)` index
- [ ] `php artisan migrate` — **not run.** A state change against the dev MySQL database that
      destroys the four dropped columns' data. Yours to run.
- [x] `ExpandedWtaxEntry`: trim `$fillable` / `$casts`, add `consolidate()`
- [x] Build the BIR-format fixture workbook from the template
      (`Docs/Expanded/EXPANDED_WTAX_BIR_FORMAT_SAMPLE.xlsx`, rebuildable with
      `php Docs/Expanded/build-bir-format-sample.php`)
- [x] Rewrite `ExpandedWtaxImport` — heading row 1, 11 columns, **no derivation**
- [x] `BirExpandedWtaxRowValidator`: ATC-present, one-name-side; keep the K-formula as report-only
- [x] `VatInputController::import()`: header + reporting-month pre-flight, before the delete
- [x] `DatFileController::downloadExpanded()`: validate raw → consolidate → generate
      *No re-sort after consolidating: ordering stays in SQL, because `tax_rate` reads back as a
      decimal string where `'10.00'` sorts before `'2.00'`.*
- [x] `DatFileController::expandedPeriods()`: consolidated `records_count`
- [x] `VatInputController::index()`: consolidated, paginated list
- [x] `DashboardMetrics`: consolidated line count, kept driver-portable
- [x] Generator docblock: new aggregation rule + the 59→58 note
- [x] `RecordEntry.jsx`: Reference column out, Branch Code in, hint rewritten
      *`colSpan` stays **8**, not 7: one column left and one arrived, so the count is unchanged.
      A "*n* rows merged" badge was added too, or the list would simply show fewer rows than
      were uploaded and read as missing data.*
- [x] `config/bir.php`: deprecate `default_rate_codes` and `payee_atc_overrides`; review ATC list
- [x] `Docs/Dashboard_Analytics_Guide.md`: §3 and §8 — income payments are no longer back-computed

**Verify**

- [x] New import cases against the BIR format — **15, in the existing
      `ExpandedWtaxImportTest`** rather than a second `ExpandedWtaxBirFormatImportTest`: the old
      file's cases all had to be rewritten anyway, and two files covering one upload path would
      have gone stale in opposite directions.
- [x] New: `ExpandedWtaxConsolidationTest` (9 cases)
- [x] New in `ExpandedWtaxDatFileTest` (4 cases: summed detail line, control total unchanged,
      payee order survives, listed count equals detail lines)
- [x] Rewrite/delete the old `ExpandedWtaxImportTest` cases per §18
- [x] Update the `DashboardTest` and `ExpandedWtaxDatFileTest` fixtures
      *Both were passing four dropped columns to `create()`, which mass assignment silently
      discarded. Added `DashboardTest::test_the_withholding_card_counts_consolidated_filing_lines`.*
- [x] `php artisan test` — **140 passed / 21 failed**, the 21 being the pre-existing Breeze
      `Auth/*` and `ProfileTest` failures. No new failures.
- [ ] Re-upload affected months; reconcile the Dashboard against a generated DAT — **yours**,
      and only after the migration.

**Implemented and tested, except the two unticked boxes: `php artisan migrate` and the
re-upload. Both change live data, so both are left with you.**

# BIR Excel Guide — Structure Analysis and System Mapping

Reference file: `Docs/1601EQ_Schedule_1_template.xls`
Scope: **Expanded Withholding Tax only.** No code changed — this is the report requested
before implementation.

---

## 1. What the file actually is

| Property | Value |
| --- | --- |
| Format | BIFF8 `.xls`, 41,472 bytes |
| Sheets | `_1601EQ_1` (data) and `ReadMe` (instructions) |
| Author / last saved by | `mhar` / `CieloBabe`, 2023-07-24 |
| Data range on `_1601EQ_1` | `A1:K3` — headers on row 1, two sample rows |

The sheet name `_1601EQ_1` means **form 1601EQ, Schedule 1** — the schedule of payees that
accompanies the quarterly remittance return for creditable expanded withholding tax.

**Stated plainly, so the decision is informed:** the `ReadMe` sheet points the full guide at
`https://bir-excel-uploader.com/excel-file-to-bir-dat-format/how-to/`. This template is
distributed by that third-party Excel→DAT converter service; it is not a document published
by the Bureau of Internal Revenue. It is still a usable contract for the column layout — and
it is treated as the source of truth for that below, as instructed — but it describes what
that converter expects as input, not a BIR-issued file specification.

---

## 2. The required structure — 11 columns, exact order

Read from the file itself, including each cell's data type and number-format code.

| # | Col | Header (verbatim) | Sample row 2 | Data type | Number format |
| --- | --- | --- | --- | --- | --- |
| 1 | A | `Reporting_Month` | `44197` → 01/01/2021 | numeric (date serial) | `mm/dd/yyyy` |
| 2 | B | `Vendor_TIN` | `111222333` | numeric | `General` |
| 3 | C | `branchCode` | `1` | numeric | `General` |
| 4 | D | `companyName` | *(blank)* | — | `General` |
| 5 | E | `surName` | `WOOLLEY` | string | `General` |
| 6 | F | `firstName` | `ALIZA` | string | `General` |
| 7 | G | `middleName` | `PENN` | string | `General` |
| 8 | H | `ATC` | `WC158` | string | `General` |
| 9 | I | `income_payment` | `300000` | numeric | `0.00` |
| 10 | J | `ewt_rate` | `1` | numeric | `0.00` |
| 11 | K | `tax_amount` | `=ROUND(I2*J2/100,2)` | **formula** | `0.00` |

Second sample row: `44197`, `222333444`, `1`, blank, `SLATER`, `KIER`, `SHANNON`, `WC160`,
`300000`, `2`, `=ROUND(I3*J3/100,2)`.

### `ReadMe` sheet — the four rules it states

1. *"One Template for EACH Reporting Month"*
2. *"In a Quarter, you will need 3 templates."*
3. *"Do not edit sheetname"*
4. *"All number data should be on Number format, NOT in COMMA format"* (under the heading
   **IMPORTANT NOTICE**)

---

## 3. The ten checklist points, answered from the file

**Required columns and exact order** — 11 columns, A→K, exactly as tabled above. No other
columns exist on the sheet.

**Data types and number formats** — `Reporting_Month` is a genuine Excel date, not text.
`Vendor_TIN` and `branchCode` are plain numbers on `General`. The three money/rate columns
are numeric on `0.00`. Names and ATC are text. The `ReadMe` reinforces this: numbers must
be in Number format, never comma-formatted — so thousands separators in `income_payment`
are explicitly disallowed.

**Company vs Individual payees** — decided by which name columns are filled, and they are
mutually exclusive:

* **Company:** `companyName` filled; `surName` / `firstName` / `middleName` left blank.
* **Individual:** `surName` + `firstName` (+ optional `middleName`) filled; `companyName`
  left blank.

Both sample rows are individuals — `companyName` blank, the three name parts filled. There
is no separate "payee type" column; the type is inferred from the filled columns alone.

**Reporting Month format** — a date formatted `mm/dd/yyyy`. Serial `44197` is
**01/01/2021**, i.e. the **first day** of the month. Both sample rows repeat the same value,
consistent with the `ReadMe` rule that one template covers one month.

**Vendor TIN and Branch Code formatting** — TIN is **9 plain digits, no dashes and no
branch suffix** (`111222333`). Branch code is a **plain number** (`1`), *not* zero-padded to
`0001`.

**ATC formatting** — a plain uppercase alphanumeric code, no spaces or punctuation:
`WC158` at 1%, `WC160` at 2%. Notably, both samples pair `WC…` (company-series) codes with
**individual** payees, so the template does not treat the WC/WI split as tied to payee type.

**Income Payment, EWT Rate and Tax Amount formatting** — all three at `0.00`.
`ewt_rate` is a **whole-number percent**: `1` means 1%, `2` means 2% — not `0.01`.
`tax_amount` is **not entered** — it is a formula:

```
tax_amount = ROUND(income_payment × ewt_rate ÷ 100, 2)
```

So in this template **income payment is the authoritative figure** and tax withheld is
derived from it.

**Columns that do not exist** — worth stating explicitly, because it settles a rule you set:
there is **no Reference / PV / SI column and no transaction-date column** anywhere on the
sheet. The template confirms your instruction to keep those out of the upload and the DAT.

---

## 4. How the 11 columns map onto what the system already emits

The system's generated detail record (verified byte-for-byte against
`Docs/Expanded/0087919760000123120251604E.dat`) has 16 fields. Five are record/agent
metadata; the remaining eleven are the per-payee payload:

| Excel col | Header | DAT detail field | Field # |
| --- | --- | --- | --- |
| A | `Reporting_Month` | period (`m/d/Y`) | 5 |
| B | `Vendor_TIN` | payee TIN | 7 |
| C | `branchCode` | payee branch code | 8 |
| D | `companyName` | company name (quoted when filled) | 9 |
| E | `surName` | last name | 10 |
| F | `firstName` | first name | 11 |
| G | `middleName` | middle name | 12 |
| H | `ATC` | ATC code | 13 |
| I | `income_payment` | income payment | 14 |
| J | `ewt_rate` | tax rate | 15 |
| K | `tax_amount` | tax withheld | 16 |

**The field set and their order already match the guide exactly, one for one.** The only
DAT-side additions are the record tag (`D3`), the form code, the withholding agent's TIN and
branch, and a running sequence number — none of which belong in an Excel upload sheet.

### Field-by-field against the storage and pipeline

| Excel column | `expanded_wtax_entries` column | Current handling |
| --- | --- | --- |
| `Reporting_Month` | `reporting_period` (date) | Normalised to **end of month** on import and again in the generator |
| `Vendor_TIN` | `payee_tin` | Validator requires exactly 9 digits and rejects `000000000` ✔ |
| `branchCode` | `payee_branch_code` (default `'0000'`) | Generator **pads to 4 digits** — differs from the template's plain `1` |
| `companyName` | `company_name` | Quoted in the DAT when filled; capped at 50 chars |
| `surName` / `firstName` / `middleName` | `last_name` / `first_name` / `middle_name` | Validator requires last + first for individuals; middle optional ✔ |
| `ATC` | `atc_code` (nullable) | Must exist in `config('bir.expanded_wtax.allowed_atc_codes')`, and its rate must match the row's rate ✔ |
| `income_payment` | `income_payment` decimal(15,2) | **Back-computed** on import — see conflict 3 |
| `ewt_rate` | `tax_rate` decimal(5,2) | Stored as whole percent (`1.00`, `2.00`) — same convention as the template ✔ |
| `tax_amount` | `tax_withheld` decimal(15,2) | Validator already enforces the template's exact formula, within 0.01 ✔ |
| *(no column)* | `source_no`, `reference_no`, `transaction_date`, `source_row` | ~~Stored for traceability but already excluded from `toBirExpandedRow()`~~ — **dropped** by `2026_08_24_120000_drop_non_bir_columns_from_expanded_wtax_entries_table`, since the guide has no column for any of them |

Withholding agent identity comes from `config('bir.companies.008791976')`
(`DatFileController::downloadExpanded()`), with `branch_code` defaulted to `'0000'` there —
this was the one open question from the earlier pass; it is not read from `bir.tin`.

---

## 5. What already complies with the guide

* **Column set and order** — identical, as shown in §4.
* **The `tax_amount` formula** — `BirExpandedWtaxRowValidator` already computes
  `round(income_payment × rate / 100, 2)` and rejects any row off by more than 0.01. The
  template's `=ROUND(I2*J2/100,2)` is already the enforced rule.
* **Rate convention** — whole-number percent on both sides.
* **ATC mapping** — `config/bir.php` maps 1% → `WC158` and 2% → `WC160` flat across payee
  types, which is exactly what the template's two samples show (WC codes on individual
  payees).
* **Reference / PV / SI exclusion** — already satisfied end to end.
* **TIN shape** — 9 digits, no dashes, already validated.

---

## 6. Three conflicts that need your decision before any code changes

> **All three were decided and are now implemented.** This section is left as written because
> it is the record of what the choice was and what it cost; the answers, per
> `Docs/Expanded_Withholding_Tax_Implementation_Plan_Prompt.md`, were:
>
> 1. **New upload format, existing 1604E output.** The template defines the upload; the DAT
>    stays 1604E. No separate 1601EQ generator was built.
> 2. **Consolidation applies**, and the byte-for-byte match with the December 2025 reference
>    file may be broken by the one line described below. `ExpandedWtaxEntry::consolidate()` is
>    the single rule, read by the records list, the Generate DAT screen's count, the download
>    and the dashboard.
> 3. **Neither amount is derived — Option B, template layout only.** Both `income_payment` and
>    `tax_amount` are read from the workbook and stored as stated. The rate-column reader is
>    gone, so the old workbook can no longer be uploaded and past months need re-uploading in
>    the BIR format.

### Conflict 1 — the guide is for 1601EQ; the system generates 1604E

The template is **1601EQ Schedule 1**, and its `ReadMe` describes a quarterly rhythm ("in a
quarter you will need 3 templates"). The system generates **1604E** — the annual information
return — writing `H1604E` / `D3,1604E` / `C3,1604E` records and a filename ending `1604E.dat`,
matching the client's filed reference file for December 2025.

These are two different BIR forms with different DAT record structures. The per-payee payload
is the same eleven fields, but the form code, the header/control records and the filing
cadence are not.

**Decision needed:** does the Excel template define a **new upload format** for data that
still comes out as the existing 1604E DAT? Or is a **second, separate 1601EQ generator**
wanted alongside it? I have not switched the form code, because doing so would silently break
the byte-for-byte match against the filed reference file.

### Conflict 2 — the consolidation rule contradicts a byte-verified decision

You require consolidating on **Reporting Month + TIN + ATC + EWT Rate**, summing Income
Payment and Tax Amount, never merging across a different ATC or rate.

The generator's current behaviour is documented as the opposite, and deliberately so:
*"Rows are not aggregated per payee: the reference file lists the same payee twice under the
same ATC, so each stored row becomes one detail line."*

I measured the actual impact on the filed reference DAT:

* 59 detail rows across **58** distinct `TIN | ATC | rate` keys
* **exactly one** duplicate key — `000491813 | WC160 | 2.00`,
  *PRUDENTIAL GUARANTEE AND ASSURANCE INC*:

  | | Income payment | Tax withheld |
  | --- | --- | --- |
  | Row A | 219,023.50 | 4,380.47 |
  | Row B | 1,988.50 | 39.77 |
  | **Merged** | **221,012.00** | **4,420.24** |

So consolidation changes that file from 59 detail lines to **58**. The control total is
unaffected (241,326.68 either way, since the sums are preserved), and the merged row still
satisfies the template's formula exactly — `ROUND(221012 × 2 ÷ 100, 2) = 4420.24` — so it
passes the validator cleanly.

**Decision needed:** confirm that the byte-for-byte match with the December 2025 reference
file may be broken by one line. Your rule is arithmetically sound and the numbers reconcile;
it simply produces a different — and defensible — file than the one previously filed. Once
confirmed, consolidation belongs in `DatFileController::downloadExpanded()`, which is where
rows are selected and ordered, so the on-screen listing and the download stay consistent.

### Conflict 3 — the derivation runs in the opposite direction

| | Authoritative figure | Derived figure |
| --- | --- | --- |
| **BIR template** | `income_payment` (typed) | `tax_amount = ROUND(income × rate ÷ 100, 2)` |
| **`ExpandedWtaxImport`** | tax withheld (read from the 1%/2%/5%/10%/15% columns) | `income_payment = round(withheld × 100 ÷ rate, 2)` |

The company's current workbook (`Docs/Expanded/EXPANDED WTAX.xlsx`) has headers on row 3 —
`No | Date | Supplier Name | TIN | Reference | (1%) | (2%) | (5%) | (10%) | (15%) | Total` —
and holds the **tax withheld** inside each rate column, with no income payment column at all.
That is why the importer works backwards, and why the Dashboard guide flags income payments
as a back-computed figure.

Adopting the template as the upload format reverses this: income payment becomes the typed
input and tax withheld becomes derived. That is the better direction — it removes the
rounding artefact entirely — but it means the **existing workbook layout can no longer be
uploaded unchanged**.

**Decision needed:** which of these?

* **A — Add the template as a second accepted layout.** Detect the 11-column sheet and read
  it forwards; keep the current rate-column reader for the existing workbook. Nothing already
  in use breaks.
* **B — Replace the upload with the template layout only.** Cleaner and matches the guide
  exactly, but Accounting must restructure their workbook, and re-uploading past months would
  need the new layout.

Option A is the safer default and is what I would implement unless you say otherwise.

---

## 7. Two smaller formatting differences to settle at the same time

**Reporting month day-of-month.** The template's sample holds the **1st** (01/01/2021); the
filed reference DAT holds the **last** day (12/31/2025), and the system normalises to end of
month in both the importer and the generator. Both conventions denote the same month. The
safe rule: accept **any** day in `Reporting_Month` on upload and treat it as that month,
leaving the end-of-month output as it is, since that matches the artefact actually filed.

**Branch code padding.** The template shows a plain `1`; the DAT carries `0001`-style
4-digit padding, which the reference file requires. Keep the padding on output and accept the
unpadded number on input.

---

## 8. Recommended sequence, pending your approval

1. Confirm Conflict 1 (form code), Conflict 2 (consolidation vs the reference file) and
   Conflict 3 (option A or B).
2. Add the 11-column template reader to `ExpandedWtaxImport` — same order, forward
   derivation, tolerant of any day in `Reporting_Month`, unpadded branch code accepted.
3. Apply consolidation on `reporting_period + payee_tin + atc_code + tax_rate` in
   `DatFileController::downloadExpanded()`, summing `income_payment` and `tax_withheld`, and
   in `expandedPeriods()` so the record count shown matches the lines generated.
4. Update the generator's docblock to record the new aggregation rule and why it now differs
   from the December 2025 reference file.
5. Extend `tests/Feature/` coverage: an 11-column upload; a consolidation case proving two
   rows with the same TIN/ATC/rate merge; and a case proving rows with the **same TIN but a
   different ATC or rate do not merge.**

No file under `app/`, `config/`, `database/` or `routes/` has been modified for this analysis.

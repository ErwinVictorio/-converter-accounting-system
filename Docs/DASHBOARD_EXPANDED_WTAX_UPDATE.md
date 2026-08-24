# Dashboard Update — Expanded Withholding Tax (1604E)

**Date:** 2026-08-24
**Scope:** Dashboard analytics only. No change to DAT generation, uploads, or the
`expanded_wtax_entries` schema.

## Goal

The dashboard reported three modules — Sales, Purchases and Importation. The
Expanded Withholding Tax module (1604E) had shipped but was invisible on the
overview. This update adds it as a **fourth, separately reported source**.

## The constraint that shaped the design

From `Docs/Expanded/EXPANDED_WTAX_DAT_PLAN.md`:

> Do not merge Expanded WTAX with VAT input totals.

Expanded withholding tax is remitted on **1604E**. It is **not** creditable
against output VAT, so it must never reach the VAT breakdown or the Total VAT
card. Folding it in would understate VAT payable by the whole month's
withholding.

That rule is now enforced in three places:

1. `DashboardMetrics::vatBreakdown()` reads only `sales`, `purchases` and
   `importation` — never `expanded`.
2. The docblock above that method says why, so the "one more input" refactor is
   warned off at the point someone would make it.
3. `DashboardTest::test_expanded_withholding_tax_stays_out_of_the_vat_figures()`
   snapshots every VAT prop, inserts a withholding row, and asserts the props are
   byte-identical.

## Backend — `app/Services/BIR/DashboardMetrics.php`

### New source

`SOURCES` gained a fourth entry. Every figure comes from columns that already
exist; nothing was added to the schema.

| Module | Table | Month column | Amount | Second figure |
| --- | --- | --- | --- | --- |
| Sales | `sales_vatsinputs` | `reporting_period` | `net_amount` | `output_vat` |
| Purchases | `vat_inputs` | `date_uploaded` | `total_purchases` | `input_vat` |
| Importation | `importation_entries` | `tax_month` | `total_landed_cost` | `vat_payable` |
| **Expanded** | **`expanded_wtax_entries`** | **`reporting_period`** | **`income_payment`** | **`tax_withheld`** |

Because the whole service is driven off `SOURCES`, the new module was picked up
by `totals()`, `monthlySeries()`, `availableMonths()`, `hasAnyData()` and
`baseQuery()` without touching those methods.

### Internal rename: `vat` → `tax`

The second figure of each source used to be keyed `vat`. The expanded module's is
**tax withheld**, not VAT, so calling it `vat` would have made the forbidden
merge look like the obvious next step. The key is now `tax` throughout
`SOURCES` and `totals()`.

This is private to the class — `DashboardMetrics` is consumed only by
`App\Http\Controllers\Dashboard`, and no Inertia prop key changed as a result.

### Prop contract

Added:

| Prop | Meaning |
| --- | --- |
| `stats.expanded.amount` | Income payments for the month |
| `stats.expanded.records` | Withholding lines (one per payee, per ATC) |
| `stats.expanded.tax_withheld` | Tax withheld for the month |
| `stats.expanded.previous_amount` | Same three figures for the preceding month, |
| `stats.expanded.previous_records` | used for the month-over-month badges |
| `stats.expanded.previous_tax_withheld` | |
| `summary.total_expanded` | Income payments, for the monthly summary strip |
| `transactions[].expanded` | Line count per month, Jan–Dec |
| `amounts[].expanded` | Income payments per month, Jan–Dec |

Changed behaviour of existing props:

- `months` — the tax month picker now also reaches months that only hold
  withholding rows.
- `hasAnyData` — a database whose only records are withholding lines no longer
  shows the "No BIR data yet" banner.

Unchanged, deliberately: `summary.vat.*` and `stats.vat.*`.

`stats.expanded` is the only source that carries its second figure in `stats`.
The VAT modules report theirs through `summary.vat` instead, and shipping them
twice would invite the two to drift.

## Frontend — `resources/js/Pages/Dashboard.jsx`

### The 1604E panel

A new full-width card sits **below** the four KPI cards, titled
**Expanded Withholding Tax (1604E · <month>)**, holding three figures:

1. **Tax Withheld** — leads, because that is the amount remitted. Neutral
   month-over-month badge.
2. **Income Payments** — the gross the tax was computed from.
3. **Withholding Lines** — the line count, in a neutral slate tone since it is
   not money.

### Why it is not a fifth KPI card

Two reasons, both deliberate:

- **Layout.** The content column is `max-w-7xl`. Five cards across leaves ~233px
  each, which truncates any seven-figure peso value in *all five* cards — a
  regression for the three existing ones, whose figures routinely run to seven
  digits.
- **Meaning.** A card between *Total Importations* and *Total VAT* reads as part
  of the VAT position. It is not. The visual separation states that 1604E is a
  different return.

### Charts

`MODULES` (the three VAT-return modules) still drives the KPI cards.
`SERIES = [...MODULES, EXPANDED]` drives **both charts and the shared legend**,
so a fourth pink series (`#db2777`) now appears in Monthly Transactions and
Monthly Amount Trend.

The amount series carries **income payments, not tax withheld** — an amount
trend has to compare like with like against sales, purchases and landed cost.
The tax withheld is reported on the panel instead.

### Other UI changes

- Monthly Summary's amount row gained **WTAX Income Payments** (now four across).
  The VAT row is untouched.
- The empty-chart message and the "No BIR data yet" banner copy now mention
  withholding records.
- `SummaryFigure` accepts an optional `badge`, so the panel can show
  month-over-month deltas. Existing call sites pass nothing and are unaffected.

## Incidental fix

The Monthly Summary strip was passing **raw numbers** to `SummaryFigure`, so it
rendered `224000` and `0` instead of `₱224,000.00` and `₱0.00` — contradicting
the rule stated in that file's own comments. All seven figures now go through
`peso()`. This was pre-existing and unrelated to the withholding work; it was
fixed because it sits in the section this update edits.

## The panel's "View records" link

It points at plain `/records`, not `/records?record_type=expanded`.

`RecordEntry.jsx` keeps its record type in **local form state** defaulting to
`purchase`; it does not read the query string. A `?record_type=` link would
therefore land silently on the purchase table. Making that page deep-linkable is
a separate, small change if it is wanted.

## Tests — `tests/Feature/DashboardTest.php`

New fixture `withholding(string $taxMonth, array $overrides = [])`. Like the
`sale()` helper, it uses **day 28** — a February fixture built on day 30 rolls
into March and silently breaks month assertions.

Added:

| Test | What it protects |
| --- | --- |
| `the_withholding_card_reports_income_payments_and_tax_withheld` | All six panel figures, including both baselines |
| `expanded_withholding_tax_stays_out_of_the_vat_figures` | The plan's constraint, by before/after snapshot |
| `withholding_records_alone_count_as_data` | The banner cannot contradict the panel below it |

Extended: `it_reports_each_module_for_the_selected_month`,
`both_charts_cover_all_twelve_months_with_a_series_per_module`,
`the_monthly_summary_covers_all_four_modules` (renamed from `..._three_...`),
`the_month_picker_reaches_months_from_every_module`,
`an_empty_database_reports_zeroes_rather_than_failing`.

### Portability

The suite runs on sqlite `:memory:`. The new source uses the same portable
idiom as the rest of the service — `COUNT`/`SUM`/`COALESCE` plus `whereBetween`
on a month range, with month grouping done in PHP — and **not** MySQL's
`DATE_FORMAT`. See `Docs/Expanded/EXPANDED_WTAX_DAT_PLAN.md` and the class
docblock for why that matters.

## Verification

| Check | Result |
| --- | --- |
| `php artisan test --filter=DashboardTest` | 15 passed, 233 assertions |
| `php artisan test` (full suite) | 118 passed, 21 failed |
| `npm run build` | Clean, 6.90s |
| Live dev MySQL, August 2026 | 125 lines · ₱9,186,973.40 income payments · ₱127,776.44 withheld · VAT breakdown all zero |

The 21 failures are pre-existing and unrelated: Breeze's `Auth/*` and
`ProfileTest` suites test routes this app does not expose (`/register`,
`/profile`, `/forgot-password`, `/verify-email`, `/confirm-password`). The
baseline before this update was 115 passed / 21 failed; 115 + 3 new tests = 118.

## Operational notes

- `2026_08_24_000000_create_expanded_wtax_entries_table` is already applied on
  the dev database (batch 9). **The dashboard now queries that table on every
  request**, so any environment without the migration will 500 on the home page,
  not just on the withholding pages. Run `php artisan migrate` before deploying.
- The 125 rows currently loaded carry `reporting_period = 2026-08-31`, i.e.
  **August 2026**. The dashboard defaults to the previous month, so the panel
  reads ₱0.00 until August is selected in the Tax Month picker. If that data was
  meant to be July, re-upload it with July as the reporting month.

## Deliberately not done

- Expanded WTAX is **not** in the Total VAT card, `summary.vat`, or
  `stats.vat` — see the constraint above.
- No new database columns, no schema change, no change to the 1604E generator or
  the upload path.
- No "recent withholding entries" table. The Recent Importation Entries table
  stays as the only per-row listing on the dashboard; withholding rows are
  browsable on `/records`.

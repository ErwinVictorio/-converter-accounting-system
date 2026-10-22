# Summary Cards for All Record Pages

Date: 2026-10-22
Status: Implemented on 2026-10-22. Backend regression suites and the production frontend build passed. Interactive browser verification remains outstanding because no browser automation tool is available in this session.

## 1. Goal and scope

Add a summary section above each Record table, following the supplied Expanded WTAX screenshot and using the existing reusable `resources/js/Components/Cards/SummaryCard.jsx` for every metric card and highlighted total.

The four Record pages are:

| Page | Route | Controller |
| --- | --- | --- |
| Purchase | `/records/purchases` | `RecordController::purchases` |
| Sales | `/records/sales` | `RecordController::sales` |
| Expanded WTAX | `/records/expanded-wtax` | `RecordController::expandedWtax` |
| Importation | `/records/importations` | `ImportationController::records` |

“All records” means these four Record listings. Entry/upload screens, master-data lists, dashboard, and Generate DAT are outside this change.

Totals must cover the entire filtered listing across all pages. They must match the amounts that the existing table would display if every matching row were visible.

## 2. Current implementation findings

- `routes/web.php` already provides all four routes; no new route is needed.
- `SummaryCard.jsx` supports `title`, `value`, `icon`, `image`, `description`, `trend`, `trendValue`, and `variant`. Its current large dashboard styling and hover effects do not match the compact reference exactly.
- `WithholdingTaxRateSummary.jsx` currently uses `Card` and custom metric markup, not `SummaryCard`. It already implements the reference layout, rate colors, empty message, and peso formatting.
- Expanded receives `withholdingTaxRateSummary`, calculated server-side from the full consolidated collection. Preserve its existing contract and calculations.
- Purchase uses `PurchaseAmountPresenter::forRecord()` to resolve different historical amount formats. Summing raw database columns would disagree with the table.
- Purchase starts from `VatInput::excludingImportationMirrors()` and applies filing-company scope before search/month filters. Retain that exact membership.
- Sales uses `SalesSiCmConsolidator`, including SI/CM netting and zero-rated behavior, before pagination. Summary amounts must use that consolidated output.
- Importation currently builds its filtered query directly inside `DataTableQuery::query()`. Extract that query into a variable so pagination and aggregation can use separate clones.
- `tests/Feature/RecordPagesTest.php` already covers filtering, isolation, mirror exclusion, Expanded rate totals, and pagination invariance.

## 3. Visual design

Use this shared structure above `RecordTableShell`:

```text
Desktop / wide viewport
+---------------------------------------------------+ +--------------------+
| Icon  Page Summary                                | | Highlighted total  |
| Description referencing current filters           | |                    |
| [Metric] [Metric] [Metric] [Metric]                 | | PHP amount         |
| [Additional metrics wrap when needed]             | | Scope description  |
| Amounts cover all filtered records across pages.  | |                    |
+---------------------------------------------------+ +--------------------+

Existing table heading, search/filter controls, and table
```

- Retain the reference's white rounded panel, subtle border/shadow, pastel metric surfaces, blue highlighted total, and generous spacing.
- Outer layout: one column by default; `2xl:grid-cols-[minmax(0,1fr)_18rem]` on wide screens, matching Expanded.
- Inner metrics: one column on phones, two from `sm`, four from `lg`. Additional metrics wrap; do not squeeze six into one row.
- Stack the highlighted total below the metrics panel on smaller screens.
- Use tabular currency digits, two decimals, thousands separators, and signed peso values such as `-₱1,234.56`.
- Long values must remain accessible and must not force horizontal page scrolling. Allow wrapping for totals; provide the full accessible value if compact amounts are truncated.
- Keep existing filters in their current toolbar. The summary reflects those filters; it introduces no separate filter state.
- Keep cards informational: no click action, trend arrows, or invented comparison percentages.
- Use subtle/static styling for the records appearance and respect reduced-motion preferences.

## 4. Exact cards and amount sources

### Purchase Summary

| Position | Label | Source per matching record |
| --- | --- | --- |
| Metric | Purchase Local | `display_purchase_local_amount` |
| Metric | Services | `display_services_amount` |
| Metric | Others | `display_others_amount` |
| Highlight | Total Purchase Amount | `display_calculated_total` |

All four fields come from `PurchaseAmountPresenter::forRecord()`. Sum each displayed row amount after its existing rounding, then accumulate cents.

Do not label the three buckets uniformly as VAT: historical records with `uses_vat_bucket_amounts = false` have different display semantics. Use the existing table labels and add: “Amounts follow the table's existing display rules. The total is calculated separately per record.” The highlighted total is not necessarily the sum of the three cards.

Do not restore the removed Purchase Imported column as a summary card. Do not include linked Importation mirror rows. Preserve unlinked purchase records according to the existing scope, regardless of `is_imported` alone. Preserve adjustments and negative balances exactly as currently displayed.

### Sales Summary

| Position | Label | Consolidated field |
| --- | --- | --- |
| Metric | Exempt Sales | `exempt_sales` |
| Metric | Zero-Rated Sales | `zero_rated_sales` |
| Metric | Taxable Net of VAT | `taxable_net_of_vat` |
| Metric | Output VAT | `output_vat` |
| Highlight | Total Gross Amount | `gross_amount` |

Sum the full filtered `$salesRows` collection before pagination. Preserve the consolidator's SI/CM signs and rounding. Do not subtract CM a second time or recalculate VAT from the grand total. The highlighted figure follows the existing Gross Amount column, even when source data means it does not equal a sum of the other cards.

### Expanded WTAX

Keep the existing behavior and labels:

- Section: “Tax Withheld Summary by Rate”.
- One metric per actual rate in the filtered result, sorted numerically.
- Label: “[rate]% Tax Withheld Total”.
- Highlight: “Total Tax Withheld”.
- Preserve blue 1%, green 2%, amber 5%, rose 10%, and neutral colors for other rates.
- Preserve fractional rates, zero/negative totals, and inclusion of validation-flagged records.
- Sum stored consolidated tax withheld once; do not recompute income multiplied by rate or multiply by `merged_rows`.

Only migrate the presentation to the shared components. Keep `withholdingTaxRateSummary.rates` and `total_tax_withheld` unchanged.

### Importation Summary

| Position | Label | Stored field |
| --- | --- | --- |
| Metric | Total Landed Cost | `total_landed_cost` |
| Metric | Dutiable Value | `dutiable_value` |
| Metric | Charges | `charges` |
| Metric | Exempt Amount | `exempt` |
| Metric | Taxable Goods | `taxable_goods` |
| Highlight | Total VAT Payable | `vat_payable` |

These are separate column totals, not additive pieces of one invented grand total. Do not sum VAT percentages or derive VAT using a fixed rate. Aggregate only matching `ImportationEntry` records, never their `VatInput` mirrors. Distinct stored entries with the same Import Entry No. still contribute separately, as they do in the listing.

## 5. Reusable component design

### Extend the existing SummaryCard

Keep all existing props and their default behavior. Add optional, backward-compatible props:

| Proposed prop | Purpose |
| --- | --- |
| `appearance = "default"` | `default`, `recordMetric`, or `recordTotal` visual treatment |
| `tone = "blue"` | Explicit blue/emerald/amber/rose/neutral palette for record metrics |
| `className` | Layout sizing without duplicating card internals |

Keep `variant` compatible with the existing warning/default behavior. Use explicit Tailwind class maps for tones so build-time class discovery works. `recordMetric` provides the compact icon-left reference layout; `recordTotal` provides the highlighted blue amount. Preserve image/icon handling for existing callers.

The record appearances must handle a formatted zero explicitly, preserve minus signs, avoid decorative overlap, and disable hover lift/rotation. Use appropriate text semantics for metric titles and values without generating duplicate page-level headings.

### Add RecordsSummarySection

Proposed file: `resources/js/Components/Records/RecordsSummarySection.jsx`.

Props: `title`, `description`, `icon`, `metrics`, `total`, `empty`, `loading`, and optional `footer`.

- `metrics`: stable `{ key, title, value, icon, tone, description? }` descriptors.
- `total`: `{ title, value, description }` descriptor.
- Own only the section heading, outer panel, responsive grid, footer, and empty/loading presentation.
- Render every amount through `SummaryCard`; do not introduce a second metric-card implementation.
- Page adapters own labels and field mappings; the reusable card knows nothing about taxes, routes, or database fields.
- Keep `WithholdingTaxRateSummary` as a small adapter mapping existing rate data to this shared section.

Extend `resources/js/Components/Records/format.js` with a reusable signed peso formatter if needed. Preserve its existing exports and table formatting behavior.

## 6. Server data contracts and calculations

Add these Inertia props; all money values are fixed two-decimal strings, without currency symbols or commas:

```text
purchaseSummary:
  record_count: integer
  purchase_local_total: "0.00"
  services_total: "0.00"
  others_total: "0.00"
  total_purchase_amount: "0.00"

salesSummary:
  record_count: integer (consolidated table rows)
  exempt_sales_total: "0.00"
  zero_rated_sales_total: "0.00"
  taxable_net_of_vat_total: "0.00"
  output_vat_total: "0.00"
  gross_amount_total: "0.00"

importationSummary:
  record_count: integer
  total_landed_cost: "0.00"
  dutiable_value_total: "0.00"
  charges_total: "0.00"
  exempt_total: "0.00"
  taxable_goods_total: "0.00"
  vat_payable_total: "0.00"
```

`record_count` distinguishes no matches from matching records whose amounts net to zero; it is not an additional required card. Expanded already uses its rate array to represent emptiness.

Use a small `app/Services/RecordSummaryService.php` for the new aggregate mappings and cents accumulation. Reuse `PurchaseAmountPresenter`; do not move or alter its business rules. Leave the established Expanded calculation in place to limit regression risk.

### Purchase query flow

1. Build the existing company-scoped, mirror-excluding query with the same search and period conditions.
2. Clone before any `DataTableQuery` operation, pagination, or limit.
3. For SQL-sorted listings, iterate matching records in bounded batches and run the presenter per record; do not load unrelated adjustment histories for summary computation.
4. For display-field sorting, reuse the complete already-presented collection for aggregation and sorting rather than load it twice.
5. Keep page-specific adjustment-history decoration as it is.
6. Return `purchaseSummary` alongside existing props.

### Sales query flow

Calculate the summary directly from `$salesRows` after consolidation and before `DataTableQuery::collection()`. Do not introduce another raw-record query or a second consolidation pass.

### Importation query flow

Extract `$entriesQuery` with the exact existing month and search predicates. Use separate clones for the paginator and a single aggregate query (`COUNT` plus `COALESCE(SUM(...), 0)` over decimal columns, with ordering removed). Normalize results to two-decimal strings. Verify model/schema precision before implementing; if any field is not a suitable decimal column, use rounded per-row cents accumulation so the summary still matches display rounding.

Do not use floating-point running sums for money. For presenter/consolidator outputs, convert each already-rounded value to integer cents, sum, then format once. Do not add caches initially: correctness after edits is more important than avoiding these bounded read operations.

## 7. Filters, refresh, and states

| Page | Membership filters to preserve |
| --- | --- |
| Purchase | `search`, `period` using `date_uploaded`, filing `company` including `unassigned` |
| Sales | `search` including document number, `period` using `reporting_period` |
| Expanded | `search`, `period`, agent TIN/branch, `report_type`, `tax_rate` when supplied |
| Importation | `search` including existing numeric/comma-normalized matching, `tax_month` |

- Sorting, page number, page size, column visibility, and selection do not change summary membership.
- Preserve the existing Sales behavior where document search filters source rows before consolidation; do not silently expand to every document for the matched customer.
- Initial response must include both table and summary.
- Search/month/company navigation must refresh both in the same Inertia response. Inspect filter helpers and `useInertiaDataTable` for partial-prop restrictions before wiring.
- After successful edit, adjustment, DM application, or deletion affecting a listing, refresh its summary with its table. Audit actual mutation callbacks; a flash-only reload is insufficient where amounts changed.
- Cancel/close/failure must not change stored values or locally fabricate new totals.
- During relevant navigation, mark the section busy and show a subtle “Updating summary…” indicator; retain the previous committed values until replacement. Clear pending state on finish, cancel, and failure. Ignore unrelated navigation events and clean up listeners.
- Empty results: show “No records match the current filters.” and a zero highlighted total. Fixed-schema pages may keep zero-valued metrics visible; Expanded keeps an empty rate grid as today.
- Matching records that total zero remain a normal populated summary.
- Request failure must not replace a valid summary with fabricated zeros; retain the last response and existing error feedback.

## 8. Files to change during implementation

| File | Work |
| --- | --- |
| `resources/js/Components/Cards/SummaryCard.jsx` | Backward-compatible record appearances |
| `resources/js/Components/Records/RecordsSummarySection.jsx` | New shared section |
| `resources/js/Components/Records/WithholdingTaxRateSummary.jsx` | Adapter using shared section/card |
| `resources/js/Components/Records/format.js` | Shared signed peso formatter if needed |
| `resources/js/Pages/Records/PurchaseRecords.jsx` | Purchase summary mapping and placement |
| `resources/js/Pages/Records/SalesRecords.jsx` | Sales summary mapping and placement |
| `resources/js/Pages/Records/ImportationRecords.jsx` | Importation summary mapping and placement |
| `resources/js/Pages/Records/ExpandedWtaxRecords.jsx` | Only integration/loading adjustments if required |
| `app/Services/RecordSummaryService.php` | New listing-only aggregations |
| `app/Http/Controllers/RecordController.php` | Purchase and Sales summary props |
| `app/Http/Controllers/ImportationController.php` | Shared filtered query and Importation summary prop |
| `tests/Feature/RecordPagesTest.php` | Summary contracts and membership regression tests |

Touch filter/navigation hooks only if inspection shows a missing summary refresh or shared loading signal. No migration, new endpoint, package installation, or route change is expected.

## 9. Implementation order

1. Confirm worktree changes and inspect any applicable repository instructions before editing.
2. Add server aggregation and regression cases for Purchase, Sales, and Importation; preserve Expanded's existing response.
3. Extend `SummaryCard` with opt-in record appearances and create `RecordsSummarySection`.
4. Migrate Expanded first and compare against the supplied screenshot, including rate colors and total placement.
5. Add the other three page mappings above their table shells.
6. Audit filter navigation, partial reloads, mutation success callbacks, and pending/error states.
7. Run focused feature/business-rule/DAT tests and the production frontend build.
8. Verify all pages in a browser on desktop/mobile, review the diff, and report results and any unverified checks.

## 10. Validation plan

### Automated correctness

- More matching rows than one page: summary includes off-page values and stays identical across pages, page sizes, and sort directions.
- Search and month filters individually and together produce exact expected totals; clearing filters restores totals.
- Purchase company isolation: all companies, one company, and unassigned; linked mirrors excluded everywhere while legitimate unlinked rows remain.
- Purchase presenter variants: true/false/null marker, raw VAT values, inferred values, adjusted records, negative values, and fractional-cent boundaries. Assert independently calculated expected values rather than deriving expectations using the same service.
- Sales: multiple source documents consolidate once; SI and positive/negative CM input forms follow existing netting; zero-rated and exempt values remain correct; document searches retain existing membership.
- Importation: distinct duplicate entry numbers both count; numeric search with commas and month filtering match the table; mixed rates use stored VAT payable; no mirror double counting.
- Empty result and populated zero-net result are distinct; all amount props keep two-decimal strings.
- Existing Expanded tests remain green: numeric rate ordering, fractional rates, merged lines, invalid records, negative/zero totals, full filtered coverage.
- Auth protection and existing table props remain unchanged.

Suggested commands from the repository root (use an available PHP/Node environment):

```powershell
php artisan test tests/Feature/RecordPagesTest.php
php artisan test tests/Feature/PurchaseCompanySelectionTest.php tests/Feature/PurchaseAdjustmentMergeTest.php tests/Feature/PurchaseDmUploadTest.php
php artisan test tests/Unit/SalesSiCmConsolidatorTest.php tests/Feature/SalesZeroRatedImportTest.php
php artisan test tests/Feature/ImportationEntryTest.php tests/Feature/ImportationUploadTest.php
php artisan test tests/Unit/ReliefPurchaseDatGeneratorTest.php tests/Unit/ReliefSalesDatGeneratorTest.php tests/Unit/ReliefImportationDatGeneratorTest.php
php artisan test tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php tests/Unit/ReliefExpandedWtaxAnnualDatGeneratorTest.php
php artisan test tests/Feature/ImportationDatFileTest.php tests/Feature/ExpandedWtaxDatFileTest.php
npm run build
git diff --check
```

The current `package.json` has no lint/test script; do not report those as run. Tests and browser checks above are implementation acceptance work, not checks already completed by this planning document.

### Browser verification

- Inspect all four pages at approximately 375px, 768px, 1280px, and 1536px widths.
- Compare Expanded with the reference: title, icon, four common rate colors, spacing, footer, and highlighted total.
- Verify Purchase's three metrics, Sales's four metrics, and Importation's five wrapping metrics.
- Check large values, negative currency, zero, empty search, additional/fractional rates, and long labels.
- Confirm summary and table update together after filtering and successful mutations; paging does not change amounts.
- Check keyboard focus remains usable, no accidental interactive cards, visible busy state, and reduced-motion behavior.
- Inspect console and request failures. Check default `SummaryCard` appearance in any existing consumers discovered at implementation time.

## 11. Boundaries and completion criteria

This is a read-only Record listing/UI feature. Preserve database contents, upload validation, calculation rules, adjustment behavior, DAT record membership, serialization, field ordering, rounding, and generation logic. The existing Purchase mirror exclusion remains listing-specific; do not propagate it to DAT queries as part of this work.

Complete when all four pages use the existing reusable `SummaryCard`, match the reference layout family, show correct full-filter totals, remain stable across table navigation, refresh after relevant successful mutations, and pass the applicable checks. A shared-card visual regression can be rolled back independently of the additive summary props; no data rollback should be necessary.

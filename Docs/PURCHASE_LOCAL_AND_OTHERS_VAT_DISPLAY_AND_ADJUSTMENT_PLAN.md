# Purchase Local and Others VAT Display and Adjustment Plan

## Status

Implemented in code on September 15, 2026. Applying the new migration to the configured local MySQL database remains pending because that connection returned `SQLSTATE[HY000] [1045]` for `root@localhost` with no password.

This plan extends the completed Services raw-VAT behavior to the **Purchase Local** and **Others** columns. The change covers Purchase Records, Purchase View Info, broker adjustments, adjustment history, and Undo/Delete while preserving the existing BIR/DAT taxable-base values.

The existing Services implementation remains the reference behavior. This plan does not change Expanded WTAX, Sales, Importation, or Purchase Imported behavior.

## Requested Business Rule

For VAT-bucket Purchase workbooks, the application must show the exact consolidated Excel VAT amounts in all three visible local buckets:

```text
Purchase Local display = consolidated raw Purchase Local VAT
Services display       = consolidated raw Services VAT
Others display         = consolidated raw Others VAT
```

The visible Purchase Total continues to run once after consolidation:

```text
Visible Total = round(
    (
        consolidated Purchase Local VAT
        + consolidated Services VAT
        + consolidated Others VAT
    ) / 0.12,
    2
)
```

Purchase Imported remains excluded from this visible Total under the existing Purchase Records rule.

## Order of Operations

```text
Validate every source row
    -> resolve Supplier identity
    -> reject identity or amount-mode conflicts
    -> form the valid consolidation group
    -> sum each raw VAT bucket separately
    -> apply the visible Total formula once
```

Do not convert each source value for display and then add those converted display values. The saved BIR/DAT path may continue its existing per-row taxable-base conversion and rounding independently.

## Example

Assume valid rows for one supplier and period consolidate to:

```text
Purchase Local VAT = 600.00
Services VAT       = 120.00
Others VAT         =  60.00
                      ------
Raw VAT total      = 780.00
```

Required Purchase Records display:

```text
Purchase Local =   600.00
Services       =   120.00
Others         =    60.00
Total          = 780.00 / 0.12 = 6,500.00
```

The corresponding stored taxable bases remain separate:

```text
Purchase Local stored base = 600.00 / 0.12 = 5,000.00
Services stored base       = 120.00 / 0.12 = 1,000.00
Others stored base         =  60.00 / 0.12 =   500.00
```

## Current Confirmed Foundation

The completed Services work already provides most of the required data path:

- `vat_inputs.uses_vat_bucket_amounts` identifies VAT-bucket, explicit taxable-base, and legacy records.
- `vat_inputs.purchase_local_vat_amount` already retains raw Purchase Local VAT.
- `vat_inputs.services_vat_amount` already retains raw Services VAT.
- `vat_inputs.others_vat_amount` already retains raw Others VAT.
- `VatInputImport` already sums those raw values separately when qualifying rows consolidate.
- `PurchaseAmountPresenter` already uses all three raw VAT amounts to calculate the visible Total.
- The Purchase Records page now uses the presenter for Purchase Local, Services, Others, and Total.
- The adjustment ledger now retains exact raw VAT for Purchase Local, Services, and Others. Legacy history rows retain the documented base-to-VAT fallback.

No new raw columns are needed on `vat_inputs`. New ledger columns are required if adjustments and reversals must be exact.

## Display Rules by Record Type

Use `PurchaseAmountPresenter` as the single backend source for Purchase Records and Purchase View Info.

### VAT-bucket records

When `uses_vat_bucket_amounts === true`:

```text
display_purchase_local_amount = purchase_local_vat_amount
display_services_amount       = services_vat_amount
display_others_amount         = others_vat_amount
display_calculated_total      = round(
    (purchase_local_vat_amount + services_vat_amount + others_vat_amount) / 0.12,
    2
)
```

If a raw field is unexpectedly null on a marked VAT-bucket row, temporarily infer that one display value as `round(stored base * 0.12, 2)` and set `display_amounts_inferred = true`. A corrected workbook re-upload is the authoritative repair.

### Explicit BIR/taxable-base records

When `uses_vat_bucket_amounts === false`:

- display the stored `purchase_local`, `services`, and `others` values directly
- calculate Total as the sum of those stored bases
- never divide an explicit taxable-base value by `0.12` again
- set `display_amounts_inferred = false`

### Historical legacy records

When `uses_vat_bucket_amounts === null`:

- infer Purchase Local display as `round(purchase_local * 0.12, 2)`
- infer Services display as `round(services * 0.12, 2)`
- infer Others display as `round(others * 0.12, 2)`
- retain visible Total as the sum of the stored bases rather than recomputing it from separately rounded inferred VAT values
- set `display_amounts_inferred = true`

Do not backfill historical rows automatically because their original workbook amount mode cannot be proven from the stored bases alone.

## Purchase Records and View Info

Extend the presenter response with:

- `display_purchase_local_amount`
- `display_others_amount`

Keep the existing:

- `display_services_amount`
- `display_calculated_total`
- `display_amounts_inferred`

In `PurchaseRecords.jsx`:

- Purchase Local must render `display_purchase_local_amount`
- Services must continue rendering `display_services_amount`
- Others must render `display_others_amount`
- Total must continue rendering `display_calculated_total`
- React must format these values only; it must not perform another VAT conversion

In Purchase View Info, use the same four presenter values. Do not expose the raw/base duplication as extra technical fields unless a separate request asks for it.

## Upload Validation and Consolidation

Preserve the current validation and grouping boundary:

- valid Purchase workbook type and reporting period
- valid Supplier BIR identity and address data
- Supplier resolution by the current TIN/name priority
- same resolved Supplier identity/name
- same upload period
- same `is_imported` classification
- non-adjusted ordinary Purchase target only
- Importation mirror exclusion
- rejection of conflicting base TINs within one would-be group
- rejection of mixed VAT-bucket and explicit taxable-base formats

For a valid VAT-bucket group, independently sum:

- `purchase_local_vat_amount`
- `services_vat_amount`
- `others_vat_amount`
- the existing converted taxable-base and derived BIR fields

The current import/consolidation calculations should not be rewritten merely for the display change. They should be audited and protected with broader tests covering Purchase Local and Others.

## Broker Adjustment Input Rules

Purchase Local and Others must follow the same user-facing contract as Services.

### Form fields

Use raw VAT inputs for:

- `purchase_local_vat_amount`
- `services_vat_amount`
- `others_vat_amount`

Purchase Imported keeps its current stored-base input semantics and remains outside this extension.

The form must:

- show each available raw VAT balance
- enforce each maximum against that raw VAT balance
- convert each entered local VAT bucket once when showing its taxable-base contribution
- reset all three VAT inputs after a successful adjustment
- show backend validation errors under the correct input

The adjusted-record calculation must treat the three raw VAT entries as:

```text
Purchase Local contribution = entered Purchase Local VAT / 0.12
Services contribution       = entered Services VAT / 0.12
Others contribution         = entered Others VAT / 0.12
```

Preserve the current Purchase Imported contribution and the existing stored-total semantics of the adjustment workflow. Do not confuse that adjustment total with the Purchase Records visible Total, where Purchase Imported is excluded.

## Backend Adjustment Conversion

Validate the three new raw VAT inputs as nullable, numeric, non-negative values.

Inside the existing locked database transaction:

1. Read the source's exact raw balance, using `stored base * 0.12` only as a legacy fallback.
2. Reject an entered raw value greater than its available raw balance.
3. Convert each entered raw value exactly once using `round(raw VAT / 0.12, 2)`.
4. Transfer the converted values through the existing stored fields:
   - `purchase_local`
   - `services`
   - `others`
5. Transfer the exact raw values through:
   - `purchase_local_vat_amount`
   - `services_vat_amount`
   - `others_vat_amount`
6. Recalculate the same derived source/target BIR totals currently maintained by the adjustment flow.
7. Preserve row locks, target-selection priority, and transaction rollback behavior.

For compatibility with old integrations or tests, the backend may temporarily accept the old `purchase_local` and `others` request keys as taxable-base inputs when the corresponding new VAT key is absent. New UI requests must use only the explicit `*_vat_amount` keys.

## Adjustment Ledger and Undo/Delete

Add nullable decimal fields to `purchase_adjustments`:

- `purchase_local_vat_amount`
- `others_vat_amount`

Keep existing `purchase_local`, `services`, and `others` ledger fields in taxable-base units. Together, each history row must record both sides of the transfer:

| Bucket | Raw VAT history | Stored-base history |
| --- | --- | --- |
| Purchase Local | `purchase_local_vat_amount` | `purchase_local` |
| Services | `services_vat_amount` | `services` |
| Others | `others_vat_amount` | `others` |

Undo/Delete must restore both representations in integer centavos and in the same transaction before deleting the adjusted target.

History completeness must verify all three raw VAT aggregates when the adjusted target contains authoritative raw values. Do not delete or partially reverse a target when either its base history or raw history is incomplete.

For pre-migration history rows, infer the missing raw ledger amount as `round(history base * 0.12, 2)` only as a legacy fallback. Do not bulk backfill old history without evidence from the original source transaction.

## Database Changes

A new forward migration is used rather than editing the already-applied Services migration.

Add to `purchase_adjustments`:

```text
purchase_local_vat_amount decimal(14,2) nullable
others_vat_amount         decimal(14,2) nullable
```

Update `PurchaseAdjustment::$fillable` and casts. No additional `vat_inputs` fields are needed.

## Implemented Areas

Implementation files:

- new migration for the two `purchase_adjustments` raw VAT fields
- `app/Models/PurchaseAdjustment.php`
- `app/Services/PurchaseAmountPresenter.php`
- `app/Http/Controllers/RecordController.php`
- `app/Http/Controllers/ViewInfoController.php`
- `app/Http/Controllers/VatInputController.php`
- `resources/js/Pages/Records/PurchaseRecords.jsx`
- `resources/js/Pages/EditVatInputRecord.jsx`
- Purchase consolidation, adjustment, reversal, records, View Info, and DAT tests
- `Docs/PURCHASE_RECORD_TOTAL_DISPLAY.md`
- `Docs/VIEW_INFO_FEATURE_DOCUMENTATION.md`
- the completed Services plan, only to mark the superseded Purchase Local/Others boundary

Do not edit `ExpandedWtaxImport.php` or other Expanded WTAX files for this Purchase-only change.

## Required Test Matrix

### Display and record modes

1. A VAT-bucket row displays exact raw Purchase Local, Services, and Others amounts.
2. Four qualifying rows sum each raw bucket before display.
3. Visible Total applies `/ 0.12` once after all three raw aggregates are summed.
4. Purchase Imported remains excluded from the visible Total.
5. Purchase Records and View Info return identical values for all three buckets and Total.
6. Explicit taxable-base records display stored Purchase Local and Others without dividing again.
7. Legacy records show inferred raw bucket values but keep the stored-base Total.
8. Missing raw data on a marked VAT-bucket record sets `display_amounts_inferred = true`.

### Upload and consolidation safeguards

9. Existing raw Purchase Local and Others import aggregation remains cent-exact.
10. Different suppliers, periods, and imported/local classifications remain separate.
11. Conflicting TIN groups are rejected before replacement.
12. Mixed VAT-bucket and explicit-base rows are rejected before replacement.
13. Adjusted rows and Importation mirrors remain protected during replacement.

### Adjustment and reversal

14. Purchase Local VAT input converts to stored base exactly once.
15. Others VAT input converts to stored base exactly once.
16. A combined Purchase Local, Services, and Others adjustment transfers all raw and base amounts correctly.
17. Maximum validation uses raw VAT balances for all three fields.
18. Repeated adjustments remain cent-exact.
19. Merging into an uploaded target preserves and adds exact raw balances.
20. Merging into an adjusted target preserves and adds exact raw balances.
21. Undo/Delete restores all raw VAT and stored-base values exactly.
22. Incomplete raw or base history blocks reversal.
23. Legacy adjustment request keys and legacy histories retain their documented fallback behavior.

### Compatibility

24. Purchase DAT rows and totals remain byte/field compatible with existing fixtures.
25. Dashboard Purchase metrics continue using `total_purchases`.
26. Purchase Imported display and adjustment behavior remain unchanged.
27. Services tests remain passing.
28. Sales, Importation, and Expanded WTAX behavior remains unchanged.

## Verification Commands

```text
php artisan test --compact --filter=PurchaseServicesConsolidationTest
php artisan test --compact --filter=PurchaseAdjustmentMergeTest
php artisan test --compact --filter=PurchaseAdjustedDeleteTest
php artisan test --compact --filter=RecordPagesTest
php artisan test --compact --filter=ViewInfoTest
php artisan test --compact --filter=ReliefPurchaseDatGeneratorTest
php artisan test --compact --filter=UploadWorkbookTypePreflightTest
php artisan test --compact --filter=PendingPurchaseUploadTest
php artisan migrate:status
npm run build
git diff --check
```

If Node/npm remains unavailable, report the frontend build as unverified rather than treating backend tests as proof of the rendered UI.

## Compatibility Boundaries

- Preserve the existing workbook headings and amount-mode detection.
- Preserve Supplier resolution and validation-before-consolidation rules.
- Preserve same-period and imported/local grouping boundaries.
- Preserve per-row taxable-base conversion and rounding for saved BIR/DAT fields.
- Never replace stored `purchase_local`, `services`, or `others` bases with raw display amounts.
- Preserve `input_vat`, `taxable_net_of_vat`, `total_purchases`, and stored `total` meanings.
- Preserve Purchase DAT layout, field order, delimiters, formatting, filenames, calculations, and line endings.
- Preserve Dashboard `total_purchases` behavior.
- Preserve atomic same-month replacement and the pending Supplier repair queue.
- Preserve adjusted rows and Importation mirrors during ordinary Purchase replacement.
- Do not change Purchase Imported under this plan.
- Do not alter Sales, Importation, or Expanded WTAX behavior.
- Do not automatically backfill legacy Purchase or adjustment history values.

## Acceptance Criteria

- Purchase Local, Services, and Others show exact consolidated raw Excel VAT amounts for VAT-bucket uploads.
- The visible Total is calculated once from the sum of those three raw VAT buckets.
- Explicit taxable-base rows are never divided by `0.12` again.
- Historical records use a clearly inferred fallback without modifying stored values.
- Adjustments accept Purchase Local, Services, and Others in raw VAT units.
- The backend converts each raw adjustment to taxable base exactly once.
- Adjustment history and Undo/Delete preserve both raw VAT and stored-base amounts for all three buckets.
- Existing validation and consolidation rules run before any destructive replacement.
- Purchase Imported, DAT generation, Dashboard totals, and unrelated modules remain unchanged.

## Implementation Result

- Purchase Records and Purchase View Info now use presenter values for Purchase Local, Services, Others, and Total.
- VAT-bucket rows show exact consolidated raw VAT amounts; explicit-base rows remain undivided; legacy rows use the documented inferred display fallback.
- The adjustment form now accepts Purchase Local, Services, and Others as VAT amounts and converts each to taxable base once in the backend.
- The adjustment ledger records exact raw VAT for all three local buckets, and Undo/Delete validates and restores both raw VAT and stored-base amounts.
- Existing taxable-base request keys remain supported for backward compatibility.
- A new forward migration adds `purchase_local_vat_amount` and `others_vat_amount` to `purchase_adjustments`.

The final broader focused suite passed with `136` tests and `1,711` assertions. This includes upload/preflight, consolidation, adjustment merge, exact Undo/Delete restoration, incomplete-history blocking, records, View Info, Purchase DAT, Dashboard, Importation, and the full-balance rounding edge. PHP lint, Pint, and `git diff --check` passed. Node/npm was unavailable, so the frontend production build was not verified.

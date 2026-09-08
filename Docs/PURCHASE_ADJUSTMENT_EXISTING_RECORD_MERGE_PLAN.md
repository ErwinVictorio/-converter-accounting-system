# Purchase Adjustment Existing Record Merge Plan

## Goal

When a broker Purchase record is adjusted and the entered vendor TIN already exists in uploaded Purchase records for the same reporting period, add the transferred amounts to that existing record instead of creating a new adjusted record.

This keeps one real vendor row per matching TIN/month/imported bucket and avoids duplicate Purchase rows when the target vendor already came from an upload.

## Current Behavior

`VatInputController::update()` currently looks for an existing target row using:

- `is_adjusted = true`
- same `is_imported`
- same `date_uploaded`
- same first 9 digits of `tin_number`

If no adjusted row exists, it creates a new `vat_inputs` row with `is_adjusted = true`.

## Required New Behavior

The adjustment target lookup should include existing uploaded Purchase rows too.

Target matching rules:

- same normalized first 9 digits of `tin_number`
- same `date_uploaded` as the source broker row
- same `is_imported` value
- target row is not the source broker row being adjusted

Target priority:

1. Existing non-adjusted uploaded Purchase row
2. Existing adjusted row
3. Create a new adjusted row only when no existing target row is found

## Accounting Behavior

When a matching existing uploaded Purchase row is found:

- add the transferred `purchase_imported`, `purchase_local`, `services`, and `others` amounts to that matched row
- recalculate only that matched row's derived totals:
  - `other_than_capital_goods`
  - `taxable_net_of_vat`
  - `input_vat`
  - `total_purchases`
  - `total`
- keep `is_adjusted = false` on the matched uploaded row
- subtract the same transferred amounts from the source broker row
- do not create a new adjusted row

When only an adjusted target row is found, keep the existing behavior:

- add the transferred amounts to the adjusted row
- recalculate the adjusted row totals
- subtract the same transferred amounts from the source broker row

When no target row is found, keep the existing behavior:

- create a new `is_adjusted = true` row
- copy the submitted BIR vendor information into that new row
- subtract the transferred amounts from the source broker row

## Backend Changes

Update `app/Http/Controllers/VatInputController.php`.

### 1. Target lookup in `update()`

Replace the adjusted-only query with a broader locked target query:

- search `vat_inputs` by same date, same `is_imported`, and same normalized first 9 TIN digits
- exclude `$vatInput->id`
- order non-adjusted rows before adjusted rows
- use `lockForUpdate()`

The query should return one target row. If found, update it. If not found, create a new adjusted row.

### 2. Preserve uploaded-row identity

If the matched row has `is_adjusted = false`, do not overwrite it to `true`.

Only set `is_adjusted = true` when creating a new fallback adjustment row.

### 3. Recalculation helper

Consider extracting the amount-add and total-recalculation logic into a small private method so both existing uploaded rows and adjusted rows use the same math.

The helper should calculate:

```php
$updatedTotal = $purchaseImported + $purchaseLocal + $services + $others;
```

Then save:

- `other_than_capital_goods = purchase_local + others`
- `taxable_net_of_vat = updatedTotal`
- `vat_rate = 12`
- `input_vat = updatedTotal * 0.12`
- `total_purchases = updatedTotal`
- `total = updatedTotal`

## Lookup / Autofill Changes

Update `VatInputController::adjustedLookup()`.

The lookup should find an existing target using the same broader target priority:

1. existing uploaded Purchase row
2. existing adjusted row

Return the same JSON shape for compatibility with `EditVatInputRecord.jsx`, unless the UI needs clearer wording later.

Optional UI wording cleanup:

- rename internal frontend labels from "adjusted lookup" to "target lookup"
- change helper text from adjusted-specific wording to "Existing purchase record found."

## Important Boundaries

- Do not change DAT generation layout or file format.
- Do not change upload replacement behavior.
- Do not alter Sales, Importation, or Expanded WTAX adjustment rules.
- Do not merge across different months.
- Do not merge across different `is_imported` values.
- Do not merge into the same broker row being adjusted.
- Keep adjusted rows ineligible for another adjustment.

## Test Plan

Add focused feature tests around `VatInputController::update()`.

Required test cases:

1. Adjustment to a TIN that exists as a normal uploaded Purchase row adds to that row and creates no adjusted row.
2. The matched uploaded row remains `is_adjusted = false`.
3. The source broker row is reduced by the transferred amounts.
4. The matched row recalculates `input_vat`, `total_purchases`, `taxable_net_of_vat`, `other_than_capital_goods`, and `total`.
5. If no uploaded row exists but an adjusted row exists, the existing adjusted row is incremented.
6. If no target exists, a new adjusted row is created.
7. A same-TIN row from another month is not used.
8. A same-TIN row with different `is_imported` is not used.
9. The source broker row cannot be selected as its own merge target.

Suggested verification commands:

```bash
php -l app/Http/Controllers/VatInputController.php
php artisan test --filter=VatInput
php artisan test --filter=RecordPagesTest
```

## Acceptance Criteria

- Adjusting a broker row into an existing uploaded vendor TIN updates that existing vendor row.
- No new adjusted row is created when a valid uploaded target row exists.
- Only the source broker row and the matched target row are changed.
- All purchase totals remain balanced after the transfer.
- Existing adjusted-row fallback behavior still works.

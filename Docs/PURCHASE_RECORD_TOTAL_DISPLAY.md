# Purchase Records Total Display

This note documents the current behavior of the Purchase Records local VAT-bucket columns, visible `Total`, and the `vat_inputs.total` database field.

## Summary

The Purchase Records table does not use the saved `vat_inputs.total` value for its visible `Total` column.

The backend supplies presentation-only Purchase Local, Services, Others, and Total values through `PurchaseAmountPresenter`. The React page formats those values but does not apply the VAT formula itself.

For a VAT-bucket upload, the raw source VAT amounts are consolidated first, then the visible Total is computed once:

```text
(raw Purchase Local VAT + raw Services VAT + raw Others VAT) / 0.12
```

Purchase Local, Services, and Others display their consolidated raw VAT amounts. The existing `purchase_local`, `services`, and `others` columns remain taxable bases used by BIR/DAT processing.

Explicit BIR/base workbooks display their stored bases and do not divide them again. Historical rows without a source-mode marker use the legacy display fallback: each local bucket is inferred as `stored base * 0.12`, while Total remains the sum of the stored local, services, and others bases.

These are presentation rules. They do not replace `vat_inputs.total`, `vat_inputs.total_purchases`, DAT amounts, dashboard totals, or imported source rows.

## UI Display Rule

File:

```text
resources/js/Pages/Records/PurchaseRecords.jsx
```

The Purchase Records list renders these amount columns:

| UI Column | Source |
| --- | --- |
| Purchase Imported | `item.purchase_imported` |
| Purchase Local | `item.display_purchase_local_amount` |
| Services | `item.display_services_amount` |
| Others | `item.display_others_amount` |
| Total | `item.display_calculated_total` |

The page receives these computed fields from the backend:

```text
display_purchase_local_amount
display_services_amount
display_others_amount
display_calculated_total
display_amounts_inferred
```

The amounts are still passed through `formatCurrency()` so the page shows comma separators and two decimal places.

## Backend Listing Query

File:

```text
app/Http/Controllers/RecordController.php
```

The Purchase Records page reads rows with:

```php
VatInput::query()
    ->select('vat_inputs.*')
```

After the listing query paginates, `PurchaseAmountPresenter` adds the presentation fields to each row. Query filtering, ordering, pagination, and the computed `is_broker` flag remain separate.

The same presenter is used by `ViewInfoController`, which keeps the Purchase Records and View Info display values aligned.

## `vat_inputs.total` Database Field

The plain `total` column still exists in `vat_inputs`.

It is written in these flows:

| Flow | Behavior |
| --- | --- |
| Purchase upload | `VatInputImport` saves `total` for new rows and grouped existing rows. |
| Importation mirror | `ImportationEntryWriter` saves `total` when an importation entry is mirrored into `vat_inputs`. |
| Broker adjustment | `VatInputController::update()` saves `total` on adjusted rows and updates the original broker row's remaining `total`. |

Current known UI usage:

| Screen | Usage |
| --- | --- |
| Purchase Records list | Does not display `vat_inputs.total`; uses the presenter total. |
| Edit/Adjust VAT Input page | Still displays `vatInput.total`. |

## `total_purchases` Is Separate

The dashboard Purchase total uses `vat_inputs.total_purchases`, not `vat_inputs.total`.

File:

```text
app/Services/BIR/DashboardMetrics.php
```

Dashboard source mapping:

```php
'purchases' => [
    'model' => VatInput::class,
    'date' => 'date_uploaded',
    'amount' => 'total_purchases',
    'tax' => 'input_vat',
],
```

## DAT Generation Is Unaffected

Purchase DAT generation does not read `vat_inputs.total`.

The DAT flow maps each saved `VatInput` through:

```php
$record->toBirPurchaseRow()
```

File:

```text
app/Models/VatInput.php
```

`toBirPurchaseRow()` sends these amount fields to the DAT validator/generator:

```text
exempt
zero_rated
services
capital_goods
other_than_capital_goods
input_vat
```

Because `total` is not part of this mapped row, changing the Purchase Records list display does not change the Purchase DAT output.

## Example

If qualifying source rows first consolidate to:

```text
Raw Purchase Local VAT = 600.00
Raw Services VAT       = 977.39
Raw Others VAT         =  60.00
```

The Purchase Records `Total` column shows:

```text
Purchase Local =   600.00
Services       =   977.39
Others         =    60.00
Total          = 1,637.39 / 0.12 = 13,644.92
```

The saved BIR/DAT taxable bases remain separate and are not divided a second time for display. The saved `vat_inputs.total` remains whatever was stored by import, importation sync, or adjustment logic.

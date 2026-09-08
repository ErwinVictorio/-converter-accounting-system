# Purchase Records Total Display

This note documents the current behavior of the Purchase Records `Total` column and the `vat_inputs.total` database field.

## Summary

The Purchase Records table does not use the saved `vat_inputs.total` value for its visible `Total` column.

The visible `Total` column is computed in the UI from the displayed VAT bucket columns:

```text
(purchase_local + services + others) / 0.12
```

This is a display-only calculation. It does not update `vat_inputs.total`, `vat_inputs.total_purchases`, DAT generation, dashboard totals, or imported source rows.

## UI Display Rule

File:

```text
resources/js/Pages/Records/PurchaseRecords.jsx
```

The Purchase Records list renders these amount columns:

| UI Column | Source |
| --- | --- |
| Purchase Imported | `item.purchase_imported` |
| Purchase Local | `item.purchase_local` |
| Services | `item.services` |
| Others | `item.others` |
| Total | `(item.purchase_local + item.services + item.others) / 0.12` |

The helper used by the page:

```jsx
const computedPurchaseTotal = (item) => {
    const purchaseLocal = Number(item.purchase_local || 0);
    const services = Number(item.services || 0);
    const others = Number(item.others || 0);

    return (purchaseLocal + services + others) / 0.12;
};
```

The value is still passed through `formatCurrency()` so the page shows comma separators and two decimal places only.

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

The listing query does not compute the amount columns. It only adds a computed `is_broker` flag for the Adjust button, applies search/month filters, sorts by supplier name and ID, and paginates the result.

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
| Purchase Records list | Does not display `vat_inputs.total`; uses computed UI total. |
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

If a displayed row has:

```text
Purchase Local = 11,857.33
Services       = 3,695.23
Others         = 0.00
```

The Purchase Records `Total` column shows:

```text
(11,857.33 + 3,695.23 + 0.00) / 0.12 = 129,604.67
```

The saved `vat_inputs.total` value remains whatever was stored by import, importation sync, or adjustment logic.

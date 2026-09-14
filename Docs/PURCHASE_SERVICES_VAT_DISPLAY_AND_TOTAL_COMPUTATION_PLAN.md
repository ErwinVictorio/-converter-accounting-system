# Purchase Consolidated Services and Total Computation Plan

## Status

Implemented on September 14, 2026.

This remains the authoritative specification for the Purchase Services display and Total computation. Validation, supplier resolution, consolidation, adjustment handling, rounding, and DAT compatibility were implemented in one scope.

## Confirmed Business Rule

The order of operations must be:

```text
Validate source rows
    -> resolve the supplier identity
    -> form valid consolidation groups
    -> sum the raw VAT amounts in each group
    -> apply the visible Total formula once to the consolidated amounts
```

Do not calculate a visible Total for each source row and then add those visible totals.

For Services:

```text
Consolidated Services = sum of the raw Excel Services VAT amounts
Services contribution to Total = Consolidated Services / 0.12
```

For the visible Purchase Total:

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

Purchase Imported remains excluded from this visible Total because that is the existing Purchase Records rule. Changing that boundary requires a separate request.

## Examples

### One Services source row

The supplied workbook `Docs/purchase/VAT INPUT REPORT (4).xlsx`, row 36, contains:

```text
Supplier:       A-ZINC INDUSTRIAL GALVANIZING PHILIPPINES
Excel Services: 977.39
Excel TOTAL:    977.39
```

Required Purchase Records result:

```text
Services: 977.39
Total:    977.39 / 0.12 = 8,144.92
```

It must not show Services `8,144.92` and then divide that taxable base again to produce Total `67,874.33`.

### Four source rows that pass the same consolidation rules

```text
Services row 1: 100.00
Services row 2: 200.00
Services row 3: 300.00
Services row 4: 377.39
                  -------
Consolidated:     977.39

Visible Services: 977.39
Visible Total:    977.39 / 0.12 = 8,144.92
```

Only one consolidated Purchase record is shown. The formula is applied after the four qualifying Services values have been added.

## Existing Upload Rules That Must Run Before Consolidation

### 1. Request validation

Keep the current upload request rules:

- an Excel file is required
- accepted file types are XLSX, XLS, and CSV
- maximum file size is 10 MB
- `record_type` must be Purchase for this flow
- a reporting month is required

Failure at this stage must create no rows and replace no existing rows.

### 2. Workbook type and period preflight

`UploadWorkbookTypePreflight` must run before import and replacement.

It must confirm:

- the workbook headings identify it as a Purchase file
- the actual Purchase importer can read its fixed heading row at worksheet row 3
- the workbook is not a Sales or Expanded WTAX file
- the workbook period or inferred transaction month matches the selected reporting month
- calculated formula cells are read consistently during preflight

If the file type or month is wrong, reject the complete upload. Do not proceed to consolidation or delete same-month records.

### 3. Per-row Purchase BIR preflight

`UploadBirInfoPreflight::checkPurchase()` must inspect source rows before replacement.

Keep the current blocking checks for:

- a usable first nine digits of Vendor TIN that is not `000000000`
- required company or individual identity
- required Supplier Address1
- required Supplier City / Address2
- numeric Purchase amount fields

Text length and comma/ampersand findings remain allowed at upload under the existing relaxed-upload policy, while the full DAT validator remains the final DAT-generation guard. Do not silently broaden or tighten this policy as part of the computation change.

If blocking supplier issues exist:

- reject the import before replacement
- retain the workbook through the existing private pending-Purchase workflow
- group repair issues by supplier identity
- require the supplier fixes before retry
- retry the exact retained workbook through the same fresh preflight

The existing repair queue identity priority must remain:

1. matched Supplier master record ID
2. normalized full 12-digit TIN
3. normalized first nine TIN digits
4. normalized supplier name
5. worksheet row number only when no usable identity exists

### 4. Rows excluded before consolidation

Continue excluding:

- empty rows
- rows with no usable supplier or individual name
- `TOTAL`, `GRAND TOTAL`, and `SUBTOTAL` rows
- suppliers configured in `bir.purchase.skipped_suppliers`, currently including normalized BUREAU OF CUSTOMS

Excluded rows do not contribute to Services, other amount buckets, or Total.

If every otherwise importable row is an excluded supplier row, fail without leaving a partially replaced month.

## Supplier Resolution Rules Before Consolidation

Every eligible row must resolve its supplier before a grouping key is created.

Keep the current matching priority:

1. normalized full 12-digit Supplier TIN
2. normalized first nine BIR TIN digits
3. normalized supplier name

When a Supplier master record matches, use its canonical:

- supplier name
- TIN
- address
- city

Name normalization remains case-insensitive and punctuation-insensitive for master matching. The final resolved supplier name is then used by the current consolidation query.

Rows with different uploaded names may therefore consolidate when both resolve to the same Supplier master record. Rows must not be combined merely because they are next to each other or have similar-looking references.

## Amount Interpretation Before Consolidation

Preserve the current distinction between VAT-bucket and explicit BIR/taxable-base rows.

A row is treated as a VAT-bucket row only when none of these fields is filled:

- Input VAT
- Capital Goods
- Other Than Capital Goods
- Taxable Net of VAT

For a VAT-bucket row:

- Purchase Imported, Purchase Local, Services, and Others are raw VAT amounts
- use the row's VAT rate or the existing 12% default
- convert each bucket to its taxable base once
- set Capital Goods from converted Purchase Imported
- set Other Than Capital Goods from converted Purchase Local plus converted Others
- set Taxable Net of VAT from the converted buckets
- use the uploaded TOTAL as Input VAT when present; otherwise sum the raw VAT buckets

For an explicit BIR/taxable-base row, preserve the uploaded base fields and the existing fallback calculations. Do not divide an already explicit taxable base by `0.12` again.

Blank amount cells continue to parse as zero. Existing comma-formatted numeric values continue to be accepted by the number parser. This plan does not silently introduce a new negative-amount policy; any tightening of negative-value validation requires an explicit rule and test.

## Exact Purchase Consolidation Rules

An incoming source row may merge into an existing Purchase import row only when all of these are true:

- the final resolved `supplier_name` is the same
- `is_imported` is the same
- `date_uploaded` is the same selected reporting-period date
- the target has `is_adjusted = false`

`is_imported` continues to be derived from whether the converted Purchase Imported amount is greater than zero.

The invoice, PV number, transaction date within the selected month, and reference number are not current grouping keys. Multiple references for one resolved supplier are intentionally represented by one consolidated Purchase row when the required grouping keys match.

Never merge:

- different reporting periods
- an imported bucket with a non-imported bucket
- a normal uploaded row into an adjusted row during Excel import
- Purchase rows into Sales, Importation, or Expanded WTAX records
- a normal Purchase upload row into an Importation mirror through replacement

### New consolidation conflict guard

The current preflight validates rows independently but does not explicitly reject every cross-row identity conflict before the importer groups them.

Add a group-level preflight guard before replacement:

- if rows would share the same final supplier-name group but retain conflicting valid base TINs, reject that group for correction
- if rows resolve to the same Supplier master record, use that master identity and do not report a false conflict
- report all affected worksheet row numbers and both conflicting TINs
- do not let a later row silently overwrite the consolidated row's earlier TIN
- cancel the complete upload so the existing month remains unchanged

This guard protects consolidation identity; it must not create a looser name-only merge rule.

## Raw VAT Aggregation and Stored Taxable Bases

The application needs two separate amount representations.

| Representation | Purpose |
| --- | --- |
| Raw VAT bucket aggregate | Purchase Records display and requested visible Total formula |
| VAT-exclusive taxable base | Saved BIR fields, adjustments, validation, Dashboard where already applicable, and DAT generation |

For VAT-bucket Purchase workbooks, do not try to reconstruct the exact consolidated Excel amount only from a previously rounded taxable base. Preserve the raw source aggregates explicitly.

### Proposed persisted presentation fields

Add nullable decimal fields to `vat_inputs` for the exact consolidated VAT-bucket source amounts:

- `purchase_imported_vat_amount`
- `purchase_local_vat_amount`
- `services_vat_amount`
- `others_vat_amount`

Use sufficient precision for existing Purchase amounts, preferably `decimal(14, 2)`. These fields are presentation/source-provenance values and must not replace the existing BIR taxable-base fields.

For each VAT-bucket source row:

1. Parse each raw Excel VAT bucket to two decimal places.
2. Convert that row to its existing taxable-base fields using the current VAT rate and current rounding.
3. When the row joins a valid consolidation group, add its raw VAT values to the group's corresponding `*_vat_amount` fields.
4. Separately add its converted taxable-base and derived BIR values using the existing importer behavior.

This preserves the current DAT arithmetic while also preserving the exact source VAT total required by the interface.

For explicit BIR/taxable-base workbooks, leave the source VAT fields null unless the workbook actually supplies authoritative VAT-bucket values. Do not relabel an explicit taxable-base Services value as a raw Excel VAT amount.

If a would-be consolidated group mixes incompatible amount modes and an exact raw aggregate cannot be established, reject it before replacement instead of guessing.

### Rounding rule

Use cent values or decimal arithmetic for raw aggregates. Avoid accumulating binary floating-point values in the frontend.

Keep these computations separate:

```text
Raw consolidated Services VAT = round(sum of source Services VAT amounts, 2)

Visible Services total base = round(Raw consolidated Services VAT / 0.12, 2)
```

The existing DAT taxable base remains the sum produced by the existing per-row conversion and rounding path. A presentation value must never overwrite a DAT value merely to hide a possible one-cent difference.

## Purchase Records and View Info

Add a single backend Purchase presentation calculator so the list and View Info use the same values.

For a VAT-bucket consolidated row, return:

- `display_services_amount = services_vat_amount`
- `display_calculated_total = round((purchase_local_vat_amount + services_vat_amount + others_vat_amount) / 0.12, 2)`

In `PurchaseRecords.jsx`:

- show `display_services_amount` in Services
- show `display_calculated_total` in Total
- remove the current frontend calculation that divides the already converted stored fields by `0.12`
- use the backend result and apply currency formatting only

In Purchase View Info:

- show the same business-facing Services and Total
- if the stored value is exposed, label it `Services Taxable Base`
- keep Stored Total and Total Purchases separately labeled; do not treat them as the visible Total

For historical rows that lack the new raw source fields, do not fabricate an exact consolidated source amount without evidence. A temporary `round(services * 0.12, 2)` fallback may be used only if it is clearly treated as an inferred legacy display value. The corrected same-month workbook should be re-uploaded to populate authoritative raw aggregates.

## Broker Adjustment Rules

The form says that Services is entered as a VAT amount. Make the full adjustment flow follow that contract.

### Input and display

- show the available Services balance in VAT amount units
- accept the Services adjustment in VAT amount units
- enforce the maximum against the remaining raw Services VAT balance
- show its contribution to the adjusted Total as `entered Services / 0.12`
- keep Purchase Local and Others semantics unchanged unless separately requested

### Backend conversion and target matching

Convert the submitted Services VAT amount once to a taxable base before changing the stored BIR fields.

Keep the current adjustment target boundary:

- exclude the source broker row
- match the first nine normalized TIN digits
- match `date_uploaded`
- match `is_imported`
- prefer an existing non-adjusted uploaded row, then an existing adjusted row, then create a new adjusted row

When merging an adjustment:

- add the entered raw Services VAT amount to the target's `services_vat_amount`
- add the converted base to the target's stored `services`
- subtract the same raw amount from the source raw balance
- subtract the same converted base from the source stored `services`
- recalculate only the locked source and matched target records
- keep the entire operation in the existing database transaction with row locks

### Adjustment ledger and Undo/Delete

Keep `purchase_adjustments.services` in taxable-base units so it is the exact inverse of the stored `vat_inputs.services` transfer.

Add a corresponding raw Services VAT amount to the ledger so the presentation aggregate can also be reversed exactly. Undo and Delete must restore both representations atomically.

Do not delete or partially reverse a target if its base history or raw-VAT history is incomplete.

Before implementation, re-audit existing adjustment histories and legacy `is_adjusted = true` rows. Do not blindly convert historical values whose input meaning cannot be proven. Any required reconciliation must update source, target, ledger, and derived totals together.

## Atomic Same-Month Replacement

Keep the existing replacement sequence:

1. Complete request, workbook type/month, BIR, identity-conflict, and amount-mode validation.
2. Only after all checks pass, begin one database transaction.
3. Delete ordinary non-adjusted Purchase rows for the selected month.
4. Preserve Purchase rows linked to Importation entries.
5. Preserve adjusted rows under the existing replacement boundary.
6. Import and consolidate the corrected workbook.
7. Roll back every deletion and insert/update if any import step fails.

A rejected upload must leave the previous month's Purchase records untouched.

## Implementation Areas

Expected files include:

- a new migration for the raw VAT aggregate fields
- `app/Models/VatInput.php`
- `app/Models/PurchaseAdjustment.php` if a raw Services ledger field is added
- `app/Imports/UploadWorkbookTypePreflight.php`
- `app/Imports/UploadBirInfoPreflight.php` or a focused consolidation preflight helper
- `app/Imports/VatInputImport.php`
- `app/Services/PurchaseUploadService.php`
- a shared Purchase presentation calculator
- `app/Http/Controllers/RecordController.php`
- `app/Http/Controllers/ViewInfoController.php`
- `app/Http/Controllers/VatInputController.php`
- `resources/js/Pages/Records/PurchaseRecords.jsx`
- `resources/js/Pages/EditVatInputRecord.jsx`
- focused Purchase upload, records, adjustment, deletion, View Info, and DAT tests

Keep the actual implementation narrow. Do not edit these files unless the final implementation requires them.

## Required Test Matrix

### Preflight and grouping

1. Wrong record type is rejected before replacement.
2. Wrong reporting month is rejected before replacement.
3. Missing/invalid blocking Supplier BIR information prevents consolidation.
4. Pending supplier issues from multiple rows are grouped by supplier identity.
5. BUREAU OF CUSTOMS and total/blank rows contribute nothing.
6. Four rows resolving to the same supplier, period, and imported bucket become one row.
7. Different periods remain separate.
8. Different `is_imported` buckets remain separate.
9. Different references still consolidate when all actual grouping keys match.
10. Same final supplier name with conflicting unresolved base TINs is rejected before replacement.
11. Different uploaded names resolving to one Supplier master record consolidate under the master identity.
12. Adjusted rows and Importation mirrors remain protected during replacement.

### Services and Total

13. Four raw Services values `100.00 + 200.00 + 300.00 + 377.39` display as `977.39`.
14. The consolidated visible Total is computed once as `977.39 / 0.12 = 8,144.92`.
15. The interface never displays `67,874.33` for that sample.
16. Mixed raw Purchase Local, Services, and Others are each summed before the one Total calculation.
17. Purchase Imported remains excluded from the visible Total.
18. Null, blank, zero, and comma-formatted source amounts follow the existing parser/validation rules safely; negative values retain their existing handling unless separately tightened.
19. A rounding-edge fixture proves the raw consolidated display and stored DAT base remain separately correct.
20. Purchase Records and View Info return identical display values.

### Adjustment and reversal

21. A Services adjustment accepts a VAT amount and transfers its converted taxable base exactly once.
22. The source and target raw VAT balances change by the entered amount.
23. The source and target stored bases change by the converted amount.
24. Repeated adjustments remain cent-exact.
25. Same-TIN, same-period, and same-imported-bucket target priority remains unchanged.
26. Undo/Delete restores both raw VAT and taxable-base ledger amounts exactly.
27. Missing or incomplete history rolls back the full reversal.

### Compatibility

28. The existing `4,339.29 -> 36,160.75` VAT-bucket import test remains valid for the stored BIR Services base.
29. The A-ZINC source `977.39` remains stored for display while `8,144.92` remains available to the DAT path.
30. Purchase DAT layout, field order, formatting, filenames, line endings, and totals are unchanged.
31. Dashboard Purchase metrics retain their existing `total_purchases` source.
32. Sales, Importation, and Expanded WTAX tests remain unchanged and passing.

Use a small generated XLSX fixture with the real title row, period row, heading row 3, and multiple same-supplier source rows. Do not make automated tests depend on the user's full workbook.

## Verification Commands

```text
php artisan test --compact --filter=UploadWorkbookTypePreflightTest
php artisan test --compact --filter=PendingPurchaseUploadTest
php artisan test --compact --filter=PurchaseAdjustmentMergeTest
php artisan test --compact --filter=PurchaseAdjustedDeleteTest
php artisan test --compact --filter=RecordPagesTest
php artisan test --compact --filter=ViewInfoTest
php artisan test --compact --filter=ReliefPurchaseDatGeneratorTest
npm run build
git diff --check
```

If Node/npm is unavailable, report the frontend build as unverified. Backend results are not proof of the rendered interface.

## Implementation Result

- VAT-bucket uploads now retain the exact raw Purchase Local, Services, and Others VAT aggregates separately from the existing stored taxable bases.
- Supplier identity and imported/local classification are resolved before consolidation. Same-name groups with conflicting valid TINs are rejected unless every row resolves to the same Supplier master record.
- Mixing VAT-bucket rows with explicit BIR taxable-base rows in one consolidation group is rejected before replacement.
- Purchase Records and Purchase View Info share one backend presenter, so Services and Total use the same rules in both interfaces.
- The adjustment form accepts Services as a VAT amount. The backend converts it to taxable base once and records both representations for exact delete/undo restoration.
- Existing records without a source-mode marker use an explicit legacy fallback and are not backfilled.
- Purchase DAT mapping, Dashboard `total_purchases`, Purchase Imported exclusion, and unrelated modules retain their existing sources and behavior.

Focused backend verification passed with `131` tests and `1,650` assertions. An earlier complete backend run reached `444` passing tests and exposed `22` unrelated failures in Auth/Profile scaffolding and one Expanded WTAX upload-dialog case; two additional focused guard tests were added afterward and passed. Node/npm was unavailable, so the frontend production build remains unverified.

## Compatibility Boundaries

- Preserve the current Purchase heading row and supported templates.
- Preserve existing supplier master-data resolution priority.
- Preserve the established consolidation key unless a conflict is explicitly rejected before import.
- Preserve current per-row taxable-base conversion and rounding for stored BIR/DAT amounts.
- Preserve same-month replacement atomicity and the pending Supplier repair queue.
- Preserve adjusted rows and Importation mirrors during ordinary Purchase replacement.
- Do not change Purchase DAT layout, field order, delimiters, formatting, filenames, totals, calculations, or line endings.
- Do not overwrite `services`, `input_vat`, `taxable_net_of_vat`, `total_purchases`, or `total` with presentation-only raw values.
- Do not include Purchase Imported in the visible Total.
- Do not change Purchase Local or Others form/display semantics beyond retaining their raw source aggregates for the requested consolidated Total.
- Do not alter Sales, Importation, or Expanded WTAX behavior.
- Do not backfill historical raw amounts or adjustment meanings without evidence.

## Acceptance Criteria

- Every source row passes the existing upload rules before it can contribute to a consolidated record.
- Only rows with the same resolved supplier, period, imported classification, and non-adjusted target status consolidate.
- Four qualifying Services source values are added into one raw Services amount.
- The Services column shows that raw consolidated amount.
- The visible Total formula runs once after consolidation.
- The A-ZINC sample shows Services `977.39` and Total `8,144.92`.
- The stored Purchase and DAT Services base remains `8,144.92` for the sample.
- Conflicting supplier/TIN groups are rejected before replacement rather than silently merged.
- Adjustments update and reverse both raw VAT and taxable-base representations atomically.
- No rejected upload partially deletes or replaces existing records.
- Purchase DAT, Dashboard, and unrelated record modules remain unchanged.

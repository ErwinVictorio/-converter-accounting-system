# Expanded WTAX Selected Company Validation

This note explains how the Generate DAT screen knows whether the selected withholding agent company has uploaded Expanded WTAX records.

## Scope

Expanded WTAX records are not checked by month only. They are scoped by three values:

| Scope Field | Meaning |
| --- | --- |
| `withholding_agent_tin` | The selected filing company TIN |
| `withholding_agent_branch_code` | The selected filing company branch code |
| `reporting_period` | The selected reporting month |

Because of this, records uploaded for one company do not appear under another company.

## Upload Behavior

When uploading an Expanded WTAX file, the upload form sends the selected withholding agent company with the workbook.

The backend requires these fields for `record_type = expanded`:

- `withholding_agent_tin`
- `withholding_agent_branch_code`

Before importing the new workbook, the app replaces only the rows matching:

- same reporting month
- same withholding agent TIN
- same withholding agent branch code

This prevents a re-upload for one company from deleting or overwriting another company's Expanded WTAX records.

## Generate DAT Page Behavior

When the user selects another company in the Known Company dropdown, the frontend reloads `/generate-datfile` with:

- `record_type=expanded`
- `withholding_agent_tin`
- `withholding_agent_branch_code`

The backend then looks for available months only from `expanded_wtax_entries` rows matching the selected company's TIN and branch code.

If no rows exist for the selected company, the page receives an empty `availablePeriods` list and shows:

```text
No expanded withholding tax records yet.
```

## Download DAT Validation

The Download DAT action repeats the same check before generating the file.

It queries `expanded_wtax_entries` using:

- selected withholding agent TIN
- selected withholding agent branch code
- selected reporting month date range

If no records match, the app does not generate a DAT file and returns:

```text
No expanded withholding tax records found for the selected company and reporting month.
```

## Example

If STC has uploaded records for August 2026:

- STC + August 2026 shows the available period and row count.
- Another company + August 2026 shows no Expanded WTAX records unless that company also uploaded its own August 2026 file.
- Downloading for the other company is blocked by the backend if no matching rows exist.

## Relevant Files

- `resources/js/Pages/GenerateDatFile.jsx`
- `app/Http/Controllers/DatFileController.php`
- `app/Http/Controllers/VatInputController.php`
- `app/Imports/ExpandedWtaxImport.php`

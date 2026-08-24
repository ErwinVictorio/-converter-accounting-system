# Expanded Withholding Tax Implementation Plan Prompt

Create a detailed implementation plan first for the **Expanded Withholding Tax update**.

Do not modify any code yet.

## Main Rule

The uploaded Excel file will already contain the completed and computed Expanded Withholding Tax values.

The system must **not recalculate or derive the uploaded amounts during import**.

Whatever valid values are provided in the BIR-format Excel file should be stored directly in the database.

Only fields required by the provided BIR Excel format should be stored for Expanded Withholding Tax records.

## BIR Fields to Store

- Reporting Month
- Vendor TIN
- Branch Code
- Company Name
- Surname
- First Name
- Middle Name
- ATC
- Income Payment
- EWT Rate
- Tax Amount

Income Payment, EWT Rate, and Tax Amount are already provided/computed in Excel and should be imported as provided.

Do not derive Income Payment from Tax Amount.
Do not derive Tax Amount from Income Payment during import.

Validation may check the values and formats, but must not silently replace uploaded amounts with newly calculated values.

## Remove Reference/PV/SI

Reference/PV/SI should no longer be part of the required Expanded WTax upload.

Do not use Reference for import validation, database grouping, display, consolidation, or DAT generation.

Review other existing fields as well. If a field is not required by the BIR format or DAT generation, identify it in the plan for removal or deprecation.

## Consolidation

Consolidate records only when all are the same:

- Reporting Month
- TIN
- ATC
- EWT Rate

For matching records, sum:

- Income Payment
- Tax Amount

Do not merge when TIN, Reporting Month, ATC, or EWT Rate is different.

## DAT Generation

Keep the existing **1604E DAT output** for now.

Do not create a separate 1601EQ generator yet.

DAT generation should use the consolidated Expanded WTax records.

Preserve existing DAT formatting and 1604E header/control logic unless a change is strictly required.

## Database Review

Analyze the existing Expanded WTax database structure and determine:

- Which columns match the BIR fields
- Which columns are no longer necessary
- Whether columns need to be added or renamed
- Whether Reference should be removed or deprecated
- Whether existing records require migration or backward compatibility

Do not make destructive database changes during planning.

## Intended Upload Flow

BIR-format Excel → Validate required columns and values → Read already-computed amounts → Store required BIR data → Consolidate matching Expanded WTax records → Display consolidated records → Generate 1604E DAT.

The import process must not perform hidden amount calculations.

## Plan Requirements

The implementation plan should identify:

1. Current files and components involved.
2. Existing Expanded WTax upload flow.
3. Existing validation flow.
4. Existing database/model behavior.
5. Existing Expanded WTax records query.
6. Existing DAT generation flow.
7. Existing amount calculation logic that should be removed.
8. Exact mapping between BIR Excel columns and database fields.
9. Fields that should no longer be stored or used.
10. Where consolidation should be applied.
11. How duplicate grouping will work.
12. How Income Payment and Tax Amount totals will be calculated.
13. Required UI changes.
14. Required backend changes.
15. Required validation changes.
16. Database migration requirements, if any.
17. Backward compatibility concerns.
18. Required tests.
19. Recommended implementation order.

## Required Test Cases

- BIR-format Excel imports successfully.
- Uploaded Income Payment is stored as provided.
- Uploaded EWT Rate is stored as provided.
- Uploaded Tax Amount is stored as provided.
- Import does not recalculate Income Payment.
- Import does not recalculate Tax Amount.
- Reference/PV/SI is not required.
- Only required BIR data is stored.
- Same Reporting Month + TIN + ATC + Rate → merge.
- Same TIN + different ATC → do not merge.
- Same TIN + different rate → do not merge.
- Same company name but different TIN → do not merge.
- Different reporting month → do not merge.
- Consolidated Income Payment total is correct.
- Consolidated Tax Amount total is correct.
- Generated 1604E DAT uses the consolidated stored values.

## Important

Use the provided Excel guide/template as the primary reference for the Expanded Withholding Tax upload structure.

Do not implement anything yet.

First create the implementation plan and clearly identify what existing logic needs to change or be removed.

At the end, provide a short **Implementation Checklist**.

Do not implement anything until the plan is reviewed and approved.

# Expanded WTAX Summary Total Card Plan

## Requested result

Add a separate **Total Tax Withheld** card to the right of the existing Tax Withheld Summary by Rate panel on wide screens, following the annotated reference. Stack the Total card below the panel on smaller screens without horizontal overflow.

Interpret the total as the sum of every available rate. The screenshot shows four rates (1%, 2%, 5%, and 10%), despite the annotation referring to three; do not exclude any rate. Additional or fractional rates must also contribute.

## Calculation

- Reuse `RecordController::withholdingTaxRateSummary()` and its existing integer-centavo buckets.
- Return `total_tax_withheld` alongside `rates`, formatted as a two-decimal string, by summing those buckets before formatting.
- Use the complete filtered, consolidated collection, including validation-warning rows and negative adjustments, exactly as the existing rate summary does.
- Search and month filters update the total; pagination does not change it. Do not total only the visible table rows or recalculate withholding from income and rate.
- No matches returns `rates: []` and `total_tax_withheld: "0.00"`; preserve the existing no-records message and show the Total as zero.
- For the second screenshot: 24,701.55 + 5,483.00 + 446.43 + 16,238.26 = PHP 46,869.24.

## Implementation steps

1. **Backend: `app/Http/Controllers/RecordController.php`**
   - Add the grand total to the existing summary response and update the return-type documentation. Reuse the existing aggregation; no new query or endpoint is needed.
2. **Page: `resources/js/Pages/Records/ExpandedWtaxRecords.jsx`**
   - Pass the server total into `WithholdingTaxRateSummary` with a zero fallback.
3. **Component: `resources/js/Components/Records/WithholdingTaxRateSummary.jsx`**
   - Wrap the existing panel and new Total card in a responsive layout above the table.
   - Give the rate panel the flexible majority of the width and the Total card enough width for a readable peso amount. Use two columns only when the available width accommodates both; otherwise stack them.
   - Preserve the existing rate labels, colors, wrapping, and filter explanation. Adjust the inner rate-grid breakpoint if necessary to prevent cramped cards.
   - Label the new card **Total Tax Withheld**, use the existing peso formatter, and visually emphasize the amount with consistent borders, rounded corners, and spacing.
4. **Verification: `tests/Feature/RecordPagesTest.php` and browser**
   - Extend existing summary tests to assert the grand total equals the rate sums, including fractional rates, centavos, zero amounts, negative amounts, and empty results.
   - Verify consolidated rows contribute once, totals include records beyond page 1, and search/month filters update the total consistently.
   - Run the focused RecordPages tests and production frontend build.
   - Inspect desktop and mobile layouts, large amounts, extra rates, empty results, filtering, and pagination.

## Scope

Only the Expanded WTAX summary response, summary UI, and focused verification are involved. Preserve stored data, consolidation, existing rate calculations, table behavior, DAT calculations/formats, and PDF/Excel behavior. No database migration is needed. `scripts/apply-purchase-company.php` is unrelated to this request.

## Acceptance criteria

- The separate Total card appears beside the rate panel when space permits and below it on smaller screens.
- Its amount equals all displayed rate totals across the full filtered result set.
- The second screenshot's values produce **PHP 46,869.24**, displayed with the peso symbol.
- Filters affect the total; changing pages does not.
- Implemented: server grand total and responsive summary card.

## Verification results

- `php artisan test --filter=RecordPagesTest`: 23 passed, 672 assertions. Covers filtered totals, pagination, consolidation, fractional rates, negatives, zero, and empty results.
- Production Vite build passed using the VS Code bundled Node runtime.
- Headless Edge component previews checked desktop/mobile positioning, empty results, additional rates, and large amounts using the actual component and built CSS. These are isolated component previews; full authenticated browser navigation was not exercised.
- `git diff --check` passed.

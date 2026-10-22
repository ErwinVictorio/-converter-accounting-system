# Application Dark Mode — Full Plan and Expected UI

Date: 2026-10-22  
Status: Design proposal and standalone preview only. No application theme changes in this task.

## 1. Deliverables and how to view

Open [the interactive UI sample](previews/records-dark-mode.html) in a browser. It works as a local HTML file without Laravel, npm, an internet connection, or a database.

The sample opens in dark mode on first use. Select Purchase, Sales, Expanded WTAX, or Importation from the sidebar. Switch Light / Dark / System in the header. Preview empty results using the button. Its preference uses a separate `records-theme-preview` storage key.

This is a visual mockup, not a screenshot of the running application. HTML/CSS represents the intended React components; production must reuse the existing `SummaryCard` and `RecordsSummarySection`, not copy this standalone implementation. Table rows and amounts are illustrative. Expanded summary values reproduce the supplied reference. Preview search filters sample table rows only; live summary and table filtering must remain synchronized by the existing server response.

## 2. Expected output

```text
+----------------------+------------------------------------------------------+
| Fortress Steel       | Fortress Steel / Expanded WTAX    Appearance [Dark] |
|                      +------------------------------------------------------+
| Purchase             |                                                      |
| Sales                | +--------------------------------+ +---------------+ |
| Expanded WTAX active | | Tax Withheld Summary by Rate   | | Total Tax     | |
| Importation          | | Current-filter description     | | Withheld      | |
|                      | | [1%] [2%] [5%] [10%]           | | ₱114,693.19   | |
|                      | | Full filtered-list scope note  | |               | |
|                      | +--------------------------------+ +---------------+ |
|                      | Expanded WTAX Records           Search / Filters     |
|                      | Dark table header, readable rows, status, actions    |
|                      | Pagination                                           |
+----------------------+------------------------------------------------------+
```

The real application retains its existing navigation, columns, filters, row actions, and company branding. The sample's short navigation and four-column table are only a visual illustration.

### Page-specific summaries

| Page | Metric cards | Highlighted total |
| --- | --- | --- |
| Purchase | Purchase Local, Services, Others | Total Purchase Amount |
| Sales | Exempt Sales, Zero-Rated Sales, Taxable Net of VAT, Output VAT | Total Gross Amount |
| Expanded WTAX | Tax withheld per actual rate; retain numeric rate order | Total Tax Withheld |
| Importation | Total Landed Cost, Dutiable Value, Charges, Exempt Amount, Taxable Goods | Total VAT Payable |

Dark mode changes colors and contrast only. It does not change card values or definitions. Purchase's highlighted amount is calculated separately from its displayed buckets; retain its explanatory note. Importation columns are separate measures. Preserve signed amounts, two decimals, zero-net records, empty messages, loading states, and totals across all filtered pages.

### Proposed dark palette

| Role | Initial color | Expected appearance |
| --- | --- | --- |
| Application background | `#0B1120` | Deep navy, not pure black |
| Card/sidebar/header | `#111C2E` | Distinct elevated surface |
| Inner metric/table header | `#172338` | Slightly lighter secondary surface |
| Border | `#334155` | Visible divisions without bright outlines |
| Primary text | `#F1F5F9` | Clear headings and amounts |
| Secondary text | `#A8B6CA` | Readable descriptions and labels |
| Blue accent | `#93C5FD` on `#172E4D` | Totals, active navigation, 1% |
| Green accent | `#6EE7B7` on `#15362F` | Positive statuses, 2% |
| Amber accent | `#FCD34D` on `#382D19` | Warnings, 5% |
| Rose accent | `#FDA4AF` on `#3C2230` | Errors, 10% |

Treat these as initial design values. Measure text and focus contrast during implementation; do not claim contrast compliance from a visual inspection alone. Keep text labels alongside color, and do not use green for every positive currency amount or red for every negative amount unless the existing business meaning requires it.

### Responsive output

- Wide desktop: summary panel and highlighted total beside each other; four metrics per inner row, with additional metrics wrapping.
- Intermediate widths: highlighted total below the summary; two metrics per row where space permits.
- Phones: one metric per row; no page-level horizontal overflow. Tables retain their existing internal horizontal scroll.
- Preserve the application's current breakpoint behavior rather than treating this mockup's approximate breakpoints as mandatory.
- Long peso amounts must remain readable; full values must be accessible when truncation is used.

## 3. Verified repository starting point

Inspected in this task:

- `resources/css/app.css` already defines light and `.dark` variables, including sidebar/chart variables. The current dark palette is largely neutral and can be adjusted to the approved navy palette.
- `tailwind.config.js` has no explicit `darkMode` setting and no semantic color mappings under `theme.extend.colors`. Defining CSS variables alone does not guarantee utilities such as `bg-background` are generated correctly.
- `resources/js/app.jsx` renders the Inertia app and Toaster without a root `ThemeProvider`.
- `resources/js/Components/ui/sonner.jsx` already calls `useTheme` from `next-themes`.
- `MainLayout.jsx` uses fixed white backgrounds and dark text. Several shared UI primitives already contain `dark:` variants, while other surfaces remain hardcoded.
- Existing `SummaryCard` and `RecordsSummarySection` provide the reusable summary structure for all four Record pages.

There is partial groundwork, but the app is not yet consistently theme-aware. Confirm package versions and component usage again before implementation. Do not bundle a Tailwind major-version upgrade into this work; adapt to the installed Tailwind 3 configuration.

## 4. Theme behavior and architecture

### Provider and persistence

1. Add a small `ThemeProvider` wrapper around the installed `next-themes` provider, or use that provider directly if no wrapper is needed.
2. Wrap both `<App />` and `<Toaster />` in `resources/js/app.jsx` so layouts, portals, and notifications share one theme.
3. Use class-based theme selection on the document root. Configure Tailwind with `darkMode: 'class'`.
4. Proposed production storage key: `fortress-theme`. Values: `light`, `dark`, `system`.
5. Default to `system` for first-time production users. Existing saved choices take precedence. The preview defaults to dark only to make the requested example visible immediately.
6. React to OS changes only when System is selected. Explicit Light/Dark choices stay fixed.
7. Persist on the same browser/origin. Do not add a user database column or promise cross-device sync.
8. Ensure blocked storage does not crash the app; fall back to a usable in-memory/system choice.
9. Keep the provider above page/layout remounts so Inertia navigation does not reset the choice.

### Prevent initial white flash

Inspect `resources/views/app.blade.php` and the provider's initial script behavior. If the client-rendered provider applies the class too late, add one small pre-paint initializer in the Blade head that reads the same storage key and system preference with guarded storage access. Set root class and `color-scheme` before content paints. Avoid competing initialization logic. Account for the application's CSP/nonces if present. Test cold reload under throttled loading in both themes.

### Theme control

Add an accessible header control labeled “Appearance” with Light, Dark, and System choices. Show the saved selection, including System even when the resolved appearance is dark. Preserve keyboard navigation and visible focus. Fit the header on mobile without hiding the sidebar trigger or breadcrumbs. Login should provide access to the same setting through its own layout/control placement.

### Semantic colors

Map semantic names in Tailwind to the existing CSS variables: background, foreground, card, card-foreground, popover, popover-foreground, primary, primary-foreground, secondary, muted, muted-foreground, accent, destructive, border, input, ring, and sidebar colors.

The variables currently contain complete `oklch(...)` colors. Do not wrap those values in `hsl(var(...))`. Pick and validate a Tailwind-3-compatible mapping and opacity strategy; either retain complete values with supported modifiers or migrate the palette to a consistent channel format. Verify generated CSS for actual `/opacity` usage before rolling out globally.

Use semantic classes for structural surfaces. Use explicit theme-aware tone maps for rate/status colors. Do not globally invert colors, replace every gray mechanically, or use universal CSS overrides that affect logos, charts, documents, and status meanings.

## 5. Component migration plan

| Area | Expected work |
| --- | --- |
| MainLayout and sidebar | Theme-aware page/header/sidebar surfaces, separators, breadcrumbs, active links, hover/focus states |
| Shared card primitives | Semantic backgrounds, foregrounds, borders; preserve layout and spacing |
| SummaryCard | Theme both default and record appearances; retain icon/image support, amount formatting, tone keys, existing props |
| RecordsSummarySection | Theme heading, panel, footer, loading text and empty state |
| WithholdingTaxRateSummary | Preserve rate-to-tone mapping and data contract |
| DataTable and table primitives | Header, rows, striping if present, hover, selection, loading, pagination, sticky sections, empty state |
| Search/filter controls | Input/select surface, placeholder, border, focus, disabled styles |
| Buttons/badges | Check every variant and ensure destructive/disabled states stay distinguishable |
| Dialogs/sheets/popovers/tooltips | Theme portal surfaces and backdrops; no white panels appearing over a dark app |
| Forms/upload review | Required/error/help text, drop zones, previews, validation notices, confirmation dialogs |
| Notifications | Verify Sonner resolves theme and rich-color messages remain readable |
| Charts/dashboard | Theme axes, grid, tooltip, legend, and series contrast without changing numeric data |
| Login/auth | Theme independent page backgrounds and form controls using the same provider |

Inventory all pages and hardcoded colors before implementation. Include Record pages, dashboard, entry/upload workflows, Importation forms, broker adjustment, View Info, master data, company management, Generate DAT, and any temporary reports still routed in the app. Check nested columns/cell renderers and not only page wrappers.

Preserve printable/exported document styling: PDF/print output stays explicitly light and legible unless separately requested. Preserve logos and uploaded content; use a suitable logo container or an existing alternate brand asset rather than color inversion.

## 6. Planned files

Known starting files:

- `tailwind.config.js`
- `resources/css/app.css`
- `resources/js/app.jsx`
- `resources/views/app.blade.php` if pre-paint initialization is required
- New `resources/js/Components/ThemeToggle.jsx`
- Optional `resources/js/Components/ThemeProvider.jsx`
- `resources/js/Layouts/MainLayout.jsx` and the actual login layout/page
- `resources/js/Components/app-sidebar.jsx` and its navigation children
- `resources/js/Components/Cards/SummaryCard.jsx`
- `resources/js/Components/Records/RecordsSummarySection.jsx`
- `resources/js/Components/ui/*` used by the application
- `resources/js/Components/DataTable/*`
- `resources/js/Pages/Records/*`, including column modules with fixed cell colors
- Remaining page/component files identified by the color inventory

Verify path casing against actual filenames; current imports contain both `Components` and `components`, which Windows can mask. Keep changed imports aligned with real paths so Linux builds are not broken.

No controller, database migration, calculation service, upload parser, route, or DAT generator change is expected.

## 7. Execution phases

1. **Inventory and baseline:** inspect current diff, applicable instructions, component usage, token generation, and document/export boundaries. Capture representative light screenshots.
2. **Theme foundation:** configure tokens, class mode, root provider, storage, initial paint, and the appearance selector. Verify root behavior on login and authenticated routes.
3. **Shell and primitives:** migrate header/sidebar, cards, buttons, inputs, tables, and portal components. Confirm the current light appearance remains usable.
4. **Records pilot:** apply the proposed appearance to all four summaries and tables. Compare Expanded with the reference and this preview. Preserve all server props/calculations.
5. **Application coverage:** complete the page inventory, including upload review, forms, master data, dialogs, charts, and error states.
6. **Verification:** build, run meaningful theme interaction checks, perform browser coverage, and run relevant business/DAT regressions where shared UI or integration edits warrant them.
7. **Delivery:** document implemented coverage, screenshots, tests, remaining exceptions, and rollback instructions. Do not call the entire app dark-mode-ready if pages remain unthemed.

## 8. Expected state behavior

| Scenario | Expected result |
| --- | --- |
| Select Dark | Shell, summary cards, controls, portals, and notifications adopt dark appearance together |
| Select Light | Existing light design remains readable and coherent |
| Select System | Appearance follows OS setting, including changes during an open session |
| Reload or navigate | Saved selection remains; no obvious white flash |
| Filter/search/sort/page | Existing response behavior remains; theme never changes summary values |
| Zero totals | Display `₱0.00`; do not imply missing records |
| Negative totals | Preserve minus sign and readable contrast |
| No matching records | Themed empty message and zero highlighted total; Expanded has no rate cards |
| Pending request | Existing busy indication remains readable; previous committed values stay visible |
| Validation/network error | Error text/toast remains readable; theme switch does not dismiss or mutate work |
| Open modal/dropdown | Portal uses the current theme, with clear focus and backdrop |
| Print/export | Document colors stay intentional and legible independently of app theme |

## 9. Verification and acceptance checklist

### Automated checks

- `npm run build` with the installed Node environment; inspect output for missing token/variant styles.
- Verify persisted Light/Dark/System selection, system preference changes, navigation retention, and cold reload without flash using a browser-capable test environment.
- Check localStorage failure handling and accessible selection state.
- Assert switching themes preserves the current URL/query, form values, filters, table rows, and summary text values.
- Check dialogs/toasts mounted through portals in both modes.
- Run existing Record and relevant DAT regression suites when integrating with the current app; theme changes must not alter their results.
- Do not invent a lint/test npm script: inspect available scripts first.

### Visual and accessibility checks

Review desktop, tablet, and phone widths, including 375px, 768px, 1280px, and 1536px. Cover summary wrapping, large/negative amounts, empty data, selected/hovered rows, disabled controls, validation errors, popovers, upload previews, and dashboard charts. Test keyboard navigation and reduced motion. Measure normal-text contrast at least 4.5:1 and large-text contrast at least 3:1; review non-text control/focus contrast as well.

### Definition of done

- Light, Dark, and System work throughout the routed application, including auth pages.
- Current preference survives reload/navigation and does not require a database.
- No accidental white surfaces or unreadable dark text remain in the audited workflows.
- Reusable components own shared styling; no duplicate summary implementations.
- Values, filters, validations, mutations, calculations, uploads, and DAT output are unchanged.
- Production build and applicable tests pass, and visual evidence is recorded.

## 10. Risks and rollback

The main risks are incomplete hardcoded-color coverage, Tailwind version/token incompatibility, incorrect first paint, portal mismatches, and dark colors leaking into print/PDF output. Address these through the phased inventory and representative workflow checks, not a global stylesheet override.

Keep theme infrastructure and component migrations in reviewable changes. Rollback removes the provider/control and reverts theme styling without touching stored business data. The theme preference key can remain harmlessly stored until the next supported implementation; no destructive cleanup is needed.

## 11. This task's validation boundary

Created only this plan and the standalone HTML sample. No application runtime files were changed. The sample is self-contained and has no external asset requests. A real-browser screenshot/visual comparison has not been captured in this session; the HTML preview is the expected-output artifact, not evidence of production rendering.

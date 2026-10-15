# Reusable DataTable implementation plan

Status: Full application table rollout implemented after the Expanded pilot. See the full rollout verification section at the end.

## Pilot delivery (2026-10-15)

- Installed TanStack React Table 8.21.3 and updated the lockfile. The shared DataTable handles rendering, optional selection, column visibility, manual sorting/pagination, toolbar slots, loading/empty states, and result counts.
- Added `useInertiaDataTable` for debounced search, controlled navigation, query preservation, and URL state synchronization.
- Expanded is the only migrated page. Its column definitions retain the existing badges and View Info source IDs. It uses the existing consolidated `row.id` as its stable key; no new identity/schema is needed.
- Expanded sorting runs after consolidation and before pagination; summaries still use all filtered records. Page sizes are validated and out-of-range pages recover to the last available page.
- Selection is supported by the shared component but disabled on Expanded because it has no bulk action.
- No changes to Purchase, Sales, Importation, Customers, Suppliers, existing shared pagination consumers, uploads, DM calculations, or DAT serialization.
- Validation: 110 focused backend tests passed (1,458 assertions), production Vite build passed, and the production Expanded UI passed browser checks against a local fixture server: column visibility, sort requests, scoped pagination, page size, debounced search, empty state, View Info source routing, browser history, and mobile containment. Browser fixtures do not use or mutate the application database.
- Browser test: `tests/Browser/expanded-data-table.cjs`. Build first, then run with Node and Playwright installed; `PLAYWRIGHT_MODULE` can point to a temporary Playwright module and `CHROME_PATH` can override the Chrome executable. This verifies UI integration; PHP feature tests verify actual Laravel behavior.
- Fixed the new Columns trigger locally to use Radix's own button with existing button styles, because the existing JSX Button does not forward refs under React 18. Existing global buttons remain unchanged.

The sections below retain the rollout design. The pilot notes above describe the initial delivery; the full rollout notes at the end describe the completed follow-up.

## 1. Objective

Build one reusable React JSX DataTable using the existing shadcn UI primitives and TanStack Table. Pages supply column definitions, rows, controlled state, and callbacks. They should not duplicate table markup, sorting controls, column menus, empty/loading states, or pagination.

Deliver the shared component and migrate Expanded WTAX, Purchase, Sales, Importation, Customers, and Suppliers in stages. Preserve each page's dialogs, permissions, validation, accounting calculations, and existing actions.

## 2. Verified project fit

- React 18, Inertia React, Tailwind 3, and JavaScript/JSX are already configured.
- `components.json` uses `tsx: false` and a Radix shadcn style. Continue with this existing component family.
- `Components/ui/table.jsx` already provides shadcn table primitives. TanStack Table is not declared in package.json.
- Four record pages use `RecordTableShell.jsx` and the existing `Layouts/Pagination.jsx`.
- Customers and Suppliers have their own layouts and filters; each currently paginates at 10 rows. Record lists commonly use 15.
- Expanded records are consolidated and validated on the server before collection pagination. Its summary uses all filtered rows, not just the visible page.
- Existing imports mix `@/components` and `@/Components`. New files must use the actual `Components` directory casing consistently so Linux deployment works. Avoid a repository-wide casing refactor.

## 3. Architecture and ownership

### Shared UI

`DataTable` owns rendering and generic table interaction. It must not import Inertia routing, know API URLs, calculate taxes, consolidate records, or perform mutations.

Internal components provide the toolbar, sortable headers, visibility menu, pagination, and optional selection controls. A page normally imports only `DataTable`; column files may use the shared header helper.

### Shared Inertia adapter

`useInertiaDataTable` translates page/search/sort/filter changes into GET navigation. It receives the endpoint and current query state from the page. It preserves page-specific filters and context, synchronizes browser navigation, debounces search, and exposes loading state.

Only the adapter depends on Inertia. The same table can later be reused with another data source without copying it.

### Page and backend

The page supplies data, columns, stable row IDs, filter controls, optional selection/actions, and dialog callbacks. Controllers own server filtering, allowed sorting, pagination, permissions, and response data.

Financial totals, consolidation, DAT generation, uploads, DM operations, and master-data validation remain domain responsibilities.

## 4. Proposed files

```text
resources/js/Components/DataTable/
  DataTable.jsx
  DataTableToolbar.jsx
  DataTableColumnHeader.jsx
  DataTableColumnVisibility.jsx
  DataTablePagination.jsx
  index.js
resources/js/hooks/
  useInertiaDataTable.js
resources/js/lib/
  dataTable.js                 # paginator normalization / query utilities
resources/js/Pages/Records/columns/
  expandedWtaxColumns.jsx
  purchaseColumns.jsx
  salesColumns.jsx
  importationColumns.jsx
resources/js/Pages/columns/
  customerColumns.jsx
  supplierColumns.jsx
```

Add compatible checkbox/dropdown primitives only if required and absent. Reuse existing Button, Input, Select, Table, and styling utilities. Install a stable React-18-compatible TanStack release after checking its documentation; commit the lockfile with the dependency. Do not copy examples from a different TanStack major or overwrite the existing shadcn configuration.

## 5. Public component contract

| Prop | Purpose |
| --- | --- |
| `columns` | TanStack column definitions: ID, accessor, cell renderer, label, sorting/hiding capability, alignment |
| `data` | Current server page of rows |
| `getRowId` | Required stable identity function; never array position |
| `pagination` | Controlled `{ pageIndex, pageSize }`, using zero-based UI indexing |
| `rowCount` | Total matching displayed records supplied by server |
| `onPaginationChange` | Callback for page or page-size changes |
| `sorting`, `onSortingChange` | Controlled single-column sort state |
| `search` | Optional `{ value, onChange, placeholder }` |
| `filters` | Slot for page-specific filter controls |
| `toolbarActions` | Slot for Add/Upload/other existing actions |
| `loading` | Pending request indicator and control state |
| `emptyState` | Page-specific title/message and optional action |
| `columnVisibility`, `onColumnVisibilityChange` | Optional controlled visibility; otherwise table uses local state |
| `enableRowSelection` | Opt-in boolean or per-row eligibility function; false by default |
| `rowSelection`, `onRowSelectionChange` | Controlled selected IDs when selection is enabled |
| `selectionActions` | Optional renderer receiving selected current-page rows |
| `className`, `tableClassName` | Layout and minimum width customization |

Use JSDoc for the contract and helpers. Accept TanStack updater functions correctly; do not assume callbacks always receive plain values.

Initial delivery uses manual/server pagination, sorting, and filtering. Do not silently enable client sorting/filtering on a partial server page. A separate client-data mode is deferred until there is a real page needing it.

## 6. Example page usage

Illustrative API to be finalized during implementation:

```jsx
const table = useInertiaDataTable({
  url: '/records/expanded-wtax',
  paginator: expandedWtaxEntries,
  query: filters,
  defaultPageSize: 15,
});

const columns = useMemo(
  () => expandedWtaxColumns({ onView: setSelectedInfoRecord }),
  []
);

<DataTable
  columns={columns}
  data={expandedWtaxEntries.data}
  getRowId={(row) => row.table_row_key}
  pagination={table.pagination}
  rowCount={expandedWtaxEntries.total}
  onPaginationChange={table.onPaginationChange}
  sorting={table.sorting}
  onSortingChange={table.onSortingChange}
  search={table.search}
  filters={<ExpandedFilters query={table.query} onChange={table.setFilters} />}
  loading={table.loading}
  emptyState={{ title: 'No Expanded WTAX records found' }}
/>
```

`table_row_key` and `ExpandedFilters` in this example are proposed, not existing APIs. Prefer an existing stable consolidation key when available. Otherwise expose a display-only composite key derived from the existing consolidation identity; no database migration is needed. Keep record identifiers used by existing dialogs/actions unchanged.

## 7. Query and server contract

- Standard query keys: `page`, `per_page`, `sort`, `direction`, plus existing page-specific search/filter keys.
- Preserve existing default page sizes; offer 10, 15, 25, 50, and 100. Validate allowed sizes server-side; do not add an unlimited option.
- Convert zero-based UI page index to Laravel's one-based page. Normalize `data`, `total`, `current_page`, `per_page`, and `last_page` from paginator props.
- Preserve existing default ordering until the user explicitly sorts. Only expose sorting on supported columns.
- Controllers whitelist sort IDs and direction; never pass arbitrary query strings as SQL identifiers.
- Use deterministic secondary ordering so equal values cannot jump between pages.
- Query-backed lists sort before pagination. Consolidated lists sort their resulting display rows before pagination, including numeric/date comparisons for monetary and date columns. Do not sort formatted currency strings.
- Keep existing filter semantics. Expanded search currently filters source data before consolidation; do not change this as part of the table migration. Any new status or aggregate filter must operate at its correct stage before pagination.
- Filtering, search, sorting, and page-size changes reset to page 1. Page navigation preserves all other query state.
- Preserve Expanded company, branch, report-type, rate, and period scope from DM links. Preserve customer/supplier fix-queue context through their existing query contract.
- Totals and rate summaries continue to describe the full filtered dataset and do not change with pagination or column visibility.
- Empty datasets show zero results and disable navigation. Recover invalid/out-of-range pages consistently after deletes or filter changes.

## 8. Interaction and appearance

- Match the app's light theme, spacing, borders, badges, and current monetary formatting; support existing theme tokens.
- Toolbar contains optional search, page-supplied filters/actions, and a Columns menu.
- Sortable headers use real buttons with accessible labels and sort indicators. Non-sortable headers show no sort affordance.
- Hide/show only eligible columns. Keep required identity/selection/action columns visible and prevent an unusable all-hidden table.
- Footer shows result range, filtered total, rows per page, and navigation even when there is only one page.
- Horizontal scrolling stays inside the table; controls wrap on narrow screens. Avoid nested horizontal scroll containers.
- Loading retains existing rows with an accessible busy indicator; disable duplicate navigation and selection while pending. Initial loading can use skeleton rows. Do not display a false empty-state message during loading.
- Debounce search around 300 ms, cancel obsolete requests/timers, preserve focus, and synchronize controls when the URL changes through back/forward navigation.
- Preserve state/scroll for ordinary table navigation. Request failure leaves data and controls usable and surfaces the existing error feedback.
- Visibility is local to each mounted table initially. Browser-storage persistence and column resizing/reordering are deferred.

## 9. Selection and actions

- Selection is optional and initially disabled on pages with no approved bulk operation.
- Header checkbox selects eligible rows on the current page and explicitly says so. Display the indeterminate state and selected count.
- Clear selection on pagination, filter/sort changes, and dataset refresh. Do not imply selection across all matching records.
- Bulk operations remain page-owned and retain confirmation/permission checks. An all-results selection model requires a separate backend contract and is outside this plan.
- Existing View/Edit/Delete controls remain available with their current dialogs and behavior. Use a shared dropdown only where it preserves discoverability and accessibility; do not force every page into one action menu.

## 10. Migration sequence

1. **Foundation:** install compatible TanStack dependency, add missing UI primitives, build shared components/adapter and query normalization. Verify the API using the Expanded page.
2. **Expanded WTAX:** extract columns; preserve validation badges, source-count/consolidation details, View dialog, DM scope links, summary cards, and signed amount formatting. Add supported server sorting and configurable page size.
3. **Purchase:** migrate columns, filters, actions, and controller pagination/sorting while preserving company filters and row semantics.
4. **Sales:** migrate after verifying sorting and counts reflect the existing consolidated display rows.
5. **Importation:** migrate its existing listing and actions without altering Purchase mirror handling.
6. **Customers and Suppliers:** migrate table portions and query adapter; retain forms, field counters, fix queues, return context, and confirmation dialogs.
7. **Cleanup:** inventory remaining uses of RecordTableShell and Layouts/Pagination. Remove or adapt only when all intended consumers have migrated. Keep other standalone tables outside this initial scope.

Each stage should be reviewable and tested before proceeding to the next. One shared table renderer must serve all migrated pages; column definitions and business callbacks remain page-specific.

## 11. Verification and acceptance

### Backend/integration

- Sorting covers records beyond the first page, including negative/zero amounts, numeric rates, dates, and ties.
- Consolidated rows retain their identity, amounts, counts, and validation details under sort/page changes.
- Validated page sizes and unsupported sort parameters behave consistently.
- Search/filter changes reset pagination; DM scope and master-data fix context survive table navigation.
- Empty and out-of-range pages recover correctly.
- Expanded filtered summaries remain identical across pages and sorts.
- Existing view/edit/delete, upload/DM, and DAT regression checks appropriate to changed code pass.

### Frontend/browser

- Verify shared table behavior on Expanded and smoke-test all six migrated screens.
- Search debounce, request failure, back/forward URL sync, page-size changes, sorting, visibility, and zero-result states work.
- Verify optional selection with eligible/ineligible rows, indeterminate state, page transitions, and dataset refresh.
- Keyboard navigation and accessible names work for menus, sorting, checkboxes, and pagination.
- Wide tables scroll inside their container on mobile; dialogs remain usable; currency alignment/formatting is preserved.
- Production frontend build, focused automated tests, and diff checks pass. Report any baseline failures separately with reproduction evidence.

### Completion criteria

All six target pages call the same DataTable, with no duplicated table-body/pagination implementation. Their existing domain workflows still work. No accounting values, stored data, DAT serialization, or database schema change as a result of the UI migration.

## 12. Boundaries and follow-up features

No new bulk delete/export operation, automatic all-results selection, inline editing, virtualization, saved views, drag-and-drop columns, database migration, or unrelated redesign is included. These can be added later against the shared contract when requested.

## Reference

Official shadcn Data Table guide: https://ui.shadcn.com/docs/components/base/data-table . Use the Radix variant matching this project's configured primitives during implementation, and verify the chosen TanStack version's APIs.

## Full table rollout (2026-10-15)

The user's follow-up expanded scope to all existing application tables. Purchase, Sales, Importation, Customers, Suppliers, Companies, and Brokers now use the same DataTable renderer as Expanded. Dashboard recent entries, Original Broker Record, and DM review use its unpaginated preview mode, keeping every supplied review row visible. The only remaining native table markup in resources/js is inside DataTable itself.

- `InertiaDataTable` adapts existing paginator props and preserves URL/workflow context. Existing page filter forms and actions are retained.
- `DataTableQuery` validates page sizes and sortable fields, handles query/collection sorting, and recovers out-of-range pages. Sales sorts consolidated rows. Purchase uses its existing amount presenter before sorting computed display fields; ordinary Purchase sorting still paginates in SQL. Sorting computed Purchase fields loads all matching rows and may cost more on very large filtered datasets.
- Brokers already supplies its full filtered result set, so it uses explicit client sorting/pagination. Preview tables disable toolbar, sorting, and pagination. DM confirm/cancel and deduction rules are unchanged.
- Filter changes preserve table options and existing fix-queue context and reset the page. Existing form validation, column contents, permissions, and action handlers remain in their pages.
- Focused regression run: 159 tests passed, 2,433 assertions. Includes cross-page descending sort, page-size validation, out-of-range recovery, presented Purchase amount ordering, master data, adjustments, DM, and pending Purchase uploads.
- Production Vite build passed. `all-data-tables.cjs` uses production assets and isolated fixture responses to verify migrated listing controls, View Info, previews, and mobile containment; `expanded-data-table.cjs` retains the Expanded browser coverage.
- Separate PendingSalesUploadTest run: 6 failures and 1 pass. The same failures reproduce with the original HEAD versions of all modified controllers loaded by a temporary bootstrap (missing uploadIssueDialog / missing PendingSalesUpload). This pre-existing upload issue is not changed by the table rollout.
- No database migration or live data mutation was performed.

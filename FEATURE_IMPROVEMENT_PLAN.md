# Feature Improvement Plan

## Goal
Move from working scaffold to production-grade behavior by improving each feature module in a fixed sequence with measurable acceptance criteria.

## Delivery Approach
- Improve one module at a time.
- For each module, complete: business rules, UX refinement, validation hardening, authorization coverage, and tests.
- Do not move to next module until acceptance checklist passes.

## Global Standards (Apply To Every Module)
- Validation:
  - Replace inline controller validation with dedicated Form Request classes.
  - Add conditional rules for status transitions and role-specific actions.
- Authorization:
  - Add/complete policies and gate checks for every mutating action.
  - Enforce location scoping for non-admin roles.
- Transactions & Concurrency:
  - Keep stock/money mutations inside DB transactions.
  - Use row-level locks where race conditions are possible.
- UX Pattern:
  - Index + right offcanvas for create/edit CRUD screens.
  - Preserve drawer state on validation errors.
  - Standardize flash + inline field errors.
- Auditability:
  - Add consistent audit entries for create/update/delete/post/approve/void actions.
- Testing:
  - Add feature tests for happy path + invalid path + forbidden path.
  - Add critical service tests for mutation logic.

## Sequence

### Phase 1: Foundation Hardening
Status: Pending

Scope:
- Extract Form Requests for all existing CRUD controllers.
- Complete policy coverage and route-level authorization middleware.
- Add reusable Blade form-error and status components.
- Implement shared offcanvas partial/component to reduce duplication.

Acceptance Criteria:
- No inline validation remains in target controllers.
- All create/update/delete/post routes are authorization protected.
- CRUD screens use consistent offcanvas behavior and error rendering.
- Existing tests pass + new auth/validation tests added.

### Phase 2: Master Data (Brands, Categories, Products, Suppliers, Customers, Locations)
Status: Pending

Scope:
- Finalize business constraints:
  - Prevent deleting referenced records unless safe.
  - Add active/inactive safeguards.
  - Improve search/filter/sort/pagination controls.
- Product UX upgrades:
  - Better brand/category selectors.
  - Guardrails for pricing and serialization fields.

Acceptance Criteria:
- Safe delete rules enforced and tested.
- Search/filter works on each master data index.
- Offcanvas forms are complete and stable on mobile + desktop.
- Feature tests cover CRUD + constraints.

### Phase 3: Purchasing (Purchase Orders + Goods Receipts)
Status: Pending

Scope:
- Enforce PO lifecycle transitions (draft -> approved -> posted/cancelled).
- GRN posting integrity checks:
  - Received qty <= ordered qty.
  - Required serials for serialized products.
- Improve line-item UX with dynamic totals and validation feedback.

Acceptance Criteria:
- Invalid transitions are blocked with clear messages.
- Posting updates stock ledger + inventory balances atomically.
- Tests cover partial receipt, duplicate serial, and over-receipt cases.

### Phase 4: Inventory + Serial Tracking
Status: Pending

Scope:
- Harden serial status machine (in_stock, reserved, sold, returned, service, scrapped).
- Add serial lookup and timeline view.
- Reconcile stock ledger vs inventory balance with diagnostics endpoint/command.

Acceptance Criteria:
- Serial state transitions are validated and audited.
- Reconciliation identifies mismatch scenarios reliably.
- Tests cover serialized and non-serialized stock movements.

### Phase 5: Stock Transfers
Status: Pending

Scope:
- Enforce transfer lifecycle (requested -> approved -> issued -> received/cancelled).
- Validate source availability at issue time using locks.
- Add receiving variance handling (if allowed by policy) and audit notes.

Acceptance Criteria:
- Cross-location stock movement is atomic and traceable.
- Unauthorized location access is blocked.
- Tests cover concurrency and insufficient stock edge cases.

### Phase 6: Sales + Invoicing
Status: Pending

Scope:
- Improve invoice drafting and posting rules.
- Serial assignment reliability and oversell prevention.
- Add price override approval flow and role constraints.
- Add printable invoice format polish.

Acceptance Criteria:
- Cannot post invoice with unavailable serial/stock.
- Price override requires proper permission trail.
- Posting creates expected AR and stock ledger entries.
- Tests cover cash/credit flows and posting failures.

### Phase 7: Payments + Receivables
Status: Pending

Scope:
- Strengthen allocation rules across invoices/installments.
- Add partial payment handling and overpayment guardrails.
- Improve AR aging and statement accuracy.

Acceptance Criteria:
- Payment allocation deterministic and idempotent.
- AR ledger balances match invoice/payment totals.
- Tests cover split allocation, reversal, and mismatch prevention.

### Phase 8: Installment Engine
Status: Pending

Scope:
- Finalize schedule generation rules.
- Late fee and overdue transitions.
- Reschedule and write-off controls with approvals.
- Reminder event hooks for queue jobs.

Acceptance Criteria:
- Installment status transitions are correct and auditable.
- Rescheduling preserves accounting integrity.
- Tests cover overdue marking, reschedule, and allocation behavior.

### Phase 9: Returns, Warranty, Service Jobs
Status: Pending

Scope:
- Implement full return resolution matrix:
  - restock, replace, repair, refund, reject.
- Warranty eligibility checks and service workflow transitions.
- Link returns to serial lifecycle and financial adjustments.

Acceptance Criteria:
- Every resolution path updates stock + AR correctly.
- Warranty validation rules are enforced.
- Tests cover in-warranty and out-of-warranty cases.

### Phase 10: Reports, Forecasts, Notifications, Exports
Status: Pending

Scope:
- Harden report filters and data correctness.
- Forecast generation command + caching strategy.
- Queue-based exports and notification retries/failures.

Acceptance Criteria:
- Reports reconcile with ledgers/inventory snapshots.
- Forecast refresh is schedulable and test-covered.
- Export and notification jobs are observable and retry-safe.

## Execution Rhythm Per Phase
1. Audit current code for the phase and list gaps.
2. Implement rules/services/controllers/requests.
3. Upgrade views (offcanvas + usability).
4. Add/expand tests.
5. Run full test suite and route sanity checks.
6. Mark phase complete with change log.

## Immediate Next Step
Start Phase 1 by introducing Form Requests + policy checks for master data controllers, then refactor offcanvas into a reusable component.

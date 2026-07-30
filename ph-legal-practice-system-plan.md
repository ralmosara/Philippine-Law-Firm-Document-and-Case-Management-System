# PH Legal Practice Management System — Full Technical Plan

**Stack:** Laravel 11 (API) + React 18 (SPA) + PostgreSQL 16
**Scope:** Full-firm practice management for Philippine law firms — case management, reglementary period automation, trust accounting, document automation, compliance tracking.

---

## 1. Architecture Overview

Monolith-first, API-driven. A single Laravel app serves as the source of truth and exposes a versioned REST API (`/api/v1`); React consumes it as a separate SPA (Vite build), deployed independently so backend and frontend can scale/release on their own cadence. No microservices — this domain doesn't need them, and a distributed system would just slow down a solo/small-team build without adding real value.

```
┌─────────────────┐      ┌──────────────────────────┐      ┌───────────────┐
│   React SPA      │◄────►│   Laravel API (Sanctum)   │◄────►│  PostgreSQL   │
│  (Vite, TS)      │ REST │   - Domain services       │      │  16           │
└─────────────────┘      │   - Queue workers          │      └───────────────┘
                          │   - Scheduler (deadlines)  │
                          └──────────────┬────────────┘
                                         │
                          ┌──────────────▼────────────┐
                          │  Redis (queues, cache)     │
                          │  Meilisearch (search)      │
                          │  Local disk storage        │
                          │  (Laravel `local` driver)  │
                          └────────────────────────────┘
```

**Why this shape:**
- The two features that actually justify senior architecture — reglementary period calculation and trust ledger reconciliation — are both **business rule engines with strict correctness requirements**, not CRUD. They live in dedicated domain service classes, fully unit-tested, independent of HTTP/controller concerns.
- Case status changes, deadline events, and trust transactions are all **append-only, event-sourced logs** rather than mutable rows with an "updated_at" and a prayer. Auditability is a legal requirement here, not a nice-to-have.
- Everything deadline-related runs through the **scheduler + queue**, never inline on a request — a missed deadline is a malpractice risk, so the reminder pipeline needs to survive a slow request cycle, a deploy, a crashed worker.

---

## 2. Database Design

### 2.1 Design principles
- PostgreSQL over MySQL specifically for: native `JSONB` (case metadata, template merge-fields), `tstzrange` for validity periods (SPA/notarial validity), row-level constraints (`CHECK`) for state machine enforcement, and better concurrent write behavior for the trust ledger.
- Every domain-critical table (case status, trust transactions, deadline events) is **append-only**. Corrections are new rows referencing the row they correct, never `UPDATE`/`DELETE`.
- Soft deletes (`deleted_at`) only on non-financial, non-audit tables (clients, documents). Financial and audit tables are never soft-deleted — they're immutable by design.

### 2.2 Core schema (abbreviated — key tables only)

```sql
-- ── Firms / Users ────────────────────────────────────────────
firms(id, name, tin, created_at)
users(id, firm_id, name, email, role, ibp_number, mcle_compliance_until, created_at)
roles(id, name)               -- partner, associate, paralegal, staff, client

-- ── Clients / Matters ────────────────────────────────────────
clients(id, firm_id, name, type, tin, created_at, deleted_at)
matters(id, firm_id, client_id, case_number, case_type, court_branch,
        judge, docket_number, status, opened_at, closed_at)
matter_parties(id, matter_id, party_type, name, counsel_of_record_id)

-- state machine — enforced via a real package (spatie/laravel-model-states or
-- equivalent transition-table approach), not just an enum column. A bare enum
-- doesn't stop application code from jumping straight from 'filed' to 'closed';
-- a state machine package defines legal transitions explicitly and throws on
-- anything else, so the invalid jump is caught at the model layer, not by
-- convention.
CREATE TYPE matter_status AS ENUM
  ('intake','filed','pre_trial','trial','decision','appeal','closed');

-- append-only audit trail — the case "history log"
matter_status_events(id, matter_id, from_status, to_status,
                      changed_by, reason, created_at)

-- ── Reglementary periods / deadlines ─────────────────────────
deadline_rules(id, name, trigger_event, period_days, period_type,
               -- period_type: 'calendar' | 'working_days'
               computation_notes)
matter_deadlines(id, matter_id, deadline_rule_id, trigger_date,
                  computed_due_date, status, escalation_stage)
holiday_calendar(id, date, description, is_national)  -- PH SC-recognized non-working days

deadline_events(id, matter_deadline_id, event_type, -- 'reminder_sent','extended','missed','met'
                 payload JSONB, created_at)          -- append-only

-- ── Documents ─────────────────────────────────────────────────
document_templates(id, firm_id, name, category, body, merge_fields JSONB)
documents(id, matter_id, template_id, current_version_id, created_by)
document_versions(id, document_id, version_no, file_path, diff_summary, created_by, created_at)
notarial_entries(id, document_id, doc_no, page_no, book_no, series_year, notarized_by, notarized_at)
document_validity(id, document_id, valid_from, valid_until) -- tstzrange-backed

-- ── Trust accounting (the other hard part) ───────────────────
trust_accounts(id, client_id, matter_id, account_no, opened_at)
trust_transactions(
  id, trust_account_id, type,          -- 'deposit' | 'disbursement'
  amount_cents, currency, reference,
  balance_after_cents,                  -- computed, never trusted from input
  created_by, created_at
)
-- CHECK constraint: balance_after_cents = previous balance +/- amount, enforced
-- via a Postgres trigger, not application code, so it can never be bypassed by
-- a raw query or a bug in a service class.

-- ── Billing ───────────────────────────────────────────────────
time_entries(id, matter_id, user_id, minutes, rate_cents, billed, created_at)
invoices(id, client_id, matter_id, status, subtotal_cents, total_cents, issued_at)
invoice_lines(id, invoice_id, description, amount_cents, source_type, source_id)

-- ── Compliance ────────────────────────────────────────────────
mcle_credits(id, user_id, activity, credits, earned_at, compliance_period)
conflict_checks(id, matter_id, checked_by, matched_client_ids JSONB, cleared, created_at)

-- ── Multi-tenancy enforcement (RLS, not just convention) ──────
-- Every firm-scoped table gets a policy like this. Application code sets
-- the current firm via `SET app.current_firm_id = ?` per request/session;
-- Postgres refuses to return rows outside that scope regardless of what
-- the query itself says.
ALTER TABLE matters ENABLE ROW LEVEL SECURITY;
CREATE POLICY firm_isolation ON matters
  USING (firm_id = current_setting('app.current_firm_id')::bigint);
```

### 2.3 Key design decisions
| Decision | Rationale |
|---|---|
| Money as `_cents` bigint, never `float`/`decimal` in app logic | Standard for financial correctness; avoids float rounding bugs in the trust ledger |
| `balance_after_cents` computed by DB trigger | Reconciliation integrity can't depend on every code path getting the math right |
| `matter_status_events` + `deadline_events` append-only | Both are legal/audit requirements — "what did we know and when" must be reconstructable |
| Holidays in their own table, not hardcoded | PH Supreme Court/Malacañang can declare special non-working days ad hoc; this must be a data problem, not a deploy |
| Row-Level Security (RLS) on `firm_id`, not just a `WHERE` clause convention | A plain `firm_id` column relies on every query being written correctly forever — one missed clause leaks one firm's data into another's results. A Postgres RLS policy enforces the tenant boundary at the DB layer itself, same philosophy as the trust ledger trigger: don't trust application code to get it right on every code path |

---

## 3. Backend Plan (Laravel)

### 3.1 Structure
Domain-oriented, not the default `app/Http/Controllers` + `app/Models` flat structure once the app grows past a few modules:

```
app/
  Domain/
    Matters/        (Models, Services, Events, Actions)
    Deadlines/       ← the reglementary period engine lives here
    Documents/
    Trust/           ← the trust ledger engine lives here
    Billing/
    Compliance/
  Http/
    Controllers/Api/V1/
    Requests/
    Resources/
  Jobs/
  Console/Commands/
```

Controllers stay thin — they validate (`FormRequest`), call a domain `Action` class, return an `ApiResource`. All business logic (deadline math, trust balance checks, conflict search) lives in `Domain/*/Services`, fully unit-testable without touching HTTP.

### 3.2 The reglementary period engine (the flagship feature)
```
Domain/Deadlines/Services/DeadlineCalculator.php
```
- Input: trigger event (e.g. "receipt of adverse decision"), a `DeadlineRule` (e.g. 15 calendar days for ordinary appeal, 10 days for TRO application), trigger date.
- Looks up `holiday_calendar` and applies PH Rules of Court counting conventions (exclude the first day, include the last; if last day falls on weekend/holiday, move to next working day).
- Emits a `matter_deadlines` row + schedules escalation jobs (72hr/24hr/day-of) via Laravel's queue + scheduler, dispatched as `SendDeadlineReminder` jobs so a slow request or deploy never silently drops a reminder.
- This is the single class most worth writing extensive unit tests against — feed it historical PH holiday calendars and known correct due dates as fixtures.

### 3.3 The trust ledger engine
```
Domain/Trust/Services/TrustLedgerService.php
```
- Every deposit/disbursement goes through `recordTransaction()`, which runs inside a DB transaction, locks the account row (`SELECT ... FOR UPDATE`), computes the new balance, and inserts — never updates. Reconciliation reports are pure aggregation queries over the immutable log, so they can be trusted independent of the ledger service's own correctness.
- A nightly `ReconcileTrustAccounts` command flags any account where the running balance (recomputed from scratch) diverges from the last `balance_after_cents` — this should be mathematically impossible if the trigger + service are correct, but the check exists because trust account errors are a bar disciplinary matter, not just a bug.

### 3.4 Other backend concerns
- **Auth:** Laravel Sanctum (SPA cookie-based auth), roles/permissions via `spatie/laravel-permission`.
- **Search:** Laravel Scout + Meilisearch over documents and case history.
- **Documents:** merge-field rendering via a templating layer (Blade-based or `phpoffice/phpword` for `.docx` output), version diffs computed and stored at write-time, not on read.
- **Internal admin tooling:** the boring 60% — client records, user/role management, template management — doesn't need hand-built React screens. Filament (Laravel-native admin panel) can cover this in a fraction of the time, freeing custom React work for the screens that actually differentiate the product: the deadline calendar, the document diff viewer, the trust ledger dashboard. Worth reserving custom frontend effort for those, not the CRUD forms.
- **Notifications:** SMS via a local PH gateway (e.g. Semaphore/Movider) + email, both queued.
- **Storage caveat:** local disk means the server itself is a single point of failure for documents and the trust/deadline PDFs generated from them — no redundancy, no built-in offsite copy. If this ever goes to production with real client documents, at minimum add a scheduled `rsync`/backup job to a second location; that's a much smaller lift now than migrating storage logic later, precisely because all file access already goes through `Storage::disk('local')` rather than raw paths.
- **Database durability caveat:** the same single-point-of-failure risk applies to Postgres itself, and here the stakes are higher — this is where trust account balances live. Before this ever holds real client money, it needs at minimum a scheduled `pg_dump` to offsite storage, and ideally streaming replication to a standby. A trust ledger that's perfectly correct in its transaction logic but lives on one unbacked disk is still a bar disciplinary risk waiting to happen.

---

## 4. Frontend Plan (React)

### 4.1 Structure
```
src/
  features/
    matters/         (list, detail, timeline)
    deadlines/        ← calendar view, conflict detection UI
    documents/         (editor, version diff viewer, template picker)
    trust/             (ledger view, reconciliation dashboard)
    billing/
    compliance/
  shared/
    api/              (typed API client, generated or hand-written from OpenAPI)
    components/       (design system primitives)
  app/                (routing, providers)
```
- **State:** server state via TanStack Query (cache, invalidation, optimistic updates for things like status changes); client/UI state via minimal local `useState`/`useReducer` — no Redux, the domain doesn't need it once server state is handled properly.
- **Forms:** React Hook Form + Zod schemas mirrored from the Laravel `FormRequest` validation rules, so validation errors match 1:1 between client and server.
- **Deadline calendar & conflict detection:** a calendar view (e.g. built on `react-big-calendar` or custom) that visually flags same-lawyer/same-time conflicts across courts — this is the single highest-value UI screen since it's the daily-use surface for the flagship feature.
- **Document diff viewer:** side-by-side redline rendering between `document_versions`, computed server-side, rendered client-side with a diff-highlighting component.
- **Client portal:** a separate, restricted React route tree (or separate build) with read-only views, since its permission surface is fundamentally different from the internal app.

### 4.2 API contract
Laravel API Resources define the JSON shape; an OpenAPI spec is generated from route + FormRequest annotations (`dedoc/scramble` or hand-maintained) and used to generate a typed TS client — keeps frontend and backend from drifting silently.

---

## 5. Compliance & Reporting Modules
- **Conflict-of-interest check:** on new matter intake, a service searches `matter_parties` + `clients` across the firm for name matches (fuzzy match via Meilisearch, not just exact), surfaces potential conflicts before the matter is created.
- **MCLE tracker:** simple credit ledger per lawyer against IBP compliance periods, with renewal reminders reusing the same notification pipeline as deadlines.
- **Reporting:** materialized views (Postgres) refreshed nightly for caseload/win-loss/revenue dashboards — keeps heavy aggregation off the request path.

---

## 6. Phased Roadmap

| Phase | Scope | Why this order |
|---|---|---|
| **1 — Foundation** | Firms/users/roles, RLS tenant isolation, matters, case status state machine (package-enforced, not bare enum) + audit log | Nothing else works without a matter to attach to, and tenant isolation is far cheaper to get right from day one than to retrofit |
| **2 — Flagship feature** | Deadline rules, holiday calendar, calculator engine, reminder pipeline, deadline calendar UI | This is the sellable, defensible feature — build and harden it early |
| **3 — Documents** | Templates, merge-fields, versioning, notarial register | High daily-use value, moderate complexity |
| **4 — Trust & Billing** | Trust ledger + trigger-enforced integrity, time tracking, invoicing | Financial correctness needs the most test coverage — budget real time here |
| **5 — Compliance & Reporting** | Conflict checks, MCLE, dashboards | Rounds out the firm-management story |
| **6 — Client portal** | Restricted read-only surface | Lowest risk, can ship last without blocking internal use |

---

## 7. Tech Stack Summary

| Layer | Choice |
|---|---|
| Backend | Laravel 11, PHP 8.3 |
| Database | PostgreSQL 16 |
| Cache/Queue | Redis |
| Search | Meilisearch (via Scout) |
| Frontend | React 18 + TypeScript, Vite |
| Server state | TanStack Query |
| Forms/validation | React Hook Form + Zod |
| Auth | Laravel Sanctum |
| Multi-tenancy | Postgres RLS on `firm_id` |
| Case state machine | `spatie/laravel-model-states` (or equivalent transition-table package) |
| Internal admin UI | Filament (client records, users, templates — not custom-built) |
| File storage | Local disk via Laravel's `Storage` facade (`local` driver) — code never touches disk paths directly, so switching to `s3` later is a config change, not a rewrite |
| Doc generation | `phpoffice/phpword` or Blade → PDF pipeline |

---

**Bottom line:** the CRUD (matters, clients, users) is the easy 60% of this build. The two modules worth spending disproportionate design and test time on are the **deadline calculator** and the **trust ledger** — both are business rule engines with real legal/financial correctness requirements, and both are the reason this reads as a senior-level system rather than a template SaaS.

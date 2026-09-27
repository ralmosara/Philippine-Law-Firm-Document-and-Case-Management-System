# Lex PH — Practice Management for Philippine Law Firms

Case, deadline, document, trust-account and billing management built around
Philippine practice: Rules of Court reglementary periods, the notarial
register, IBP/MCLE compliance, 12% VAT billing, and client trust accounting.

**Stack:** Laravel 13 (PHP 8.4) API · React 19 + TypeScript SPA · PostgreSQL 16 · Redis

---

## What it does

| Area | Capabilities |
|---|---|
| **Matters & clients** | Matters with an enforced status lifecycle (intake → filed → pre-trial → trial → decision → appeal → closed) and an append-only status history; parties and adverse counsel; per-case-type workflow checklists created automatically when a matter opens. |
| **Deadlines** | Reglementary periods computed under Rule 22, Sec. 1 (exclude the first day, include the last, roll past weekends and holidays), with a live preview that explains every adjustment. Seeded with common Rules of Court periods; firms add their own. Hearing calendar. Escalating reminders (7 days / 3 days / 1 day / day-of) by email, plus SMS through Semaphore for the last two stages; overdue deadlines are flagged as missed and escalated. |
| **Documents** | Templates with `{{ merge_fields }}` filled from the matter, client, court and lawyer; immutable versions with side-by-side redlines; draft → final → signed → notarized lifecycle; selective sharing to the client portal. |
| **Files** | Upload pleadings, evidence, scans, email and audio to a matter (up to 20 MB each, type checked against the content). Stored privately under unguessable names with a SHA-256 checksum; always downloaded as attachments; removal hides the file but keeps it for retention; share individual files to the portal. Every upload is scanned by ClamAV before it is stored; malware is refused and logged, and if the scanner is down uploads are refused rather than stored unscanned. |
| **File search & OCR** | Search inside uploaded PDF, Word, Excel, PowerPoint, OpenDocument, RTF, text, CSV and email files, firm-wide or within a matter, with the matching passage highlighted. Scanned images and image-only PDFs are read with OCR (Tesseract, English and Filipino). Text is extracted in the background; PostgreSQL full-text search with prefix matching ("affid" finds "affidavit"). |
| **Tasks** | A board per firm or matter (to do → in progress → for review → done) with drag-and-drop and a keyboard-accessible "move to" menu, priorities and assignees; every move is logged. Built on the matter's task deadlines, so workflow checklists land on the board automatically. |
| **Client messaging** | Private threads between the client and the firm per matter, in the portal and the firm's inbox, with attachments (virus-scanned, saved to the matter's files). Email alerts never contain the message itself. Messages are permanent. |
| **AI assistant** | Ask Claude (Anthropic) about a matter: answers come from its documents and uploaded files with citations you can open, and drafts can be saved as documents. Off until the firm opts in (Firm Settings) because case files go to a third-party processor; each question is recorded in the audit log; conversations are private to the lawyer. |
| **Online intake** | A public "request a consultation" page (`/consult/<firm>`) with Data Privacy Act consent. Every request is conflict-checked automatically against clients and all matter parties; the firm schedules the consultation, declines (the email never gives a reason), or accepts it into a client and matter once any conflict is resolved. |
| **E-signature** | Ask the client to sign a final document; they review it and sign (drawn or typed) in the portal after an explicit consent step. Each signature keeps its evidence: signer, time, IP address, browser, and the SHA-256 of exactly what was shown, re-checked at signing (RA 8792). The lawyer is emailed when the client signs or declines. |
| **Online payments** | Clients pay issued invoices by card, GCash, Maya or QR Ph through PayMongo's hosted checkout (no card data touches the app), or the firm sends a payment link. The invoice is marked paid only by PayMongo's signed webhook, idempotently; money that arrives for an invoice that was meanwhile paid or voided is flagged for refund. |
| **Notarial register** | Doc./Page/Book/Series numbering per notary (2004 Rules on Notarial Practice), competent evidence of identity, permanent entries. |
| **Trust accounts** | Client funds ledger: every posting carries its running balance, rows are append-only, disbursements are partner-only, overdrafts are impossible. Nightly reconciliation of every account. Paying an invoice from trust disburses and records it atomically. |
| **Time & billing** | Global timer that survives reloads; time at the lawyer's standard rate; expenses advanced for the client (docket and sheriff's fees, TSN, courier…) with receipts; hourly, flat, monthly retainer, contingency and pro bono arrangements with acceptance and appearance fees. Invoices combine unbilled time, fee lines and expenses: 12% VAT on professional fees only (or none for non-VAT firms), expenses at cost. Draft → issued → paid / void; voiding releases time and expenses. Billing statements and documents download as PDF on the firm's letterhead (with the e-signature record appended). |
| **Reports** | Aged receivables (by days past due), collections by responsible lawyer, and matter profitability (time recorded, billed, collected, unbilled, expenses), each downloadable as CSV for Excel. |
| **Calendar sync** | A private subscription link for Google Calendar, Outlook or a phone: hearings, filing deadlines and tasks, updated about hourly. Titles show only the matter reference unless the lawyer opts in to case details. |
| **Compliance** | Conflict-of-interest search across current and former clients and all matter parties (order- and case-insensitive), with every search and its resolution kept. MCLE credit tracking per compliance period, and a firm-wide compliance view. |
| **Analytics** | Collections, receivables, unbilled WIP, trust funds held, matters by stage and lawyer utilization — computed live. |
| **Client portal** | Separate login where clients see matter progress, upcoming hearings, shared documents, invoices and trust activity — never internal notes or deadlines. |
| **Administration** | Users and roles, firm deadline rules, holiday calendar, workflows, and a Data Privacy Act–oriented audit log of changes to client data. |
| **Account security** | Password reset by email (no account enumeration; signs out other sessions). Two-step verification with any authenticator app (TOTP), single-use recovery codes, codes accepted once only, and an administrator reset for lost phones. Firms can require two-step verification for everyone: staff without it can only set it up until they do. Portal clients reset their own password, or are invited by email to choose one; a person who is a client of several firms gets a separate link per firm. |

---

## Quick start (development)

Prerequisites: PHP 8.3+ (64-bit recommended) with `pdo_pgsql`/`pdo_sqlite`, Composer, Node 20+, and PostgreSQL 16 (or SQLite for a quick look).

```bash
# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
#   set DB_* in .env (or DB_CONNECTION=sqlite for a quick look)
php artisan migrate --seed          # reference data + a demo firm (non-production only)
php artisan serve                   # http://localhost:8000
php artisan queue:work              # in another terminal: sends reminders
php artisan schedule:work           # in another terminal: hourly reminders, nightly reconciliation

# Frontend
cd ../frontend
npm install
npm run dev                         # http://localhost:5173 (proxies /api to :8000)
```

Open **http://localhost:5173**. Always use the Vite URL: the SPA and API must share an origin for cookie authentication.

### Demo accounts (password: `password`)

| Email | Role | Try |
|---|---|---|
| `ceo@demofirm.ph` | Managing Partner | Home analytics, Firm Settings, audit log, invoicing, trust disbursements |
| `partner@demofirm.ph` | Partner | Invoices, trust accounts, notarial register |
| `lawyer1@demofirm.ph` | Associate | Matters, deadlines, documents, timer, MCLE |
| `paralegal@demofirm.ph` | Paralegal | Tasks, conflict checks, time entries |
| `client1@corporate.com` | Client (portal) | Sign in at **/portal** |

## Production-like stack (Docker)

```bash
cp backend/.env.example backend/.env
docker compose run --rm app php artisan key:generate --show   # paste into backend/.env as APP_KEY
DB_PASSWORD=<app-password> DB_ROOT_PASSWORD=<admin-password> docker compose up -d --build
docker compose exec app php artisan migrate --force --seed
docker compose exec app php artisan db:seed --class=EnterpriseDemoSeeder --force   # optional demo data
```

Open **http://localhost:8080**. The stack runs nginx (SPA + API on one origin, with security headers and a CSP), PHP-FPM, a queue worker, the scheduler, PostgreSQL 16 and Redis.

---

## Architecture

```
backend/app/
  Domain/                 business rules, independent of HTTP
    Matters/              OpenMatter, TransitionMatterStatus (state machine), WorkflowEngine
    Deadlines/            DeadlineCalculator (Rule 22), DeadlineScheduler, ReminderDispatcher, jobs, notifications
    Documents/            DocumentMerger, CreateDocumentVersion
    Trust/                TrustLedgerService (row-locked postings, reconciliation)
    Billing/              InvoiceGenerator (VAT, lifecycle, pay-from-trust)
    Compliance/           ConflictChecker, MCLETracker
    Analytics/            AnalyticsService
  Http/Controllers/Api/V1 thin controllers: validate → authorize → call the domain → return a Resource
  Http/Resources          the JSON contract (money is always integer centavos)
  Support/Tenancy         TenantContext
frontend/src/
  features/<area>/        api.ts (TanStack Query hooks) + components
  shared/                 API client, types, formatting, design-system primitives
```

**Key decisions**

- **Tenant isolation is enforced twice.** Every firm-owned model uses a global scope driven by a `TenantContext` that middleware sets from the signed-in user *before* route-model binding; another firm's record is a 404, never a leak, and writing a row into another firm throws. Underneath, PostgreSQL row-level security restricts every firm-owned table to the request's firm (`app.firm_id`, set by the same middleware), so even a raw query that skips the scope cannot read or write another firm's rows. Sign-in, webhooks, the queue worker and the scheduler run in an explicit trusted mode; a connection with neither setting sees nothing. Covered by `TenantScopeTest` and `RowLevelSecurityTest`.
- **Money is integer centavos** end to end; VAT and time amounts are computed server-side and never accepted from input.
- **Audit-grade records are append-only** at the model layer: matter status history, deadline events, document versions, trust transactions and notarial entries. On PostgreSQL the trust ledger is additionally guarded by a trigger and CHECK constraints that reject UPDATE/DELETE, overdrafts, and any posting whose running balance does not follow from the previous one — even from raw SQL.
- **Deadline reminders survive failures:** an hourly, idempotent dispatcher claims each reminder stage with a conditional update (no duplicates across overlapping runs), and queued jobs retry with backoff.
- **Authentication:** Sanctum cookie sessions for staff; a separate `client` session guard for the portal, so neither session can reach the other's API. Login is rate-limited and does not reveal whether an account exists; CSRF is enforced on every mutation.
- **Authorization:** role capabilities (`manage-firm`, `manage-finances`, `work-matters`, `practice-law`) are checked on the server for every action; the UI mirrors them but is never the enforcement point.

API conventions: versioned under `/api/v1`; errors are `{status: "error", message, errors?}`; lists are `{data, links, meta}` with `per_page` capped at 100.

## Testing

```bash
cd backend && php artisan test                    # SQLite (Postgres-only ledger-guard tests skip)
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=ph_legal_test \
  DB_USERNAME=... DB_PASSWORD=... php vendor/bin/phpunit   # full suite on PostgreSQL
vendor/bin/pint --test                            # code style

cd frontend && npm run lint && npm run build      # oxlint + strict TypeScript + production build
```

CI (`.github/workflows/ci.yml`) runs all of the above, with the backend suite on SQLite and on PostgreSQL 16, then once more on PostgreSQL as an ordinary (non-superuser) role so row-level security is in force for every test.

## Operations checklist

- **Run the queue worker and scheduler** in production (both are services in `docker-compose.yml`). Without them, no reminders are sent.
- **Keep the holiday calendar current.** Fixed-date holidays and Holy Week are seeded for 2025–2027. Holidays set by annual proclamation (Eid'l Fitr, Eid'l Adha, special days) and court closures must be added under **Firm Settings → Holidays** as they are announced. Deadline computations depend on this.
- **Have counsel verify the seeded Rules of Court periods** (Firm Settings → Deadline rules) against current rules before relying on them.
- **Connect as an ordinary database role, never a superuser.** PostgreSQL does not apply row-level security to superusers or `BYPASSRLS` roles, so connecting as one silently turns the second tenancy layer off. `docker-compose.yml` creates the `ph_legal` role for the app (`backend/docker/postgres/10-app-role.sh`) and keeps the superuser for administration. If you use PgBouncer, use session pooling: the tenant is a session setting.
- **Back up PostgreSQL and uploaded files off-site** (e.g. nightly `pg_dump`, ideally streaming replication, plus the `storage` volume or your `MATTER_FILES_DISK` bucket). The trust ledger and the signature evidence live in the database.
- **Online payments:** set `PAYMONGO_SECRET_KEY`, register a PayMongo webhook for `https://<your-domain>/api/webhooks/paymongo` with the event `checkout_session.payment.paid`, and set `PAYMONGO_WEBHOOK_SECRET` to its secret. Watch the logs for `Online payment received that could not be applied` (logged at `critical`); such payments are also shown on the invoice for refund.
- **Mail must work in production** (`MAIL_*`): password reset links, portal invitations and e-signature requests are sent by email.
- **Keep ClamAV running.** `docker-compose.yml` runs `clamav/clamav:stable` (about 1.5 GB of RAM; it downloads and updates its signatures itself). Outside Docker, run clamd and set `CLAMAV_ENABLED=true` and `CLAMAV_HOST`. While the scanner is unreachable, uploads are refused with a "try again" message; set `CLAMAV_FAIL_OPEN=true` only if you accept storing files unscanned in that case. Rejected files are recorded in the audit log as `file_rejected_malware`.
- **The queue worker extracts text for search**, so it needs the same file storage as the app (the `storage` volume in `docker-compose.yml`). Files uploaded while it is down become searchable once it runs.
- **Before requiring two-step verification** (Firm Settings → Firm & security), tell staff they will need an authenticator app. If someone loses their phone and recovery codes, a managing partner resets it from Firm Settings → Users.
- **OCR** runs in the queue worker (the Docker image includes `tesseract-ocr` with Filipino data and `poppler-utils`; `OCR_ENABLED=true`). Long scans take minutes, so keep the queue's `retry_after` above 600 seconds (`REDIS_QUEUE_RETRY_AFTER=900` in `docker-compose.yml`) or they would run twice.
- **AI assistant:** set `ANTHROPIC_API_KEY` (model `ANTHROPIC_MODEL`, default `claude-opus-5-5`), then a managing partner turns it on under **Firm Settings → Firm & security** after confirming the data processing agreement and client notices. Questions run on the queue; usage (tokens) is stored per answer.
- **Online intake:** choose the web address and turn on requests under **Firm Settings → Firm & security**, then link `/consult/<address>` from your website. Requests are rate-limited per IP and protected by a honeypot.
- **Calendar links** are credentials: if one leaks, the lawyer replaces it under **Profile** (the old link stops working immediately). Deactivating a user also stops their feed.
- **The app runs in Philippine time** (`APP_TIMEZONE=Asia/Manila`). Keep it that way: "today" decides reglementary periods, reminder days and what counts as a future date.
- **Notarization still needs personal appearance.** The 2004 Rules on Notarial Practice require the signatory to appear before the notary, so an e-signature cannot replace it; the app keeps the notarial register for the in-person act.
- Watch the logs for `Trust account failed reconciliation` (logged at `critical` by `trust:reconcile`).
- Set `SEMAPHORE_API_KEY` to send SMS; without it, SMS messages are written to the log.
- Use 64-bit PHP in production. 32-bit builds cannot represent peso amounts above about ₱21 million in centavos.

## Not yet implemented

- Text from legacy binary `.doc`/`.xls`/`.ppt`/`.msg` files (found by name and description only), and DOCX export (PDF export exists).
- Real-time updates: messages and the assistant refresh by polling (every 15 s and 2 s), not WebSockets.
- Calendar subscriptions for portal clients (their hearings are shown in the portal).
- Third-party e-signature providers (DocuSign and similar); signing is built in and happens in the client portal.
- Refunds through PayMongo from inside the app (issue them in the PayMongo dashboard).

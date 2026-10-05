# Lex PH — Practice Management for Philippine Law Firms

Case, deadline, document, trust-account and billing management built around
Philippine practice: Rules of Court reglementary periods and pleadings, the
notarial register, IBP/MCLE compliance, 12% VAT billing with withholding tax
and BIR Form 2307, client trust accounting, and the Data Privacy Act. A client
portal lets clients follow their matters, pay, sign, message and upload.

**Stack:** Laravel 13 (PHP 8.4) API · React 19 + TypeScript SPA · PostgreSQL 16 (row-level security) · Redis · Laravel Reverb (WebSockets) · Caddy (HTTPS) · ClamAV · Tesseract OCR

---

## What it does

| Area | Capabilities |
|---|---|
| **Matters & clients** | Matters with an enforced status lifecycle (intake → filed → pre-trial → trial → decision → appeal → closed) and an append-only status history; parties and adverse counsel; per-case-type workflow checklists created automatically when a matter opens. |
| **Directory** | Courts down to the branch (station, address, e-filing email, phone, notes) and the people the firm deals with: judges, clerks of court, prosecutors, opposing counsel, experts and sheriffs. Setting a matter's court from the directory fills its caption fields and presiding judge; the judge, clerk, prosecutor and opposing counsel are linked to the matter with tap-to-call numbers. Opposing counsel are included in conflict checks. |
| **Business development** | Prospective clients from first contact to engagement: source and referrer, practice area, estimated fees, owner and next follow-up (with a reminder), through lead, consultation, proposal and engagement letter, with a history of calls, meetings and notes. Each prospect and its opposing parties are conflict-checked when added; once any flag is resolved, an engaged prospect becomes a client and matter in one step. Online intake requests can join the pipeline. A conversion report shows win rates and fees won by source and by lawyer, the open pipeline, and why prospects were lost. |
| **Mobile app** | Installable on Android, iPhone and desktop ("Add to Home Screen"): opens full screen with shortcuts to Court day, deadlines and tasks. Screens a lawyer needs in court (today's hearings, deadlines, tasks, the open matter and its lists) still open on a weak or no signal, from the last saved copy. Time entries and hearing outcomes recorded without a signal are kept on the phone and sent when it reconnects, exactly once; anything the server then refuses is shown. **Phone notifications** for every bell notification (hearings, missed deadlines, assignments, approvals), turned on per device; by default a locked phone shows only the kind of notification, never client or case details. Saved copies are deleted at sign-out. |
| **Court day** | A phone-first page of the day's hearings: courtroom, branch, judge, parties, opposing counsel, a tap-to-call client number and what to bring. "Hearing done" records the outcome (held, reset with the new date, cancelled), the deadlines the court gave (counted under Rule 22) and the appearance time, in one step. |
| **Deadlines** | Reglementary periods computed under Rule 22, Sec. 1 (exclude the first day, include the last, roll past weekends and holidays), with a live preview that explains every adjustment. Seeded with common Rules of Court periods; firms add their own. Hearing calendar. Escalating reminders (7 days / 3 days / 1 day / day-of) by email, plus SMS through Semaphore for the last two stages; overdue deadlines are flagged as missed and escalated. **Clients hear about their hearings** too, once the firm turns it on: when one is set, moved or cancelled, and a week before and the day before, by email and SMS, in their language (a client or a hearing can be left out). **Prescription**: record when a cause of action arose and choose its type from a list of common periods with their legal basis (Civil Code Arts. 1140–1149, 1389 and 1391; Labor Code Art. 306; RPC Art. 90; Act No. 3326; the Consumer Act) or enter another; the last day is computed in calendar years and months, moved past weekends and holidays under Rule 22. Interruptions under Art. 1155 (filing, written demand, written acknowledgment) restart civil periods; marking an action filed stops the clock. Reminders 6 months, 3 months, 30, 14, 7, 3 and 1 days before, on the day and once past (managing partners too in the last month), and a firm-wide list soonest first. |
| **Documents** | Templates with `{{ merge_fields }}` filled from the matter, client, court and lawyer; immutable versions; **compare** any two versions, or a version with the other side's draft (an uploaded Word file or text PDF), as a redline or side by side, and download the redline as a PDF to send; draft → final → signed → notarized lifecycle; selective sharing to the client portal. Download as PDF or Word (.docx). |
| **Knowledge bank** | The firm's model pleadings, clauses, forms, research notes and jurisprudence notes (citation such as the G.R. number, the doctrine in the firm's words, key passages), by practice area and tag, with full-text search. Keep a finished document as a model in one click, insert entries while drafting a pleading, and let the AI assistant draw on the entries for the matter's practice area (cited as K sources). |
| **Pleadings** | Assembled from the matter with a live preview: court heading, caption with the parties on the proper sides (People of the Philippines in criminal cases), docket label by case type and "For:" nature of the action, title, body or outline, prayer, and the Rule 7, Sec. 3 signature block (roll, IBP, PTR, MCLE, e-mail). Verification and certification against forum shopping for initiatory pleadings; explanation of service and copy furnished to opposing counsel. Word export keeps the layout (caption as a two-column table) on the firm's paper and font: 8.5 x 13 in, 14 pt and the Efficient Use of Paper Rule margins by default. |
| **Evidence & exhibits** | Each side's exhibits per matter, marked the Philippine way: letters for the plaintiff, petitioner or prosecution, numbers for the defendant, respondent or accused, with sub-markings (A-1, A-1-a; 1-a, 1-a-1) suggested automatically. Record the description, the purpose, the identifying witness and the linked file, then the objection and the court's ruling (one ruling can cover several exhibits). The exhibit list downloads as CSV, and the **Formal Offer of Evidence** (Rule 132, Secs. 34–35) is drafted from the exhibits in the pleading format, ready to edit and export to Word. |
| **Electronic filing** | The pleading (as drafted, in the court format on the firm's pleading paper, or the signed PDF) and its annexes as one PDF: a labelled separator page before each annex (Annex "A", "B"… or "1", "2"…), bookmarks for every part, and pages numbered consecutively. Checked before filing: size against the court's limit (`EFILING_MAX_MB`, default 25 MB; scans are reduced to 150 dpi if that makes it fit), scans without a text layer, pages not on the firm's paper, and a pleading still in draft. Then the record: when, how and where it was filed, the court's reference, the deadline it meets (marked done), and the acknowledgment with its saved copy. Needs Ghostscript and poppler (included in the Docker image). |
| **Document requests** | Send the client a checklist (ID, contracts, SPA…) with a due date; they upload each item in the portal (virus-scanned, filed with the matter); accept it or send it back with a reason. Reminders before the due date and once overdue. |
| **Files** | Upload pleadings, evidence, scans, email and audio to a matter (up to 20 MB each, type checked against the content). Stored privately under unguessable names with a SHA-256 checksum; always downloaded as attachments; removal hides the file but keeps it for retention; share individual files to the portal. Every upload is scanned by ClamAV before it is stored; malware is refused and logged, and if the scanner is down uploads are refused rather than stored unscanned. |
| **Email to matter** | Every matter has its own private address. Mail sent, copied (Cc/Bcc) or forwarded to it is filed with the matter: the message as an .eml and each attachment as a matter file, virus-scanned and searchable like any upload, with the body readable in the app. Mail from the firm's staff or the matter's client is filed at once; mail from anyone else waits for a lawyer to file or reject it. Emails saved from Outlook or Gmail can be uploaded as .eml. A leaked address can be replaced. |
| **File search & OCR** | Search inside uploaded PDF, Word, Excel and PowerPoint files (including the old binary .doc, .xls and .ppt, read with catdoc), OpenDocument, RTF, text, CSV and email (.eml, and Outlook .msg converted with msgconvert; read as sender, recipients, date, subject and text, not raw MIME), firm-wide or within a matter, with the matching passage highlighted. Scanned images and image-only PDFs are read with OCR (Tesseract, English and Filipino). Text is extracted in the background; PostgreSQL full-text search with prefix matching ("affid" finds "affidavit"). |
| **Tasks** | A board per firm or matter (to do → in progress → for review → done) with drag-and-drop and a keyboard-accessible "move to" menu, priorities and assignees; every move is logged. Built on the matter's task deadlines, so workflow checklists land on the board automatically. |
| **Client messaging** | Private threads between the client and the firm per matter, in the portal and the firm's inbox, with attachments (virus-scanned, saved to the matter's files). Email alerts never contain the message itself. Messages are permanent. |
| **AI assistant** | Ask Claude (Anthropic) about a matter: answers come from its documents and uploaded files with citations you can open, and drafts can be saved as documents. Off until the firm opts in (Firm Settings) because case files go to a third-party processor; each question is recorded in the audit log; conversations are private to the lawyer. |
| **Online intake** | A public "request a consultation" page (`/consult/<firm>`) with Data Privacy Act consent, **in English or Filipino** (with the privacy notice and the emails to the applicant in the same language). The applicant can say when the problem arose; the lawyer picks the type of claim and sees at once when it prescribes ("prescribes in 15 days" in the inbox), and taking the case (directly or through the pipeline) starts tracking it on the matter. Every request is conflict-checked automatically against clients and all matter parties; the firm schedules the consultation, declines (the email never gives a reason), or accepts it into a client and matter once any conflict is resolved. |
| **E-signature** | Ask the client to sign a final document; they review it and sign (drawn or typed) in the portal after an explicit consent step. Each signature keeps its evidence: signer, time, IP address, browser, and the SHA-256 of exactly what was shown, re-checked at signing (RA 8792). The lawyer is emailed when the client signs or declines. |
| **Online payments** | Clients pay issued invoices by card, GCash, Maya or QR Ph through PayMongo's hosted checkout (no card data touches the app), or the firm sends a payment link. The invoice is marked paid only by PayMongo's signed webhook, idempotently; money that arrives for an invoice that was meanwhile paid or voided is flagged and can be refunded through PayMongo from the invoice. |
| **Notarial register** | Doc./Page/Book/Series numbering per notary (2004 Rules on Notarial Practice), competent evidence of identity, permanent entries. |
| **Trust accounts** | Client funds ledger: every posting carries its running balance, rows are append-only, disbursements are partner-only, overdrafts are impossible. Nightly reconciliation of every account. Paying an invoice from trust disburses and records it atomically. |
| **Time & billing** | Global timer that survives reloads; time at the lawyer's standard rate; expenses advanced for the client (docket and sheriff's fees, TSN, courier…) with receipts; hourly, flat, monthly retainer, contingency and pro bono arrangements with acceptance and appearance fees. Invoices combine unbilled time, fee lines and expenses: 12% VAT on professional fees only (or none for non-VAT firms), expenses at cost. Draft → issued → partly paid → paid / void; voiding releases time and expenses. Payments are recorded in installments (cash, check, bank transfer, e-wallet, card, client trust or PayMongo), each with the **creditable withholding tax** the client deducted (on fees only, never VAT or expenses) and its **BIR Form 2307**, tracked until it arrives. A mistaken payment is voided with a reason, never deleted. Billing statements and documents download as PDF on the firm's letterhead (with the e-signature record appended). |
| **Matter budgets** | A budget per matter in pesos (billable fees, optionally with expenses) or hours, optionally split by stage. Shows what is used and left, overall and stage by stage (time and expenses count toward the stage the matter was in that day). The responsible lawyer, whoever set it and the managing partners are alerted once at 80% and once at 100%; raising the budget re-arms the alerts. Optionally shown to the client in the portal (the total and how much is used; never the notes or breakdown). |
| **Cash advances** | Lawyers request funds for case costs (filing and sheriff's fees, TSN, notarial, courier…) from firm funds or the client's trust deposit. A partner approves (never their own request, except a managing partner) and releases it with the voucher or check number; a release from trust is posted to the client's trust ledger. The lawyer liquidates within a week, one line per receipt: each becomes an expense on the matter (billed at cost when paid by the firm; recorded but not billed again when paid from trust, with any unspent balance returned to the trust account). Reminders on the due day, and to partners once overdue. |
| **Collections** | Monthly retainers billed automatically on the matter's billing day (as a draft, or issued and e-mailed). Payment reminders the firm can turn on: 3 days before the due date, then 1 week and 1 month overdue, with the statement attached, pausable per invoice. Trust accounts with an agreed minimum: below it the client is asked to top up, at most weekly. |
| **BIR tax compliance** | For the firm's own returns: the quarter's fees invoiced, output VAT or percentage-tax base, collections and tax withheld by clients; the **SAWT** built from recorded payments and their Forms 2307 (CSV, flagging tax not yet backed by a 2307); and a filing calendar of the returns the firm files (2550Q/2551Q, 1701Q/1702Q and annual ITR, 0619-E/1601-EQ/1604-E, 1601-C/1604-C with employees), with deadlines moved past weekends and holidays, confirmation references, and reminders to finance partners a week before, the day before and when overdue. |
| **Electronic invoicing** | For BIR EIS: when on, each invoice the firm issues becomes an e-invoice (seller and buyer TINs, lines, sales by VAT treatment, totals) sent through the configured transmitter, and each void after that a cancellation; a fingerprint, the provider's reference and answer, retries over several hours, and the date it must reach the BIR are kept for each, with finance staff alerted to any not sent by then. No accredited provider is connected yet: by default e-invoices are kept on file, ready to send; a provider is added as a transmitter (a generic signed HTTPS one is included). |
| **Corporate secretarial** | For corporate clients: SEC registration, fiscal year and by-laws meeting date generate each year's annual stockholders' meeting, **GIS** (30 days after the meeting), **audited financial statements** (120 days after the fiscal year) and **BIR annual ITR** (15th day of the 4th month), plus custom obligations. A firm-wide compliance grid and a card on the client page; reminders to the responsible lawyer 30, 7 and 1 days before and when overdue. One click installs a secretary's certificate, board resolution, and notice and minutes of the annual meeting as templates filled from the profile. |
| **Reports** | Aged receivables (by days past due), collections by responsible lawyer, and matter profitability (time recorded, billed, collected, unbilled, expenses), each downloadable as CSV for Excel. |
| **Calendar sync** | A private subscription link for Google Calendar, Outlook or a phone: hearings, filing deadlines and tasks, updated about hourly. Titles show only the matter reference unless the lawyer opts in to case details. |
| **Compliance** | Conflict-of-interest search across current and former clients (and the other names they are known by) and all matter parties, tuned for Philippine names: any word order, "de la" = "dela", "Ma." = Maria, titles, Jr./III and company forms ignored, small misspellings caught; each match is ranked with its reason, and the check with its resolution downloads as a PDF for the file. MCLE credit tracking per compliance period, and a firm-wide compliance view. |
| **Analytics** | Collections, receivables, unbilled WIP, trust funds held, matters by stage and lawyer utilization — computed live. |
| **Client portal** | Separate login where clients see matter progress, upcoming hearings, shared documents, invoices and trust activity — never internal notes or deadlines. **In English or Filipino**: the client switches on the sign-in screen or once signed in; the choice is saved on their record (staff can also set it), and the firm's emails to them follow it. A Filipino privacy notice is built in, or the firm writes its own. Clients can subscribe to **their own hearings** in Google Calendar, an iPhone or Outlook (a private link, in their language, that stops with portal access), and are asked **how the firm did** when a matter closes: a rating out of 5 and a comment, answered in the portal. Ratings of 2 or less alert the responsible lawyer and managing partners, who record the follow-up; Matters → Client feedback summarizes results by lawyer and practice area. |
| **Data privacy** | For the firm's obligations under the Data Privacy Act (RA 10173): a versioned privacy notice naming the DPO, shown on the intake form and accepted by portal clients (again when it changes); requests from data subjects to see, correct, delete or object, from the portal or recorded by staff, with a 15-day target; a one-click export of everything held about a client; anonymization once no business is open; a retention period with secure disposal of closed matters; and a breach log with the 72-hour NPC notification countdown. |
| **Notifications** | A bell with everything that needs you: reminders, missed deadlines, client messages and uploads, intake requests, signatures, assignments, online payments and privacy requests. Live over WebSockets (Laravel Reverb), as are client conversations (new messages appear on both sides within seconds, in the firm's inbox and the portal) and AI assistant answers; one connection per browser, private channels checked against the firm and the client, and no message text sent over the socket. Without a WebSocket server, screens poll. |
| **Import** | **Documents** from a ZIP with one folder per matter (matched by reference or case number, or by hand in the preview; subfolders kept as descriptions): uploaded in 10 MB pieces, so neither the upload limit nor a dropped connection stops a large archive, then filed in the background, each file virus-scanned, type-checked and made searchable like an upload, files already in the matter skipped, undo for 7 days. Bring existing clients, matters, deadlines, trust opening balances and **billing history** (invoices with what was paid and withheld, and time entries, billed or still unbilled) over from Excel or CSV: tolerant headers, Philippine date and peso formats, duplicates found in the database and within the file, a preview before anything is saved, and undo for seven days. |
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

In development the notification bell polls once a minute. For live updates, set `BROADCAST_CONNECTION=reverb` and the `REVERB_*` values in `backend/.env` and run `php artisan reverb:start` in another terminal. The simplest way to see everything working together (HTTPS, workers, WebSockets, virus scanning, OCR, backups) is Docker, below.

### Demo accounts (password: `password`)

| Email | Role | Try |
|---|---|---|
| `ceo@demofirm.ph` | Managing Partner | Home analytics, Firm Settings (import data, audit log), Data Privacy, Time & Billing → Collections and Form 2307, trust disbursements |
| `partner@demofirm.ph` | Partner | Invoices and payments, trust accounts, notarial register, reports |
| `lawyer1@demofirm.ph` | Associate | Matters (Documents → Draft a pleading; Files → Request documents), Court day, deadlines, timer, MCLE, the notification bell |
| `paralegal@demofirm.ph` | Paralegal | Tasks, conflict checks, time entries |
| `client1@corporate.com` | Client (portal) | Sign in at **/portal**: privacy notice, matters, invoices, messages, document uploads, My data |

The demo firm is for trying the system; never load it into a database that holds real client data.

## Trying the full system on your computer (Docker)

Needs Docker Desktop (about 4 GB of free memory; ClamAV alone uses 1.5 GB) and nothing else listening on ports 80 and 443.

```bash
cp .env.example .env
cp backend/.env.example backend/.env    # then set APP_KEY, e.g. with: docker compose run --rm app php artisan key:generate --show
```

In `.env` set `DOMAIN=localhost`, `APP_URL=https://localhost`, `SANCTUM_STATEFUL_DOMAINS=localhost` and `HSTS_MAX_AGE=0`, and give every secret a random value. This prints suitable values:

```bash
node -e "const r=n=>require('crypto').randomBytes(n).toString('base64url');for (const k of ['DB_PASSWORD','DB_ROOT_PASSWORD','BACKUP_PASSPHRASE','REVERB_APP_KEY','REVERB_APP_SECRET','HEALTH_TOKEN']) console.log(k+'='+r(24))"
```

Then:

```bash
docker compose up -d --build                                        # first build takes a few minutes
docker compose exec app php artisan migrate --force --seed          # tables and reference data (Rules of Court periods, holidays)
docker compose exec app php artisan db:seed --class=EnterpriseDemoSeeder --force   # the demo firm
```

Open **https://localhost** and accept the browser's certificate warning (Caddy's local certificate). Sign in with the demo accounts above. ClamAV needs a few minutes after the first start to download its virus signatures; until then uploads are refused.

```bash
docker compose ps                  # what is running
docker compose logs -f app         # follow the app log (e-mails are written here while MAIL_MAILER=log)
docker compose stop                # stop; data is kept in Docker volumes
docker compose up -d               # start again
```

`COMPOSE_PROJECT_NAME=lexph` in `.env` names the containers and volumes `lexph-…`, so they never mix with volumes from an older checkout of this folder (a `dbdata` volume from an old MySQL setup would stop PostgreSQL from starting).

## Running in production (Docker)

```bash
cp .env.example .env                    # DOMAIN, APP_URL, passwords, BACKUP_PASSPHRASE, REVERB_APP_KEY/SECRET, OPS_ALERT_EMAIL
cp backend/.env.example backend/.env    # mail, PayMongo, Anthropic, SMS
docker compose run --rm app php artisan key:generate --show   # paste into backend/.env as APP_KEY
docker compose up -d --build
docker compose exec app php artisan migrate --force --seed
docker compose exec app php artisan db:seed --class=EnterpriseDemoSeeder --force   # optional demo data
```

Use strong random values for every password and key (the command in the section above prints some), and do not load the demo firm. Point `DOMAIN` at the server and open ports 80 and 443; Caddy then gets and renews the Let's Encrypt certificate itself. Set `MAIL_*` in `backend/.env` to a real mail service: password resets, portal invitations, reminders and alerts are all e-mail.

| Service | Role |
|---|---|
| `caddy` | The only public entry point: HTTPS, HTTP → HTTPS redirect, HSTS. Replaces any `X-Forwarded-*` a client sends. |
| `web` | nginx: the SPA and the API on one origin, with security headers and a CSP. |
| `app` | PHP-FPM (Laravel). Trusts `X-Forwarded-*` only from the private Docker network; cookies are `Secure`. |
| `queue` | Reminders, email and other quick jobs. |
| `queue-heavy` | OCR, text extraction and the assistant, on their own queue, so a pile of scanned uploads can never delay a deadline reminder. |
| `reverb` | WebSocket server for live notifications; browsers reach it at `/app` on the same domain. |
| `scheduler` | Deadline reminders (hourly), trust reconciliation (nightly), retainer billing, payment and document reminders and trust top-up requests (daily at 9), worker heartbeats, the health check and its alerts (every 5 min). |
| `backup` | Nightly encrypted backup, weekly restore test, optional off-site copy. |
| `db`, `redis`, `clamav` | PostgreSQL 16 (the app connects as a non-superuser), Redis, virus scanner. |

### Backups and restore

Every night at `BACKUP_TIME` the `backup` service writes a `pg_dump` of the database, a physical base backup for point-in-time recovery, and an archive of the uploaded files, each encrypted with AES-256 under `BACKUP_PASSPHRASE`, with checksums. It keeps `BACKUP_KEEP_DAYS` days on the server and, with `RCLONE_REMOTE` set, copies each backup off-site (S3, Backblaze B2, Google Drive, SFTP…; configure the remote with `rclone config` and put `rclone.conf` in `backend/docker/backup/config/`). Once a week it **restores the latest backup into a scratch database** and checks it (schema version, row-level security policies, the file archive read end to end). The health check reports a backup that is late, failed, or not restore-tested within 8 days.

```bash
docker compose exec backup backup.sh           # back up now
docker compose exec backup verify.sh           # restore test now
# Disaster recovery: replaces the live database and files with a backup.
docker compose stop app queue queue-heavy scheduler
docker compose run --rm -e CONFIRM=yes restore [STAMP]   # newest by default; fetched from off-site if not local
docker compose start app queue queue-heavy scheduler
```

#### Point-in-time recovery

Between nightly backups, PostgreSQL archives every change (its write-ahead log, WAL) at least every `ARCHIVE_TIMEOUT` seconds (default 300) while there is activity, and the `backup` service encrypts each segment and copies it off-site within seconds. Together with the nightly base backup this restores the database to **any moment** within `BACKUP_KEEP_DAYS`: after losing the server (at most about five minutes of work lost), or to just before a mistake, such as a wrong trust posting or a bulk delete.

```bash
docker compose stop app queue queue-heavy scheduler reverb backup db
docker compose run --rm -e TARGET_TIME='2026-10-03 14:04:00' -e CONFIRM=yes pitr   # local time
docker compose start db && docker compose logs -f db     # wait for "ready to accept connections"
docker compose start app queue queue-heavy scheduler reverb backup
```

The replaced database is kept in the `backups` volume (`pre-pitr-<time>`) until you delete it, so a wrong target time can be retried. Uploaded files are not rolled back. The health check fails if PostgreSQL cannot archive WAL or archived WAL is not being shipped.

**Keep `BACKUP_PASSPHRASE` somewhere other than the server** (a password manager, a sealed envelope in the office safe). Without it the backups cannot be decrypted.

### Monitoring

- `GET /api/health` answers 200 while everything works and 503 when something is failing: database, cache, Redis, the scheduler and both queue workers (by heartbeat), failed jobs, ClamAV, backups, WAL archiving and disk space. Point an uptime monitor (UptimeRobot, Better Stack…) at it; that also catches the whole server being down. With `Authorization: Bearer <HEALTH_TOKEN>` it returns each check's detail.
- `OPS_ALERT_EMAIL` receives an email when a health check fails, a job fails for good (a reminder that will not go out), or a trust account fails reconciliation. Each alert is sent at most once an hour.
- **Error tracking:** set `SENTRY_LARAVEL_DSN` to send exceptions to Sentry. Request bodies, user details and log lines are never sent; stack traces and exception messages are, so keep client data out of exception messages.

---

## Architecture

```
backend/app/
  Domain/                 business rules, independent of HTTP
    Matters/              OpenMatter, TransitionMatterStatus (state machine), WorkflowEngine
    Deadlines/            DeadlineCalculator (Rule 22), DeadlineScheduler, ReminderDispatcher, jobs, notifications
    Documents/            DocumentMerger, CreateDocumentVersion
    Trust/                TrustLedgerService (row-locked postings, reconciliation)
    Billing/              InvoiceGenerator (VAT, lifecycle), InvoicePayments (installments, withholding, Form 2307, trust)
    Compliance/           ConflictChecker, MCLETracker
    Analytics/            AnalyticsService
  Http/Controllers/Api/V1 thin controllers: validate → authorize → call the domain → return a Resource
  Http/Resources          the JSON contract (money is always integer centavos)
  Support/Tenancy         TenantContext, DatabaseTenancy (row-level security)
  Support/Ops             SystemHealth (/api/health), OpsAlert (throttled email alerts)
frontend/src/
  features/<area>/        api.ts (TanStack Query hooks) + components
  shared/                 API client, types, formatting, design-system primitives
```

**Key decisions**

- **Tenant isolation is enforced twice.** Every firm-owned model uses a global scope driven by a `TenantContext` that middleware sets from the signed-in user *before* route-model binding; another firm's record is a 404, never a leak, and writing a row into another firm throws. Underneath, PostgreSQL row-level security restricts every firm-owned table to the request's firm (`app.firm_id`, set by the same middleware), so even a raw query that skips the scope cannot read or write another firm's rows. Sign-in, webhooks, the queue worker and the scheduler run in an explicit trusted mode; a connection with neither setting sees nothing. Covered by `TenantScopeTest` and `RowLevelSecurityTest`.
- **Money is integer centavos** end to end; VAT and time amounts are computed server-side and never accepted from input.
- **An invoice's paid status follows its payments.** Each payment is recorded under a row lock, and the invoice's settled amount and status (issued, partly paid, paid) are recomputed from the active payments, so they cannot drift. Overpayment, withholding above the fees, and payments on draft or void invoices are refused; voiding a payment taken from trust returns the money to the trust ledger.
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

CI (`.github/workflows/ci.yml`) runs all of the above, with the backend suite on SQLite and on PostgreSQL 16, then once more on PostgreSQL as an ordinary (non-superuser) role so row-level security is in force for every test. A test also fails if any table with a `firm_id` column lacks a row-level security policy.

**Load testing.** `php artisan ops:load-test-data --force [--scale=N]` fills one firm of a throwaway copy with years of volume (300 clients, 500 matters, 10,000 deadlines, 50,000 time entries, 3,000 invoices, 20,000 searchable files, 3,000 documents, 100,000 audit entries per unit of scale) in under a minute, without sending anything. Never run it on a live database. At scale 1, every page tested answers in under 250 ms as the app's own role (row-level security in force), and a firm-wide file search in under 0.5 s even when every file matches.

## Operations checklist

- **Run both queue workers and the scheduler** in production (`queue`, `queue-heavy` and `scheduler` in `docker-compose.yml`). Without them, no reminders are sent; `/api/health` fails within five minutes of one stopping.
- **Point an uptime monitor at `/api/health` and set `OPS_ALERT_EMAIL`** before real use (see Monitoring).
- **Keep the holiday calendar current.** Fixed-date holidays and Holy Week are seeded for 2025–2027. Holidays set by annual proclamation (Eid'l Fitr, Eid'l Adha, special days) and court closures must be added under **Firm Settings → Holidays** as they are announced. Deadline computations depend on this.
- **After upgrading, run `php artisan files:extract-text` once** (in Docker: `docker compose exec app php artisan files:extract-text`). Old `.doc`, `.xls`, `.ppt` and `.msg` files uploaded before these formats could be read are re-read and become searchable; add `--failed` to also retry files whose extraction failed.
- **Have counsel verify the seeded Rules of Court periods** (Firm Settings → Deadline rules) against current rules before relying on them.
- **Connect as an ordinary database role, never a superuser.** PostgreSQL does not apply row-level security to superusers or `BYPASSRLS` roles, so connecting as one silently turns the second tenancy layer off. `docker-compose.yml` creates the `ph_legal` role for the app (`backend/docker/postgres/10-app-role.sh`) and keeps the superuser for administration. If you use PgBouncer, use session pooling: the tenant is a session setting. On a database outside `docker-compose.yml`, also run `backend/docker/postgres/admin.sql` once as the administrator; without it, file and knowledge-bank searches cannot use their indexes under row-level security and slow down as files accumulate (the `db-setup` service does this automatically in Docker).
- **Set `RCLONE_REMOTE` so backups leave the server**, and rehearse a restore once (see Backups and restore). A backup on the same disk does not survive the disk. The trust ledger and the signature evidence live in the database. If uploads go to a bucket (`MATTER_FILES_DISK`), turn on versioning there: the backup covers the local `storage` volume.
- **Online payments:** set `PAYMONGO_SECRET_KEY`, register a PayMongo webhook for `https://<your-domain>/api/webhooks/paymongo` with the event `checkout_session.payment.paid`, and set `PAYMONGO_WEBHOOK_SECRET` to its secret. Watch the logs for `Online payment received that could not be applied` (logged at `critical`); such payments are also shown on the invoice for refund.
- **Mail must work in production** (`MAIL_*`): password reset links, portal invitations and e-signature requests are sent by email.
- **Keep ClamAV running.** `docker-compose.yml` runs `clamav/clamav:stable` (about 1.5 GB of RAM; it downloads and updates its signatures itself). Outside Docker, run clamd and set `CLAMAV_ENABLED=true` and `CLAMAV_HOST`. While the scanner is unreachable, uploads are refused with a "try again" message; set `CLAMAV_FAIL_OPEN=true` only if you accept storing files unscanned in that case. Rejected files are recorded in the audit log as `file_rejected_malware`.
- **The heavy-queue worker extracts text for search**, so it needs the same file storage as the app (the `storage` volume in `docker-compose.yml`). Files uploaded while it is down become searchable once it runs.
- **Before requiring two-step verification** (Firm Settings → Firm & security), tell staff they will need an authenticator app. If someone loses their phone and recovery codes, a managing partner resets it from Firm Settings → Users.
- **OCR** runs on the heavy queue (the Docker image includes `tesseract-ocr` with Filipino data and `poppler-utils`; `OCR_ENABLED=true`). Long scans take minutes: the `redis-heavy` connection's `retry_after` (900 s) stays above the worker's `--timeout` (660 s), which stays above the job's own limit (600 s), so a scan never runs twice. Outside Docker, set `QUEUE_HEAVY_CONNECTION=redis-heavy` and run a second worker: `php artisan queue:work redis-heavy --queue=heavy --timeout=660`.
- **Withholding tax:** set the firm's usual rate under **Firm Settings → Firm details** (confirm the rate for your income bracket with your accountant). It is only a suggestion; record what each client actually withheld. **Time & Billing → Form 2307** lists the certificates still to collect, which the firm needs to claim the tax credit.
- **AI assistant:** set `ANTHROPIC_API_KEY` (model `ANTHROPIC_MODEL`, default `claude-opus-5-5`), then a managing partner turns it on under **Firm Settings → Firm & security** after confirming the data processing agreement and client notices. Questions run on the queue; usage (tokens) is stored per answer.
- **Online intake:** choose the web address and turn on requests under **Firm Settings → Firm & security**, then link `/consult/<address>` from your website. Requests are rate-limited per IP and protected by a honeypot.
- **Email to matter:** set `INBOUND_EMAIL_ADDRESS` (e.g. `files@inbound.yourfirm.ph`, on a subdomain whose MX points to your inbound provider) and a long random `INBOUND_EMAIL_SECRET`, then have the provider post each raw message to `https://inbound:<secret>@<your-domain>/api/webhooks/inbound-email` (SendGrid Inbound Parse with "POST the raw, full MIME message", or a Mailgun route forwarding to a URL ending in `mime`); the secret can also go in an `X-Inbound-Secret` header, never in the query string. Mail is filed without review only when the sender is staff or the client **and** the provider confirms the From address: set `INBOUND_EMAIL_AUTHSERV_ID` to the name your provider writes in its `Authentication-Results` headers (SendGrid's own DKIM results are used automatically). Unconfirmed mail waits for review. Messages up to about 23 MB are accepted. Without the address, emails can still be uploaded as .eml files.
- **Phone notifications:** generate the key pair once with `docker compose run --rm app php artisan ops:vapid-keys` and put `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` and `VAPID_SUBJECT` (a `mailto:` address) in `.env`; new keys later mean everyone turns notifications on again. On iPhones, notifications work once Lex PH is added to the Home Screen (iOS 16.4+). The installable app needs a trusted HTTPS certificate: on `localhost`, trust Caddy's local certificate first, or browsers refuse to install it.
- **Calendar links** are credentials: if one leaks, the lawyer replaces it under **Profile** (the old link stops working immediately). Deactivating a user also stops their feed.
- **The app runs in Philippine time** (`APP_TIMEZONE=Asia/Manila`). Keep it that way: "today" decides reglementary periods, reminder days and what counts as a future date.
- **Notarization still needs personal appearance.** The 2004 Rules on Notarial Practice require the signatory to appear before the notary, so an e-signature cannot replace it; the app keeps the notarial register for the in-person act.
- Watch the logs for `Trust account failed reconciliation` (logged at `critical` by `trust:reconcile`).
- Set `SEMAPHORE_API_KEY` to send SMS; without it, SMS messages are written to the log.
- **Use HTTPS only.** `docker-compose.yml` does this with Caddy. Behind another proxy or load balancer, set `TRUSTED_PROXIES` to its address, `SESSION_SECURE_COOKIE=true` and an `https://` `APP_URL`.
- Use 64-bit PHP in production. 32-bit builds cannot represent peso amounts above about ₱21 million in centavos.

### Before relying on the new tools

- **Privacy notice:** have your Data Protection Officer review the built-in notice (or write your own under Data Privacy → Notice & DPO) and register the DPO with the NPC as required.
- **Pleadings:** the assembled parts follow the 2019 Amended Rules of Civil Procedure and the Efficient Use of Paper Rule as commonly applied; check the format against your court's current requirements (some courts and agencies ask for A4 or other margins) and review every pleading before filing.
- **Payment reminders** are off until you turn them on (Time & Billing → Collections); review the wording first.
- **BIR tax compliance:** set the taxpayer type, the ATC your clients use on their 2307s and whether the firm has employees (Billing → BIR tax compliance → Setup). Deadlines are those commonly applied to eBIRForms filers; eFPS filers have staggered dates, so adjust them. Have your accountant confirm the figures before filing.
- **Corporate secretarial:** the SEC sets AFS filing dates by the last digit of the registration number in some years; adjust those due dates. Review the installed corporate templates against each corporation's by-laws.
- **Prescription:** the built-in periods are a starting list; have a lawyer check them against current law and jurisprudence, and check each matter's accrual date. Special laws, suspension and interruption change the answer.
- **Filipino portal:** have a Filipino-speaking lawyer review the translations (in `frontend/src/features/client-portal/i18n.tsx` and `backend/lang/fil.json`), above all the built-in Filipino privacy notice and the e-signature consent.
- **Electronic invoicing:** no accredited EIS provider is connected; until one is (`EINVOICE_DRIVER` and a transmitter for that provider), e-invoices are only kept on file. Check whether the firm is covered by the EIS rollout and the current transmission deadline (`EINVOICE_DEADLINE_DAYS`, default 3) with your accountant.
- **Formal Offer of Evidence:** under the 2019 amendments to the Rules on Evidence the offer is made orally unless the court allows it in writing; review the draft before using it either way.

## Not yet implemented

- Third-party e-signature providers (DocuSign and similar); signing is built in and happens in the client portal.
- Refunds through PayMongo of payments already applied to an invoice (void the invoice payment, then refund in the PayMongo dashboard); unapplied payments are refunded from the invoice.
- Official receipts: the billing statement is not a BIR-registered receipt. Issue ORs from your registered system or booklet and record the OR number as the payment reference.

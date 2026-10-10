# CardTrail — Design Spec

**Date:** 2026-10-10
**Status:** Draft for review

## 1. Purpose

Every month the accountants email each manager a spreadsheet of their Amex transactions. Managers fill in what each transaction was for, save each invoice into a monthly folder named after its spreadsheet row (`Row29.pdf`), and email the spreadsheet and files back. It is manual, slow, and needs a lot of chasing.

CardTrail replaces that loop with one responsive web app, installable on iPhone from Safari as a PWA:

- Accountants upload the statement export once; transactions are split to each cardholder automatically.
- Managers fill in a purpose and attach a receipt per line from their phone or desktop, then submit.
- Accountants review each line next to its receipt, approve or query it, and export a ZIP in today's format (completed spreadsheet + `RowNN` receipt files).
- The app does the chasing: emails, push notifications, deadline reminders, and query alerts.

**Success looks like:** a manager can complete their month from their phone in a few minutes; accountants no longer email spreadsheets or rename files; the export is a drop-in replacement for what they receive today.

**Users:** Crown Group employees only (internal tool). The project is authorised.

## 2. Constraints

- **Stack:** Laravel 12 + Vue 3 starter kit (Inertia), Tailwind, MySQL, Pest, database queues — a single monolith (approach A).
- **Platforms:** responsive web, mobile-first for managers; PWA installable from Safari on iPhone.
- **Company AI policy:** no real statements, card numbers, invoices, staff names, internal system code/config or security details are used during development with AI tools. All development and test data is fabricated. No receipt is ever sent to an external AI/OCR service.
- **Internal AD auth package:** will be added later as a Composer package by the developer; its code is not shared in AI sessions. The app integrates with it through an interface (§6.1).
- **Hosting, production mail route, and Microsoft 365 mailbox access** are IT decisions, out of scope for v1 build.

## 3. Roles

| Role | Can do |
|---|---|
| **Admin (accountant)** | Manage users and card identifiers; create/publish/close periods; upload imports and map columns; assign unassigned lines; review, approve, query; export; manage settings; view inbound mail log. |
| **Cardholder (manager)** | See and complete only their own reconciliations; attach receipts; use their receipt inbox; submit; respond to queries; view their past periods read-only. |

A user may hold both roles (`is_admin`, `is_cardholder` flags).

## 4. Monthly flow

1. **Create period** — admin creates e.g. "September 2026" (status `draft`) with a due date and reminder offsets (default: 3 days before, and on the day).
2. **Import** — admin uploads one or more CSV/XLSX exports into the period.
   - First time: map columns (date, description, amount, card last 4 / cardholder name, purpose target column). Other columns are kept as read-only "extra" data. The mapping is saved and auto-applied to files with the same header signature.
   - Rows are auto-assigned to users by card identifier; unmatched rows go to **Unassigned**.
   - Each transaction keeps its real spreadsheet row number.
   - Preview shows matched counts per user, unassigned count, unparseable rows (fix or skip), and suspected duplicates before confirming.
3. **Publish** — period becomes `published`; each cardholder with lines gets an email + push notification.
4. **Complete** — the manager works through their lines: purpose + receipt(s), or purpose + "no receipt" with reason. Autosave; "Save & next".
5. **Submit** — available only when every line is done. The reconciliation locks for the manager.
6. **Review** — admin views each line beside its receipt. **Approve** or **Query** (with comment). A query reopens only that line (reconciliation returns to `in_progress`) and notifies the manager; when the manager resubmits, the admin is notified. When all lines are approved, the reconciliation is `approved`.
7. **Export** — per manager or whole period: ZIP containing the original spreadsheet with purposes written in, plus receipts named by row.
8. **Close** — admin closes the period; it becomes read-only for everyone.

## 5. Data model

All money is stored as integer pence. All models use standard timestamps.

| Model | Fields | Notes |
|---|---|---|
| **User** | `name`, `email` (unique), `password` (nullable), `use_microsoft` (bool), `external_id` (nullable, unique), `is_admin`, `is_cardholder`, `active` | Users are deactivated, not deleted. A user with `use_microsoft` cannot sign in by password. |
| **CardIdentifier** | `user_id`, `last4` (nullable, 4 digits), `cardholder_name` (nullable) | At least one of the two required. Many per user. |
| **Period** | `label`, `month` (date, first of month, unique), `due_date`, `status` (`draft`/`published`/`closed`), `reminder_offsets` (json array of days, e.g. `[3, 0]`) | |
| **Import** | `period_id`, `original_filename`, `stored_path`, `file_hash`, `column_mapping` (json), `header_row` (int), `row_count`, `uploaded_by` | Original file is retained for export. |
| **ColumnMapping** | `name`, `header_signature` (hash of normalised headers), `mapping` (json), `header_row` | Saved mappings, auto-applied on signature match. |
| **Reconciliation** | `period_id`, `user_id` (unique together), `status` (`in_progress`/`submitted`/`approved`), `submitted_at`, `approved_at`, `approved_by` | One per cardholder per period. |
| **Transaction** | `period_id`, `import_id`, `reconciliation_id` (nullable = unassigned), `row_number`, `transacted_on` (date), `description`, `amount` (int pence, negative = credit), `card_ref` (last 4 / name as imported), `extra` (json), `purpose` (nullable), `no_receipt` (bool), `no_receipt_reason` (nullable), `status` (`todo`/`done`/`queried`/`approved`), `approved_at`, `approved_by` | |
| **Receipt** | `user_id`, `transaction_id` (nullable = in inbox), `disk`, `path`, `original_name`, `mime`, `size`, `source` (`camera`/`upload`/`bulk`/`email`), `inbound_message_id` (nullable) | Many per transaction. |
| **Comment** | `transaction_id`, `user_id`, `body` | Query thread. |
| **InboundMessage** | `source_driver`, `message_id` (unique), `from_email`, `subject`, `received_at`, `user_id` (nullable), `outcome` (`accepted`/`unknown_sender`/`no_attachments`/`rejected_type`), `attachment_count` | Admin-visible inbound log; also prevents double-processing. |
| **Activity log** | `spatie/laravel-activitylog` | Sign-ins, imports, edits, assignments, submissions, approvals, queries, exports. |

### 5.1 Business rules

- **Line done** = `purpose` filled AND (≥1 receipt OR (`no_receipt` AND reason filled)). Credits (negative amounts) need only a purpose.
- Line status is derived on save: `todo` ↔ `done`; `queried` is set only by an admin query and cleared to `done` when the manager edits and the line again meets the done rule.
- **Submit** allowed only when no line in the reconciliation is `todo` or `queried`.
- Manager edits are blocked while the reconciliation is `submitted` or `approved`, except lines in `queried` status.
- A query sets the line to `queried` and the reconciliation to `in_progress`; the manager must resubmit.
- Admin can reassign a line (including from Unassigned) any time before it is approved. Reassigning creates the target user's reconciliation if needed. If the target reconciliation was `submitted`, it returns to `in_progress`.
- A late import into a published period adds lines; any affected reconciliation that was `submitted` returns to `in_progress` and the manager is notified.
- Closed periods are read-only for all users.
- Only the last 4 digits of a card number are ever stored. A full card-number column is truncated to last 4 on import and not kept in `extra`.

## 6. Components

### 6.1 Authentication

- **Email-first login:** user enters email → if the user exists, is active, and has `use_microsoft`, redirect to the external identity provider; otherwise show the password step. Unknown emails show the password step (no account enumeration) and fail generically.
- **Password auth:** starter-kit login, password reset, rate limiting. Users are created by admins (invite email with set-password link); no public registration.
- **`IdentityProvider` interface** (`app/Auth/IdentityProvider.php`):
  - `redirect(): \Symfony\Component\HttpFoundation\Response` — start external sign-in.
  - `user(Request $request): ExternalIdentity` — returns verified `email` and `externalId`, or throws.
- Callback controller: look up user by `external_id`, else by email; require `active` and `use_microsoft`; store `external_id` on first sign-in; never auto-register.
- Driver selected by config `cardtrail.identity.driver` (env `CARDTRAIL_IDENTITY_DRIVER`):
  - `fake` (v1, only bound when `app()->environment('local', 'testing')`): a "Sign in as…" page simulating the external provider.
  - `crown-ad` (added later by the developer): adapter wrapping the internal AD Composer package.
- No Socialite.

### 6.2 Imports

- `phpoffice/phpspreadsheet` reads `.xlsx` and `.csv`.
- **Header detection:** admin confirms the header row (default: first non-empty row).
- **Parsers** (pure, unit-tested classes):
  - Dates: `dd/mm/yyyy`, `dd/mm/yy`, `d M Y`, ISO, Excel serial numbers. UK day-first is assumed for ambiguous dates.
  - Amounts: `£`, thousands separators, `-12.00`, `(12.00)`, `12.00 CR` → negative pence; `12.00 DR` → positive.
- **Auto-assignment:** match `card_ref` to `CardIdentifier.last4` first, then case-/whitespace-insensitive `cardholder_name`. Ambiguous matches (multiple users) → Unassigned.
- **Duplicates:** whole-file hash match warns before import; row-level duplicates (same date, amount, description, card) within the period are flagged in the preview and skipped unless the admin includes them.
- Unparseable rows appear in the preview with the reason; the admin can fix values inline or skip them.

### 6.3 Receipts

- Accepted: PDF, JPG, PNG, HEIC/HEIF, DOC, DOCX. Max 20 MB each.
- Images: HEIC → JPEG and downscale to max 2000 px long edge via Imagick (queued). If Imagick lacks HEIC support, store the original unchanged and log a warning.
- Stored on private disk `receipts` (local in v1; S3/Azure Blob later via config). Path: `receipts/{user_id}/{uuid}.{ext}`.
- Served only via `ReceiptController@show`, authorised by `ReceiptPolicy`.
- **Bulk upload:** many files at once land in the user's receipt inbox (`transaction_id = null`).
- **Matching from inbox:** candidate lines sorted by closeness of transaction date to the receipt's upload/received date, then by amount if the user enters one. Manager picks; nothing is auto-attached.

### 6.4 Email-in receipts

- One address (e.g. `receipts@…`, configured). Sender must match an active cardholder's email (case-insensitive); attachments of accepted types become inbox receipts with `source = email`.
- Unknown sender, no attachments, or only rejected types → recorded in `InboundMessage` with outcome; no bounce reply.
- **`InboundMailSource` interface:** `fetch(): iterable<InboundEmail>` (each with message id, from, subject, received at, attachments) plus `markProcessed(InboundEmail)`.
  - `eml` driver (v1, development): `php artisan receipts:ingest path/to/file.eml` (accepts a file or a directory of `.eml` files).
  - `graph` driver (production, later): poll a shared Microsoft 365 mailbox via Microsoft Graph on a schedule. Requires IT to grant the app mailbox access.
  - Webhook drivers (Postmark/Mailgun) remain an option if IT prefers; not built in v1.
- Idempotent on `message_id`.

### 6.5 Review

- Split view: line list (filter by status) and receipt viewer (PDF via browser embed, images inline, DOC/DOCX as download link).
- Approve line, query line (comment required), approve all remaining lines.
- Comments thread on each line, visible to both parties.

### 6.6 Export

- Queued `BuildExport` job; notifies the requester with a download link when done; ZIP kept for 7 days then pruned.
- **Spreadsheet:** the original uploaded file per import, opened with PhpSpreadsheet, with each line's purpose written into the mapped purpose column (or a new "Purpose" column appended), and " (No receipt: {reason})" appended to the purpose where applicable. Rows are never deleted or reordered, so row numbers always match the `RowNN` file names. A per-manager export fills in only that manager's purposes.
- **Receipts:** `Row{row_number}.{ext}`; additional receipts for the same line `Row{row_number}-2.{ext}`, `-3`, …
- **ZIP layout:**
  - Per manager: `{Period label} - {User name}/` containing the spreadsheet(s) and that manager's receipts.
  - Whole period: the spreadsheet(s) at the root with every manager's purposes filled in, plus one `{User name}/` folder of receipts per manager.
- If a period has multiple imports, receipts are prefixed by import to avoid collisions: `Import2-Row29.pdf` (first import keeps plain `Row29`).

### 6.7 Notifications

Laravel Notifications via `mail` and `webpush` (`laravel-notification-channels/webpush`, VAPID keys):

| Event | Recipient |
|---|---|
| Period published / new lines added | Cardholders with lines |
| Deadline reminder (per `reminder_offsets`, daily scheduled command, only users with incomplete reconciliations) | Cardholder |
| Manual nudge from dashboard | Cardholder |
| Line queried | Cardholder |
| Reconciliation submitted / resubmitted | All admins |
| Export ready / export failed | Requesting admin |

Dev mail: `log` mailer or Mailpit. Production mail route: IT decision.

### 6.8 PWA

- `vite-plugin-pwa`: manifest (name "CardTrail", icons, standalone display), service worker handling push and notification clicks.
- One-time in-app prompt to enable notifications, shown when running standalone (iOS requires an installed PWA for web push).
- No offline caching of app data in v1.

## 7. Screens

**Shared:** email-first sign-in → password step or external identity redirect.

**Cardholder**
1. **Home** — current period card (progress, due date, Continue), queries badge, past periods.
2. **Transaction list** — tabs To do / Done / Queried; sticky Submit button.
3. **Transaction detail** — details + extra columns, purpose (with recent-purpose suggestions), Take photo / Choose file, receipt thumbnails, No receipt toggle + reason, query thread, autosave, Save & next.
4. **Receipt inbox** — unmatched receipts, preview, pick a line, bulk upload, shows the email-in address.

**Admin**
5. **Period dashboard** — per-manager progress and status, overdue flags, nudge, Unassigned link, export, publish/close.
6. **Import wizard** — upload → header row + column mapping → preview (assignment, errors, duplicates) → confirm.
7. **Unassigned** — assign lines to users (single or bulk).
8. **Review** — split view, approve / query / approve all.
9. **Manage Users** — name, email, roles, card identifiers, Use Microsoft 365, active; invite and edit.
10. **Settings** — default reminder offsets, saved column mappings.
11. **Inbound log** — email-in outcomes.

Mobile-first layout; review screen stacks on narrow viewports.

## 8. Error handling

- Import parse errors and duplicates surfaced in preview, never silently dropped.
- Client-side type/size validation before upload; failed uploads retryable without losing typed purpose.
- Deactivated users with open lines flagged on the dashboard for reassignment.
- Queued jobs (image conversion, export, inbound mail) retry 3 times; final failure notifies the requester (or logs for inbound mail).
- Inbound mail problems recorded in the inbound log.

## 9. Security

- Laravel Policies: cardholders access only their own reconciliations, transactions, receipts, comments; admins access all.
- Receipts never publicly addressable; streamed through authorised controller.
- Login and email-step rate limiting; no account enumeration.
- Secure, HTTP-only session cookies; CSRF via Inertia defaults.
- Only last 4 card digits stored.
- Activity log for all significant actions.
- Repository contains no real data; seeders and fixtures are fabricated.

## 10. Testing (Pest)

- **Unit:** date and amount parsers, header signature matching, auto-assignment, line-done rule, status transitions, export file naming (including multi-import and multi-receipt cases).
- **Feature:** import wizard end-to-end with fixture files; manager complete → submit; review approve / query → fix → resubmit → approve; policies (cardholder A cannot see cardholder B's lines or receipts); password login, email-first routing, fake identity provider sign-in (including rejection when `use_microsoft` is off or user inactive); `.eml` ingestion (accepted, unknown sender, no attachments, duplicate message id); reminder command targeting; export ZIP contents and spreadsheet purpose values.
- **Fixtures:** factories and a demo seeder generating fake statements (invented merchants and amounts), plus small dummy PDF/JPG receipts.

## 11. Out of scope for v1

- Hosting and deployment (IT)
- Production `graph` inbound driver and `crown-ad` identity adapter (developer adds later; interfaces ready)
- OCR of any kind; any external AI processing of receipts
- Offline mode
- Admin-configurable extra fields per line
- Native app

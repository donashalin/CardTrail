# CardTrail

Monthly card reconciliation for Crown Group. Accounts upload the card statement. Each cardholder adds a purpose and a receipt for every line from their phone or desktop, then submits. Accounts review each line and download a ZIP in the familiar layout: the spreadsheet with purposes filled in, plus `Row29.pdf` receipt files.

Laravel 12 + Inertia + Vue 3. The design is in `docs/superpowers/specs/2026-10-10-cardtrail-design.md`, and the build plans are in `docs/superpowers/plans/`.

> Development and test data are invented. Never load real statements, card numbers, receipts or staff details into a development copy.

## Running it locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed          # demo accounts in local/testing only
php artisan webpush:vapid           # push notification keys, written to .env
composer dev                        # web server, queue worker, logs and Vite together
```

The queue worker matters. Notifications, receipt photo resizing and exports all run on the queue. In development, emails go to `storage/logs/laravel.log` (`MAIL_MAILER=log`).

### Demo accounts (password `password`)

| Email | Role |
|---|---|
| admin@cardtrail.test | Accounts (admin) |
| sam@cardtrail.test | Cardholder, card ending 1001 |
| jo@cardtrail.test | Cardholder, card ending 1002 |
| morgan@cardtrail.test | Cardholder using the stand-in Microsoft 365 sign-in, card ending 1003 |

### Trying a month

1. Sign in as the admin, create a period, and upload `resources/demo/demo-statement.csv`.
2. Confirm the columns and the preview, assign the unassigned line, then publish.
3. Sign in as Sam. Give each line a purpose and a receipt (any PDF or photo), or tick "no receipt" and give a reason. Then submit.
4. Back as the admin, open Review from the period page. Approve or query lines, then use Export all.

Email-in: `php artisan receipts:ingest resources/demo/demo-receipt.eml` puts an invented receipt in Sam's inbox.

Reminders: `php artisan reminders:send` (scheduled daily at 08:00).

### Local PHP upload limits

PHP's defaults (`upload_max_filesize=2M`, `post_max_size=8M`) are below CardTrail's limits: receipts can be up to 20 MB each, and up to 20 can be uploaded at once to the inbox. Raise them in your `php.ini` (`php --ini` shows where it is), for example `upload_max_filesize=25M` and `post_max_size=200M`. Otherwise a normal phone photo can fail to upload. On servers running PHP-FPM, `public/.user.ini` already sets these; `php artisan serve` ignores it, so set them in `php.ini` locally.

### Phones and push notifications

Push notifications and installing to the Home Screen need HTTPS (or `localhost`). On an iPhone, open the site in Safari, then choose Share → Add to Home Screen. Open CardTrail from the Home Screen icon to be offered notifications. Email notifications work everywhere.

### Opening it on your phone over Tailscale

Tailscale gives this Mac an HTTPS address that your other tailnet devices can reach, which is enough for push notifications and Add to Home Screen. It needs MagicDNS and HTTPS Certificates turned on under DNS in the Tailscale admin console.

```bash
# Once: set APP_URL to this Mac's tailnet address, so emails and notifications link there
sed -i '' -E "s#^APP_URL=.*#APP_URL=https://$(tailscale status --json | python3 -c 'import json,sys; print(json.load(sys.stdin)["Self"]["DNSName"].rstrip("."))')#" .env

composer tailnet                    # builds the assets, then runs the server on 127.0.0.1:8088, the queue worker and the scheduler
tailscale serve --bg 8088           # tailnet only, and remembered across restarts; `tailscale serve reset` turns it off
```

`tailscale serve status` shows the address. Don't run `npm run dev` at the same time: it points the pages at Vite on `localhost`, which a phone can't reach. Don't use `tailscale funnel`, which would put CardTrail on the public internet.

## Integrations added later

- **Company sign-in.** Microsoft 365 sign-in goes through `App\Auth\IdentityProvider`. Add an adapter for the internal AD package and point `CARDTRAIL_IDENTITY_DRIVER` at it.
- **Receipts mailbox.** Email-in goes through `App\Mail\Inbound\InboundMailSource`. Add a mailbox driver once IT grants access, register it in `config/cardtrail.php`, and set `CARDTRAIL_INBOUND_DRIVER` and `CARDTRAIL_INBOUND_SCHEDULE=true`.

## Tests

```bash
php artisan test
```

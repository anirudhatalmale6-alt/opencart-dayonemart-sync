# OpenCart → DayOneMart Real-Time Sync (Letsync bridge)

Keeps a DayOneMart (Laravel) store continuously mirrored from an OpenCart 3.x store —
products, categories, customers, orders and their payments — in real time, with
automatic retries, zero duplication and a full transfer log.

Built for: OpenCart 3.0.5.0 (`green7.ae`) → DayOneMart / Laravel 12 (`green7.app`).

---

## How it works

```
 OpenCart admin/storefront change
        │  (letsync module fires an event: add/edit/delete)
        ▼
 POST https://green7.app/webhooks/{product|category|customer|order}/handle
        │  header X-Letsync-Token  (shared secret)
        ▼
 LetsyncWebhookController  ──dispatch──▶  Sync{Entity}Job  (database queue "letsync")
        │
        ▼
 {Entity}SyncService.syncById(openCartId)
        │  reads AUTHORITATIVE data straight from the OpenCart DB (read-only connection)
        │  maps it to the DayOneMart schema
        │  upserts, keyed by `external_id` (the OpenCart id)  ──▶  idempotent, no duplicates
        ▼
 DayOneMart tables + `letsync_logs` (success / error / skipped)
```

The webhook only carries the event name and the record id. The service then reads the
full, current record from OpenCart. That means the exact same code path powers both
the **real-time** sync and the **one-time backfill** — there is a single source of truth
and no fragile payload parsing.

### Why this design
- **No lost records** — jobs run on a durable database queue and retry up to 5 times
  with backoff. A failed transfer stays queued, not dropped.
- **No duplicates** — every DayOneMart row carries an `external_id` (the OpenCart id).
  Re-syncing the same record updates it in place.
- **No missing fields** — the mapper reads the live OpenCart row, not a cached snapshot.
- **Isolated** — only the sync jobs use the database queue; the rest of the app is
  untouched (global `QUEUE_CONNECTION` stays as-is).

---

## What syncs

| OpenCart            | DayOneMart                                                             |
|---------------------|-----------------------------------------------------------------------|
| category (top level)| `categories`                                                          |
| category (child)    | `sub_categories` (linked to its mapped parent category)               |
| product             | `items` + `item_prices` + `item_stocks` + `grocery_items` + `files` (images) |
| customer            | `users` (user_type = customer)                                        |
| order               | `orders` + `order_items` + `order_payments`                           |

Real-time triggers (OpenCart `letsync` events, already installed): product / category /
customer add-edit-delete, plus order add / edit / status-change / delete, plus
storefront customer & address changes.

---

## Files added to the DayOneMart app

```
config/letsync.php                                  configuration (token, module, image base)
routes/letsync.php                                  webhook routes
app/Http/Middleware/VerifyLetsyncToken.php          shared-secret auth
app/Http/Controllers/Letsync/LetsyncWebhookController.php
app/Jobs/Letsync/Sync{Product,Category,Customer,Order}Job.php
app/Services/Letsync/OpenCartReader.php             read-only OpenCart DB reader
app/Services/Letsync/{Product,Category,Customer,Order}SyncService.php
app/Services/Letsync/ImageImporter.php              downloads OpenCart images
app/Services/Letsync/SyncLogger.php                 writes letsync_logs
app/Console/Commands/LetsyncBackfillCommand.php     one-time backfill
database/migrations/*_add_external_id_for_letsync.php
database/migrations/*_create_letsync_logs_table.php
```

Small, reversible edits (backed up as `*.letsync.bak`):
- `config/database.php` — adds a read-only `opencart` connection (env `OC_DB_*`).
- `bootstrap/app.php` — registers the `letsync` middleware alias + webhook route group.
- `.env` — adds `LETSYNC_*` and `OC_DB_*`.

---

## Setup

1. Copy the files above into the app (see `deploy_letsync.py`).
2. Add env vars (see `.env.example` keys): `LETSYNC_TOKEN`, `OC_DB_*`, `LETSYNC_IMAGE_BASE_URL`.
3. Migrate: `php artisan migrate`.
4. Ensure the storage symlink exists: `php artisan storage:link`.
5. Install the queue-worker cron (see `setup_cron.sh`):
   ```
   * * * * * cd /path/to/app && php artisan queue:work database --queue=letsync --stop-when-empty --max-time=55 --tries=5
   ```
6. Point OpenCart's `letsync` module at the app and enable it (see `repoint_letsync.sql`),
   using the same token as `LETSYNC_TOKEN`.

## Backfill existing data

```
php artisan letsync:backfill all          # categories, then products, customers, orders
php artisan letsync:backfill products
php artisan letsync:backfill orders --limit=100
```
Idempotent — safe to re-run any time.

## Monitoring

```sql
SELECT entity, status, COUNT(*) FROM letsync_logs GROUP BY entity, status;
SELECT * FROM letsync_logs WHERE status = 'error' ORDER BY id DESC;   -- errors to act on
SELECT * FROM failed_jobs;                                            -- exhausted retries
```

## Notes
- Order lines that reference products deleted from OpenCart are logged as `skipped`
  (the product no longer exists to link to); the order header still syncs.
- OpenCart products map to DayOneMart's active module (configurable via `LETSYNC_MODULE_ID`).

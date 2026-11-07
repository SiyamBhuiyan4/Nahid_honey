# Nahid Honey

Online shop for Nahid Honey — pure honey, khejur gur, dates, nuts, oils and other
natural foods, with cash-on-delivery ordering for Bangladesh. Plain PHP 8 (no
framework, no build step), a storefront plus an admin panel for the owner and staff.

- **Storefront**: home with banners and categories, search and filters, product
  pages with reviews, cart, wishlist, checkout with coupons, order tracking.
- **Admin panel** (`/admin/`): dashboard, orders, customers, products, categories,
  inventory, reviews, coupons, reports, media uploads, settings, staff accounts with
  permissions, and an activity log.
- **Telegram**: new orders are posted to the store's Telegram channel, product images
  are stored there, and in production **all store data lives in Telegram too**.

## Run it locally

Requirements: PHP 8.1+ with `pdo_sqlite`, `curl`, `mbstring`, `openssl` (Homebrew PHP
has them all).

```bash
./start_server.sh                # http://127.0.0.1:8080   (PORT=9000 ./start_server.sh)
# same as:  php -S 127.0.0.1:8080 api/index.php
```

On first run the data store is created and filled with the sample products, reviews and
orders from `database/*.json`. Open `http://127.0.0.1:8080/admin/` — the first visit
asks you to create the **Owner** account.

Locally the data goes to `storage/store.sqlite` (git-ignored). Delete that file to start
fresh. To work against the live Telegram data from your machine, put the production
values in a `.env` file (see `.env.example`) — `start_server.sh` loads it.

## Project structure

```
api/index.php          Front controller (Vercel + local). Maps /x and /x.php to public/x.php,
                       /admin → /admin/, serves static files in local mode, blocks /inc/,
                       dotfiles and anything outside public/, shows public/404.php otherwise.
app/
  db.php               Data store API: store_all/get/put/patch/delete, store_next_id,
                       setting()/setting_set(), log_activity(), store_flush().
                       Drivers: telegram, pgsql, sqlite.
  store_telegram.php   Telegram storage driver.
  auth.php             Staff login (signed cookie), CSRF, flash messages, login throttle.
  permissions.php      Roles and the permission catalogue.
  orders.php           Order statuses, order creation, customers, order lookup.
  coupons.php          Coupon validation and usage counting.
  catalogue.php, media.php
config/
  site.php             Store defaults (name, contact, banners, delivery fees...). Values
                       saved in Admin → Settings override these.
  telegram.php         Bot token / channel (read from environment variables first).
database/
  *.json               Seed data imported on first run. TelegramDB.php = channel notifications/uploads.
public/                Everything reachable from the web
  index.php product.php checkout.php track.php wishlist.php order-success.php ...
  api.php              JSON endpoints used by the storefront (cart, coupons, place order)
  media.php            Image proxy for files stored in Telegram (keeps the token private)
  sitemap.php          /sitemap.xml      robots.txt      404.php
  inc/                 bootstrap + layout (not web-accessible)
  admin/               admin pages; admin/inc/admin.php = admin bootstrap + chrome
  assets/              css, js, images
bin/
  create-admin.php     Create / reset an owner or admin account (forgotten password)
  backup.php           Export all data to a JSON file
  restore.php          Import a backup
vercel.json            Vercel runtime, routes, headers
```

## Admin panel, roles and permissions

| Role | Access |
|---|---|
| **Owner** | Everything. Created on first-run setup (`/admin/setup.php`) or with `bin/create-admin.php`. Cannot be removed or demoted by others. |
| **Administrator** | Everything, including managing staff. |
| **Employee** | Only the permissions ticked for them in Admin → Staff. |

Permissions an owner/admin can give employees (presets: Order manager, Catalogue
manager, Support agent, Packer/delivery, Marketing):

- Dashboard: view dashboard, view reports
- Orders: view, create manual (phone) orders, edit status/notes, delete/cancel, export CSV
- Catalogue: view/add/edit/delete products, update stock, manage categories, upload images
- Customers & marketing: view customers, manage reviews, manage coupons
- Store: edit settings, manage staff, view activity log

Every important change is recorded in **Admin → Activity log** (who, what, when, IP).

**Forgot the password?** From a computer with this repo:

```bash
# against the production (Telegram) data
STORE_DRIVER=telegram TELEGRAM_BOT_TOKEN=... TELEGRAM_CHANNEL_ID=... \
  php bin/create-admin.php owner@example.com            # asks for a new password
php bin/create-admin.php owner@example.com --generate   # prints a random password
php bin/create-admin.php new-admin@example.com --role=admin --name="Rahim"
```

## Where the data is stored

`app/db.php` picks the driver from `STORE_DRIVER` (`telegram` | `pgsql` | `sqlite`).
Without it: Postgres if `DATABASE_URL` is set, Telegram when running on Vercel, SQLite
otherwise. The PHP code is the same for all three.

### Telegram (production default)

Vercel's file system is read-only and wiped between requests, so the shop keeps its data
in the Telegram channel:

- One **document message per collection** (`products.json.gz`, `orders.json.gz`,
  `users.json.gz`, ...). Saving replaces the file inside the same message, so the channel
  does not fill up. Captions say *“Nahid Honey data · … — do not delete”*.
- One **pinned text message** starting with `NAHID_HONEY_DB_INDEX — do not delete`
  followed by JSON that points to the current file of every collection. The shop finds
  it through the channel's pinned message.

**Do not delete, edit or unpin those messages.** If you ever have to pin something else
in the data channel, set `TELEGRAM_DB_INDEX_MSG_ID` to the index message's id (shown
in its link `t.me/c/<channel>/<id>`) so the shop can still find it — or better, use a
separate private channel just for data (`TELEGRAM_DB_CHANNEL_ID`, add the bot as an
admin with post/edit/pin/delete rights before the first deploy).

How it behaves:

- Reads are cached in the function's `/tmp` (files forever — every version has its own
  id — and the index for 3 seconds), so most page views make at most one quick call to
  Telegram. If Telegram is down, the last cached data is shown.
- Writes are collected during a request and saved once at the end: the latest version of
  each changed collection is downloaded, this request's changes are re-applied
  document-by-document, uploaded, and the index is updated and re-checked. Two people
  saving different things at the same moment do not overwrite each other. If saving
  fails the visitor gets an error page instead of a false “saved”.
- Saving takes roughly 1–3 seconds (a few calls to Telegram). Fine for a small shop;
  for heavy traffic switch to Postgres.
- Limits: a collection file must stay under 20 MB (≈ 15–20 thousand orders). The activity
  log keeps the newest 3,000 entries. Failed-login counters stay on the server only.
- Anyone who has the bot token or can open the channel can read the data files (orders
  include customer names, phones and addresses; staff accounts contain password hashes).
  Keep the channel private, keep the token secret, and optionally set `TELEGRAM_DB_KEY`
  to encrypt the files (if you lose that key the data cannot be read).

### Postgres (optional)

Set `DATABASE_URL` (e.g. a free [Neon](https://neon.tech) database, or Vercel → Storage →
Neon) to use Postgres instead. Tables are created and seeded automatically on the first
request. Use Neon's **pooled** connection string. Move data between stores with
`bin/backup.php` + `bin/restore.php`.

### Backups

```bash
php bin/backup.php                         # -> backups/nahid-honey-<date>.json (git-ignored)
php bin/restore.php backups/file.json      # upsert; add --replace to mirror the file exactly
```

Prefix with the same environment variables as above to back up production. Backup
files contain customer data and password hashes — store them privately.

## Deploy to Vercel

The project is already linked (`.vercel/project.json`, project `nahid-honey`); it uses
the community runtime `vercel-php@0.7.3` (PHP 8.3, includes `pdo_pgsql`, `pdo_sqlite`,
`curl`, `openssl`). Functions run in `fra1` (Frankfurt), close to Telegram's servers.

1. **Rotate the Telegram bot token.** The old token was committed to this public
   repository, so anyone can use it. In Telegram open **@BotFather → /mybots → your bot →
   API Token → Revoke current token**, and copy the new token. (The fallback token in
   `config/telegram.php` should then be removed from the code.)
2. Make sure the bot is an **administrator** of the channel with *Post*, *Edit*, *Delete*
   and *Pin messages* rights (or of a separate data channel, see above).
3. In Vercel → Project → **Settings → Environment Variables** (Production + Preview) add:

   | Name | Value |
   |---|---|
   | `TELEGRAM_BOT_TOKEN` | the new token from step 1 |
   | `TELEGRAM_CHANNEL_ID` | the channel id, e.g. `-100…` |
   | `APP_SECRET` | 64 random hex characters: `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` |
   | `STORE_DRIVER` | `telegram` |
   | `TELEGRAM_DB_CHANNEL_ID` | *(optional)* separate private data channel |
   | `TELEGRAM_DB_KEY` | *(optional)* encryption passphrase for the data files |
   | `DATABASE_URL` | *(only if you use Postgres instead of Telegram)* |

   Or from the CLI: `vercel env add TELEGRAM_BOT_TOKEN production`.
4. Deploy: `vercel --prod` (or push to the connected GitHub branch).
5. **Immediately** create the owner account — either open `https://<your-domain>/admin/`
   and complete the setup form, or run `bin/create-admin.php` with the production
   variables. Until an owner exists, anyone who opens `/admin/setup.php` could claim it.
6. Check: home page, a product page, place a test order (it appears in Admin → Orders and
   in the channel), then delete the test order.

Security headers, `no-store` caching for `/admin`, a one-day cache for `/assets`, and
blocking of `inc/` folders, dotfiles and source files are configured in `vercel.json`
and `api/index.php`. Local data (`storage/`), `.env*` and tools are excluded from git
(`.gitignore`) and from uploads (`.vercelignore`).

## Environment variables

See [`.env.example`](.env.example) for the full list with explanations.

# Sprawl-9

A browser-based MMO/idle text game set in a decaying neon megacity arcology.
You're an unregistered drifter — a ghost in the Sprawl — who jacks in with nothing
and builds a reputation. Plain **PHP + MySQL**, no framework.

## Stack
- PHP 8+ (PDO, sessions)
- MySQL / MariaDB
- Single front controller (`index.php`) routing `?p=page&act=action` to files in `pages/`

## Features
Accounts (register/login), bank (Iron Ledger), skills (Datacore), player marketplace
(Bazaar), gathering + crafting (Foundry), travel + mining (Transit Hub), combat
(Combat Sim), casino (Lucky Daemon), message boards, and a live public chat.

---

## Local setup (XAMPP / LAMP)

1. Put the project in your web root (e.g. `htdocs/sprawl9`).
2. Create the config file and fill in your DB credentials:
   ```
   cp config.sample.php config.php
   ```
   Edit `config.php` → `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
3. Create the database (name must match `DB_NAME`), then apply the migrations
   **in this exact order**:

   | # | File |
   |---|------|
   | 1 | `schema.sql` |
   | 2 | `schema_bazaar.sql` |
   | 3 | `schema_foundry.sql` |
   | 4 | `schema_transit.sql` |
   | 5 | `schema_combat.sql` |
   | 6 | `schema_casino.sql` |
   | 7 | `schema_boards.sql` |
   | 8 | `schema_chat.sql` |

   CLI example:
   ```
   mysql -u USER -p sprawl9 < schema.sql
   mysql -u USER -p sprawl9 < schema_bazaar.sql
   # ...and so on, in order
   ```
   (Or import each file via phpMyAdmin.)
4. Visit `index.php` → **Register a Ghost** → jack in.

---

## Deploying to GoDaddy (cPanel Linux Hosting)

> Requires a **Linux cPanel hosting** plan with PHP + MySQL. (Managed WordPress and
> Website Builder plans can't run this.)

### One-time setup
1. **Create the database** in cPanel → *MySQL Databases*: make a database, a user,
   and add the user to the database with all privileges. Note the **full** names
   (GoDaddy prefixes them, e.g. `cpaneluser_sprawl9`).
2. **Get the code onto the server** — pick one:
   - **cPanel → Git Version Control** (pull from GitHub): "Create", paste your repo's
     HTTPS clone URL, set the path. cPanel clones it. Use the included `.cpanel.yml`
     (edit `CPANELUSER`/path first) and click **Deploy** to copy files into `public_html`.
   - **SSH** (if enabled): `git clone <repo-url>` into your web folder; `git pull` to update.
3. **Create `config.php` on the server** (it's gitignored, so it isn't in the repo):
   ```
   cp config.sample.php config.php
   ```
   Edit it with the **GoDaddy** database name/user/password from step 1.
4. **Run the migrations** via cPanel → *phpMyAdmin*, importing each `schema_*.sql`
   in the order shown above.

### Updating later
Push changes to GitHub, then in cPanel → *Git Version Control* click **Update** /
**Deploy** (or `git pull` over SSH). `config.php` stays put and untouched.

---

## Security notes
- `config.php` (real DB password) is **gitignored** — never committed.
- All SQL uses **PDO prepared statements**; passwords use `password_hash`/`password_verify`.
- All user-facing output is escaped via the `e()` helper; chat renders via
  `textContent` to prevent XSS.
- Players register their own accounts — no seeded credentials.

## Project structure
```
index.php            Front controller + 3-column shell
config.php           DB credentials + helpers (gitignored; copy from config.sample.php)
config.sample.php    Template for config.php
chat_api.php         JSON endpoint for the live chat
style.css            Neon theme
schema*.sql          Database migrations (apply in order)
pages/               One file per page/venue
```

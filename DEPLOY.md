# Deploying to Hostinger (shared hosting)

The layout maps directly onto Hostinger's: `public_html` gets the **contents of
`public/`**, and everything else sits **one level above it**, outside the web root —
which is exactly where `public/index.php` looks for it (`dirname(__DIR__)`).

```
domains/yourdomain.com/
├── public_html/          ← contents of public/ (index.php, app.html, .htaccess, assets/)
├── src/
├── vendor/               ← run `composer install --no-dev` locally first; vendor is not in git
├── migrations/
├── migrate.php
├── composer.json
├── composer.lock
└── .env                  ← created on the server, never committed
```

## Steps

1. **Database** — hPanel → Databases → MySQL: create a database and a user, note the
   three values (Hostinger prefixes them, e.g. `u123456_ledger`).

2. **PHP** — hPanel → PHP Configuration: select **PHP 8.2** (or newer).

3. **Upload** — on your PC run `composer install --no-dev`, zip the project, upload the
   zip to `domains/yourdomain.com/` with the File Manager and extract it there. Then move
   the **contents** of the extracted `public/` folder into `public_html` (replace the
   default placeholder files) and delete the empty `public/` folder. Do not upload your
   local `.env`.

4. **.env** — File Manager → create `.env` in `domains/yourdomain.com/` (next to `src/`,
   NOT inside public_html):

   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://yourdomain.com

   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=u123456_ledger
   DB_USER=u123456_ledger
   DB_PASS=the-password-you-set

   JWT_SECRET=paste-a-fresh-value-here
   JWT_ISSUER=ledger
   ```

   Generate the secret on your PC: `php -r "echo base64_encode(random_bytes(32));"`.
   `APP_URL` must be your real domain — invite links are built from it.

5. **Tables** — either way works:
   - **SSH** (hPanel → Advanced → SSH access): `cd domains/yourdomain.com && php migrate.php`
   - **No SSH**: hPanel → phpMyAdmin → your database → Import, and run the files in
     `migrations/` one at a time, in filename order (001 → 005).

6. **HTTPS** — hPanel → Security → SSL: install the free certificate, then turn on
   **Force HTTPS**.

7. Open your domain, click **Create one**, and register — that first account becomes the
   owner of its organization. Do **not** run `seed.php` in production (it refuses anyway
   when `APP_ENV` is not `local`).

## If something is off

- Blank page or 500: check `APP_DEBUG=false` stays off, and read the PHP error log in
  hPanel. The usual cause is a wrong path (`vendor/` or `.env` not one level above
  `public_html`) or wrong DB credentials.
- `/signin` works but refreshing `/projects` gives 404: `.htaccess` is missing from
  `public_html`.
- Argon2id check (one-time, via SSH): `php -r "var_dump(defined('PASSWORD_ARGON2ID'));"`
  must print `bool(true)` — Hostinger's PHP 8.2 builds include it.

# Ledger

Multi-tenant project cashbook. Tracks money In and Out per project, PKR only.

PHP 8.2 with no runtime framework, MySQL 8 or MariaDB 10.4+, vanilla ES modules.
No build step.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `json`, `openssl`, and Argon2id support in `password_hash`
- MySQL 8.0+ **or** MariaDB 10.4+ (XAMPP ships MariaDB; both are supported — see
  Notable decisions)
- Composer (autoloading and dev tooling only — nothing framework-like at runtime)

Check Argon2id is available before going further:

```
php -r "var_dump(defined('PASSWORD_ARGON2ID'));"
```

### On XAMPP

XAMPP does not put its binaries on `PATH`. Either add `C:\xampp\php` and
`C:\xampp\mysql\bin` to it, or prefix each command:

```
C:\xampp\php\php.exe migrate.php
C:\xampp\mysql\bin\mysql.exe -u root ledger
```

XAMPP also ships `php_zip.dll` disabled, and Composer needs it to unpack archives.
Either uncomment `extension=zip` in `C:\xampp\php\php.ini` (around line 962), or pass
it per command:

```
C:\xampp\php\php.exe -d extension=zip composer.phar install
```

`composer.phar` lives in the project root rather than being installed globally, and is
git-ignored. Install it with the signature check from getcomposer.org rather than
trusting the download.

## Setup

```
composer install
cp .env.example .env
php -r "echo 'JWT_SECRET=' . base64_encode(random_bytes(32)) . PHP_EOL;"   # paste into .env
```

Create the schema, then run the migrations:

```
mysql -u root -p -e "CREATE DATABASE ledger CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php migrate.php --dry-run     # list what would run
php migrate.php               # apply
php seed.php                  # local dev data only, refuses to run when APP_ENV != local
```

Serve:

```
php -S localhost:8000 -t public public/index.php
```

Open <http://localhost:8000>. The API lives under `/api/v1`.

## Tests

```
composer test      # 208 PHPUnit tests
composer test:js   # money formatting and paisa arithmetic, via node --test
composer lint      # PSR-12
```

`test:js` needs Node 18+; it uses `node:test` from the standard library and adds no
dependency. The PHP suite is the primary one and does not need Node.

See [SECURITY.md](SECURITY.md) for the threat model and the reasoning behind the
security-relevant decisions.

Integration tests need a separate throwaway database, because they truncate every table
between tests. Create it, then create `.env.testing` alongside `.env`:

```
mysql -u root -e "CREATE DATABASE ledger_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
printf 'DB_NAME=ledger_test\nAPP_ENV=testing\n' > .env.testing
```

`.env.testing` is loaded on top of `.env`, so it only needs the overrides. The suite
refuses to start unless `DB_NAME` ends in `_test`, and it builds its schema by running
the real migration files rather than a snapshot.

## Layout

```
public/          front controller + static assets (the only web-exposed directory)
src/
  Auth/          JWT issue/verify, refresh rotation, rate limiting
  Controllers/   thin, no SQL and no business rules
  Domain/        Role, ProjectStatus — the vocabulary, as enums
  Exceptions/    HttpException and ValidationException, rendered in one place
  Http/          router, request, response, middleware
  Repositories/  all SQL, every statement scoped by org_id
  Security/      Action, Membership, Policy — the single answer to "may they?"
  Services/      business rules and transactions
  Support/       env loader, PDO factory, validator, migrator
migrations/      numbered .sql files, append-only
tests/
design/          the design system this app is built to
migrate.php      CLI runner: applies pending migrations in filename order
seed.php         local dev data
```

## Conventions

- `declare(strict_types=1);` on every PHP file, PSR-12, one class per file.
- Money is always `BIGINT` paisa. Never a float, never a string, never rupees.
  Formatting happens only at the presentation layer.
- Every query is scoped by an `org_id` taken from the verified access token, never
  from the request body or path. A resource in another org returns `404`, not `403`.
- Responses are `{ data, meta }` on success and `{ error: { code, message, fields } }`
  on failure. Validation failures are `422` with per-field messages.
- No PHP sessions. Auth is a short-lived JWT access token plus a rotating refresh
  token, so the web client and a future mobile client use the identical path.

## Accounts without email

A brand-new organization comes from public signup: `POST /auth/register` takes a name,
email, password and organization name, creates the account, the organization, the owner
membership and the default categories in one transaction, and signs the caller in. It is
rate limited per IP.

After that there is no mail sending, so an admin provisions the rest of the team by hand:

- **Create member directly** — the server generates a one-time password and returns it
  once, in the create response. It is never stored in plaintext and never shown again.
- **Generate a signup link** — the server returns a URL containing a single-use token.
  The admin copies it and delivers it however they like. Only the token's hash is stored.

Both expire after `INVITE_TTL_HOURS`.

When creating a member the admin may type the password instead of taking the generated
one. An admin can also **reset a member's password** from the Members screen — typed or
generated, shown once. Either way the admin knows it, so the account is flagged
`must_change_password` and every session the member had is revoked. Nobody may reset the
owner's password, matching the rule that nobody manages an owner through member
management.

## Notable decisions

- **The book is append-only.** An entry is never edited and never deleted, by any role.
  A wrong entry is corrected by posting a reconciling entry of the opposite type that
  references it (`POST /entries/{id}/reconcile`); both stay visible in the book. There
  is no `PATCH /entries/{id}` and no `DELETE /entries/{id}`.
- **Projects are soft-deleted.** `deleted_at` hides the project and its entries from
  every query; the rows survive. Financial records are never hard-deleted.
- **JWT is signed with HS256 using `hash_hmac`.** A JWT library would be one more
  dependency for roughly forty lines of code with a fixed algorithm.
- **The design system's inline `style=` attributes are compiled into a class-based
  stylesheet.** The CSP forbids `unsafe-inline`, and inline styles cannot survive that.
  Tokens, values, and layout are carried over unchanged.
- **Refresh tokens are stored as hashes.** On use, the old one is revoked and a new one
  issued; presenting an already-used token revokes the entire family.
- **The running balance is a property of the entry, not of the query.** The window
  function runs inside a CTE over the project's whole book, before any filter or cursor is
  applied. Page 2 therefore shows the same figure page 1 would have shown for the same
  row, and filtering to one category does not renumber history. Ordering is
  `(entry_date, id)` — the id tiebreak is what keeps several entries on one date stable.
- **Entry listing uses cursor pagination, not offsets.** The book is append-only and
  grows constantly; an offset would skip or repeat rows between requests. The cursor
  encodes the sort tuple itself.
- **`Policy::can()` is pure and fails closed.** No I/O, so the whole permission matrix is
  a unit test. Entry permissions require a `ProjectStatus` argument and answer `false`
  without one, so a call site that forgets to pass the project is denied rather than
  quietly allowed into an archived book. `authorize()` adds the audit record and the 403.
- **`Action::EditEntry` and `Action::DeleteEntry` exist and are permanently `false`.**
  There is no endpoint behind them. They are there so that adding one later means
  deleting an explicit `false` — a decision somebody has to make on purpose rather than
  an absence nobody notices.
- **Two exception types, not one per status code.** `HttpException` carries the status,
  error code and any extra headers, with named constructors (`HttpException::notFound()`,
  `::conflict()`, `::tooManyRequests()`) so call sites read as intent rather than as
  numbers. `ValidationException` extends it to carry per-field messages. The front
  controller is the single place either is turned into a response.
- **Services throw `HttpException` directly rather than a parallel domain hierarchy.**
  A second set of exception types mapped one-to-one onto the first would be pure
  translation code in an app this size.
- **There is no `Config` class.** Every environment variable is read in exactly one
  place, so a wrapper over `Env` would be a pass-through with a second name.
- **Deletes answer `204 No Content`, without an envelope.** It is the correct status for
  a request with nothing to return, and every HTTP client already treats it specially.
- **`Validator`'s declared rules are the whitelist.** Any field in the payload that no
  rule mentions is rejected as `Unknown field.`, so there is no second allow-list to
  drift out of sync. Values are only returned by `validate()`, which makes using an
  unvalidated value structurally impossible rather than merely discouraged.
- **PHP and MySQL both run on UTC, pinned together in `Database::connect()`.** Expiry is
  decided in two ways in this codebase — some checks compare against PHP's `time()`, others
  filter with MySQL's `NOW()` — and on a stock XAMPP install those clocks sat three hours
  apart. An invite disappeared from the members list hours before it stopped working.
  Timestamps are rendered in the reader's own zone by the browser; a `DATE` is a calendar
  date and is never shifted.
- **The schema targets both MySQL 8 and MariaDB 10.4.** The only divergence that
  mattered was the collation: `utf8mb4_0900_ai_ci` is MySQL-only, so the schema uses
  `utf8mb4_unicode_ci`, which both accept and which is equally accent- and
  case-insensitive for the email uniqueness check. Everything else the app needs —
  window functions, CTEs, `CHECK` constraints, composite foreign keys, `JSON` columns —
  exists in both.

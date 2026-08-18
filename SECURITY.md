# Security

What this application defends against, how, and what it deliberately does not do.

Ledger holds one thing worth stealing: a record of money moving through construction
projects. The realistic harms are a competitor reading a rival's book, a departing employee
altering history to cover a shortfall, and an attacker with a stolen laptop reaching an
account. The design follows from those three.

---

## Threat model

| Adversary | Wants | Principal defence |
|---|---|---|
| Unauthenticated attacker on the internet | Any access at all | Argon2id passwords, rate-limited login, generic failures, short-lived tokens |
| A member of organization A | To read or alter organization B's book | Tenant scoping in one place, enforced again by composite foreign keys |
| A member of the right organization, wrong role | To spend, edit or delete beyond their remit | A single Policy every endpoint consults; the book is append-only for everyone |
| Someone who has captured a refresh token | A quiet, indefinite session | Rotation on every use, whole-family revocation on reuse |
| An insider covering a mistake or a theft | To make a wrong entry disappear | No update or delete path exists for entries; corrections are new rows; everything is logged |
| A curious member of staff | To learn which organizations or projects exist | 404 rather than 403, with byte-identical bodies |

Explicitly **out of scope for this phase**: file upload, email delivery, payment
processing. None of them exist in the codebase — not as stubs, not as dead configuration —
so none of them carry risk.

---

## Tenant isolation

The property: **a user of organization A cannot read or write anything belonging to
organization B, and cannot tell B's resources apart from ones that were never created.**

Three independent mechanisms, in order of how early they stop an attempt.

**1. The org_id never comes from the client.** The `{org}` in a URL is treated as a claim,
not a fact. One decorator in `src/routes.php` turns it into a `Membership` read from
`organization_members`, or answers 404. Every org-scoped handler takes its `org_id` from
that object. Routes addressed by project id alone (`/projects/{id}/entries`) resolve the
same way through `ProjectRepository::findForUser()`, which joins through the membership
table in a single query.

**2. Every query carries the scope.** Repositories are the only files containing SQL, and
every statement filters on `org_id`. Reviewing this is a matter of reading one directory.

**3. The database refuses the write regardless.** `projects` and `categories` each carry a
`UNIQUE (org_id, id)`, which lets `entries` declare:

```sql
FOREIGN KEY (org_id, project_id) REFERENCES projects (org_id, id)
FOREIGN KEY (org_id, category_id) REFERENCES categories (org_id, id)
```

A repository that forgot its scoping would still be unable to store a row whose `org_id`
disagrees with its project's. This is verified, not assumed — `TenantIsolationTest` inserts
such a row directly and asserts the constraint rejects it.

### 404, never 403

Answering 403 for another tenant's project confirms that project exists. Every
cross-tenant path returns 404 with a body identical to a genuinely unknown id:

```
organizations/2/projects       → "Resource not found."   (real org, not a member)
organizations/99999/projects   → "Resource not found."   (no such org)
organizations/1/projects/6     → "No such project."      (id 6 exists, in another org)
organizations/1/projects/9999  → "No such project."      (no such id)
```

The two gates use different wording, which is safe because the responses are identical
*within* each gate; the distinction only reveals which gate fired, and that is decided by
the URL shape the caller already chose.

---

## Authentication

**Passwords** are hashed with Argon2id at PHP's defaults, rehashed on sign-in when those
defaults move. No composition rules; a 12-character minimum.

**Access tokens** are JWTs signed HS256 with `hash_hmac`. No library: the algorithm is
fixed, and pinning it is what closes the two classic JWT holes.

```php
$valid = ($header['alg'] ?? null) === self::ALGORITHM   // rejects "none" and RS256 confusion
    && ($header['typ'] ?? null) === 'JWT'
    && ($claims['iss'] ?? null) === $this->issuer
    && is_int($claims['exp'] ?? null) && $claims['exp'] > $now;
```

Signature comparison uses `hash_equals`. Lifetime is 15 minutes, and **the user row is
re-read on every request** rather than trusted from the claims, so suspending an account
takes effect immediately rather than at the end of a token's life.

**Refresh tokens** are 256 bits of `random_bytes`, stored as a SHA-256 digest. A fast
digest is correct here and Argon2id would be wrong: there is no low-entropy secret for a
slow hash to protect, and refresh is on the hot path.

One login opens one *family*. Each refresh consumes its token and issues a successor in the
same family. Presenting a token that has already been consumed means it was captured, so
the entire family is revoked — attacker and legitimate holder both return to the sign-in
screen, and the theft becomes visible instead of silent. The row is locked
`FOR UPDATE` so two concurrent refreshes cannot both pass the check, and **the revocation
is committed before the rejection is thrown** — getting that order wrong would roll the
revocation back along with the request. `TokenRotationTest` asserts the victim's unused
token is dead afterwards.

### No account oracle

Sign-in failure is always `401 unauthenticated`, "Authentication failed.", for an unknown
address, a wrong password, and a suspended account alike. When the address is unknown a
password is still verified against a decoy Argon2id digest, so a miss costs the same time
as a wrong password. `JwtTest` asserts that four distinct rejection paths produce exactly
one unique status-and-message pair.

**One deliberate exception.** `POST /auth/register` reports a taken email address plainly.
That is an enumeration vector and it is accepted knowingly: the alternative — accepting the
signup and silently doing nothing — leaves a person staring at a screen that lied to them.
Registration is rate-limited per IP instead. If open signup is ever turned off, this
disappears with it.

---

## Authorisation

`src/Security/Policy.php` is the only file in which a role is compared to anything. There
is no role string comparison in any controller.

`can()` is pure — no I/O, so the whole matrix is a unit test with no database. `authorize()`
adds the audit record and the 403.

Two properties worth stating:

**It fails closed.** Entry permissions require a `ProjectStatus` argument and return
`false` without one. A call site that forgets to pass the project is denied, not quietly
admitted into an archived book.

**The matrix mirrors the design.** `PolicyTest` transcribes the capability table from
`design/Ledger.dc.html` row for row, in the same column order, so the two can be compared
by eye. It was mutation-tested rather than trusted: breaking the append-only rule, dropping
the archived-project check, and removing owner protection were each caught.

`Action::EditEntry` and `Action::DeleteEntry` exist and are permanently `false` with no
endpoint behind them. Adding one later means deleting an explicit `false` — a decision
somebody has to make on purpose rather than an absence nobody notices.

---

## The book is append-only

An entry is never updated and never deleted, by any role, including the owner. There is no
`PATCH /entries/{id}` and no `DELETE /entries/{id}` — not disabled, not permission-gated:
absent.

A wrong entry is corrected by `POST /projects/{p}/entries/{e}/reconcile`, which posts an
entry of the opposite type carrying `reconciles_entry_id`. Both rows stay in the book and
both appear in reports. An entry may be corrected once; a correction cannot exceed the
entry it corrects.

Organizations and projects are soft-deleted. Financial records are never destroyed.

---

## Injection

Every statement is a prepared statement. No value is ever concatenated into SQL.

Three places cannot be parameterised, and each is handled by whitelist:

- **`ORDER BY`** — `ProjectRepository::SORT_COLUMNS` maps a request key to a fixed
  fragment. An unrecognised key falls back to the default.
- **`DATE_FORMAT` interval** — `ReportRepository::INTERVALS` maps a key to a pattern, and
  the pattern is bound as a value.
- **`LIKE`** — the placeholder quotes the value, but `%` and `_` inside it stay live, so
  search terms are escaped with `addcslashes($term, '%_\\')`. Without it, a search for
  `50%` matched every row.

Verified by firing hostile values at every filter:

```
'; DROP TABLE entries; --   → 200, interval used: monthly
%Y-%m'), (SELECT 1          → 200, interval used: monthly
' OR '1'='1  /  %  /  _     → 200, no wildcard leakage
entries table: 277 rows still present
```

### Input validation

`Validator` is a whitelist, and the declared rules *are* the whitelist — a field no rule
mentions is rejected as `Unknown field.` rather than ignored, so there is no second list to
drift. Values are returned only by `validate()`, which makes using an unvalidated value
structurally impossible rather than merely discouraged.

Types are strict: `"185000"` is not an acceptable amount. A client sending money as a
string has a bug worth surfacing.

---

## Output

There is no XSS sink in this application, rather than a helper that guards one.

The frontend contains no `innerHTML`, no `document.write`, and no `eval`. Every string
reaching the page becomes a text node via `h()` in `public/assets/js/dom.js`. A project
named `<img onerror=…>` is text because there is no code path that could treat it as
anything else. `h()` also throws on a `style` prop, so nobody can accidentally introduce an
inline style the CSP would reject.

### Headers

Sent on every response from `public/index.php`:

```
Content-Security-Policy: default-src 'none'; script-src 'self';
  style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com;
  img-src 'self' data:; connect-src 'self'; form-action 'self';
  base-uri 'none'; frame-ancestors 'none'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: no-referrer
Cache-Control: no-store
Strict-Transport-Security: max-age=31536000; includeSubDomains   (HTTPS only)
```

No `unsafe-inline`, anywhere. The design system styles every element inline; those
declarations were compiled into `public/assets/css/ledger.css` as classes. Chart bar widths
are set through `element.style.*`, which is CSSOM and not an inline style — CSP does not
restrict it. Verified: every route was loaded in headless Chrome with CSP violation
reporting on, and none fired.

`Cache-Control: no-store` on every response keeps balances and entry lists out of shared
caches.

---

## Rate limiting

Fixed-window counters in the `rate_limits` table.

| Bucket | Default | Purpose |
|---|---|---|
| `login:ip:{ip}` | 30 / 5 min | One host walking a list of addresses |
| `login:email:{sha256}` | 5 / 5 min | Many hosts grinding one address |
| `register:ip:{ip}` | 5 / 5 min | Bulk organization creation |
| `write:user:{id}` | 120 / 5 min | Runaway client, accidental or otherwise |

Both login buckets are spent on **every** attempt including successful ones; otherwise a
valid credential would be an unlimited oracle. Email buckets are keyed by digest so the
table never holds an address in clear.

The client address is `REMOTE_ADDR` only. `X-Forwarded-For` is deliberately ignored — it is
client-controlled, and honouring it would let anyone reset their own bucket by varying a
header. **Behind a reverse proxy this must be revisited**, or every request will share one
bucket.

`ponytail:` a fixed window lets a caller send up to 2× the limit across a boundary. That is
an acceptable ceiling for slowing credential stuffing; move to a sliding window if real
abuse appears.

---

## Secrets

`JWT_SECRET` and database credentials come from `.env`, which is git-ignored;
`.env.example` carries no values.

`Env` deliberately does **not** call `putenv()` or write `$_ENV`/`$_SERVER`. Those are
inherited by any spawned process and are visible to `phpinfo()` and to some error handlers.
Values stay in a private static array.

`display_errors` is off unconditionally. Unexpected failures are logged and answered with a
generic 500; only `HttpException` messages, which are written to be seen, reach a client.

Nothing secret is stored recoverably:

| Secret | At rest |
|---|---|
| Password | Argon2id |
| One-time password | Argon2id, shown once in the create response, never again |
| Invite token | SHA-256 of 256 random bits |
| Refresh token | SHA-256 of 256 random bits |

---

## Accounts without email

No mail is sent, so an admin provisions accounts by hand. Both paths end with the admin
holding a secret to pass on, and neither can be recovered from the database.

**Direct creation** returns a one-time password once, in the create response. The account
is flagged `must_change_password` and the client forces a change before anything else. The
alphabet excludes `I`, `L`, `O`, `0` and `1` — somebody is going to read it down a phone
line.

**Invite link** returns a URL containing a single-use token. Expired, withdrawn, already
accepted, unknown, and belonging to a deleted organization all produce the same 404: a link
that no longer works should not explain why. `MemberProvisioningTest` asserts the expired
and unknown responses are identical strings.

Accepting with an address that already has an account grants membership without touching
the password. The token is the proof of invitation; membership on an account the bearer
cannot sign into gains them nothing.

Ownership is never handed out by a link — the `invites.role` column has no `owner` value.

---

## Audit trail

`activity_log` records who did what, when, from where, with before/after JSON. It captures
successful changes and also **`auth.login_failed`, `auth.refresh_reuse_detected`,
`auth.refresh_expired` and `permission.denied`**. `org_id` and `user_id` are nullable
because a failed sign-in against an unknown address has neither, and those attempts still
have to be recorded.

The log is readable by every role including Viewer. An audit trail nobody may read is not
an audit trail.

---

## Money

Amounts are `BIGINT` paisa everywhere: schema, queries, API, and JavaScript. No float
touches a monetary value at any layer. The database enforces `CHECK (amount_paisa > 0)` —
direction lives in `type`, so a signed amount and a type cannot disagree.

Formatting happens once, in `public/assets/js/money.js`, and nowhere else.

A JSON number is an exact integer to 2^53, which is Rs 90,071,992,547,409.91. That ceiling
is real but is some hundreds of times Pakistan's GDP; if Ledger ever needs more, amounts
must move to strings on the wire.

---

## Known limitations

Stated rather than buried:

- **Access tokens sit in `localStorage`.** Script-readable, and defensible only because the
  CSP forbids inline script and there is no XSS sink to read them with. An `HttpOnly`
  cookie would be stronger for the web client but cannot serve the mobile client this API
  is also built for. If the mobile client is dropped, revisit this first.
- **Registration reveals a taken email address.** Deliberate; see above.
- **`X-Forwarded-For` is ignored**, so rate limiting is wrong behind a proxy until the
  trusted-proxy question is answered properly.
- **No CSRF tokens.** None are needed: authentication is a bearer token in a header, never
  a cookie, so a cross-site form post carries no credentials. This stops being true the
  moment anything moves to cookies.
- **No brute-force lockout, only rate limiting.** A lockout would let an attacker who knows
  an address deny that person service.
- **Fixed-window rate limiting**, with the boundary burst noted above.
- **Access tokens cannot be revoked before they expire.** The 15-minute lifetime is the
  bound. Revoking a refresh family stops renewal, not the current access token.
- **No dependency scanning in CI.** There are two dev dependencies and no runtime ones, so
  the surface is small, but `composer audit` belongs in a pipeline.

---

## Verifying

```
composer test        # 208 tests: permission matrix, balances, tenant isolation, token rotation
composer test:js     # money formatting and paisa arithmetic
composer lint        # PSR-12
```

The integration suite refuses to run unless `DB_NAME` ends in `_test`, and builds its
schema from the real migration files rather than a snapshot that could drift.

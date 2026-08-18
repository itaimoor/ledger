# Form Uniformity + Admin Password Management — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every form control uniform per the design system, and let owner/admin set or reset member passwords with full policy, session-revocation, and audit coverage.

**Architecture:** Frontend gets one new composite component (`passwordInput` in `ui.js`) styled by a new `.password-field` class that mirrors `.input` exactly; `field()` learns to target a composite's real input. Backend adds one Action (`ResetMemberPassword`), one repository method (`revokeAllForUser`), one service method (`MemberService::resetPassword`), one controller method, one route — no schema change, no migration (`must_change_password` and `refresh_tokens` already exist).

**Tech Stack:** PHP 8.2 (no framework), MariaDB via XAMPP, vanilla ES modules, PHPUnit, PHPCS (PSR-12).

**Spec:** The task brief in conversation (2026-08-18): Task 1 = form styling, Task 2 = admin password management. Design source of truth: `design/Ledger.dc.html` (sign-in mock lines 204–245, disabled currency line 259); compiled tokens: `public/assets/css/ledger.css`.

## Global Constraints

- `declare(strict_types=1);`, PSR-12, one class per file.
- Layering: routing → controller → service → repository. **No SQL outside `src/Repositories/`.**
- Every query scoped by `org_id` from the verified session (`Membership`), never from the request.
- No role-string comparison outside `src/Security/Policy.php`.
- No `innerHTML`; elements via `h()` in `dom.js`. No inline styles (CSP forbids `unsafe-inline`); CSS only in `public/assets/css/ledger.css`.
- Minimum password length: `AuthService::MINIMUM_PASSWORD_LENGTH` (12).
- Migrations are append-only — this plan needs **none**.
- Not a git repository: no commit steps. Every task ends with the full verification pair:
  - `C:\xampp\php\php.exe vendor\bin\phpunit`
  - `C:\xampp\php\php.exe vendor\bin\phpcs --standard=PSR12 src tests public/index.php migrate.php seed.php`
- Baseline before starting: 195 PHPUnit tests green.
- **Stop after each task for user review.**

## Decisions locked in (flag to user at plan review)

1. **Admin-typed passwords also set `must_change_password = 1`** — at creation *and* at reset. The admin knows the password either way; the account holder must replace it with one only they know. Same rationale as the generated path.
2. **Typing a password for someone who already has an account is a 422**, not a silent ignore: "use Reset password instead." Silently keeping their old password would lie to the admin.
3. **`Action::ResetMemberPassword` shares the `ManageMembers` policy arm** (`$manages && $subject !== Role::Owner`) but is its own case, so the audit log names the real action and the capability table stays one-row-per-permission.
4. **Self-reset is not offered in the UI** (the row already hides Remove for self; Reset hides the same way). The API does not special-case it — the policy row decides.
5. The reset response is `200 OK` with `{ one_time_password: string|null }` — null when the admin typed one.

---

### Task 1: Password field component (`.password-field`) + `field()` targeting fix

**Files:**
- Modify: `public/assets/css/ledger.css` (forms section, after the `.field-row` rule ~line 396)
- Modify: `public/assets/js/ui.js` (`field()` ~line 42; new `passwordInput` export)
- Modify: `public/assets/js/views/auth.js` (delete local `passwordInput` lines 22–36, import from ui.js)

**Interfaces:**
- Produces: `passwordInput(props)` exported from `ui.js` → returns a `div.password-field` wrapper with `.input` property pointing at the real `<input type="password">`. Callers read `x.input.value`. Task 6 consumes this in `admin.js`.
- Produces: `field(label, control, {hint, error, id})` now applies `id`/`aria-invalid` to `control.input ?? control`.

- [ ] **Step 1: Add the CSS.** In `ledger.css`, replace the existing disabled rule (line 394) and add the composite rules after `.field-row`:

```css
.input:disabled, .select:disabled {
  background: var(--surface-3);
  color: var(--ink-500);
  border-color: var(--line);
  cursor: not-allowed;
}
```

```css
/*
 * The password field is a composite — input plus Show/Hide — but must be visually
 * indistinguishable from .input: same height, padding, border, radius, font and focus
 * ring. The toggle is the design's plain-text affordance, not a bordered button.
 */
.password-field {
  display: flex; align-items: center; gap: var(--s-3);
  width: 100%; min-height: var(--row-h);
  padding: 0 var(--s-4);
  border: 1px solid var(--line-strong); border-radius: var(--r-3);
  background: var(--surface);
}
.password-field input {
  flex: 1; min-width: 0; align-self: stretch;
  border: 0; outline: 0; padding: 0; background: none;
  font: 400 14px/1 var(--sans); color: var(--ink-900);
}
.password-field:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
.password-field:has(input[aria-invalid="true"]) { border-color: var(--out); }
.password-toggle {
  border: 0; background: none; padding: 0; cursor: pointer;
  font: 500 12px/1 var(--sans); color: var(--ink-500);
  white-space: nowrap;
}
.password-toggle:hover { color: var(--ink-900); }
```

(Design refs: toggle = `font:500 12px ink-500` inside the field, Ledger.dc.html line 213; disabled currency = `border var(--line)`, `surface-3`, `ink-500`, line 259; focus ring = the compiled `.input:focus` treatment.)

- [ ] **Step 2: Move `passwordInput` into `ui.js`** (it is about to be used on two screens) and fix `field()`:

```js
export function field(label, control, { hint, error, id } = {}) {
  // A composite control (the password field) exposes its real input; id and validity
  // belong on that, not on the wrapper.
  const target = control.input ?? control;
  if (id) target.id = id;
  if (error) target.setAttribute('aria-invalid', 'true');

  return h('label', { class: 'field' },
    span({ class: 'field-label', text: label }),
    control,
    hint && !error ? span({ class: 'field-hint', text: hint }) : null,
    error ? span({ class: 'field-error', text: error }) : null,
  );
}

/** A password box with a Show/Hide toggle inside it, sized to match .input exactly. */
export function passwordInput(props = {}) {
  const input = h('input', { type: 'password', autocomplete: 'current-password', ...props });
  const toggle = button({ class: 'password-toggle', text: 'Show' });

  toggle.addEventListener('click', () => {
    const revealed = input.type === 'text';
    input.type = revealed ? 'password' : 'text';
    toggle.textContent = revealed ? 'Show' : 'Hide';
  });

  const wrap = div({ class: 'password-field' }, input, toggle);
  wrap.input = input;
  return wrap;
}
```

Note: the inner input deliberately has **no** `.input` class (that would double the border). `button()` from dom.js already defaults `type="button"`, so the toggle cannot submit the form.

- [ ] **Step 3: Switch `auth.js` to the shared component.** Delete its local `passwordInput` (lines 22–36) and add `passwordInput` to the `ui.js` import list. No call sites change — all four screens already use `passwordInput(...)` and read `.input.value`.

- [ ] **Step 4: Verify in the browser.** Serve (`C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public public/index.php`), then check `/signin`, `/signup`, `/join/<any>` (invalid-invite state is fine; for the form state create an invite as faisal), and `/password` (sign in as a `must_change_password` account, or temporarily flag one). Password field must be pixel-identical in height/border/focus to the email field beside it; Show/Hide toggles without resizing.

- [ ] **Step 5: Run the suites** (both commands from Global Constraints). Expected: 195 tests green, PHPCS clean (no PHP touched, but run anyway).

**STOP for user review.**

---

### Task 2: Form audit — disabled fields, org-settings button row, 390 px pass

Audit result (every form was read): sign in / sign up / accept invite / change password are fixed by Task 1. New project, add member, invite link, project settings, categories, add entry, reconcile already use `.input`/`.select`/`.textarea` at 44 px with consistent `field()` labels, sheet footers (`Cancel · gap · primary`) and `dialog-actions` rows — no changes. Toolbar controls (36 px in `.filters`/`.page-actions`, 32 px `.search`) are the design's own toolbar sizing, not drift. Two real defects remain:

**Files:**
- Modify: `public/assets/js/app.js` (`orgSettingsView`, ~line 186)

**Interfaces:** none new.

- [ ] **Step 1: Org settings button row.** The Save button sits bare inside `.form-grid`, so it stretches full-width — unlike project settings, where actions sit in a `.page-actions` row. Wrap it (the non-owner `notice` stays as-is):

```js
            role === 'owner'
              ? div({ class: 'page-actions' },
                button({
                  class: 'btn btn-primary',
                  text: 'Save changes',
                  onClick: async () => {
                    try {
                      await api.patch(`/organizations/${orgId}`, { name: name.value.trim() });
                      forgetOrganizations();
                      toast('Saved', 'ok');
                      render();
                    } catch (error) {
                      reportError(error);
                    }
                  },
                }),
              )
              : notice('Only the owner can rename the organization.', 'info'),
```

- [ ] **Step 2: Confirm disabled styling landed.** The Task 1 CSS already fixed the two "Currency — PKR" fields (signup + org settings) and the non-owner org-name field: `surface-3` fill, `ink-500` text, soft `--line` border, `not-allowed` cursor. Check both screens visually.

- [ ] **Step 3: 390 px pass.** With the server running, load sign in, sign up, accept invite, change password, members, and add-entry sheet at 390×844 (headless Chrome: `& "C:\Program Files\Google\Chrome\Application\chrome.exe" --headless --disable-gpu --window-size=390,844 --screenshot=<scratchpad>\signin-390.png http://127.0.0.1:8000/signin` — adjust the Chrome path if needed, or DevTools device mode). Verify: password field same height as email; `.field-row` collapses to one column; buttons ≥44 px; nothing overflows horizontally.

- [ ] **Step 4: Run the suites.** Expected: 195 green, PHPCS clean.

**STOP for user review.**

---

### Task 3: `Action::ResetMemberPassword` in Policy (TDD)

**Files:**
- Modify: `tests/Unit/PolicyTest.php`
- Modify: `src/Security/Action.php`
- Modify: `src/Security/Policy.php`

**Interfaces:**
- Produces: `Action::ResetMemberPassword` (value `member.password_reset`); `Policy::can($m, Action::ResetMemberPassword, Role $targetRole)` → true only for Owner/Admin when `$targetRole !== Role::Owner`. Task 4 consumes this.

- [ ] **Step 1: Write the failing tests.** In `PolicyTest::capabilityTable()`, after the 'Invite members and change roles' row:

```php
        yield "Reset a member's password" =>
            [Action::ResetMemberPassword, Role::Accountant, [true, true, false, false]];
```

And extend the owner-protection test:

```php
    #[DataProvider('everyRole')]
    public function testNobodyMayManageTheOwnerThroughMemberManagement(Role $role): void
    {
        self::assertFalse($this->policy()->can($this->member($role), Action::ManageMembers, Role::Owner));
        self::assertFalse($this->policy()->can($this->member($role), Action::ResetMemberPassword, Role::Owner));
    }
```

- [ ] **Step 2: Run to verify failure.** `C:\xampp\php\php.exe vendor\bin\phpunit --filter PolicyTest` — Expected: FAIL (`Error: Undefined constant Ledger\Security\Action::ResetMemberPassword`).

- [ ] **Step 3: Implement.** In `Action.php`, after `ManageMembers`:

```php
    case ResetMemberPassword = 'member.password_reset';
```

In `Policy::can()`, extend the member-management arm:

```php
            // Nobody edits or removes the owner through member management, and nobody —
            // the owner included — resets the owner's password from here.
            Action::ManageMembers,
            Action::ResetMemberPassword => $manages && $subject !== Role::Owner,
```

- [ ] **Step 4: Run to verify pass.** `C:\xampp\php\php.exe vendor\bin\phpunit --filter PolicyTest` — Expected: PASS. (`testEveryActionIsDecidedForEveryRole` picks the new case up automatically; an unmatched case would be an `UnhandledMatchError`.)

- [ ] **Step 5: Run the full suites.** Expected: all green, PHPCS clean.

**STOP for user review.**

---

### Task 4: `POST /organizations/{org}/members/{user}/password` (TDD)

**Files:**
- Modify: `tests/Integration/MemberProvisioningTest.php` (setUp + new reset section)
- Modify: `src/Repositories/RefreshTokenRepository.php`
- Modify: `src/Services/MemberService.php` (constructor + `resetPassword()`)
- Modify: `src/Controllers/MemberController.php`
- Modify: `src/routes.php` (wiring + route)

**Interfaces:**
- Consumes: `Action::ResetMemberPassword` (Task 3), `MemberService::generateOneTimePassword()`, `UserRepository::updatePasswordHash(int $id, string $hash, bool $mustChange)`, `Policy::authorize()`.
- Produces: `RefreshTokenRepository::revokeAllForUser(int $userId): int`; `MemberService::resetPassword(Membership $membership, int $targetUserId, ?string $password, string $ip): array{one_time_password: ?string}`; `MemberController::resetPassword(Request, Membership, array $params): Response`.
- Constructor change: `MemberService` gains `RefreshTokenRepository $refreshTokens` as its 4th parameter (after `$invites`, before `$activity`). Update `src/routes.php` (pass the existing `$refreshTokens`) and the test setUp.

- [ ] **Step 1: Update the test setUp** to the new constructor (add `use Ledger\Repositories\RefreshTokenRepository;`):

```php
        $this->members = new MemberService(
            $this->memberships,
            $this->users,
            new InviteRepository($pdo),
            new RefreshTokenRepository($pdo),
            $activity,
            new Policy($activity),
            $pdo,
            'https://ledger.example.pk',
            72,
        );
```

- [ ] **Step 2: Write the failing tests.** New section at the end of `MemberProvisioningTest` (before the private helpers):

```php
    /* ------------------------------------------------------------- reset path */

    public function testResettingGeneratesAPasswordThatWorksAndForcesAChange(): void
    {
        $targetId = (int) $this->addUsman()['user']['id'];

        $result = $this->members->resetPassword($this->admin, $targetId, null, '127.0.0.1');

        $password = (string) $result['one_time_password'];
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $password);

        $user = $this->users->findByEmail(self::PROVISIONED);
        self::assertTrue(password_verify($password, (string) $user['password_hash']));
        self::assertSame(1, (int) $user['must_change_password']);
    }

    public function testResettingWithATypedPasswordStoresItAndReturnsNothing(): void
    {
        $targetId = (int) $this->addUsman()['user']['id'];

        $result = $this->members->resetPassword($this->admin, $targetId, 'dictated-by-admin', '127.0.0.1');

        self::assertNull($result['one_time_password'], 'The admin typed it, so there is nothing to reveal.');

        $user = $this->users->findByEmail(self::PROVISIONED);
        self::assertTrue(password_verify('dictated-by-admin', (string) $user['password_hash']));
        self::assertSame(1, (int) $user['must_change_password'], 'The admin knows it, so it is still temporary.');
    }

    public function testResettingRevokesEveryRefreshTokenTheMemberHeld(): void
    {
        $targetId = (int) $this->addUsman()['user']['id'];

        self::pdo()
            ->prepare(
                'INSERT INTO refresh_tokens (user_id, family_id, token_hash, expires_at)
                 VALUES (?, ?, ?, NOW() + INTERVAL 30 DAY)'
            )
            ->execute([$targetId, bin2hex(random_bytes(16)), hash('sha256', 'live-session')]);

        $this->members->resetPassword($this->admin, $targetId, null, '127.0.0.1');

        $alive = self::pdo()->prepare(
            'SELECT COUNT(*) FROM refresh_tokens WHERE user_id = ? AND revoked_at IS NULL'
        );
        $alive->execute([$targetId]);

        self::assertSame(0, (int) $alive->fetchColumn(), 'A reset that leaves sessions alive is not a reset.');
    }

    public function testNobodyResetsTheOwnersPassword(): void
    {
        $ownerId = $this->makeUser('owner@rehmanbuilders.pk');
        self::pdo()
            ->prepare("INSERT INTO organization_members (org_id, user_id, role) VALUES (?, ?, 'owner')")
            ->execute([$this->admin->orgId, $ownerId]);

        try {
            $this->members->resetPassword($this->admin, $ownerId, null, '127.0.0.1');
            self::fail('Expected a 403.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
    }

    public function testAnAccountantCannotResetAnybody(): void
    {
        $targetId = (int) $this->addUsman()['user']['id'];
        $accountant = new Membership($this->admin->orgId, $this->admin->userId, Role::Accountant);

        try {
            $this->members->resetPassword($accountant, $targetId, null, '127.0.0.1');
            self::fail('Expected a 403.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
    }

    public function testResettingSomeoneOutsideTheOrganizationIsNotFound(): void
    {
        $strangerId = $this->makeUser('stranger@elsewhere.pk');

        try {
            $this->members->resetPassword($this->admin, $strangerId, null, '127.0.0.1');
            self::fail('Expected a 404.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
        }
    }

    public function testTheResetIsLogged(): void
    {
        $targetId = (int) $this->addUsman()['user']['id'];

        $this->members->resetPassword($this->admin, $targetId, null, '127.0.0.1');

        $logged = self::pdo()->query(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'member.password_reset'"
        );
        self::assertSame(1, (int) $logged->fetchColumn());
    }
```

- [ ] **Step 3: Run to verify failure.** `C:\xampp\php\php.exe vendor\bin\phpunit --filter MemberProvisioningTest` — Expected: FAIL (constructor arity, then undefined `resetPassword`).

- [ ] **Step 4: Implement the repository method** in `RefreshTokenRepository.php`:

```php
    /** @return int rows revoked */
    public function revokeAllForUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL'
        );
        $statement->execute([$userId]);

        return $statement->rowCount();
    }
```

- [ ] **Step 5: Implement the service.** In `MemberService`: add `private readonly RefreshTokenRepository $refreshTokens,` as the 4th constructor parameter (import `Ledger\Repositories\RefreshTokenRepository`), and add after `remove()`:

```php
    /**
     * An admin replaces a member's password — with one they typed, or with a generated
     * one-time password returned exactly once. Either way the holder must choose their own
     * on next sign-in, and every session they had is revoked: a reset that left the old
     * sessions alive would not be a reset. (An access token already issued survives its
     * remaining lifetime, at most 15 minutes — the documented bound.)
     *
     * @return array{one_time_password: ?string}
     */
    public function resetPassword(Membership $membership, int $targetUserId, ?string $password, string $ip): array
    {
        $target = $this->requireMember($membership, $targetUserId);

        $this->policy->authorize($membership, Action::ResetMemberPassword, $target->role, ip: $ip);

        $oneTimePassword = $password === null ? self::generateOneTimePassword() : null;

        $this->pdo->beginTransaction();

        try {
            $this->users->updatePasswordHash(
                $targetUserId,
                password_hash($password ?? (string) $oneTimePassword, PASSWORD_ARGON2ID),
                mustChange: true,
            );

            $revoked = $this->refreshTokens->revokeAllForUser($targetUserId);

            $this->activity->record(
                $membership->orgId,
                $membership->userId,
                'member.password_reset',
                'user',
                $targetUserId,
                after: ['generated' => $password === null, 'sessions_revoked' => $revoked],
                ip: $ip,
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return ['one_time_password' => $oneTimePassword];
    }
```

(`requireMember` answers 404 for a non-member before any permission check, so the endpoint reveals nothing about accounts outside the org — same shape as `changeRole`/`remove`.)

- [ ] **Step 6: Implement the controller method** in `MemberController` (after `destroy`; `AuthService` is already imported):

```php
    /**
     * An admin replaces a member's password. The response carries the generated one-time
     * password exactly once, or null when the admin typed one.
     *
     * @param array<string, string> $params
     */
    public function resetPassword(Request $request, Membership $membership, array $params): Response
    {
        $input = (new Validator($request->body()))
            ->string('password', min: AuthService::MINIMUM_PASSWORD_LENGTH, max: 200, required: false)
            ->validate();

        return Response::ok($this->members->resetPassword(
            $membership,
            (int) $params['user'],
            $input['password'] ?? null,
            $request->ip,
        ));
    }
```

- [ ] **Step 7: Wire it up in `routes.php`.** Pass `$refreshTokens` (already constructed, line 67) to `MemberService` as the 4th argument, and add after the members `DELETE` route:

```php
$router->post(
    '/api/v1/organizations/{org}/members/{user}/password',
    $inOrgWriting($members->resetPassword(...)),
);
```

- [ ] **Step 8: Run to verify pass.** `C:\xampp\php\php.exe vendor\bin\phpunit --filter MemberProvisioningTest` — Expected: PASS.

- [ ] **Step 9: Run the full suites.** Expected: all green, PHPCS clean.

**STOP for user review.**

---

### Task 5: Optional admin-typed password at member creation (TDD)

**Files:**
- Modify: `tests/Integration/MemberProvisioningTest.php`
- Modify: `src/Services/MemberService.php` (`create()`)
- Modify: `src/Controllers/MemberController.php` (`store()`)

**Interfaces:**
- Produces: `MemberService::create(Membership $membership, string $name, string $email, string $role, string $ip, ?string $password = null): array` — response gains `'account_created' => bool` (Task 6 consumes it). Existing positional callers are untouched by the trailing optional parameter.

- [ ] **Step 1: Write the failing tests** (in the one-time-password section):

```php
    public function testCreatingWithAnAdminTypedPasswordStoresItAndReturnsNoOneTimePassword(): void
    {
        $result = $this->members->create(
            $this->admin,
            'Usman Khan',
            self::PROVISIONED,
            'viewer',
            '127.0.0.1',
            'dictated-by-admin',
        );

        self::assertNull($result['one_time_password']);
        self::assertTrue($result['account_created']);

        $user = $this->users->findByEmail(self::PROVISIONED);
        self::assertTrue(password_verify('dictated-by-admin', (string) $user['password_hash']));
        self::assertSame(1, (int) $user['must_change_password'], 'The admin knows it, so it is still temporary.');
    }

    public function testATypedPasswordForAnExistingAccountIsRejected(): void
    {
        $this->users->create('Zara Khan', 'zara@rehmanbuilders.pk', 'her-own-password');

        $this->expectException(ValidationException::class);

        $this->members->create(
            $this->admin,
            'Zara Khan',
            'zara@rehmanbuilders.pk',
            'viewer',
            '127.0.0.1',
            'attempted-override',
        );
    }
```

- [ ] **Step 2: Run to verify failure.** `--filter MemberProvisioningTest` — Expected: FAIL (`create()` takes 5 arguments / missing `account_created` key).

- [ ] **Step 3: Implement in `MemberService::create()`.** Signature gains `?string $password = null` (trailing). After the existing conflict check:

```php
        if ($existing !== null && $password !== null) {
            throw new ValidationException(
                ['password' => 'They already have an account and keep their password. Use Reset password instead.'],
            );
        }

        $oneTimePassword = $existing === null && $password === null ? self::generateOneTimePassword() : null;
```

In the transaction, the insert becomes:

```php
            $userId = $existing !== null
                ? (int) $existing['id']
                : $this->users->createWithTemporaryPassword($name, $email, $password ?? (string) $oneTimePassword);
```

Extend the activity `after:` payload with `'password_set_by_admin' => $password !== null,` and the return with:

```php
        return [
            'user' => ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => $role],
            'account_created' => $existing === null,
            // Null when the person already had an account, or when the admin typed one.
            'one_time_password' => $oneTimePassword,
        ];
```

- [ ] **Step 4: Update `MemberController::store()`:**

```php
        $input = (new Validator($request->body()))
            ->string('name', max: 120, min: 2)
            ->email('email')
            ->enum('role', $this->invitableRoles())
            ->string('password', min: AuthService::MINIMUM_PASSWORD_LENGTH, max: 200, required: false)
            ->validate();

        return Response::created($this->members->create(
            $membership,
            $input['name'],
            $input['email'],
            $input['role'],
            $request->ip,
            $input['password'] ?? null,
        ));
```

- [ ] **Step 5: Run to verify pass**, then the full suites. Expected: all green, PHPCS clean.

**STOP for user review.**

---

### Task 6: Members screen UI — password at creation, Reset password action

**Files:**
- Modify: `public/assets/js/views/admin.js`
- Modify: `public/assets/js/views/activity.js` (one ACTIONS entry)

**Interfaces:**
- Consumes: `passwordInput` from `ui.js` (Task 1); `POST .../members` with optional `password` + `account_created` in the response (Task 5); `POST .../members/{user}/password` → `{ one_time_password }` (Task 4).

- [ ] **Step 1: `addMemberSheet` gains the optional password.** Import `passwordInput` from `../ui.js`. Add the control and field:

```js
  const password = passwordInput({ autocomplete: 'new-password' });
```

Body becomes `[errors, result, field('Name', name), field('Email', email), field('Role', role), field('Password', password, { hint: 'Optional. Leave blank to generate a one-time password. At least 12 characters either way — they replace it on first sign-in.' })]`.

In `submit()`, the payload gains the field only when typed, and the result handles the three outcomes:

```js
      const { data } = await api.post(`/organizations/${orgId}/members`, {
        name: name.value.trim(),
        email: email.value.trim(),
        role: role.value,
        ...(password.input.value ? { password: password.input.value } : {}),
      });

      mount(result,
        data.one_time_password
          ? div({ class: 'form-grid' },
            notice('Copy this now. It is not stored and cannot be shown again.', 'warn'),
            div({ class: 'password-reveal' }, h('code', { text: data.one_time_password })),
            copyField(data.one_time_password),
          )
          : data.account_created
            ? notice('Account created with the password you set. They will be asked to replace it when they first sign in.', 'in')
            : notice(`${data.user.email} already had an account and has been added to this organization.`, 'info'),
      );
```

- [ ] **Step 2: Reset action on the member row.** In `memberRow`, the actions cell becomes (append flattens the array):

```js
      h('td', { class: 'row-actions' },
        locked || isSelf
          ? span({ class: 'muted', text: '—' })
          : [
            button({ class: 'btn btn-sm', text: 'Reset password', onClick: () => resetPasswordSheet(orgId, member) }),
            button({ class: 'btn btn-sm', text: 'Remove', onClick: () => removeMember(member) }),
          ],
      ),
```

(`locked` already means the owner row; `isSelf` keeps self-service on `/password`. Both match the existing Remove rules.)

- [ ] **Step 3: The reset sheet** (module-level, beside `addMemberSheet`/`inviteSheet`):

```js
function resetPasswordSheet(orgId, member) {
  const password = passwordInput({ autocomplete: 'new-password' });
  const errors = div();
  const result = div();

  sheet({
    title: `Reset password — ${member.name}`,
    subtitle: 'Signs them out everywhere. They choose their own on next sign-in.',
    body: [
      errors,
      result,
      field('New password', password, {
        hint: 'Optional. Leave blank to generate a one-time password shown once. At least 12 characters.',
      }),
    ],
    footer: (dismiss) => [
      button({ class: 'btn', text: 'Close', onClick: dismiss }),
      div({ class: 'sheet-foot-gap' }),
      button({ class: 'btn btn-primary', text: 'Reset password', onClick: submit }),
    ],
  });

  async function submit() {
    clear(errors);

    try {
      const { data } = await api.post(
        `/organizations/${orgId}/members/${member.id}/password`,
        password.input.value ? { password: password.input.value } : {},
      );

      mount(result, data.one_time_password
        ? div({ class: 'form-grid' },
          notice('Copy this now. It is not stored and cannot be shown again.', 'warn'),
          div({ class: 'password-reveal' }, h('code', { text: data.one_time_password })),
          copyField(data.one_time_password),
        )
        : notice(`Password set. ${member.name} has been signed out everywhere and will choose their own on next sign-in.`, 'in'));
    } catch (error) {
      const fields = reportError(error);
      mount(errors, ...Object.values(fields).map((message) => notice(message)));
    }
  }
}
```

- [ ] **Step 4: Activity label.** In `activity.js` ACTIONS, after `member.role_changed`:

```js
  'member.password_reset': { tag: 'Member', tone: 'chip-warn', text: "reset a member's password" },
```

(`FILTERABLE = Object.keys(ACTIONS)` picks it up automatically.)

- [ ] **Step 5: Verify end-to-end in the browser.** As faisal (admin): create a member with a typed short password (expect the 422 under the field), with a valid typed password (expect the "account created" notice, no reveal), and with a blank password (expect the one-time reveal). Reset bilal's password both ways; confirm bilal's session dies (refresh fails → sign-in) and signing in with the new password forces `/password`. Confirm the owner row and your own row offer no Reset. Check the Activity screen shows "reset a member's password". Check the sheet at 390 px.

- [ ] **Step 6: Run the full suites one last time.** Expected: all green (195 + ~11 new), PHPCS clean.

**STOP for user review.**

---

## Self-review

- **Spec coverage:** Task 1.1 → plan Task 1; 1.2 → Task 2 (audit documented, two real fixes); 1.3 (`field()` id) → Task 1 Step 2; 1.4 (disabled) → Task 1 Step 1 + Task 2 Step 2; 1.5 (390 px) → Task 2 Step 3 and Task 6 Step 5. Task 2.1 → plan Task 5; 2.2 → Tasks 4+6; 2.3 (endpoint) → Task 4; 2.4 (rules: roles/owner/revocation/audit/min-length) → Tasks 3+4; 2.5 (Policy + PolicyTest) → Task 3. No migration needed — verified `must_change_password` and `refresh_tokens.revoked_at` exist.
- **Placeholders:** none; every step carries its code.
- **Type consistency:** `resetPassword` name and signature identical in service/controller/route/tests; `account_created` produced in Task 5 and consumed in Task 6; `passwordInput` produced in Task 1 and consumed in Task 6; `RefreshTokenRepository` is `MemberService`'s 4th constructor arg in service, routes.php, and test setUp alike.

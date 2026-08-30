# Hostinger Private Configuration Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Load production PMS secrets and the private SQLite path from a server-only Hostinger file outside `public_html`, without overwriting process environment values or shipping production data.

**Architecture:** Add a small dependency-free PHP environment-file loader that runs before the existing constants in `channel-manager/config.php`. It derives the default file from the directory above `public_html`, supports an explicit `KFS_ENV_FILE` override, accepts only `KFS_*` keys, and leaves existing process environment variables authoritative. Deployment documentation and packaging checks will make the private file and database layout explicit.

**Tech Stack:** PHP 8.2+, built-in INI parsing, the existing custom PHP test runner, Apache/Hostinger shared hosting, SQLite, Git/GitHub.

---

### Task 1: Specify private environment loading

**Files:**
- Modify: `tests/run.php`
- Test: `tests/run.php`

**Step 1: Write the failing tests**

Add tests after `config.php` is loaded that:

```php
test('private environment loader reads only KFS keys and quoted values', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'kfs-env-');
    file_put_contents($path, "KFS_TEST_FILE=loaded\nKFS_TEST_QUOTED=\"hello world\"\nOTHER_SECRET=ignored\n");
    putenv('KFS_TEST_FILE');
    putenv('KFS_TEST_QUOTED');
    putenv('OTHER_SECRET');
    assertSame(2, loadKfsEnvFile($path));
    assertSame('loaded', getenv('KFS_TEST_FILE'));
    assertSame('hello world', getenv('KFS_TEST_QUOTED'));
    assertFalse(getenv('OTHER_SECRET') !== false);
    unlink($path);
});

test('private environment loader preserves process values and handles missing files', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'kfs-env-');
    file_put_contents($path, "KFS_TEST_EXISTING=file-value\n");
    putenv('KFS_TEST_EXISTING=process-value');
    assertSame(0, loadKfsEnvFile($path));
    assertSame('process-value', getenv('KFS_TEST_EXISTING'));
    assertSame(0, loadKfsEnvFile($path . '.missing'));
    unlink($path);
});
```

Add a resolver test that sets and clears `KFS_ENV_FILE`, proving the override wins and the fallback is the domain-root `kfs.env` path.

**Step 2: Run the tests to verify they fail**

Run: `php tests/run.php`

Expected: FAIL with `loadKfsEnvFile is not implemented` or an undefined-function error attributable to the missing loader.

**Step 3: Commit the failing tests**

```bash
git add tests/run.php
git commit -m "test: specify private Hostinger config loading"
```

### Task 2: Implement the minimal environment loader

**Files:**
- Create: `channel-manager/env-loader.php`
- Modify: `channel-manager/config.php:4-5`
- Test: `tests/run.php`

**Step 1: Implement the loader**

Create dependency-free functions with these contracts:

```php
function kfsEnvFilePath(string $channelManagerDir = __DIR__): string
{
    $override = getenv('KFS_ENV_FILE');
    if ($override !== false && trim((string)$override) !== '') return trim((string)$override);
    return dirname($channelManagerDir, 2) . '/kfs.env';
}

function loadKfsEnvFile(string $path): int
{
    // Return zero unless $path is a readable regular file.
    // Parse with INI_SCANNER_RAW, accept only /^KFS_[A-Z0-9_]+$/ keys,
    // keep existing getenv() values, and putenv() accepted scalar values.
    // Return the number of assignments loaded and never print file contents.
}
```

At the top of `channel-manager/config.php`, before `kfsEnv()` is used:

```php
require_once __DIR__ . '/env-loader.php';
loadKfsEnvFile(kfsEnvFilePath(__DIR__));
```

**Step 2: Run the focused test suite**

Run: `php tests/run.php`

Expected: all PHP tests PASS, including the new loader tests.

**Step 3: Run syntax checks**

Run: `find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l`

Expected: every file reports `No syntax errors detected`.

**Step 4: Commit the implementation**

```bash
git add channel-manager/env-loader.php channel-manager/config.php
git commit -m "feat: load private Hostinger configuration"
```

### Task 3: Document and verify the production layout

**Files:**
- Modify: `.env.example`
- Modify: `DEPLOYMENT.md`
- Modify: `README.md`

**Step 1: Document the server-only file**

Document `/home/<user>/domains/kanchifarmstay.com/kfs.env`, `/home/<user>/domains/kanchifarmstay.com/private/calendar.db`, owner-only permissions, environment precedence, and the rule that neither file belongs in `public_html` or Git.

**Step 2: Run secret and packaging checks**

Run:

```bash
git grep -nE 'rzp_live_|KanchiFarm2025|ksf-ical-secret-2025|kanchi-cron-2025' -- ':!docs/plans/*'
git ls-files | grep -E '(^|/)(calendar\.db|kfs\.env|\.env)$'
```

Expected: both commands return no matches.

**Step 3: Commit documentation**

```bash
git add .env.example DEPLOYMENT.md README.md
git commit -m "docs: add Hostinger private runtime layout"
```

### Task 4: Verify and package the release

**Files:**
- Create locally and do not commit: `PMS-hostinger-8bdd580-or-newer.zip`

**Step 1: Run the full verification suite**

Run the PHP suite, JavaScript date tests, PHP lint, HTML/JS checks documented in `README.md`, and the existing browser smoke tests.

Expected: all tests and syntax checks pass with no production secrets in tracked files.

**Step 2: Build the release archive**

Use `git archive` from the verified commit, then exclude `.github`, `tests`, `docs`, `.env.example`, `README.md`, `DEPLOYMENT.md`, `serve.ps1`, AppleDouble files, and any runtime database/log/lock files.

**Step 3: Inspect the archive**

Run: `unzip -l <archive>` plus explicit negative checks for `.git`, `.github`, `tests`, `docs`, `.env`, `calendar.db`, logs, locks, and secrets.

Expected: only deployable application files are present.

**Step 4: Commit any final packaging metadata changes, then push**

```bash
git push origin codex/ical-hardening
```

Expected: the remote branch and PR contain the private-config commits and pass CI.

### Task 5: Prepare Hostinger production without changing public files

**Files on Hostinger:**
- Create: `/home/<user>/domains/kanchifarmstay.com/kfs.env`
- Create: `/home/<user>/domains/kanchifarmstay.com/private/`
- Preserve/migrate: current live `calendar.db`

**Step 1: Verify the manual Hostinger backup completed**

Confirm the backup timestamp is newer than the deployment start and includes both files and databases.

**Step 2: Inspect the current live paths and configuration**

Use Hostinger File Manager or SSH read-only inspection to locate `public_html`, the live PMS database, existing production configuration, and the active cron entry. Do not copy secrets into chat or logs.

**Step 3: Create the private runtime layout**

Create the private directory and `kfs.env`, migrate existing settings without exposing them, point `KFS_DB_PATH` to the preserved database, and apply owner-only permissions where supported.

**Step 4: Upload the verified release to a non-public temporary directory**

Extract and compare the archive outside `public_html`. Confirm the development database is absent.

### Task 6: Deploy, validate, and retain rollback

**Files on Hostinger:**
- Replace: deployable application files under `public_html`
- Preserve: server-only `kfs.env` and private `calendar.db`

**Step 1: Request action-time confirmation**

Immediately before replacing live files, explain that this changes the public website and may cause a short maintenance window. Obtain explicit confirmation as required by the Computer Use policy.

**Step 2: Switch the verified release into `public_html`**

Preserve unrelated production-only files only when inspection proves they are needed. Never overwrite the private database or configuration.

**Step 3: Run production HTTP and application smoke tests**

Verify homepage/assets, admin login, protected file responses, availability quote, manual conflict rejection, iCal import/export privacy, and Razorpay configuration without creating a real charge.

**Step 4: Configure and test cron**

Run `php /absolute/path/to/public_html/channel-manager/cron.php` every 15 minutes, verify the lock prevents overlap, and confirm a manual sync updates status.

**Step 5: Complete OTA cutover carefully**

Update each exact Airbnb, Booking.com, Agoda, and MakeMyTrip listing with its destination-specific PMS export only after its import succeeds. Obtain action-time confirmation before each external account edit.

**Step 6: Retain rollback assets**

Keep the pre-deployment Hostinger backup and old release until production checks and OTA refreshes have passed.

# Hostinger private configuration design

## Goal

Deploy the PMS directly to `kanchifarmstay.com` on Hostinger shared hosting without committing production secrets or overwriting the live SQLite booking database.

## Chosen approach

The application will load optional environment values from a server-only configuration file stored one directory above `public_html`. The default path will be derived from the application location so it works for both Apache requests and CLI cron jobs. A process environment variable may override the path when another host provides native environment management.

The file will use simple `KEY=VALUE` lines. Only recognised `KFS_` keys will be loaded, existing process environment values will win, blank lines and comments will be ignored, and malformed files will fail closed without exposing their contents. The production file will not be committed or placed under the document root.

## Production layout

```text
/home/<hostinger-user>/domains/kanchifarmstay.com/
├── kfs.env                    # server-only secrets, not web accessible
├── private/calendar.db        # live SQLite database and runtime files
└── public_html/               # deployed application release
```

`KFS_DB_PATH` will point to the private database. Release archives and GitHub deployment must exclude `channel-manager/calendar.db`, `.env` files, tests, documentation, Git metadata, and development artefacts.

## Loading and precedence

1. Keep every already-defined process environment variable unchanged.
2. Resolve the configuration path from `KFS_ENV_FILE` when set.
3. Otherwise use the domain-root `kfs.env` path derived from `channel-manager/config.php`.
4. Load only valid `KFS_*` assignments from a readable regular file.
5. Continue using the existing `kfsEnv()` interface so the rest of the application remains unchanged.

## Failure and security behaviour

- A missing file leaves configuration unset; existing production-required validation remains authoritative.
- The loader never prints paths, secrets, or file contents.
- The configuration file will be created with owner-only permissions where Hostinger permits it.
- Apache rules continue blocking databases, logs, tests, and `.env` files inside `public_html` as defence in depth.
- Deployment preserves the production database and provides a rollback path through the Hostinger backup created immediately before release.

## Verification

Automated tests will prove that the loader reads recognised keys, preserves existing environment values, ignores invalid/non-`KFS_` entries, supports quoted values, and safely handles a missing file. The full PHP, JavaScript, syntax, and browser verification suite will run before production deployment. After upload, HTTP checks will confirm that secret and database paths are inaccessible, followed by admin, booking, iCal, payment, and cron smoke tests.

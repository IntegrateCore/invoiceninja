# IntegrateCore client file library

Clients connect to one existing folder below `IntegrateCore-Documents/02. Client Information`. Assign the folder from the client's Documents section. The same underlying document records serve the React admin, authenticated client portal, and existing native document APIs. The web views browse folders; native apps retain their standard document list. Dotfiles, hidden ancestors, and symlinks are excluded.

New file-manager files are shared with that client. Existing Invoice Ninja attachments retain their original entity, ID, and sharing flag. If a private file disappears outside Invoice Ninja, its private metadata is retained and new unknown files remain private until an administrator explicitly changes their access. The administrator sees a visibility-review notice and can click the row's access setting. Folder ownership is exclusive across companies and clients; connected clients cannot be merged until their library folders are consolidated.

## Server configuration

```
INTEGRATECORE_FILES_ENABLED=true
INTEGRATECORE_FILES_URL=https://files.integratecore.net
INTEGRATECORE_FILES_USERNAME=<dedicated service account>
INTEGRATECORE_FILES_PASSWORD=<secret>
INTEGRATECORE_LIBRARY_URL=https://files.integratecore.net/files/IntegrateCore-Documents/02.%20Client%20Information/
```

The service account must be scoped by File Browser to `/IntegrateCore-Documents/02. Client Information` relative to its `/srv` root. Disable execution, sharing, and admin permissions; enable ordinary file create/read/delete operations. Keep credentials in the server's environment, never the browser bundle. TLS verification remains enabled. The integration is disabled unless explicitly configured.

The dev deployment uses a separate library snapshot and service account on `brates-server`; it does not write to the live Hetzner drive. Its Invoice Ninja URL is `http://192.168.1.119:8082` and isolated file manager is `http://192.168.1.119:8083`. Production Invoice Ninja is unchanged.

## Migration and synchronization

Run database migrations, then connect a folder. Existing client-owned attachments (including projects and invoices) are copied with collision-safe names. SHA-256 verification precedes switching the document reference. Original files are retained. `client_file_migrations` records the original disk/path and checksum, and reserves incomplete copies so they cannot be accidentally published. Retry interrupted migration with Refresh files.

```
php artisan integratecore:client-files --dry-run
php artisan integratecore:client-files --migrate
```

The scheduler syncs connected folders every minute; document pages also refresh on demand. A failed or malformed directory response never removes document records. Folder ZIP downloads use streamed temporary files, preserve relative directory structure, exclude private files for portal contacts, and enforce a 1 GB limit using actual downloaded bytes. No public file-manager share link grants portal access.

## Consulting hours

```
INTEGRATECORE_CONSULTING_HOURS_ALERTS_ENABLED=true
INTEGRATECORE_CONSULTING_HOURS_ALERT_EMAIL=bradley@integratecore.net
php artisan integratecore:consulting-hours-mobile <numeric-company-id>
```

Mobile setup reserves an unused standard client custom field labelled Time left (hours), without replacing existing custom-field labels or values. The computed value appears in the native client overview and configurable table mode; the stock native app's compact list cards have fixed fields. This does not replace the installed Flutter app with React. React remains available through the phone's web browser.

An email is sent once when a funded client has at most 2 hours remaining. Refill above 2 re-arms the alert. Failed delivery remains pending for retry. Never-funded zero clients and cloned clients do not generate low-hours notifications. Dev uses `MAIL_MAILER=log`; messages are captured for review, not delivered externally. Production delivery requires enabling the feature with a working configured mail transport.

## Validation and rollback

Focused PHPUnit suites cover path confinement, hidden-file filtering, cross-client isolation, visibility preservation, interrupted migration, ambiguous uploads, directory response validation, ZIP contents/cleanup, mobile-field setup, and low-hours alert episodes. Dev end-to-end checks exercise the existing mobile upload/download API and authenticated portal/admin library.

Before deployment, retain a dev database backup and the prior container image. The 2026-10-04 rollout saved these under `/opt/invoiceninja-dev/client-files-backup` and image `invoiceninja-dev:client-files-rollback-20261004`. The old container does not understand library document references. To roll back this dev deployment, stop the app, retain a new database backup, restore the saved pre-deployment dev database, switch the Compose image to the rollback tag, and disable the integration flags before restarting. Keep both the library snapshot and original uploads. Do not roll back the container alone after documents have migrated. Schema additions are additive; database restoration is a separate deliberate operation.

The saved database is `database-before.sql`; `docker-compose.before.yml` records the original Compose configuration. Restoring that database removes later dev database changes, so retain the current backup for reconciliation. Original uploads and the separate library snapshot must remain available throughout rollback.

Upstream bases: backend `f1ffa5f998` (v5-stable, 2026-09-18) and React `d0c3fdcf4` (28.09.2026.1). Both are merged into the development feature branches with custom branding, consulting-hours accounting, and file integration retained.

The paired React changes are in [IntegrateCore/invoiceninja-ui PR #1](https://github.com/IntegrateCore/invoiceninja-ui/pull/1). The private File Browser UI fork is [IntegrateCore/filebrowser, integratecore-ui-v2](https://github.com/IntegrateCore/filebrowser/tree/integratecore-ui-v2).

Browser verification uses the guarded `qa/dev-session.php` helper inside the isolated dev container. It creates a temporary API token and portal contact for client 7 and cleans up only matching verification records for that client/company. From the repository root:

```bash
npm ci
npx playwright install chromium
mkdir -p .local/client-files
umask 077
cleanup() {
  ssh -o BatchMode=yes brates-server \
    'docker exec -i -u www-data invoiceninja-dev-invoiceninja-1 php /dev/stdin cleanup' \
    < qa/dev-session.php > /dev/null
  rm -f .local/client-files/browser-session.json
}
trap cleanup EXIT
ssh -o BatchMode=yes brates-server \
  'docker exec -i -u www-data invoiceninja-dev-invoiceninja-1 php /dev/stdin create' \
  < qa/dev-session.php > .local/client-files/browser-session.json
chmod 600 .local/client-files/browser-session.json
node qa/client-library-browser.mjs
```

An existing Chromium binary can be supplied through `PLAYWRIGHT_EXECUTABLE_PATH`. `QA_SESSION_FILE` and `QA_OUTPUT_DIR` override the private session file and artifact directory. The browser script refuses a production host and redacts portal keys from failure diagnostics. Test session credentials, screenshots, and ZIP artifacts are excluded from Git. The Docker nginx configuration forwards the normalized hostname with an optional validated numeric port so portal redirects retain the dev port.

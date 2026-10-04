# IntegrateCore client file library

Clients connect to one existing folder below `IntegrateCore-Documents/02. Client Information`. An Invoice Ninja administrator manages assignments from the Clients folder manager; ordinary users can browse permitted libraries. The same underlying document records serve the React admin, authenticated client portal, and existing native document APIs. The web views browse folders; native apps retain their standard document list, scoped to the selected folder for client document requests. With the integration enabled, an unassigned client has an empty library, and direct client attachments on local disks are unavailable in the portal until an explicit migration. Single and bulk portal downloads apply the same visibility and selected-folder checks. Invoice/project attachment views retain their legitimate original attachment references. Dotfiles, hidden ancestors, and symlinks are excluded.

The Clients toolbar and each client's Documents section provide an administrator-only Client folders manager showing every folder and its current client. Assigning an already connected client to another folder changes its library view. Reassigning a folder transfers its view to the selected client; clearing the selection disconnects it. Files stay on the file server throughout these changes.

Click supported files to preview images, PDFs, and escaped text/code, including Python and Deluge. PDFs use the browser's native viewer with an Open in new tab fallback. HTML and SVG are displayed as text, never executed. Preview downloads enforce actual-byte limits of 25 MB for media and 1 MB for text; unsupported or oversized files retain ordinary download access. Protected portal preview/content routes apply the same client ownership and sharing rules as downloads.

New file-manager files are shared with that client. Existing Invoice Ninja attachments retain their original entity, ID, and sharing flag. If a private file disappears outside Invoice Ninja, its private metadata is retained and new unknown files remain private until an administrator explicitly changes their access. The administrator sees a visibility-review notice and can click the row's access setting. A folder has one current client view and permanent company tenancy. An administrator may assign, unassign, or transfer the view within that company. Changing this pointer never copies, moves, or deletes physical files. Direct client indexes retain their IDs, hashes, and visibility when transferred; indexes from a disconnected view are soft-deleted and reused on reconnection. Invoice/project attachments retain their original relationships and exact approved paths even outside the new view. A new owner gets a separate index whose visibility inherits private source metadata. Private originals override public aliases in portal listing, ZIP, preview, and download authorization. Connected clients cannot be merged until their library folders are consolidated.

## Server configuration

```
INTEGRATECORE_FILES_ENABLED=true
INTEGRATECORE_FILES_URL=https://files.integratecore.net
INTEGRATECORE_FILES_USERNAME=<dedicated service account>
INTEGRATECORE_FILES_PASSWORD=<secret>
INTEGRATECORE_LIBRARY_URL=https://files.integratecore.net/files/IntegrateCore-Documents/02.%20Client%20Information/
```

The service account must be scoped by File Browser to `/IntegrateCore-Documents/02. Client Information` relative to its `/srv` root. Disable execution, sharing, and admin permissions; enable ordinary file create/read/delete operations. Keep credentials in the server's environment, never the browser bundle. TLS verification remains enabled. The integration is disabled unless explicitly configured.

The dev deployment uses a separate library snapshot and service account on `brates-server`; it does not write to the live Hetzner drive. Its Invoice Ninja URL is `http://192.168.1.119:8082` and isolated file manager is `http://192.168.1.119:8083`. Production connects through `http://integratecore-files` on the existing private Docker network, with a separate scoped `invoice-ninja-prod` account. Its credentials remain only in the production environment and private backup directory.

## Optional production Compose network

The standalone `docker-compose.yml` keeps its existing app, MySQL, Redis, ports, and volumes. To connect its app to an already provisioned File Browser network, add the optional `docker-compose.file-library.yml` override. Set `INTEGRATECORE_FILES_NETWORK` to that existing network name in the private server environment. The File Browser service must resolve there as `integratecore-files`; set `INTEGRATECORE_FILES_URL=http://integratecore-files` and the integration flags/scoped account credentials in the same private app environment.

```bash
docker compose -f docker-compose.yml -f docker-compose.file-library.yml up -d
```

Use these same two files for subsequent Compose operations on that deployment. The override attaches only the application to the external network; MySQL and Redis remain on the default network. It adds no ports, mounts, credentials, File Browser service, or Cloudflare tunnel configuration. Existing custom production Compose files need only the equivalent app network attachment; retain their own ports and volume paths. Server environments, tunnel tokens, QA session JSON, upload data, database backups, and Laravel cached configuration are excluded from Docker build contexts and Git. The frontend Pusher app key is public configuration; its secret remains server-only.

## Migration and synchronization

Run database migrations, then connect a folder. Assignment itself only changes the view pointer. Use Refresh files or the migration command below to copy existing original client-owned attachments (including projects and invoices) with collision-safe names. SHA-256 verification precedes switching the document reference. Original files are retained. `client_file_migrations` records the original disk/path and checksum, and reserves incomplete copies so they cannot be accidentally published. Retry interrupted migration with Refresh files.

```
php artisan integratecore:client-files --dry-run
php artisan integratecore:client-files --migrate
```

The scheduler runs sync-only `integratecore:client-files` every minute; document pages also refresh on demand. Migration runs only through an explicit administrator Refresh files action or `--migrate` command. Changing a folder pointer does not migrate originals immediately or on the next scheduled refresh. A failed or malformed directory response never removes document records. Folder ZIP downloads use streamed temporary files, preserve relative directory structure, exclude private files for portal contacts, and enforce a 1 GB limit using actual downloaded bytes. No public file-manager share link grants portal access.

## Consulting hours

```
INTEGRATECORE_CONSULTING_HOURS_ALERTS_ENABLED=true
INTEGRATECORE_CONSULTING_HOURS_ALERT_EMAIL=bradley@integratecore.net
php artisan integratecore:consulting-hours-mobile <numeric-company-id>
```

Mobile setup reserves an unused standard client custom field labelled Time left (hours), without replacing existing custom-field labels or values. The computed value appears in the native client overview and configurable table mode; the stock native app's compact list cards have fixed fields. This does not replace the installed Flutter app with React. React remains available through the phone's web browser.

Client lists, nested client includes, and native bootstrap responses load the owning company in batches so computing this field does not add one company query per client. Query-count regression tests cover both direct client lists and clients included in payment responses.

An email is sent once when a funded client has at most 2 hours remaining. Refill above 2 re-arms the alert. Failed delivery remains pending for retry. Never-funded zero clients and cloned clients do not generate low-hours notifications. Dev uses `MAIL_MAILER=log`; messages are captured for review, not delivered externally. Production delivery requires enabling the feature with a working configured mail transport.

## Validation and rollback

Production deployment saves private database/environment/Compose snapshots and retains the previous image before replacing only the application container. It requires at least 6 GiB of free root-disk space before pulling an image and waits for the API health check. MySQL, Redis, uploads, and Docker volumes are preserved. Images build on GitHub rather than the business file server.

The original pre-upgrade production image is also retained as a checksummed private Docker archive on the mounted drive. Load that archive before a legacy rollback. The pre-upgrade database and originals must be restored together when returning to an older version that cannot read library-backed document references; keep a fresh current database snapshot and reconcile any intervening business changes. Build-checkout Git metadata resides in a private directory on the separate mounted drive, with symlinks preserving the existing checkouts. Neither it nor the rollback archive is inside a client folder.

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

Folder assignment verification uses separate temporary clients, contacts, an editor user/token, a project attachment, and two new unassigned folders. It never remaps a business client. Keep the browser session above alive while running this suite:

```bash
umask 077
folder_qa_run=$(openssl rand -hex 8)
cleanup_folder_qa() {
  ssh -o BatchMode=yes brates-server \
    "docker exec -i -u www-data invoiceninja-dev-invoiceninja-1 php /dev/stdin cleanup $folder_qa_run" \
    < qa/dev-folder-pointer-fixtures.php && \
    rm -f .local/client-files/folder-pointer-fixtures.json
}
trap cleanup_folder_qa EXIT
ssh -o BatchMode=yes brates-server \
  "docker exec -i -u www-data invoiceninja-dev-invoiceninja-1 php /dev/stdin create $folder_qa_run" \
  < qa/dev-folder-pointer-fixtures.php > .local/client-files/folder-pointer-fixtures.json
chmod 600 .local/client-files/folder-pointer-fixtures.json
node qa/client-folder-pointer-browser.mjs
```

Run this in a separate shell so its cleanup trap does not replace the shared browser-session cleanup. Both fixture helpers require the exact isolated dev app URL and internal `http://file-library` endpoint. The folder helper stores a private ownership ledger in mounted storage and validates the business mapping snapshot, fixture identities, file paths, and SHA-256 hashes before cleanup. Unknown files, changed bytes, or non-fixture ownership stop cleanup. The browser checks folder switching/transfers, direct index identity, private flags, retained project downloads, editor authorization, mobile assignment/clear, protected previews, and the single Time left display. Repeat runs reset only ledger-owned folders assigned to the two fixture clients. Credentials remain in ignored private files; retain the shared session until all browser suites finish.

Preview-specific verification uses `qa/dev-preview-fixtures.php` with `create|cleanup <16 lowercase hex run ID>` and `qa/client-library-preview-browser.mjs`. Its temporary uploads cover valid raster/PDF content, escaped Python/Deluge/HTML, forged media signatures, unsupported content, private files, and cross-client denial. Redirect creation output to `.local/client-files/preview-fixtures.json` with mode 600, run the browser suite while the shared session is active, then invoke the matching guarded cleanup and remove only that fixture credentials file. The suite verifies authenticated inline responses, escaped script markers, explicit original-byte downloads, and no browser execution or page errors.

Production verification has separate guards and credentials. Run it only after the production rollout, migrations, folder connections, and mobile setup are complete. `qa/prod-session.php` requires exactly `https://portal.integratecore.net`, internal library URL `http://integratecore-files`, clients 7 and 6 connected to separately configured expected folders, and client 10 unmapped. Before `create`, provide `IC_QA_EXPECTED_FOLDERS` as a private process environment JSON object with exactly numeric client IDs 7 and 6 as keys and their expected folder names as values; use a mode-600 server-side QA env file with `docker exec --env-file <private QA env file>`. Names and credentials must stay out of tracked files and command logs. `inspect` and `cleanup` use the existing private ownership ledger and do not require this creation-only environment setting. Its `create|inspect|cleanup <16 lowercase hex run ID>` commands snapshot balances and document relationships before creating one temporary administrator token and three non-primary contacts with email sending disabled. Creation output belongs in `.local/client-files/production-session.json` with mode 600. Send the helper to the production app container over SSH stdin using `-i ~/.ssh/VPS -o IdentitiesOnly=yes -o BatchMode=yes integratecore-vps`; do not copy credential output into logs.

`node qa/client-library-production-browser.mjs` uses the exact admin origin `https://admin.integratecore.net` and portal/API origin `https://portal.integratecore.net` with those credentials to read existing production files, verify native balance/custom1/document APIs, inspect the folder manager and hours display, check each portal's file isolation, and reject cross-client or traversal downloads/previews/ZIPs. It creates no file fixtures and changes no assignments or access flags. Authenticated data bootstrap/search, native document lists, and portal ZIP downloads use POST for read operations; other browser business mutations are blocked. The verifier uses a system token and requires a single-company administrator so bootstrap does not create untracked tokens. Afterward run the matching helper `inspect` and `cleanup`, then remove only the production session JSON. Cleanup validates nonce ownership and revokes its own token/contacts even if concurrent business activity changes the reported snapshot. File checksums and relationship comparisons remain available in the private mounted ledger until cleanup; browser reports/screenshots stay in ignored `.local/client-files/production`.

`qa/client-library-native.php` runs through PHP stdin in the isolated dev container and refuses other app/library URLs. It verifies native document listing, upload/download, visibility, path confinement, and retained migration originals using a temporary token and temporary uploads cleaned in `finally`. It does not assign business folders. The normal API's password requirement for deletion remains in force; the verifier uses the document repository to clean its own upload when that requirement blocks the API call.

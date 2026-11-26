# IntegrateCore Invoice Ninja releases

The release unit is one amd64 GHCR image digest built from two reviewed full
commit SHAs. Build and test off-server. Staging and production never build or
retag the candidate. Transfer the JSON handoff privately from the trusted
builder; it is an operator receipt, not a signed attestation.

## Build a candidate

Select the exact backend and public UI commits. The UI uses a local branch
at exactly the input commit in a disposable clone, with no UI credential.
The builder checks the PHP base pin, CLI, FPM and extensions, runs focused task
tests in disposable MySQL, and then pulls and boots the published digest
against a fresh database. Failed post-push verification may leave an
unapproved candidate tag; do not stage it. Only success creates the handoff.

The pre-push gate preserves the consulting hours, fake vendor mail, task bulk
update and reassignment, client merge balance, and client API snapshot checks
from successful UI Actions run 45858393855 (backend 173ff5a, UI 98090aa).
It also retains task rounding and time-log checks. A missing suite or method
fails closed: the selected backend must carry all these tests. The suite runs
on the candidate image, with disposable MySQL and an internal network after
Composer installs test dependencies. Mail uses the array driver and the vendor
routing test uses Laravel's mail fake; no real mail is sent.

After cutover, manually dispatch **IntegrateCore immutable candidate** with
the two SHAs. Download the `release-handoff` artifact. The workflow uses the
backend GitHub token with the contents:read and packages:write scopes already
used by the backend publishing workflows. It needs no deploy credentials
and refuses to publish if it cannot confirm the existing package is private.

If Actions cannot dispatch, run the same script on an authorized personal
or off-server Linux amd64 runner. Use Docker on that runner only, Node 22.12+
in the 22 series, PHP 8.4 (bcmath, curl, DOM, GD, Imagick, Intl, mbstring, PDO
MySQL, SimpleXML, SOAP, ZIP), Composer 2, Git, curl, jq and OpenSSL. Run only
one candidate at a time for the same approved release:

    bash scripts/integratecore-release/candidate.sh BACKEND_SHA UI_SHA REGISTRY_USER release-handoff.json

Enter an existing backend-owned package writer token at the prompt. Use only
the existing least-privilege package credential, not a UI or deploy token.
If the runner or existing writer is unavailable, stop before publishing
without provisioning either. Save the handoff and build log with the release
review. Never substitute a tag for a digest.

## Isolated GitHub-hosted candidate before cutover

Run 45895223617 proved a prior image could be pulled by digest and boot on
fresh disposable MySQL, with health and migrations passing. It did not check
provenance and is not a release handoff. Run 45891287139 used a one-off build
and proved that the backend repository's job token could write to the
existing package then. Neither run proves the current job token can read
private package metadata from the REST API. The candidate refuses to push
unless that lookup succeeds and reports the named package is private. A
403, missing package, or public visibility is a blocker, not permission to
add credentials or skip the check.

The reviewed backend pin is PHP 8.4.27; the bundled PHP source declares ZIP
1.22.8 in `ext/zip/php_zip.h`. The existing staging runtime also reported
those two versions independently. Its older PDO MySQL extension reported
8.4.26 and is not accepted by this release gate; the pinned backend builds
PDO MySQL from PHP source, where its extension version is PHP_VERSION.
The candidate checks the exact base digest and parent layers, runtime,
four source/base labels, and the exact registry manifest's config digest
before booting the re-pulled linux/amd64 image and writing the handoff.

Without registering a workflow on the default branch, prepare a commit
directly atop the exact reviewed PR #12 SHA. Add only the workflow below
as `.github/workflows/integratecore-pr12-candidate-entry.yml`. Replace both
`REVIEWED_PR12_SHA` occurrences with the same full commit SHA. Review the
base tree's push workflows and any external hooks again before pushing.
Only after separate authorization to publish, push the commit once to
the previously unused `ci/integratecore-pr12-candidate-20261126` branch.
That push is the trigger; do not push it as a preparation step or open a
PR from that branch. Never use a deployment ref or alter a legacy workflow.

```yaml
name: IntegrateCore PR 12 isolated candidate
on:
  push:
    branches: [ci/integratecore-pr12-candidate-20261126]
    paths: [.github/workflows/integratecore-pr12-candidate-entry.yml]
permissions: {}
jobs:
  candidate:
    if: github.repository == 'IntegrateCore/invoiceninja' && github.ref == 'refs/heads/ci/integratecore-pr12-candidate-20261126'
    permissions:
      contents: read
      packages: write
    uses: IntegrateCore/invoiceninja/.github/workflows/integratecore-candidate.yml@REVIEWED_PR12_SHA
    with:
      release_sha: REVIEWED_PR12_SHA
      backend_sha: 173ff5a8362e49956b1bdb59197b286aa449e1c8
      ui_sha: 98090aa2595fddca5d1e8433c4436d1292245ec7
```

The caller grants no deploy secrets, environment access, or runner access
beyond a GitHub-hosted runner with the backend repo's existing job token.
It invokes the real reusable workflow and checks out its scripts from the
same immutable revision. The caller is not a default-branch dispatch and
does not reuse React Release. The existing repository is public: the run
and artifact are not a confidential channel. The handoff contains only
release identifiers, not package credentials or application configuration.
Download it from the trusted successful run and transfer it privately to the
operator. Do not add any private evidence or credentials to the artifact.
If the release identifiers themselves must be private, do not use the public
Actions route; use the authorized off-server fallback.

## Stage and promote

Use the same reviewed script checkout and handoff on both hosts. The staging
project is `/opt/invoiceninja-dev`; production is `/srv/invoiceninja` on the
already approved Hetzner destination. Use the existing Invoice Ninja deploy
identity and confirmed user/port only with that destination, with BatchMode,
IdentitiesOnly and StrictHostKeyChecking enabled against the existing known
host key. Do not run ssh-keyscan to establish trust. The existing
read-only registry login must work under the deploy principal; the writer
token never goes to a host. Do not provision a host credential to proceed.

After the current safe release is no longer using staging, run on staging:

    bash scripts/integratecore-release/host.sh staging preflight release-handoff.json
    bash scripts/integratecore-release/host.sh staging apply release-handoff.json

Staging apply records automatic health and migrations; it does not record
manual acceptance. On the exact staged digest, a human reviewer must verify
login, the client portal and the changed workflows with staging test data.
Keep observations privately under a release-review reference like REL-123.
Never include passwords, tokens, cookies, screenshots or customer data in
the JSON. This is a schema example, not a completed record. Replace results
with "pass" only after actually checking:

    {
      "image": "ghcr.io/integratecore/invoiceninja@sha256:<exact digest from handoff>",
      "login": "<result>",
      "portal": "<result>",
      "workflows": "<result>",
      "review": "REL-123"
    }

Record acceptance on staging, then retrieve a fresh receipt directly from it.
Receipt creation rechecks live identity, health and migrations, and requires
matching manual acceptance. Restaging clears acceptance. Transfer the receipt
as trusted operator evidence, not as a signature:

    bash scripts/integratecore-release/host.sh staging accept release-handoff.json acceptance.json
    bash scripts/integratecore-release/host.sh staging receipt release-handoff.json > staging-receipt.json

After explicit production approval, securely transfer that receipt and the
original handoff to production. On production run:

    bash scripts/integratecore-release/host.sh production preflight release-handoff.json
    bash scripts/integratecore-release/host.sh production apply release-handoff.json staging-receipt.json

Confirm the external app and relevant flows after either deployment. The
script serializes releases per host, checks disk, memory, DB connectivity and
current app health, pulls by digest and checks labels, backs up while the app
is stopped, and rolls only the app with no local build. The persistent
Compose override records the digest. The legacy workflows and scripts do not
share the lock: do not run them concurrently or after cutover.

## Backup and recovery

Before apply, have a maintenance window, pause any external DB writers,
and verify the existing storage snapshot/backup covers the persistent app
data and can be restored. The script saves the existing configuration,
previous image, and a checked DB dump under `releases/` on the target. Keep
these private. Do not prune the rollback image or overwrite the dump.

If apply fails or is interrupted, leave the app stopped and use
`releases/recovery-required` to locate the attempt. Image rollback and
database recovery are separate decisions:

1. If `candidate-start-attempted` is absent and the candidate never started,
   restore the prior Compose and environment settings and run the saved
   `rollback.yml` override with `--no-deps --no-build --pull never`.
2. If the start marker exists or startup is uncertain, do not start older
   code until schema and persistent data compatibility have been verified.
   Decide separately whether to recover forward or restore the verified
   database and storage backup as a matched set. Restoration can discard
   writes; require explicit recovery authorization. Image rollback does not
   undo migrations. Never auto-restore the database.

Verify app, DB and external flows before clearing the recovery marker.

## Reviewed cutover only

1. Review this PR and coordinate with the current release operator. Existing
   Deploy, Deploy Dev, phpunit, React Release and PHP runtime workflows are
   unchanged. Merging or enabling Actions is not authorized by this review.
2. In a separately approved cutover, first disable the legacy push deploy
   workflows and any equivalent hooks. Verify both deploy workflows are
   disabled and no releases are queued or running. Do not push or merge to
   any production-triggering branch (including `v5-stable` or `main`), or
   to `dev` under its staging trigger, while legacy auto-deploy is active.
   Remove the legacy push triggers in the reviewed cutover, preserving CI
   names and unrelated workflows.
3. Verify GitHub's actual default branch. Only after legacy triggers are
   neutralized, register the candidate workflow on that branch through a
   reviewed, explicitly approved change. A dev-only file cannot register it
   for manual dispatch.
4. Re-enable backend Actions only with separate explicit approval, with no
   active legacy release triggers. Verify registration and an approved
   candidate-only dispatch before relying on it. Until then use the
   off-server fallback with the existing credential and authorized runner.
   Staging and production remain separate, explicitly authorized digest
   operations.

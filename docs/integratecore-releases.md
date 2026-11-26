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

## GitHub Actions dispatch blocker

Backend manual dispatch returned HTTP 422: `Actions has been disabled for
this repository`. The repository is a public fork; its permission endpoint
returned enabled=true and allowed_actions=all, and the workflow endpoint
listed Deploy Dev as active. The fork's Actions tab was independently
confirmed disabled. Explicit approval to re-enable it remains pending. The available identity got HTTP 403 for organization Actions policy;
package metadata also returned HTTP 403 requiring read:packages. Do not
change scope, settings or visibility to work around those responses.

An existing org owner can compare Settings > Actions > General for the
organization and backend repository, including repo/fork allowlists, with
`gh api orgs/IntegrateCore/actions/permissions` and
`gh api repos/IntegrateCore/invoiceninja/actions/permissions`. If those and
the Actions tab agree but the 422 persists, give GitHub Support the repo,
workflow ID, ref, UTC timestamp, exact error and X-GitHub-Request-Id, never
the auth headers. Manual dispatch also requires the workflow on the default
branch. Verify with an approved dispatch after cutover and confirm a run
appears before relying on Actions. Until then, use the manual fallback.

https://docs.github.com/rest/actions/permissions
https://docs.github.com/actions/how-tos/managing-workflow-runs/manually-running-a-workflow
https://docs.github.com/packages/working-with-a-github-packages-registry/working-with-the-container-registry

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

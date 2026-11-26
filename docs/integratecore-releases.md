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
listed Deploy Dev as active. These settings do not establish that dispatch
works. The available identity got HTTP 403 for organization Actions policy;
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

Check login, client portal and changed business flows on staging. Then
retrieve a fresh receipt directly from staging (not from the builder):

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

If apply fails or is interrupted, leave the app stopped and consult
`releases/recovery-required` for the attempt directory. If the candidate
never started, restart the prior image with the saved rollback override.
If it reached migration/startup, first decide with the operator whether
to recover forward or restore the database and persistent storage as a
matched set. Restoring can discard writes since the dump; never do it
automatically. After the approved recovery choice, restore saved Compose
and environment settings and use the saved `rollback.yml` as the canonical
override to run the retained old image with `--no-deps --no-build --pull never`.
Check app health, DB state and external flows, then clear the marker. Do not
clear the marker just to force a retry.

## Reviewed cutover only

This branch does not replace or trigger the existing Deploy, Deploy Dev,
phpunit, React Release or PHP runtime workflows. Before merging/activating
the design, coordinate with the active release's operator and replace the
two legacy push-based release triggers in the cutover review (preserving
workflow names/paths and unrelated CI); otherwise pushes to `dev` or
`v5-stable` can still run the legacy release. Verify the package
connection using the existing backend package credential, review both host
preflights, and test off-server before authorizing any live promotion.

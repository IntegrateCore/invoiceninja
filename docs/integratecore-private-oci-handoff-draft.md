# Private staging image handoff (not enabled)

## Normal release in four steps

1. **Build once off-server.** The candidate job tests, publishes to private
   GHCR, and emits its handoff. The release operator independently obtains
   that successful run's handoff and approves the exact image and source pins.
2. **Deliver only the image.** An approved, delivery-only runner reads the
   exact digest and sends the original manifest, config and layer bytes to
   one private staging inbox. It sends no credentials, backups or app data.
3. **Verify and prove local identity.** Seal the inbox slot, verify all bytes
   against the trusted handoff, then use a separately reviewed same-host
   import. Inspect the image by the original repository@digest and require
   the verified config ID and linux/amd64. No tags or rewritten manifests.
4. **Approve staging deployment separately.** Before applying, reserve a
   change window, pause competing writers, and verify matched recoverable DB
   and storage backups. Keep the release lock, label/runtime checks,
   recovery marker, rollback, health/migrations and manual acceptance.
   Nothing about this flow authorizes production.

**Today:** the normal reviewed host script still pulls the digest from GHCR.
This PR adds verification and a local acceptance model only. It does not
activate delivery or add an offline import or deploy command.

## One-time setup, only with separate owner approval

1. **Fix the identity.** Choose one owner-controlled, protected non-deploy
   ref and a reviewed delivery-only workflow revision. Require an approved
   non-PR event; no fork/PR triggers, fork artifacts, wildcard refs, or
   execution of untrusted repository code. Pin actions by full SHA. Give
   only that job packages:read and id-token:write (contents:read only if
   required); keep root permissions empty. Use the job's own GHCR token
   to read the existing private package; never send that token to staging.
2. **Set exact trust.** In Tailscale, the owner configures GitHub's issuer,
   a dedicated audience, exact subject, and conjunctive exact matches for
   repository, immutable repository ID, event_name, ref, workflow_ref and
   workflow_sha. If reusable, also require the exact job_workflow_ref and
   job_workflow_sha; still pin the caller. Use only Auth Keys > Write,
   scoped to the single dedicated ephemeral sender tag. Check actual claims
   without logging a JWT. Every new workflow revision needs its own review
   and trust update. Do not accept a repo-only, branch-only or wildcard rule.
3. **Allow one private port.** Assign a dedicated inbox tag to a separate
   staging inbox node identity, not the shared host identity. Allow the sender
   tag only TCP 9443 to that inbox node. Check the *effective* policy for broader legacy or wildcard
   access. No SSH (including Tailscale SSH), shell, Docker socket, subnet,
   exit node, production reachability, Funnel, or general network access.
   The receiver must authenticate TLS and the ephemeral Tailscale peer and
   verify its tag; do not trust sender-provided headers or reuse an existing
   Serve handler.
4. **Isolate the inbox.** Run unprivileged, tailnet-only, with one slot,
   strict mode 0700, fixed canonical object names, per-object limits,
   total byte quota, rate/time bounds and no readback or automatic import.
   Seal it from the sender before the operator verifies or imports it.
   Keep it outside app storage. Verify negative cases (another repo, ref,
   event, workflow, edited revision, and access to a second port) before
   allowing a real handoff.

The [disabled example](examples/integratecore-oci-handoff.disabled.json)
is not runnable or installed by this PR. Official Tailscale documentation
supports exact custom claim matches without wildcards, ephemeral GitHub
nodes, and protocol/port-specific grants. It does not prove this tailnet's
plan entitlement, active issuer configuration, or effective connectivity.
If the owner cannot demonstrate exact claim enforcement and port isolation
on this tailnet, **stop**; do not fall back to a reusable auth key or a
broader grant.

## Local acceptance and import boundary

Run on Linux with Python 3.11+:

    python3 scripts/integratecore-release/test-verify-oci-handoff.py

The synthetic acceptance verifies at the source, copies only image files
into a quota-limited private slot, verifies again before sealing, and tests
operator-collected runtime evidence with synthetic inspect output. It checks
original manifest SHA-256, descriptor sizes, config and every layer digest,
and provenance pins; it rejects corrupt, escaping, oversized or additional
files and tag-only or mismatched runtime evidence. It does not listen,
contact a registry, or import images. Do not extract untrusted archives or
recompress layers; the OCI index is only a pointer to the original manifest.

The lowest-risk next step is to prove, with a harmless synthetic layout in
a disposable nonproduction runtime matching staging's Docker image store,
that a restricted same-host offline import preserves the original
repository@digest and config. Give the receiver no Docker access; a separate
local operator rechecks a sealed slot before importing. Collect fresh runtime
inspect output locally by the exact reference; uploader-provided evidence
does not count. If that identity cannot be preserved, stop. A reviewed
offline release path is still necessary; do not skip the current pull guard.

An existing scoped private blob store could remove the inbound listener
but would still need the same byte checks and import proof. No approved
store is assumed and no new infrastructure is requested here.

## Teardown

Close the slot and disable the listener first; revoke the exact federated
identity and remove the sender-to-inbox grant. Log out or remove remaining
ephemeral nodes, remove the delivery workflow and unused tags/variables,
and remove the unneeded quarantined image copies under the approved
retention rule. Recheck that the runner cannot connect. Do not touch app
data, backup retention, the existing registry login path, or production.

References:
- [Tailscale GitHub Action](https://tailscale.com/docs/integrations/github/github-action)
- [Tailscale workload identity federation and claim matching](https://tailscale.com/docs/features/workload-identity-federation)
- [Tailscale port-specific grants](https://tailscale.com/docs/features/access-control/grants)
- [GitHub OIDC claims](https://docs.github.com/en/actions/reference/security/oidc)

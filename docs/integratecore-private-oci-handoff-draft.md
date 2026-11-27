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
   the manifest identity, separately verified config, and linux/amd64. No
   tags or rewritten manifests.
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
   The receiver uses private WireGuard transport and must authenticate the ephemeral Tailscale peer and
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

## Receiver slice: disabled, no listener

The local receiver core accepts one image-only OCI archive at PUT /v1/image
into a private single-use slot. An operator supplies the expected archive
checksum independently; it is not a substitute for the pinned manifest,
handoff and blob checks. Successful upload returns status only, never
readback, import or deployment. The slot is finalized without overwrite;
disconnect or timeout removes the partial file. A crash can leave a stale
partial, which blocks the slot until the owner inspects and clears it.

A future adapter must run as a dedicated non-root tsnet application with a
neutral hostname (for example, private-inbox), private state and only quota-
limited inbox write access. Bind the application through tsnet.Server.Listen
on one granted port, not a host TCP socket. Prefer WireGuard-only transport
to avoid public certificate naming; if HTTPS is required, wrap that same
listener with a private CA and pin it at the sender. Never use ListenFunnel,
public DNS/certs for the private name, subnet forwarding, or host SSH.

For each accepted connection, the adapter must call Server.LocalClient()
and WhoIs with the socket's actual RemoteAddr. Deny errors, absent node,
missing exact CapMap key https://integratecore.net/cap/oci-image-upload,
or anything other than the single dedicated sender tag. Never read forwarded
headers as identity. The owner must separately grant that sender only the
dedicated receiver port and capability. Restrict header size/time and
connection count before the core; reject transfer encodings and conflicting
lengths, and authorize before reading or sending 100 Continue. Enforce
read deadlines and a filesystem quota externally as well; synchronous disk
operations cannot be interrupted by the asyncio body timeout.

The separate Go draft in scripts/integratecore-release/tsnet-inbox is
disabled by default. It uses only a dedicated tsnet listener over WireGuard,
with no public CA, Funnel or host listener. Its startup requires explicit
enablement, separate non-root paths and an approved archive checksum.
The Python core remains the local model. No service unit or activation is
included. Linux Go 1.27.4 and Tailscale v1.104.7 are pinned; isolated tests use
fake WhoIs peers and never start tsnet. Real peer enforcement still requires
owner-approved identity, effective tag/capability and port policy, and
external filesystem quotas. Do not enable it on synthetic evidence alone.

GitHub workflow_dispatch requires registration on the default branch; an
isolated ref alone does not register it. No active workflow or default-branch
change is included. An eventual owner-approved trigger must still enforce
the exact repository, event, ref and immutable reviewed workflow revision.

Run the synthetic entry-point acceptance with:
`python3 scripts/integratecore-release/test-oci-inbox.py`.

References: [tsnet source](https://github.com/tailscale/tailscale/blob/main/tsnet/tsnet.go),
[WhoIs response](https://github.com/tailscale/tailscale/blob/main/client/tailscale/apitype/apitype.go),
[GitHub dispatch registration](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#workflow_dispatch).

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

### Verified offline import candidate

[Run 45909090809, job 140212838187](https://github.com/IntegrateCore/invoiceninja/actions/runs/45909090809/job/140212838187)
used a disposable Ubuntu 24.04 runner with matching Docker 29.1.3,
containerd 2.2.1, runc 1.3.4, containerd overlayfs image store, linux/amd64
and cgroup v2. With a tiny synthetic single-manifest OCI archive,
`docker image load` preserved the fixture's original repository@digest
for both tested index annotations (tag or exact digest). The exact
reference still ran with `--pull never` after the tag moved. Wrong digest
and tag-only evidence were rejected. No registry or transport was needed
for the successful import.

Minimal route to review: after the owner approves a local import, have the
operator verify a sealed image and load its OCI archive with the original
repository name. Do not give the receiver Docker access. Require fresh
Docker inspect evidence for that exact reference: RepoDigests must include
it, and Id and Descriptor.digest must both be the manifest digest on this
image store. Independently retrieve and hash the actual manifest and config
bytes from the local containerd content store; verify the manifest links
to the already-approved config. Do not compare Docker's Id to the config
digest on this store. The original OCI bytes and release pins remain the
authority, not a tag or an uploader-supplied receipt.

The test did not use the real GHCR image, staging daemon, production,
Tailscale, or a customer workload. The current release script still requires
a GHCR pull. A separate review must approve the local importer and offline
release integration before any staging attempt; do not skip or change the
current pull/rollback guards here.

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

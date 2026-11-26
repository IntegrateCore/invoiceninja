# Private staging image handoff (design draft; not enabled)

Keep off-server builds and deploy the same tested image. The current normal
path stays unchanged: the host applies a verified handoff, pulls that exact
private GHCR digest, checks labels and runtime, and uses the existing recovery
guards. This draft does not add an import or deploy path. A locally loaded
image is not accepted in lieu of the pull by the current host script.

## Proposed private delivery job

- A separate delivery-only job, with root workflow permissions empty and
  job permissions limited to packages:read and id-token:write (contents:read
  only if required). It reads the already-tested private image by digest with
  its own GitHub job token. It does not build, push, deploy, receive a staging
  secret, or run repository-controlled code from pull requests. Pin all
  actions and any reusable workflow to reviewed full commit SHAs. Do not
  use pull_request, pull_request_target, fork-run artifacts, or wildcard
  branches as delivery inputs.
- With a separate owner-approved setup, use Tailscale workload identity
  federation to give that job an ephemeral runner identity. Configure the
  GitHub issuer, a dedicated audience and exact subject, and conjunctive
  custom claims for immutable repository ID, exact repository, intended
  event and ref, exact workflow_ref and reviewed workflow_sha. If using a
  reusable workflow, additionally restrict job_workflow_ref and
  job_workflow_sha to the exact pinned callee; that is not a substitute for
  pinning the caller. Inspect the expected claim shape without logging a JWT.
  Use an owner-controlled ref and approval boundary. If exact matches for
  repo, ref, workflow and revision cannot be enforced by the provider, do
  not enable federation. Test denial from another repo/fork, ref, workflow
  and changed revision before any real delivery.
- Scope the federated auth-key capability to one dedicated ephemeral sender
  tag. Grant that tag only one destination and TCP port: an isolated staging
  inbox. Check the effective policy for wildcard or inherited access.
  No SSH (including Tailscale SSH), Docker socket, shell, subnet or exit
  node access, production reachability, general network access or Funnel.
  Stage privately; do not expose the inbox on LAN or public interfaces
  as a side effect. The runner logs out after the job.
- Require authenticated TLS and verify the sender's ephemeral peer identity
  and tag server-side. An unprivileged inbox receives only the preapproved
  digest into an owner-only quarantine. No listing, file reads, reverse
  sync, arbitrary paths, or automatic import on upload. Use a single-use
  bounded slot, per-object size and total quota, rate and time limits,
  request count/concurrency limits, exclusive temporary files and atomic
  seal. Keep material outside app storage and backups. Do not reuse an
  existing unrelated Tailscale Serve handler. Staging gets no GitHub
  registry token; only image bytes travel toward staging.

## Receipt and validation boundary

The sender fetches the original registry manifest bytes by immutable digest
with the correct media-type negotiation, then its exact config and layer blobs.
Do not use a tag, Docker save/load as proof of the registry digest, or export
with manifest conversion, layer recompression, or fallback to public artifacts.
The standard OCI image layout index is only a local pointer; the original
manifest bytes belong in the digest-addressed blob store. The receiver
must accept only canonical, locally derived object names, not untar untrusted
uploads. Seal and isolate the directory from the sender before validation.

Provide the original handoff from the separately authenticated successful run
and the independently reviewed digest and backend/UI/PHP pins to the validator.
Never accept an uploader-supplied receipt as approval. The validator requires
one amd64 Linux manifest, checks original bytes against the approved SHA-256,
checks descriptor sizes and hashes for config and every layer with bounded
metadata and streaming blob reads, and verifies the four provenance labels.
Run under the established CPU, RAM, disk, I/O and wall limits; a resource
failure is a failed receipt, not permission to run unbounded. Check actual
descriptor sizes and measured throughput before approving a transfer.

Synthetic tests (Linux, Python 3.11 or newer):

    python3 scripts/integratecore-release/test-verify-oci-handoff.py

A verifier success is not deployment approval. An eventual, independently
reviewed offline import must prove that the runtime resolves the original
canonical repository@digest to the verified config without retagging, rewriting
or weakening the online guard, and must rerun the existing provenance, runtime,
health, migrations, lock, backup/rollback and manual acceptance gates.
If the runtime cannot retain that identity, stop. Before any staging apply,
approve a change window, pause other writers, and verify recoverable matched
database and app-storage backups. Neither this draft nor a staging receipt
authorizes production.

An already-configured private blob store could instead accept a single
write-only object from the runner and expose a short-lived read for staging,
scoped to the immutable image, with no list/public access. It would still
require the identical receipt checks and offline import review. No such
approved store is assumed, and this draft does not request new infrastructure.

References:
https://tailscale.com/docs/integrations/github/github-action
https://tailscale.com/docs/features/workload-identity-federation

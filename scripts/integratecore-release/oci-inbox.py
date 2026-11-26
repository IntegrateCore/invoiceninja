"""Disabled receiver contract: no listener, runtime, credentials, or network adapter."""

import asyncio
import hashlib
import fcntl
import os
import re
import secrets
import stat

CAPABILITY = "https://integratecore.net/cap/oci-image-upload"
SENDER_TAG = "tag:invoiceninja-staging-image-sender"
DIGEST = re.compile(r"[a-f0-9]{64}")
MAX_BYTES = 1024**3
MAX_CHUNK = 256 * 1024


class Inbox:
    def __init__(self, directory, whois, archive_sha256, *, enabled=False,
                 max_bytes=MAX_BYTES, seconds=120):
        if not DIGEST.fullmatch(archive_sha256) or not 0 < max_bytes <= MAX_BYTES or not 0 < seconds <= 600:
            raise ValueError("invalid inbox limits or expected archive checksum")
        self.directory = directory
        self.whois = whois
        self.archive_sha256 = archive_sha256
        self.enabled = enabled
        self.max_bytes = max_bytes
        self.seconds = seconds
        self.busy = False

    async def receive(self, *, peer_address, method, path, length, read):
        """Single-event-loop entry point. Peer address must come from the accepted socket."""
        if not self.enabled:
            return 503
        if method != "PUT" or path != "/v1/image":
            return 404
        if self.busy:
            return 429
        self.busy = True
        try:
            async with asyncio.timeout(self.seconds):
                try:
                    capabilities, tags = await self.whois(peer_address)
                except Exception:
                    return 403
                if CAPABILITY not in capabilities or set(tags) != {SENDER_TAG}:
                    return 403
                if type(length) is not int or not 0 < length <= self.max_bytes:
                    return 413
                return await self._store(length, read)
        except TimeoutError:
            return 408
        except (OSError, ValueError):
            return 400
        finally:
            self.busy = False

    async def _store(self, length, read):
        directory = os.open(self.directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        name = ".incoming-" + secrets.token_hex(16)
        created = False
        try:
            info = os.fstat(directory)
            if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
                return 503
            # Refuse any stale partial, existing receipt or second uploader, including across processes.
            try:
                fcntl.flock(directory, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return 429
            with os.scandir(directory) as entries:
                if next(entries, None) is not None:
                    return 409
            fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                         0o600, dir_fd=directory)
            created = True
            checksum = hashlib.sha256()
            with os.fdopen(fd, "wb") as output:
                remaining = length
                while remaining:
                    limit = min(MAX_CHUNK, remaining)
                    chunk = await read(limit)
                    if not isinstance(chunk, bytes) or not 0 < len(chunk) <= limit:
                        return 400
                    output.write(chunk)
                    checksum.update(chunk)
                    remaining -= len(chunk)
                if checksum.hexdigest() != self.archive_sha256:
                    return 422
                output.flush()
                os.fsync(output.fileno())
                os.fchmod(output.fileno(), 0o400)
            try:
                os.link(name, "sealed.oci.tar", src_dir_fd=directory, dst_dir_fd=directory,
                        follow_symlinks=False)
            except FileExistsError:
                return 409
            return 201
        finally:
            try:
                if created:
                    os.unlink(name, dir_fd=directory)
            finally:
                os.close(directory)

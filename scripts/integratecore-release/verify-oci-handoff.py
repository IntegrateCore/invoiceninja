#!/usr/bin/env python3
"""Validate a sealed, unpacked image-only OCI layout. Never import or deploy it."""

import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
import tempfile
from contextlib import contextmanager

REPOSITORY = "ghcr.io/integratecore/invoiceninja"
MANIFEST_TYPES = {
    "application/vnd.oci.image.manifest.v1+json",
    "application/vnd.docker.distribution.manifest.v2+json",
}
CONFIG_TYPES = {
    "application/vnd.oci.image.config.v1+json",
    "application/vnd.docker.container.image.v1+json",
}
LAYER_TYPES = {
    "application/vnd.oci.image.layer.v1.tar",
    "application/vnd.oci.image.layer.v1.tar+gzip",
    "application/vnd.oci.image.layer.v1.tar+zstd",
    "application/vnd.docker.image.rootfs.diff.tar.gzip",
}
METADATA_LIMIT = 1024 * 1024
LAYER_LIMIT = 128
DEFAULT_BYTE_LIMIT = 2 * 1024**3
DIGEST = re.compile(r"sha256:([a-f0-9]{64})")
COMMIT = re.compile(r"[a-f0-9]{40}")


class InvalidHandoff(ValueError):
    pass


def require(condition, message):
    if not condition:
        raise InvalidHandoff(message)


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, "duplicate JSON key")
        result[key] = value
    return result


def json_object(payload):
    def invalid_constant(_):
        raise InvalidHandoff("non-JSON numeric constant")

    obj = json.loads(payload, object_pairs_hook=unique_object, parse_constant=invalid_constant)
    require(isinstance(obj, dict), "expected a JSON object")
    return obj


@contextmanager
def opened_dir(path, parent=None):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
    try:
        yield fd
    finally:
        os.close(fd)


@contextmanager
def opened_file(path, parent=None):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
    with os.fdopen(fd, "rb") as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1, "not a private regular file")
        yield stream, info.st_size


def metadata(path, parent=None):
    with opened_file(path, parent) as (stream, size):
        require(0 < size <= METADATA_LIMIT, "invalid metadata size")
        payload = stream.read(METADATA_LIMIT + 1)
        require(len(payload) == size, "metadata changed during verification")
        return json_object(payload)


def require_entries(directory, expected):
    remaining = set(expected)
    with os.scandir(directory) as entries:
        for entry in entries:
            require(entry.name in remaining, "unexpected layout entry")
            remaining.remove(entry.name)
    require(not remaining, "missing layout entry")


def descriptor(obj, types):
    require(isinstance(obj, dict), "invalid descriptor")
    require(obj.get("mediaType") in types, "unexpected descriptor type")
    require(not ({"urls", "data"} & obj.keys()), "external or embedded blob references are not allowed")
    value = obj.get("digest")
    match = DIGEST.fullmatch(value) if isinstance(value, str) else None
    require(match, "invalid blob digest")
    size = obj.get("size")
    require(type(size) is int and size > 0, "invalid descriptor size")
    return match.group(1), size


def blob(directory, entry, parse_json=False):
    name, size = entry
    with opened_file(name, directory) as (stream, actual_size):
        require(actual_size == size, "blob size mismatch")
        if parse_json:
            require(size <= METADATA_LIMIT, "invalid blob metadata size")
            payload = stream.read(METADATA_LIMIT + 1)
            require(len(payload) == size, "blob changed during verification")
            checksum = hashlib.sha256(payload)
        else:
            checksum = hashlib.sha256()
            left = size
            while left:
                chunk = stream.read(min(left, 256 * 1024))
                require(chunk, "blob truncated during verification")
                checksum.update(chunk)
                left -= len(chunk)
            require(not stream.read(1), "blob grew during verification")
        require(checksum.hexdigest() == name, "blob digest mismatch")
    return json_object(payload) if parse_json else None


def verify(layout, handoff, image, backend, ui, php_base, byte_limit=DEFAULT_BYTE_LIMIT):
    image_prefix = REPOSITORY + "@"
    require(image.startswith(image_prefix) and DIGEST.fullmatch(image[len(image_prefix):]),
            "invalid approved image reference")
    require(COMMIT.fullmatch(backend) and COMMIT.fullmatch(ui) and DIGEST.fullmatch(php_base),
            "invalid approved release pins")
    receipt = metadata(handoff)
    require(type(receipt.get("version")) is int and receipt == {
        "version": 1, "image": image, "backend": backend, "ui": ui, "php_base": php_base,
    }, "handoff does not match independently approved release pins")

    with opened_dir(layout) as root, opened_dir("blobs", root) as blobs, opened_dir("sha256", blobs) as sha:
        require_entries(root, {"oci-layout", "index.json", "blobs"})
        require_entries(blobs, {"sha256"})
        require(metadata("oci-layout", root) == {"imageLayoutVersion": "1.0.0"}, "invalid OCI layout")
        index = metadata("index.json", root)
        entries = index.get("manifests")
        require(index.get("schemaVersion") == 2 and isinstance(entries, list) and len(entries) == 1,
                "expected exactly one image manifest")
        manifest_entry = descriptor(entries[0], MANIFEST_TYPES)
        require(image == image_prefix + "sha256:" + manifest_entry[0], "approved manifest digest mismatch")
        manifest = blob(sha, manifest_entry, parse_json=True)
        require(manifest.get("schemaVersion") == 2
                and manifest.get("mediaType") == entries[0]["mediaType"]
                and not ({"manifests", "subject", "artifactType"} & manifest.keys()),
                "expected the original single-platform image manifest")
        config_entry = descriptor(manifest.get("config"), CONFIG_TYPES)
        layers = manifest.get("layers")
        require(isinstance(layers, list) and 0 < len(layers) <= LAYER_LIMIT, "invalid layer count")
        layer_entries = [descriptor(layer, LAYER_TYPES) for layer in layers]
        require(sum(size for _, size in [manifest_entry, config_entry, *layer_entries]) <= byte_limit,
                "layout exceeds approved byte limit")
        require_entries(sha, {name for name, _ in [manifest_entry, config_entry, *layer_entries]})
        config = blob(sha, config_entry, parse_json=True)
        require(config.get("os") == "linux" and config.get("architecture") == "amd64",
                "unexpected image platform")
        image_config = config.get("config")
        labels = image_config.get("Labels") if isinstance(image_config, dict) else None
        expected_labels = {
            "org.opencontainers.image.source": "https://github.com/IntegrateCore/invoiceninja",
            "org.opencontainers.image.revision": backend,
            "net.integratecore.ui.revision": ui,
            "org.opencontainers.image.base.digest": php_base,
        }
        require(isinstance(labels, dict) and all(labels.get(k) == v for k, v in expected_labels.items()),
                "image provenance labels do not match approved pins")
        for entry in layer_entries:
            blob(sha, entry)
        return "sha256:" + config_entry[0]


def accept_local(source, inbox, handoff, image, backend, ui, php_base, byte_limit):
    """Exercise the sender and inbox boundaries over local files, not a transport."""
    require(type(byte_limit) is int and 0 < byte_limit <= DEFAULT_BYTE_LIMIT, "invalid inbox byte limit")
    pins = (handoff, image, backend, ui, php_base)
    sender_config = verify(source, *pins, byte_limit=byte_limit)
    inbox = Path(inbox)
    with opened_dir(inbox) as inbox_fd:
        info = os.fstat(inbox_fd)
        require(info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o700,
                "inbox must be owned by this process and mode 0700")
        fcntl.flock(inbox_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        require_entries(inbox_fd, set())
        with tempfile.TemporaryDirectory(prefix=".incoming-", dir=inbox) as scratch:
            layout = Path(scratch) / "layout"
            target = layout / "blobs" / "sha256"
            target.mkdir(parents=True)
            remaining = byte_limit + 2 * METADATA_LIMIT

            def copy_file(directory, name, destination, limit):
                nonlocal remaining
                with opened_file(name, directory) as (source_file, size):
                    require(0 < size <= min(limit, remaining), "inbox byte limit exceeded")
                    with destination.open("xb") as output:
                        left = size
                        while left:
                            chunk = source_file.read(min(left, 256 * 1024))
                            require(chunk, "source truncated during handoff")
                            output.write(chunk)
                            left -= len(chunk)
                        require(not source_file.read(1), "source grew during handoff")
                    remaining -= size
                    destination.chmod(0o400)

            with opened_dir(source) as root, opened_dir("blobs", root) as blobs, opened_dir("sha256", blobs) as sha:
                for name in ("oci-layout", "index.json"):
                    copy_file(root, name, layout / name, METADATA_LIMIT)
                with os.scandir(sha) as entries:
                    for count, entry in enumerate(entries, 1):
                        require(count <= LAYER_LIMIT + 2 and DIGEST.fullmatch("sha256:" + entry.name),
                                "unexpected sender blob")
                        copy_file(sha, entry.name, target / entry.name, byte_limit)

            recipient_config = verify(layout, *pins, byte_limit=byte_limit)
            require(recipient_config == sender_config, "sender and inbox config mismatch")
            layout.rename(inbox / "sealed")
    return recipient_config


def check_runtime_evidence(inspect_payload, image, config, manifest_bytes, config_bytes):
    """Verify locally collected Docker/containerd evidence; no import or execution."""
    repo, separator, digest = image.partition("@")
    require(repo == REPOSITORY and separator and DIGEST.fullmatch(digest),
            "runtime evidence needs an approved digest reference")
    require(DIGEST.fullmatch(config), "runtime evidence needs a verified config digest")
    items = json.loads(inspect_payload, object_pairs_hook=unique_object)
    require(isinstance(items, list) and len(items) == 1 and isinstance(items[0], dict),
            "expected exactly one locally inspected image")
    item = items[0]
    digests = item.get("RepoDigests")
    runtime_descriptor = item.get("Descriptor")
    require(isinstance(digests, list) and image in digests and item.get("Id") == digest
            and isinstance(runtime_descriptor, dict) and runtime_descriptor.get("digest") == digest
            and item.get("Os") == "linux" and item.get("Architecture") == "amd64",
            "runtime does not resolve the approved repository manifest digest")
    for data, expected in ((manifest_bytes, digest), (config_bytes, config)):
        require(0 < len(data) <= METADATA_LIMIT and "sha256:" + hashlib.sha256(data).hexdigest() == expected,
                "runtime content digest mismatch")
    manifest = json_object(manifest_bytes)
    require(descriptor(manifest.get("config"), CONFIG_TYPES)[0] == DIGEST.fullmatch(config).group(1),
            "runtime manifest does not reference the verified config")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("layout")
    parser.add_argument("handoff", help="independently obtained trusted release handoff")
    for option in ("image", "backend", "ui", "php-base"):
        parser.add_argument("--" + option, required=True)
    args = parser.parse_args()
    try:
        config = verify(args.layout, args.handoff, args.image, args.backend, args.ui, args.php_base)
    except InvalidHandoff as error:
        sys.exit(f"OCI handoff rejected: {error}")
    except (OSError, ValueError, TypeError, RecursionError):
        sys.exit("OCI handoff rejected: unreadable or malformed layout")
    print(f"OCI handoff verified for {args.image}; config {config}. Not imported or deployed.")


if __name__ == "__main__":
    main()

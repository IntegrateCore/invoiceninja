#!/usr/bin/env python3
import hashlib
import os
import subprocess
import sys
import tarfile
import json
import runpy
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

validator = runpy.run_path(str(Path(__file__).with_name("verify-oci-handoff.py")))
verify = validator["verify"]
verify_archive = validator["verify_archive"]
accept_local = validator["accept_local"]
check_runtime_evidence = validator["check_runtime_evidence"]
InvalidHandoff = validator["InvalidHandoff"]
BACKEND, UI, PHP_BASE = "a" * 40, "b" * 40, "sha256:" + "c" * 64
REPO = validator["REPOSITORY"]


class HandoffTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        base = Path(temp.name)
        self.layout = base / "layout"
        self.handoff = base / "handoff.json"
        self.blobs = self.layout / "blobs" / "sha256"
        self.blobs.mkdir(parents=True)
        self.put_json(self.layout / "oci-layout", {"imageLayoutVersion": "1.0.0"})
        self.layer = self.add_blob(b"synthetic layer", "application/vnd.oci.image.layer.v1.tar+gzip")
        self.config = self.add_blob(json.dumps({
            "os": "linux", "architecture": "amd64",
            "config": {"Labels": {
                "org.opencontainers.image.source": "https://github.com/IntegrateCore/invoiceninja",
                "org.opencontainers.image.revision": BACKEND,
                "net.integratecore.ui.revision": UI,
                "org.opencontainers.image.base.digest": PHP_BASE,
            }},
        }).encode(), "application/vnd.oci.image.config.v1+json")
        manifest_type = "application/vnd.oci.image.manifest.v1+json"
        self.manifest = self.add_blob(json.dumps({
            "schemaVersion": 2, "mediaType": manifest_type,
            "config": self.config, "layers": [self.layer],
        }, indent=2).encode(), manifest_type)
        self.put_index(self.manifest)
        self.image = REPO + "@" + self.manifest["digest"]
        self.put_json(self.handoff, {
            "version": 1, "image": self.image, "backend": BACKEND, "ui": UI, "php_base": PHP_BASE,
        })

    @staticmethod
    def put_json(path, value):
        path.write_text(json.dumps(value), encoding="utf-8")

    def put_index(self, entry):
        self.put_json(self.layout / "index.json", {"schemaVersion": 2, "manifests": [entry]})

    def add_blob(self, data, media_type):
        sha = hashlib.sha256(data).hexdigest()
        (self.blobs / sha).write_bytes(data)
        return {"mediaType": media_type, "digest": "sha256:" + sha, "size": len(data)}

    def blob_path(self, entry):
        return self.blobs / entry["digest"].split(":")[1]

    def check(self, **kwargs):
        return verify(self.layout, self.handoff, self.image, BACKEND, UI, PHP_BASE, **kwargs)

    def test_valid(self):
        self.assertEqual(self.check(), self.config["digest"])

    def test_corrupt_layer_same_size(self):
        self.blob_path(self.layer).write_bytes(b"x" * self.layer["size"])
        with self.assertRaisesRegex(InvalidHandoff, "blob digest mismatch"):
            self.check()

    def test_corrupt_config_same_size(self):
        self.blob_path(self.config).write_bytes(b"x" * self.config["size"])
        with self.assertRaisesRegex(InvalidHandoff, "blob digest mismatch"):
            self.check()

    def test_corrupt_registry_manifest_same_size(self):
        self.blob_path(self.manifest).write_bytes(b"x" * self.manifest["size"])
        with self.assertRaisesRegex(InvalidHandoff, "blob digest mismatch"):
            self.check()

    def test_different_manifest_in_index(self):
        self.put_index(dict(self.manifest, digest="sha256:" + "d" * 64))
        with self.assertRaisesRegex(InvalidHandoff, "approved manifest digest mismatch"):
            self.check()

    def test_path_traversal_digest(self):
        self.put_index(dict(self.manifest, digest="sha256:../../handoff.json"))
        with self.assertRaisesRegex(InvalidHandoff, "invalid blob digest"):
            self.check()

    def test_symlink_escape(self):
        layer = self.blob_path(self.layer)
        outside = self.handoff.parent / "outside"
        layer.rename(outside)
        layer.symlink_to("../../../" + outside.name)
        with self.assertRaises(OSError):
            self.check()

    def test_handoff_pin_mismatch(self):
        receipt = json.loads(self.handoff.read_text())
        self.put_json(self.handoff, dict(receipt, ui="d" * 40))
        with self.assertRaisesRegex(InvalidHandoff, "approved release pins"):
            self.check()

    def test_excess_byte_limit(self):
        with self.assertRaisesRegex(InvalidHandoff, "byte limit"):
            self.check(byte_limit=1)

    def test_extra_blob(self):
        (self.blobs / "unrelated").touch()
        with self.assertRaisesRegex(InvalidHandoff, "unexpected layout entry"):
            self.check()

    def test_external_blob_location(self):
        self.put_index(dict(self.manifest, urls=["https://example.invalid/blob"]))
        with self.assertRaisesRegex(InvalidHandoff, "external or embedded"):
            self.check()

    def test_truncated_layer(self):
        self.blob_path(self.layer).write_bytes(b"x")
        with self.assertRaisesRegex(InvalidHandoff, "blob size mismatch"):
            self.check()

    def archive(self, extra=None, format=tarfile.USTAR_FORMAT):
        target = self.handoff.parent / "sealed.oci.tar"
        with tarfile.open(target, "w", format=format) as tar:
            for path in sorted(self.layout.rglob("*")):
                tar.add(path, arcname=str(path.relative_to(self.layout)), recursive=False)
            if extra is not None:
                tar.addfile(extra)
        return target

    def check_archive(self, archive, checksum=None):
        digest = checksum or hashlib.sha256(archive.read_bytes()).hexdigest()
        return verify_archive(archive, digest, self.handoff, self.image, BACKEND, UI, PHP_BASE)

    def test_archive_cli_checks_exact_bytes_and_cleans_scratch(self):
        archive = self.archive()
        scratch = self.handoff.parent / "scratch"
        scratch.mkdir()
        result = subprocess.run(
            [sys.executable, str(Path(__file__).with_name("verify-oci-handoff.py")),
             str(archive), str(self.handoff), "--archive-sha256", hashlib.sha256(archive.read_bytes()).hexdigest(),
             "--image", self.image, "--backend", BACKEND, "--ui", UI, "--php-base", PHP_BASE],
            env=dict(os.environ, TMPDIR=str(scratch)), capture_output=True, text=True, check=True,
        )
        self.assertIn(self.config["digest"], result.stdout)
        self.assertEqual(list(scratch.iterdir()), [])

    def test_archive_rejects_wrong_checksum_and_corrupt_blob(self):
        with self.assertRaisesRegex(InvalidHandoff, "archive checksum mismatch"):
            self.check_archive(self.archive(), "0" * 64)
        self.blob_path(self.layer).write_bytes(b"x" * self.layer["size"])
        with self.assertRaisesRegex(InvalidHandoff, "blob digest mismatch"):
            self.check_archive(self.archive())

    def test_archive_rejects_traversal_duplicates_and_links(self):
        link = tarfile.TarInfo("blobs/sha256/" + "a" * 64)
        link.type = tarfile.SYMTYPE
        link.linkname = "../../outside"
        for entry, reason in ((tarfile.TarInfo("../escape"), "unexpected archive file"),
                              (tarfile.TarInfo("oci-layout"), "duplicate"),
                              (link, "ordinary USTAR")):
            with self.subTest(name=entry.name):
                with self.assertRaisesRegex(InvalidHandoff, reason):
                    self.check_archive(self.archive(entry))

    def test_archive_rejects_pax_and_trailing_data(self):
        with self.assertRaisesRegex(InvalidHandoff, "ordinary USTAR"):
            self.check_archive(self.archive(format=tarfile.PAX_FORMAT))
        archive = self.archive()
        with archive.open("ab") as stream:
            stream.write(b"x" * 512)
        with self.assertRaisesRegex(InvalidHandoff, "trailing archive data"):
            self.check_archive(archive)

    def create_inbox(self, mode=0o700):
        inbox = self.handoff.parent / "inbox"
        inbox.mkdir()
        inbox.chmod(mode)
        return inbox

    def accept(self, inbox, limit):
        return accept_local(self.layout, inbox, self.handoff, self.image, BACKEND, UI, PHP_BASE, limit)

    def runtime_payload(self, **changes):
        item = {"Id": self.manifest["digest"], "RepoDigests": [self.image],
                "Descriptor": {"digest": self.manifest["digest"]},
                "Os": "linux", "Architecture": "amd64"}
        return json.dumps([dict(item, **changes)])

    def test_local_handoff_to_runtime_evidence(self):
        example_path = (Path(__file__).resolve().parents[2] /
                        "docs/examples/integratecore-oci-handoff.disabled.json")
        config = json.loads(example_path.read_text(encoding="utf-8"))
        self.assertIs(config["enabled"], False)
        self.assertIsNone(config["inbox_example"]["listen"])
        self.assertIs(config["inbox_example"]["auto_import"], False)
        inbox = self.create_inbox()
        verified = self.accept(inbox, config["inbox_example"]["max_blob_bytes"])
        self.assertEqual({p.name for p in inbox.iterdir()}, {"sealed"})
        self.assertFalse((inbox / "sealed" / self.handoff.name).exists())
        self.assertEqual(verify(inbox / "sealed", self.handoff, self.image,
                                BACKEND, UI, PHP_BASE), verified)
        check_runtime_evidence(self.runtime_payload(), self.image, verified,
                               self.blob_path(self.manifest).read_bytes(),
                               self.blob_path(self.config).read_bytes())
        with self.assertRaisesRegex(InvalidHandoff, "unexpected layout entry"):
            self.accept(inbox, config["inbox_example"]["max_blob_bytes"])

    def test_small_inbox_rejects_without_sealing(self):
        inbox = self.create_inbox()
        with self.assertRaisesRegex(InvalidHandoff, "byte limit"):
            self.accept(inbox, 10)
        self.assertEqual(list(inbox.iterdir()), [])

    def test_open_inbox_rejected(self):
        with self.assertRaisesRegex(InvalidHandoff, "mode 0700"):
            self.accept(self.create_inbox(mode=0o755), 4096)

    def test_corruption_after_sender_check_leaves_inbox_empty(self):
        def verify_then_corrupt(layout, *args, **kwargs):
            result = verify(layout, *args, **kwargs)
            if layout == self.layout:
                self.blob_path(self.layer).write_bytes(b"x" * self.layer["size"])
            return result

        inbox = self.create_inbox()
        with patch.dict(accept_local.__globals__, verify=verify_then_corrupt):
            with self.assertRaisesRegex(InvalidHandoff, "blob digest mismatch"):
                self.accept(inbox, 4096)
        self.assertEqual(list(inbox.iterdir()), [])

    def test_runtime_rejects_tag_only_or_wrong_manifest(self):
        for changes in ({"RepoDigests": []},
                        {"RepoDigests": [REPO + ":candidate"]},
                        {"Id": self.config["digest"]},
                        {"Descriptor": {"digest": "sha256:" + "d" * 64}}):
            with self.subTest(changes=changes):
                with self.assertRaisesRegex(InvalidHandoff, "does not resolve"):
                    check_runtime_evidence(self.runtime_payload(**changes), self.image, self.config["digest"],
                                           self.blob_path(self.manifest).read_bytes(),
                                           self.blob_path(self.config).read_bytes())

    def test_runtime_rejects_mismatched_store_bytes(self):
        manifest = self.blob_path(self.manifest).read_bytes()
        config = self.blob_path(self.config).read_bytes()
        for observed in ((manifest + b" ", config), (manifest, config + b" ")):
            with self.subTest(observed=observed):
                with self.assertRaisesRegex(InvalidHandoff, "runtime content digest mismatch"):
                    check_runtime_evidence(self.runtime_payload(), self.image, self.config["digest"], *observed)


if __name__ == "__main__":
    unittest.main()

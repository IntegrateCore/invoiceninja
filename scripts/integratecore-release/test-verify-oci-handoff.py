#!/usr/bin/env python3
import hashlib
import json
import runpy
import tempfile
import unittest
from pathlib import Path

validator = runpy.run_path(str(Path(__file__).with_name("verify-oci-handoff.py")))
verify = validator["verify"]
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


if __name__ == "__main__":
    unittest.main()

import asyncio
import hashlib
from pathlib import Path
import runpy
import tempfile
import unittest

module = runpy.run_path(str(Path(__file__).with_name("oci-inbox.py")))
Inbox = module["Inbox"]
DATA = b"synthetic image bytes"
HASH = hashlib.sha256(DATA).hexdigest()


async def trusted(peer):
    if peer != "actual-socket-peer":
        return [], []
    return [module["CAPABILITY"]], [module["SENDER_TAG"]]


class InboxTests(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.path = Path(directory.name)

    async def send(self, inbox, **kwargs):
        offset = 0

        async def read(limit):
            nonlocal offset
            result = DATA[offset:offset + limit]
            offset += len(result)
            return result

        request = dict(peer_address="actual-socket-peer", method="PUT", path="/v1/image",
                       length=len(DATA), read=read)
        return await inbox.receive(**(request | kwargs))

    async def test_disabled_and_untrusted_never_read(self):
        async def forbidden(_):
            self.fail("body read before authorization")

        self.assertEqual(await self.send(Inbox(self.path, trusted, HASH), read=forbidden), 503)
        inbox = Inbox(self.path, trusted, HASH, enabled=True)
        self.assertEqual(await self.send(inbox, peer_address="other-peer", read=forbidden), 403)
        self.assertEqual(await self.send(inbox, length=0, read=forbidden), 413)
        self.assertEqual(await self.send(inbox, path="/../image", read=forbidden), 404)
        self.assertEqual(list(self.path.iterdir()), [])

    async def test_requires_capability_and_exact_tag(self):
        for caps, tags in (([], [module["SENDER_TAG"]]),
                           ([module["CAPABILITY"]], [module["SENDER_TAG"], "tag:other"])):
            async def wrong(_):
                return caps, tags
            self.assertEqual(await self.send(Inbox(self.path, wrong, HASH, enabled=True)), 403)

    async def test_seals_once(self):
        inbox = Inbox(self.path, trusted, HASH, enabled=True)
        self.assertEqual(await self.send(inbox), 201)
        self.assertEqual(await self.send(inbox), 409)
        self.assertEqual((self.path / "sealed.oci.tar").read_bytes(), DATA)
        self.assertEqual(len(list(self.path.iterdir())), 1)

    async def test_wrong_digest_and_interruption_clean_up(self):
        inbox = Inbox(self.path, trusted, "0" * 64, enabled=True)
        self.assertEqual(await self.send(inbox), 422)

        async def disconnected(_):
            return b""

        self.assertEqual(await self.send(inbox, read=disconnected), 400)
        self.assertEqual(list(self.path.iterdir()), [])

    async def test_concurrency_and_timeout(self):
        entered = asyncio.Event()

        async def stalled(_):
            entered.set()
            await asyncio.Future()

        inbox = Inbox(self.path, trusted, HASH, enabled=True, seconds=0.05)
        first = asyncio.create_task(self.send(inbox, read=stalled))
        await entered.wait()
        self.assertEqual(await self.send(inbox), 429)
        self.assertEqual(await first, 408)
        self.assertEqual(list(self.path.iterdir()), [])


if __name__ == "__main__":
    unittest.main()

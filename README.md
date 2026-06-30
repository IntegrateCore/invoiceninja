# Invoice Ninja

This fork is set up for a simple Docker workflow:

1. Clone or pull this repo locally on your MacBook.
2. Make your code or branding changes.
3. Test locally.
4. Build the production image for `linux/amd64`.
5. Push the image to GitHub Container Registry.
6. Push the source changes to GitHub.
7. Hetzner pulls the image and restarts the containers.

## Build

```bash
docker buildx build \
  --platform linux/amd64 \
  -t ghcr.io/integratecore/invoiceninja:latest \
  --push .
```

## Deploy on Hetzner

```bash
cd /srv/invoiceninja
docker compose pull
docker compose up -d
docker image prune -f
```

## Notes

- Keep `.env`, storage files, database data, and backups on the server only.
- The production container runs PHP-FPM, Nginx, the queue worker, and the scheduler.
- Production uses `ghcr.io/integratecore/invoiceninja:latest`.

# Invoice Ninja

This repo is set up for one source repo and one production image:

1. Clone or pull this repo locally on your MacBook from `main`.
2. Make your code or branding changes.
3. Test locally.
4. Build the Docker image on your MacBook for `linux/amd64`.
5. Push the image to GitHub Container Registry.
6. Push the source changes to GitHub on `main`.
7. Hetzner pulls the image and restarts the containers.

## Build the image

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
- Production runs PHP-FPM, Nginx, the queue worker, and the scheduler inside the image.
- Production uses `ghcr.io/integratecore/invoiceninja:latest`.

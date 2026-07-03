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
docker compose down --remove-orphans || true
docker system prune -af --volumes
docker pull ghcr.io/integratecore/invoiceninja:latest
docker compose up -d
docker system prune -af --volumes
```

## Notes

- Keep `.env`, storage files, database data, and backups on the server only.
- Production runs PHP-FPM, Nginx, the queue worker, and the scheduler inside the image.
- Production uses `ghcr.io/integratecore/invoiceninja:latest`.

## Dev branch

The `dev` branch is for the on-prem test server on `brates-server`.

- Pushing to `dev` builds and publishes `ghcr.io/integratecore/invoiceninja:dev`.
- `brates-server` polls the `dev` branch every 5 minutes, pulls the new image when it changes, and restarts the local dev stack.
- Dev access stays on the local network and does not touch production.

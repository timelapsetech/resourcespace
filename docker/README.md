# Deploy ResourceSpace with Docker (Mac)

This stack runs **this fork** (custom plugins + StaticSync hook) in Docker Desktop on a Mac. For what differs from upstream ResourceSpace, see the root [README.md](../README.md).

- **Originals** (NAS or other stills tree) bind-mounted read-only at `/data/originals`
- **Filestore** (proxies, manifests, uploads) bind-mounted wherever you choose (e.g. external drive)
- **MariaDB** in Compose
- **Remote Ollama** via `$ollama_endpoint` on another LAN machine

## One-time host setup

1. Install [Docker Desktop](https://www.docker.com/products/docker-desktop/) for Mac.
2. Mount your originals share so the host path you will put in `.env` exists (example: `/Volumes/YourNAS/YourShare/Originals`).
3. Attach the external drive you want for proxies/files (optional but recommended).
4. **Docker Desktop → Settings → Resources → File Sharing** — allow:
   - The volume that holds your originals (e.g. `/Volumes/YourNAS`)
   - Your external drive volume (e.g. `/Volumes/YourExternalDrive`)

### NAS / SMB note (important on Mac)

Docker Desktop often **hangs starting a container** when bind-mounting an **SMB** share (Finder “Connect to Server” mounts under `/Volumes/...`). Prefer one of:

1. **NFS mount** of the same NAS share (more reliable with Docker), or  
2. Keep originals on a **local/external APFS/HFS disk** and sync from the NAS outside Docker, or  
3. If you must use SMB: add the volume in File Sharing, then test with  
   `docker run --rm -v "/Volumes/YourNAS/YourShare/Originals:/data:ro" ubuntu:24.04 ls /data`  
   If that command hangs, fix the mount method before `compose up`.

Quote paths with spaces in `.env`:

```bash
ORIGINALS_HOST_PATH="/Volumes/YourNAS/Your Share/Originals"
```

## Bootstrap on a new Mac

```bash
git clone <this-repo-url> resourcespace
cd resourcespace
chmod +x docker/bootstrap.sh
./docker/bootstrap.sh
```

Edit the generated files:

| File | What to set |
|------|-------------|
| `.env` | `FILESTORE_HOST_PATH`, `ORIGINALS_HOST_PATH`, optional `MYSQL_DATA_HOST_PATH`, `RS_HTTP_PORT` |
| `docker/db.env` | Strong `MYSQL_*` passwords |
| `docker/config.php` | Same DB password as `docker/db.env`, scramble keys, `$baseurl`, `$ollama_endpoint` / `$ollama_model` |

Example `.env` for an external drive + NAS:

```bash
RS_HTTP_PORT=8080
FILESTORE_HOST_PATH=/Volumes/YourExternalDrive/resourcespace/filestore
ORIGINALS_HOST_PATH="/Volumes/YourNAS/Your Share/Originals"
SYNCDIR_HOST_PATH=./syncdir
# MYSQL_DATA_HOST_PATH=/Volumes/YourExternalDrive/resourcespace/mysql
```

Create the filestore folder if needed:

```bash
mkdir -p "$FILESTORE_HOST_PATH"
```

Generate scramble keys (bootstrap may do this automatically):

```bash
openssl rand -hex 16   # paste into $scramble_key
openssl rand -hex 16   # paste into $api_scramble_key
```

Match `$mysql_password` in `docker/config.php` to `MYSQL_PASSWORD` in `docker/db.env`.

Native (non-Docker) Mac development can keep using `include/config.php` separately; Compose mounts `docker/config.php` into the container only.

## Start

```bash
docker compose up --build -d
docker compose ps
```

Open `http://localhost:8080` (or your `RS_HTTP_PORT`).

### Nginx Proxy Manager (`proxied` network)

Compose joins the existing external Docker network named `proxied` (same network NPM uses). MariaDB stays on the internal `backend` network only.

1. Ensure the network exists: `docker network ls | grep proxied` (create it if NPM hasn’t already).
2. In NPM, add a proxy host → forward to `resourcespace:80` (container name + port 80).
3. Set `$baseurl` in `docker/config.php` to the public URL NPM serves (e.g. `https://dam.example.com`) — no trailing slash.
4. Optionally comment out the `ports:` mapping in `docker-compose.yml` once NPM is the only entry point.

### First-run web setup

If the database is empty, ResourceSpace shows the setup wizard:

- **Database IP / server:** `mariadb` (not `localhost`)
- **MySQL binary path:** leave blank
- Use the same DB name/user/password as `docker/db.env`

If you already filled `docker/config.php` correctly, setup may only ask you to create the admin account.

### After login

1. **Admin → System → Manage plugins** — activate Image Sequence, openai_gpt (if using AI), and any others you need (`aria_home`, `chroma_theme`, etc. may already be listed in config).
2. Open **Image Sequence** plugin setup once (creates resource type + fields).
3. Confirm mounts:

```bash
docker compose exec resourcespace ls /data/originals
docker compose exec resourcespace ls /var/www/html/filestore
docker compose exec resourcespace ls /data/syncdir
```

4. Manual sync smoke test:

```bash
docker compose exec resourcespace php plugins/image_sequence/pages/tools/image_sequence_sync.php
```

Cron inside the container also runs:

- `offline_jobs.php` every 5 minutes (proxies, AI jobs)
- `image_sequence_sync.php` hourly
- hitcount copy daily

## Remote Ollama (LAN)

Ollama is **not** part of this Compose file. On the AI machine:

```bash
export OLLAMA_HOST=0.0.0.0:11434
ollama pull qwen3.5:4b
```

In `docker/config.php`:

```php
$ollama_endpoint = 'http://192.168.x.x:11434/api/chat';  // your AI host LAN IP
$ollama_model = 'qwen3.5:4b';
```

Verify from the app container:

```bash
docker compose exec resourcespace curl -s http://192.168.x.x:11434/api/tags
```

## Path map

| Role | Inside container | Host (`.env`) |
|------|------------------|---------------|
| Filestore | `/var/www/html/filestore` | `FILESTORE_HOST_PATH` |
| Originals | `/data/originals` | `ORIGINALS_HOST_PATH` |
| Local syncdir | `/data/syncdir` | `SYNCDIR_HOST_PATH` |
| MariaDB data | `/var/lib/mysql` | named volume, or `MYSQL_DATA_HOST_PATH` |
| PHP config | `/var/www/html/include/config.php` | `./docker/config.php` |

## Backups

- **Originals:** stay on the NAS — no backup via Docker.
- **Filestore:** rsync/copy the host folder `FILESTORE_HOST_PATH`.
- **Database:**

```bash
docker compose exec mariadb mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" resourcespace \
  > "resourcespace-$(date +%Y%m%d).sql"
```

## Useful commands

```bash
docker compose logs -f resourcespace
docker compose exec resourcespace php pages/tools/offline_jobs.php
docker compose down
docker compose down -v   # also deletes named MariaDB volume
```

## Fresh install note

Assumes a **new** database and re-ingest from the originals tree.

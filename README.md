# ResourceSpace (Time Lapse Technologies fork)

A customized [ResourceSpace](https://www.resourcespace.com/) Digital Asset Management system for **time-lapse still sequences**, cinematic browsing, curated ratings, and optional **local AI** (Ollama) metadata.

Upstream base: ResourceSpace **SVN Trunk** (`include/version.php`).

## Quick start (Docker on Mac)

See **[docker/README.md](docker/README.md)** for the full deploy guide.

```bash
chmod +x docker/bootstrap.sh
./docker/bootstrap.sh
# Edit .env, docker/db.env, docker/config.php
docker compose up --build -d
```

---

## Differences from upstream ResourceSpace

This fork keeps the standard Montala plugin pack and adds Time Lapse Technologies–specific workflows. Summary:

| Area | What changed |
|------|----------------|
| **Custom plugins** | Image Sequence, Aria Home, Chroma Theme, Asset Rating |
| **Stock plugin forks** | `openai_gpt` enhanced for Ollama + field locks + sequence stills |
| **Core patches** | StaticSync skip hook, secondary scan-root paths, search-card hooks |
| **Deploy** | Docker Compose for Mac (NAS originals + external filestore + remote Ollama) |

### Custom plugins

#### Image Sequence (`plugins/image_sequence`)
Ingest folders of sequentially numbered stills as first-class **Image Sequence** resources.

- One folder = one sequence on ingest (EXIF first/last + cadence samples; optional later shot-split)
- Frames stay on disk under **read-only** scan roots; manifests/proxies live in filestore
- CLI sync, web ZIP ingest, FFmpeg proxy video, Omakase frame-accurate player (in/out, representative frame)
- Same NLE controls on standard video resources; search-card hover scrub; ZIP download of frames
- **Requires** the core `staticsync_skip_file` hook (see below)

Details: [`plugins/image_sequence/readme.txt`](plugins/image_sequence/readme.txt)

#### Aria Home (`plugins/aria_home`)
Replaces the default home/dashboard with a cinematic browse page:

- Featured hero carousel (image + video)
- Curated top-bar tags and multi-section facet sidebar
- Paginated asset grid (works with Chroma Theme cards)

#### Chroma Theme (`plugins/chroma_theme`)
Dark, image-forward DAM theme:

- Deep cinematic palette; overlay gallery cards; split metadata on resource view
- Defaults toward `xlthumbs` and dark appearance

#### Asset Rating (`plugins/asset_rating`)
Shared 0–5 star quality rating for curation:

- Synced to `resource.rating` for search sort (`$orderbyrating`)
- Stars on resource view and rating pills on search/home cards

### Modified stock plugin: `openai_gpt`

Montala’s plugin is retained with fork enhancements:

- Native **Ollama** `/api/chat` path (including vision/image parts) for LAN models
- Per-field **AI locks** so manual curation is not overwritten
- Keyword/list normalization tuned for local-model output
- Integration with Image Sequence representative stills (offline AI metadata jobs)

### Core patches (not possible via hooks alone)

| File | Change |
|------|--------|
| [`pages/tools/staticsync.php`](pages/tools/staticsync.php) | Calls `hook('staticsync_skip_file', …)` so claimed sequence frames are not re-imported. Patch also at `plugins/image_sequence/patches/staticsync_skip_file.patch` |
| [`include/file_functions.php`](include/file_functions.php) | Allows `$image_sequence_sync_roots` in `is_valid_rs_path()` (secondary / NAS roots) |
| [`pages/search_views/thumbs.php`](pages/search_views/thumbs.php) | Hook points for card pills / filetype labels; featured 2×2 card spans |
| [`pages/search_views/list.php`](pages/search_views/list.php) | Hook point for filetype labels |

All other bundled plugins under `plugins/` are the standard ResourceSpace pack unless noted above.

### Docker packaging

Added for portable Mac/server deploy (not in vanilla RS):

- `Dockerfile`, `docker-compose.yml`, `docker/bootstrap.sh`, `docker/entrypoint.sh`, `docker/crontab`
- Host paths via `.env` (`FILESTORE_HOST_PATH`, `ORIGINALS_HOST_PATH`, …)
- Cron: offline jobs every 5 minutes; image-sequence sync hourly
- Config examples under `docker/` (never commit live secrets)

---

## Configuration & secrets (public repo hygiene)

**Do not commit** machine-specific or secret files. These are gitignored:

| Path | Purpose |
|------|---------|
| `include/config.php` | Native/local PHP config (DB, scramble keys, paths) |
| `docker/config.php` | Docker-mounted PHP config |
| `docker/db.env` | MariaDB passwords |
| `.env` | Host bind-mount paths |
| `plugins/image_sequence/config/config.php` | Local sync-root overrides |
| `filestore/`, `syncdir/`, `data/` | Runtime media / cache |

Use the `*.example` templates and fill in values on each machine:

- [`.env.example`](.env.example)
- [`docker/config.php.example`](docker/config.php.example)
- [`docker/db.env.example`](docker/db.env.example)
- [`plugins/image_sequence/config/config.php.example`](plugins/image_sequence/config/config.php.example)

Generate scramble keys with `openssl rand -hex 16`. Never put real passwords, identifying LAN IPs, or personal home/NAS volume names into tracked example files.

If this repository was previously public or pushed with machine-specific paths in git history, rewrite history (e.g. `git filter-repo`) and force-push so remotes no longer retain those commits.

---

## License

ResourceSpace core: see [`license.txt`](license.txt) and [`documentation/licenses/`](documentation/licenses/).  
Custom plugins in this fork follow the same project licensing unless a plugin directory states otherwise.

# Metadata fields + AI (Ollama) on a fresh install

A new Docker database does **not** include the metadata field definitions or
AI prompts from your old test Mac. Ollama connectivity can live in
`docker/config.php`; field wiring lives in the **database**.

## What you had in testing

| Piece | Where it lived |
|-------|----------------|
| Ollama endpoint / model | `include/config.php` / `docker/config.php` |
| Per-field AI prompts + “Preview image” input | DB: `resource_type_field.openai_gpt_*` |
| AI locks (protect from overwrite) | DB: `resource_openai_gpt_lock` (created automatically when you edit a field) |
| Location / City / State | DB metadata fields (`location`, `city`, `state`) — Location was custom; City/State used by Aria Home |
| Image Sequence fields (`imgseq_*`) | Auto-created when you open Image Sequence setup |
| Aria Home facets | `plugins/aria_home/config/config.php` field refs (must match *your* new field IDs) |

## On the new Mac — do this once

### A. Config / plugins

1. In `docker/config.php`, ensure Ollama points at your AI host (not `127.0.0.1`):

```php
$openai_gpt_provider = 'ollama';
$openai_gpt_provider_override = 'ollama';
$ollama_endpoint = 'http://YOUR_AI_LAN_IP:11434/api/chat';
$ollama_model = 'qwen3.5:4b';
$ollama_model_override = 'qwen3.5:4b';
$offline_job_queue = true;
$plugins[] = 'openai_gpt';
$plugins[] = 'image_sequence';
// …plus aria_home, chroma_theme, etc.
```

2. Restart: `docker compose up -d`

3. Log in as admin → **System → Manage plugins**
   - Activate **openai_gpt** and **image_sequence** if not already active
4. Open each setup page once:
   - **openai_gpt** setup → confirm provider Ollama / model
   - **image_sequence** setup → creates `imgseq_*` fields
   - **aria_home** setup → after City/State fields exist (below)

### B. Create Location / City / State (if missing)

**Admin → System → Metadata fields** (or Resource types → fields):

| Shortname | Title | Type |
|-----------|-------|------|
| `location` | Location | Text box (single line) |
| `city` | City | Dynamic keywords |
| `state` | State | Dynamic keywords |

Assign them to the resource types you use (Photo, Image Sequence, Video as needed).

Then open **Aria Home setup** so City/State facets map to these fields (and US states get seeded).

### C. Wire AI on each field

For each field below: open the field edit page → set:

- **AI Processing Input** = *Image: Preview image* (value `-1`)
- **AI Processing Prompt** = (copy from your test system — see export below)

Fields that were AI-wired in testing:

- `title`, `caption`, `notes`
- `keywords`, `subject`, `recognised`
- `country`, `location`, `city`, `state`
- `accessibilityalttext`, `accessibilityextend`

### D. Confirm Ollama from the container

```bash
docker compose exec resourcespace curl -s http://YOUR_AI_LAN_IP:11434/api/tags
```

Set a representative frame on a sequence (or upload a photo) and wait for offline jobs (~5 min cron), or run:

```bash
docker compose exec resourcespace php pages/tools/offline_jobs.php
```

Locks appear automatically after you manually edit an AI-managed field (padlock / unlock on the edit form).

## Export prompts from this (old) Mac

On the test Mac where MySQL still has your config:

```bash
chmod +x docker/scripts/export_ai_field_config.sh
./docker/scripts/export_ai_field_config.sh > ai-fields.json
```

Copy `ai-fields.json` to the new Mac and use it as a checklist when pasting prompts into each field’s admin page.

Or dump related tables (advanced — watch for ref ID clashes on a partial DB):

```bash
docker exec rs-mysql mysqldump -uresourcespace -presourcespace resourcespace \
  resource_type_field node plugins \
  > rs-fields-$(date +%Y%m%d).sql
```

Prefer recreating by **shortname** + prompts over a blind full-table import into a fresh install.

## Quick troubleshooting

| Symptom | Check |
|---------|--------|
| No AI fields filling | Plugin active? Input + prompt set? Ollama reachable from container? `$offline_job_queue` true? |
| No Location/City/State | Fields not created yet (fresh DB) |
| No lock UI | Edit a field that has an AI prompt; lock is created on manual save |
| Aria Home empty facets | City/State field refs in aria_home config don’t match new field IDs — re-open Aria setup or edit `plugins/aria_home/config/config.php` |

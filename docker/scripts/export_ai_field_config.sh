#!/usr/bin/env bash
# Export AI-wired metadata field definitions from a running ResourceSpace DB
# so a fresh install can be reconfigured (match by field shortname, not ref).
#
# Usage (from this Mac / test DB):
#   ./docker/scripts/export_ai_field_config.sh > ai-fields.json
#
# On the new Mac, use Admin UI to create missing fields, then paste prompts
# from the JSON — or ask for an import helper if you prefer automated apply.

set -euo pipefail

MYSQL_CONTAINER="${MYSQL_CONTAINER:-rs-mysql}"
MYSQL_USER="${MYSQL_USER:-resourcespace}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:-resourcespace}"
MYSQL_DB="${MYSQL_DB:-resourcespace}"

docker exec "$MYSQL_CONTAINER" mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DB" -N -B -e "
SELECT JSON_ARRAYAGG(
  JSON_OBJECT(
    'name', name,
    'title', title,
    'type', type,
    'openai_gpt_input_field', openai_gpt_input_field,
    'openai_gpt_prompt', openai_gpt_prompt
  )
)
FROM resource_type_field
WHERE openai_gpt_prompt IS NOT NULL AND openai_gpt_prompt != '';
"

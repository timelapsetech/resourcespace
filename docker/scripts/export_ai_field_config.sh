#!/usr/bin/env bash
# Export AI-wired metadata field definitions from a running ResourceSpace
# instance (match by field shortname, not ref).
#
# Usage:
#   ./docker/scripts/export_ai_field_config.sh > ai-fields.json
#
# Uses the app container + PHP so prompts with quotes/newlines are escaped correctly.

set -euo pipefail

APP_CONTAINER="${APP_CONTAINER:-resourcespace}"

docker exec "$APP_CONTAINER" php -r '
include "/var/www/html/include/boot.php";
$rows = ps_query(
    "SELECT name, title, type, openai_gpt_input_field, openai_gpt_prompt
     FROM resource_type_field
     WHERE openai_gpt_prompt IS NOT NULL AND openai_gpt_prompt <> \"\"
     ORDER BY name"
);
$out = [];
foreach ($rows as $r) {
    $out[] = [
        "name" => $r["name"],
        "title" => $r["title"],
        "type" => (int) $r["type"],
        "openai_gpt_input_field" => (int) $r["openai_gpt_input_field"],
        "openai_gpt_prompt" => $r["openai_gpt_prompt"],
    ];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
'

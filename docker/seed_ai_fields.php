<?php
/**
 * Seed / repair TLT AI metadata fields per docker/AI_METADATA_SETUP.md
 *
 *   docker cp docker/seed_ai_fields.php resourcespace:/tmp/seed_ai_fields.php
 *   docker compose exec resourcespace php /tmp/seed_ai_fields.php
 */

include '/var/www/html/include/boot.php';
command_line_only();
setup_user(get_user(1));

include_plugin_config('openai_gpt');
include_once '/var/www/html/plugins/openai_gpt/include/openai_gpt_functions.php';
include_once '/var/www/html/plugins/aria_home/include/aria_home_functions.php';
include_once '/var/www/html/plugins/image_sequence/include/image_sequence_functions.php';

openai_gpt_ensure_lock_table();

if (!ps_value("SELECT inst_version AS value FROM plugins WHERE name=?", ['s', 'openai_gpt'], '')) {
    activate_plugin('openai_gpt');
    echo "activated openai_gpt\n";
}
if (!ps_value("SELECT inst_version AS value FROM plugins WHERE name=?", ['s', 'image_sequence'], '')) {
    activate_plugin('image_sequence');
    echo "activated image_sequence\n";
}

image_sequence_ensure_setup();
echo "image_sequence setup ok\n";

/**
 * Ensure a global metadata field exists; return its ref.
 */
function seed_ai_ensure_field(string $shortname, string $title, int $type): int
{
    $existing = (int) ps_value(
        'SELECT ref value FROM resource_type_field WHERE name = ?',
        ['s', $shortname],
        0,
        'schema'
    );
    if ($existing > 0) {
        $info = get_resource_type_field($existing);
        if (is_array($info) && ((int) ($info['type'] ?? -1) !== $type || (string) ($info['title'] ?? '') !== $title)) {
            ps_query(
                'UPDATE resource_type_field SET type = ?, title = ?, global = 1 WHERE ref = ?',
                ['i', $type, 's', $title, 'i', $existing],
                'schema'
            );
            clear_query_cache('schema');
        }
        echo "field exists {$shortname}={$existing}\n";
        return $existing;
    }

    $ref = (int) create_resource_type_field($title, 0, $type, $shortname, true);
    if ($ref <= 0) {
        throw new RuntimeException("Failed creating field {$shortname}");
    }
    ps_query('UPDATE resource_type_field SET global = 1 WHERE ref = ?', ['i', $ref], 'schema');
    clear_query_cache('schema');
    echo "field created {$shortname}={$ref}\n";
    return $ref;
}

function seed_ai_set_prompt(int $ref, string $prompt, int $input = -1): void
{
    ps_query(
        'UPDATE resource_type_field SET openai_gpt_prompt = ?, openai_gpt_input_field = ? WHERE ref = ?',
        ['s', $prompt, 'i', $input, 'i', $ref],
        'schema'
    );
    clear_query_cache('schema');
    $field = get_resource_type_field($ref);
    echo "AI set #{$ref} {$field['name']}\n";
}

// Doc: location = text; city/state = dynamic keywords
$location = seed_ai_ensure_field('location', 'Location', FIELD_TYPE_TEXT_BOX_SINGLE_LINE);
$city = seed_ai_ensure_field('city', 'City', FIELD_TYPE_DYNAMIC_KEYWORDS_LIST);
$state = seed_ai_ensure_field('state', 'State', FIELD_TYPE_DYNAMIC_KEYWORDS_LIST);

aria_home_ensure_setup();
// Force Aria Home to the fields we just ensured (fresh installs won't have refs 119/120).
$aria = get_plugin_config('aria_home') ?: [];
$aria['aria_home_city_field'] = $city;
$aria['aria_home_state_field'] = $state;
$aria['aria_home_country_field'] = 3;
$aria['aria_home_other_field'] = 1;
$sections = $aria['aria_home_facet_sections'] ?? [];
if (!is_array($sections)) {
    $sections = [];
}
$sections['city'] = array_merge(
    is_array($sections['city'] ?? null) ? $sections['city'] : [],
    ['label' => 'City', 'field' => $city, 'enabled' => true, 'limit' => 20]
);
$sections['state'] = array_merge(
    is_array($sections['state'] ?? null) ? $sections['state'] : [],
    ['label' => 'State', 'field' => $state, 'enabled' => true, 'limit' => 20]
);
$sections['country'] = array_merge(
    is_array($sections['country'] ?? null) ? $sections['country'] : [],
    ['label' => 'Country', 'field' => 3, 'enabled' => true, 'limit' => 16]
);
$aria['aria_home_facet_sections'] = $sections;
set_plugin_config('aria_home', $aria);
aria_home_seed_state_nodes($state);
echo "aria_home city={$city} state={$state}\n";

// Resolve core field refs by shortname (stable across installs).
$by_name = static function (string $name): int {
    return (int) ps_value(
        'SELECT ref value FROM resource_type_field WHERE name = ?',
        ['s', $name],
        0,
        'schema'
    );
};

$title = $by_name('title');
$caption = $by_name('caption');
$notes = $by_name('notes');
$keywords = $by_name('keywords');
$subject = $by_name('subject');
$recognised = $by_name('recognised');
$country = $by_name('country');
$alt = $by_name('accessibilityalttext');
$extend = $by_name('accessibilityextend');

seed_ai_set_prompt($title, 'Write a short, specific title for this time-lapse still or sequence frame. Max 12 words. No quotes.');
seed_ai_set_prompt($caption, 'Write a concise 1-2 sentence caption describing what is visually happening in this time-lapse still. Be concrete about subject, place cues, weather/light, and motion if obvious.');
seed_ai_set_prompt($notes, 'Write brief production or observational notes about this still for an internal DAM (1-2 sentences). Focus on useful context not already obvious from a short caption.');
seed_ai_set_prompt($keywords, 'List 5-12 short topical keywords as a JSON array (subjects, weather, time of day, setting). No sentences.');
seed_ai_set_prompt($subject, 'List subject keywords as a JSON array of short nouns/phrases describing main subjects. If none clear, reply with [].');
seed_ai_set_prompt($recognised, 'List clearly recognised named places, brands, or well-known entities as a JSON array of short names. If none, reply with [].');
seed_ai_set_prompt($country, 'Identify the country shown or strongly implied. Reply with a JSON array containing exactly one country name string, e.g. ["United States"], or [] if uncertain.');
seed_ai_set_prompt($location, 'Identify the specific place or location name if recognizable (park, plaza, neighborhood, venue, natural feature). Reply with only the place name, or none if uncertain.');
seed_ai_set_prompt($city, 'Identify the city if clearly shown or implied. Reply with a JSON array containing exactly one city name string (keep multi-word names together), e.g. ["New York City"], or [] if uncertain.');
seed_ai_set_prompt($state, 'Identify the US state or territory if clearly shown or implied. Reply with a JSON array containing exactly one official state name string, e.g. ["New York"], or [] if not applicable/uncertain.');
seed_ai_set_prompt($alt, 'Write a brief accessibility alt text for this image in one short sentence.');
seed_ai_set_prompt($extend, 'Write a longer accessibility description (2-4 sentences) covering important visual details for someone who cannot see the image.');

echo "--- configured AI fields ---\n";
$rows = ps_query(
    "SELECT ref, name, title, type, openai_gpt_input_field FROM resource_type_field
     WHERE openai_gpt_prompt IS NOT NULL AND openai_gpt_prompt <> ''
     ORDER BY name"
);
foreach ($rows as $row) {
    echo "#{$row['ref']}\t{$row['name']}\tt={$row['type']}\tinput={$row['openai_gpt_input_field']}\n";
}

$lock_ok = (int) ps_value(
    "SELECT COUNT(*) value FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'resource_openai_gpt_lock'",
    [],
    0
) > 0;
echo 'lock_table=' . ($lock_ok ? 'yes' : 'no') . "\n";
echo "done\n";

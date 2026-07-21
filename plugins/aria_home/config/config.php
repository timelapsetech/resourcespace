<?php

# Prefer a fixed cinematic home over dash tiles
$home_dash = false;
$no_welcometext = true;

# Type IDs (resolved further at runtime if needed)
$aria_home_photo_restype = 1;
$aria_home_video_restype = 3;
$aria_home_sequence_restype = 5;

# Source field for curated top-bar tags (Category by default so pills = categories)
$aria_home_featured_tags_field = 125;

# Curated node refs shown across the top (empty = first N from source field until configured)
$aria_home_featured_tag_nodes = [];

# Sidebar facet sections: key => [label, field_ref, enabled, limit]
# field_ref 0 = special "collections" section
$aria_home_facet_sections = [
    'collections' => ['label' => 'Collections', 'field' => 0, 'enabled' => true, 'limit' => 12],
    'country' => ['label' => 'Country', 'field' => 3, 'enabled' => true, 'limit' => 16],
    'state' => ['label' => 'State', 'field' => 120, 'enabled' => true, 'limit' => 20],
    'city' => ['label' => 'City', 'field' => 119, 'enabled' => true, 'limit' => 20],
    'other' => ['label' => 'Keyword', 'field' => 1, 'enabled' => true, 'limit' => 24],
    'subject' => ['label' => 'Subject', 'field' => 73, 'enabled' => true, 'limit' => 20],
    'event' => ['label' => 'Event', 'field' => 74, 'enabled' => true, 'limit' => 16],
    'landmark' => ['label' => 'Landmark', 'field' => 85, 'enabled' => true, 'limit' => 16],
    'person' => ['label' => 'People', 'field' => 29, 'enabled' => true, 'limit' => 16],
    'emotion' => ['label' => 'Emotion', 'field' => 75, 'enabled' => false, 'limit' => 16],
];

# Grid page size (keep low — home fires one preview request per card)
$aria_home_per_page = 12;

# Featured hero carousel (only the active slide loads eagerly)
$aria_home_hero_limit = 3;
$aria_home_hero_interval_ms = 7000;

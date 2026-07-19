<?php

declare(strict_types=1);

include '../../../include/boot.php';
include '../../../include/authenticate.php';
if (!checkperm('a')) {
    exit(escape($lang['error-permissiondenied'] ?? 'Permission denied'));
}

$plugin_name = 'aria_home';
if (!in_array($plugin_name, $plugins)) {
    plugin_activate_for_setup($plugin_name);
}

include_once dirname(__DIR__) . '/include/aria_home_functions.php';
aria_home_ensure_setup();

$ids = aria_home_featured_ids();
$config = get_plugin_config($plugin_name) ?: [];
$sections = $config['aria_home_facet_sections'] ?? ($aria_home_facet_sections ?? []);
if (!is_array($sections)) {
    $sections = [];
}

$tags_field = (int) ($config['aria_home_featured_tags_field'] ?? $aria_home_featured_tags_field ?? 73);
$curated_nodes = $config['aria_home_featured_tag_nodes'] ?? [];
if (!is_array($curated_nodes)) {
    $curated_nodes = [];
}
$curated_nodes = array_values(array_filter(array_map('intval', $curated_nodes)));

// Keyword / fixed-list fields for facet mapping
$facet_fields = ps_query(
    'SELECT ref, title, name FROM resource_type_field WHERE type IN (2,3,7,9) ORDER BY title ASC',
    [],
    'schema'
) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tags_field = (int) getval('aria_home_featured_tags_field', $tags_field, true);

    if (getval('save', '') !== '' && enforcePostRequest(false)) {
        $posted_tags = getval('aria_home_featured_tag_nodes', [], false, 'is_array');
        $curated_nodes = array_values(array_unique(array_filter(
            array_map('intval', $posted_tags),
            static fn ($n) => $n > 0
        )));

        $posted_sections = getval('facet_sections', [], false, 'is_array');
        $new_sections = [];
        foreach ($sections as $key => $section) {
            $row = $posted_sections[$key] ?? [];
            if (!is_array($row)) {
                $row = [];
            }
            $new_sections[$key] = [
                'label' => trim((string) ($row['label'] ?? $section['label'] ?? $key)),
                'field' => $key === 'collections' ? 0 : (int) ($row['field'] ?? $section['field'] ?? 0),
                'enabled' => !empty($row['enabled']),
                'limit' => max(1, min(100, (int) ($row['limit'] ?? $section['limit'] ?? 16))),
            ];
        }
        $sections = $new_sections;

        $config['aria_home_featured_field'] = $ids['field'];
        $config['aria_home_featured_node'] = $ids['node'];
        $config['aria_home_featured_tags_field'] = $tags_field;
        $config['aria_home_featured_tag_nodes'] = $curated_nodes;
        $config['aria_home_facet_sections'] = $sections;
        set_plugin_config($plugin_name, $config);

        $aria_home_featured_tags_field = $tags_field;
        $aria_home_featured_tag_nodes = $curated_nodes;
        $aria_home_facet_sections = $sections;
    }
}

$tag_choices = aria_home_featured_tag_choices($tags_field);

include '../../../include/header.php';
?>
<div class="BasicsBox">
    <h1><?php echo escape($lang['aria_home_setup'] ?? 'Aria Home setup'); ?></h1>
    <p><?php echo escape($lang['aria_home_setup_intro'] ?? ''); ?></p>

    <form method="post" action="<?php echo escape($_SERVER['PHP_SELF'] ?? ''); ?>">
        <?php generateFormToken('aria_home_setup'); ?>

        <div class="Question">
            <label><?php echo escape($lang['aria_home_featured_field'] ?? 'Featured on home'); ?></label>
            <div class="Fixed">
                Field #<?php echo (int) $ids['field']; ?>
                (shortname: featured_home) · Yes node #<?php echo (int) $ids['node']; ?>
            </div>
            <div class="clearerleft"></div>
        </div>

        <h2><?php echo escape($lang['aria_home_featured_tags'] ?? 'Featured tags (top bar)'); ?></h2>
        <p class="FormHelp"><?php echo escape($lang['aria_home_featured_tags_help'] ?? 'Pick which keywords appear as pills across the top of the home page. Leave empty to show the first tags from the source field.'); ?></p>

        <div class="Question">
            <label for="aria_home_featured_tags_field"><?php echo escape($lang['aria_home_featured_tags_field'] ?? 'Tag source field'); ?></label>
            <select id="aria_home_featured_tags_field" name="aria_home_featured_tags_field" class="stdwidth"
                    onchange="this.form.submit()">
                <?php foreach ($facet_fields as $ff) { ?>
                    <option value="<?php echo (int) $ff['ref']; ?>"<?php echo $tags_field === (int) $ff['ref'] ? ' selected' : ''; ?>>
                        <?php echo escape(i18n_get_translated((string) $ff['title']) . ' (#' . (int) $ff['ref'] . ')'); ?>
                    </option>
                <?php } ?>
            </select>
            <div class="clearerleft"></div>
        </div>

        <div class="Question">
            <label><?php echo escape($lang['aria_home_featured_tags_pick'] ?? 'Show these tags'); ?></label>
            <div class="aria-setup-tag-grid">
                <?php if ($tag_choices === []) { ?>
                    <p><?php echo escape($lang['aria_home_no_tag_choices'] ?? 'No keywords found on this field yet.'); ?></p>
                <?php } else {
                    foreach ($tag_choices as $choice) {
                        $checked = in_array((int) $choice['ref'], $curated_nodes, true);
                        ?>
                        <label class="aria-setup-tag">
                            <input type="checkbox"
                                   name="aria_home_featured_tag_nodes[]"
                                   value="<?php echo (int) $choice['ref']; ?>"
                                <?php echo $checked ? ' checked' : ''; ?>>
                            <?php echo escape($choice['name']); ?>
                        </label>
                    <?php }
                } ?>
            </div>
            <div class="clearerleft"></div>
        </div>

        <h2><?php echo escape($lang['aria_home_facet_sections'] ?? 'Left sidebar facets'); ?></h2>
        <p class="FormHelp"><?php echo escape($lang['aria_home_facet_sections_help'] ?? 'Enable sections and map each to a metadata field. Large lists (e.g. Country) only show values currently used on resources.'); ?></p>

        <table class="ListviewStyle aria-setup-facets">
            <thead>
            <tr>
                <th><?php echo escape($lang['aria_home_facet_enabled'] ?? 'On'); ?></th>
                <th><?php echo escape($lang['aria_home_facet_label'] ?? 'Label'); ?></th>
                <th><?php echo escape($lang['aria_home_facet_field'] ?? 'Field'); ?></th>
                <th><?php echo escape($lang['aria_home_facet_limit'] ?? 'Limit'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sections as $key => $section) {
                $enabled = !empty($section['enabled']);
                $label = (string) ($section['label'] ?? $key);
                $field = (int) ($section['field'] ?? 0);
                $limit = (int) ($section['limit'] ?? 16);
                $is_collections = ($key === 'collections');
                ?>
                <tr>
                    <td>
                        <input type="checkbox"
                               name="facet_sections[<?php echo escape((string) $key); ?>][enabled]"
                               value="1"
                            <?php echo $enabled ? ' checked' : ''; ?>>
                    </td>
                    <td>
                        <input type="text"
                               class="stdwidth"
                               name="facet_sections[<?php echo escape((string) $key); ?>][label]"
                               value="<?php echo escape($label); ?>">
                    </td>
                    <td>
                        <?php if ($is_collections) { ?>
                            <em><?php echo escape($lang['aria_home_collections'] ?? 'Collections'); ?></em>
                            <input type="hidden"
                                   name="facet_sections[<?php echo escape((string) $key); ?>][field]"
                                   value="0">
                        <?php } else { ?>
                            <select name="facet_sections[<?php echo escape((string) $key); ?>][field]" class="stdwidth">
                                <option value="0">—</option>
                                <?php foreach ($facet_fields as $ff) { ?>
                                    <option value="<?php echo (int) $ff['ref']; ?>"<?php echo $field === (int) $ff['ref'] ? ' selected' : ''; ?>>
                                        <?php echo escape(i18n_get_translated((string) $ff['title']) . ' (#' . (int) $ff['ref'] . ')'); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        <?php } ?>
                    </td>
                    <td>
                        <input type="number"
                               min="1"
                               max="100"
                               style="width:4.5em"
                               name="facet_sections[<?php echo escape((string) $key); ?>][limit]"
                               value="<?php echo $limit; ?>">
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>

        <div class="QuestionSubmit">
            <input type="submit" name="save" value="<?php echo escape($lang['save'] ?? 'Save'); ?>">
        </div>
    </form>

    <p class="PageInformal">
        <?php echo escape($lang['aria_home_setup_tip'] ?? 'Tip: mark resources with “Featured on home” for the hero. Tag assets with Location, City, Subject, Event, Landmark, or People so those sidebar sections fill in.'); ?>
    </p>
</div>
<style>
.aria-setup-tag-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 0.35rem 0.75rem;
    max-width: 720px;
    padding: 0.5rem 0 1rem;
}
.aria-setup-tag {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-weight: normal;
    margin: 0;
}
.aria-setup-facets {
    width: 100%;
    max-width: 900px;
    margin: 0.5rem 0 1.25rem;
}
.aria-setup-facets th,
.aria-setup-facets td {
    padding: 0.4rem 0.5rem;
    vertical-align: middle;
}
</style>
<?php
include '../../../include/footer.php';

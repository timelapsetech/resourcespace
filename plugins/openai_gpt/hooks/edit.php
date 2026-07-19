<?php

/**
 * Shared unlock handler + live lock UI after autosave of AI-managed fields.
 * Server-side lock already happens in Aftersaveresourcedata; this updates the edit form immediately.
 */
function HookOpenai_gptEditEditadditionaljs()
{
    global $lang, $baseurl_short, $ref;

    $unlock_url = $baseurl_short . 'plugins/openai_gpt/pages/unlock_field.php';
    $csrf = generate_csrf_js_object('openai_gpt_unlock_field');

    $ai_fields = [];
    foreach (openai_gpt_get_configured_fields() as $ai_field) {
        $ai_fields[] = (int) $ai_field['ref'];
    }

    $locked_label = $lang['openai_gpt_field_locked'] ?? 'Protected from AI overwrite';
    $unlock_label = $lang['openai_gpt_unlock_field'] ?? 'Allow AI to update';
    $resource = (int) ($ref ?? 0);
    ?>
    window.openaiGptAiFields = <?php echo json_encode($ai_fields); ?>;
    window.openaiGptLockResource = <?php echo (int) $resource; ?>;
    window.openaiGptLockLabels = {
        locked: <?php echo json_encode($locked_label); ?>,
        unlock: <?php echo json_encode($unlock_label); ?>
    };

    window.openaiGptFieldHasValue = function (field) {
        field = String(field);
        // Text inputs / textareas
        var $text = jQuery('[name="field_' + field + '"]');
        if ($text.length) {
            return jQuery.trim($text.val() || '') !== '';
        }
        // Fixed-list / nodes (checkboxes, radios, hidden node inputs)
        var $nodes = jQuery('[name="nodes[' + field + ']"], [name="nodes[' + field + '][]"]');
        if ($nodes.length) {
            var has = false;
            $nodes.each(function () {
                var $el = jQuery(this);
                if ($el.is(':checkbox') || $el.is(':radio')) {
                    if ($el.is(':checked') && jQuery.trim($el.val() || '') !== '') {
                        has = true;
                        return false;
                    }
                } else if (jQuery.trim($el.val() || '') !== '') {
                    has = true;
                    return false;
                }
            });
            return has;
        }
        // Dynamic keywords often use field_N_selector + hidden inputs
        var $dyn = jQuery('#field_' + field + '_selected .keywordselected, #keywords_' + field + ' .keywordselected');
        if ($dyn.length) {
            return true;
        }
        return false;
    };

    window.openaiGptShowLockUi = function (field) {
        field = String(field);
        var resource = window.openaiGptLockResource;
        if (!resource || jQuery('.openai_gpt_ai_lock[data-field="' + field + '"]').length) {
            return;
        }
        var labels = window.openaiGptLockLabels;
        var $status = jQuery('#AutoSaveStatus' + field);
        var $question = $status.closest('.Question');
        if (!$question.length) {
            $question = jQuery('#field_' + field + '_displayed').closest('.Question');
        }
        if (!$question.length) {
            return;
        }

        var $icon = jQuery(
            '<button type="button" class="lock_icon openai_gpt_unlock_btn openai_gpt_ai_lock"'
            + ' data-field="' + field + '" data-resource="' + resource + '"'
            + ' title="' + jQuery('<div>').text(labels.locked + ' — ' + labels.unlock).html() + '"'
            + ' aria-label="' + jQuery('<div>').text(labels.locked + ' — ' + labels.unlock).html() + '">'
            + '<i class="icon-lock" aria-hidden="true"></i></button>'
        );
        var $autosave = $question.find('.AutoSaveStatus').first();
        if ($autosave.length) {
            $autosave.after($icon);
        } else {
            $question.find('label').first().after($icon);
        }

        var $row = jQuery(
            '<div class="Question openai_gpt_ai_lock" data-field="' + field + '" data-resource="' + resource + '"'
            + ' style="border-top:none;padding-top:0;margin-top:-0.4em;">'
            + '<label>&nbsp;</label>'
            + '<div class="Fixed" style="font-weight:normal;opacity:0.9;">'
            + '<i class="icon-lock" aria-hidden="true"></i> '
            + jQuery('<div>').text(labels.locked).html()
            + ' — <a href="#" class="openai_gpt_unlock_btn" data-resource="' + resource + '" data-field="' + field + '">'
            + jQuery('<div>').text(labels.unlock).html()
            + '</a></div><div class="clearerleft"></div></div>'
        );
        $question.after($row);
    };

    window.openaiGptHideLockUi = function (field) {
        jQuery('.openai_gpt_ai_lock[data-field="' + String(field) + '"]').remove();
    };

    window.openaiGptRefreshLockUiAfterAutosave = function (field) {
        field = parseInt(field, 10);
        if (!field || window.openaiGptAiFields.indexOf(field) === -1) {
            return;
        }
        if (window.openaiGptFieldHasValue(field)) {
            window.openaiGptShowLockUi(field);
        } else {
            window.openaiGptHideLockUi(field);
        }
    };

    if (!window.openaiGptUnlockBound) {
        window.openaiGptUnlockBound = true;
        jQuery(document).on('click', '.openai_gpt_unlock_btn', function (e) {
            e.preventDefault();
            var btn = jQuery(this);
            var resource = btn.data('resource');
            var field = btn.data('field');
            if (!resource || !field) { return; }
            var csrf = <?php echo $csrf; ?>;
            var data = jQuery.extend({
                ajax: 'true',
                ref: resource,
                field: field
            }, csrf || {});
            jQuery.post(<?php echo json_encode($unlock_url); ?>, data)
                .done(function (resp) {
                    if (typeof resp === 'string') {
                        try { resp = JSON.parse(resp); } catch (err) { resp = null; }
                    }
                    if (resp && resp.ok) {
                        window.openaiGptHideLockUi(field);
                    } else {
                        styledalert(
                            <?php echo json_encode($lang['error'] ?? 'Error'); ?>,
                            (resp && resp.message) ? resp.message : <?php echo json_encode($lang['error_generic'] ?? 'Something went wrong'); ?>
                        );
                    }
                })
                .fail(function () {
                    styledalert(
                        <?php echo json_encode($lang['error'] ?? 'Error'); ?>,
                        <?php echo json_encode($lang['error_generic'] ?? 'Something went wrong'); ?>
                    );
                });
        });

        // Autosave already locks server-side; refresh the lock chrome as soon as SAVED returns.
        jQuery(document).ajaxSuccess(function (event, xhr, settings) {
            if (!settings || !settings.url || settings.url.indexOf('autosave=true') === -1) {
                return;
            }
            var match = settings.url.match(/[?&]autosave_field=(\d+)/);
            if (!match) {
                return;
            }
            var field = parseInt(match[1], 10);
            var resp = xhr.responseJSON;
            if (!resp) {
                try { resp = JSON.parse(xhr.responseText); } catch (err) { return; }
            }
            if (!resp || resp.result !== 'SAVED') {
                return;
            }
            window.openaiGptRefreshLockUiAfterAutosave(field);
        });
    }
    <?php
}

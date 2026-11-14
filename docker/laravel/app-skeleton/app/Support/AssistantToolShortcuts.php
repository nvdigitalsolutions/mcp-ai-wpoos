<?php

namespace App\Support;

use App\Models\Assistant;

/**
 * The raw tool-shortcut list for an assistant.
 *
 * The console's shortcut drawer reads `mcp-ai-pro/v1/tool-shortcuts`, which
 * shapes a raw list supplied by the WordPress shortcode builder. This is the
 * Laravel equivalent of that source: the two places the console writes
 * shortcuts, read back in the shape the shared normaliser expects.
 *
 * `tool_prebuilt_shortcuts` is a tool-slug keyed map. Its entries usually omit
 * `tool` because the key already says it, so the key is used as the fallback —
 * without it every prebuilt shortcut would lose its tool association and
 * collapse onto its label for an id.
 *
 * `disable_tool_shortcuts` wins outright: an assistant that switched the
 * surface off exposes nothing.
 */
final class AssistantToolShortcuts
{
    /**
     * Raw shortcuts for one assistant.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forAssistant(Assistant $assistant): array
    {
        $fields = $assistant->advancedFields();

        if (! empty($fields['disable_tool_shortcuts'])) {
            return array();
        }

        $raw = array();

        foreach ((array) ($fields['tool_shortcuts'] ?? array()) as $shortcut) {
            if (is_array($shortcut)) {
                $raw[] = $shortcut;
            }
        }

        foreach ((array) ($fields['tool_prebuilt_shortcuts'] ?? array()) as $tool => $settings) {
            if (! is_array($settings) || ! is_array($settings['shortcuts'] ?? null)) {
                continue;
            }

            foreach ($settings['shortcuts'] as $shortcut) {
                if (! is_array($shortcut)) {
                    continue;
                }

                if (! isset($shortcut['tool']) || '' === (string) $shortcut['tool']) {
                    $shortcut['tool'] = (string) $tool;
                }

                $raw[] = $shortcut;
            }
        }

        return $raw;
    }
}

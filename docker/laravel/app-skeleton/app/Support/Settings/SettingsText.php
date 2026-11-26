<?php

namespace App\Support\Settings;

/**
 * Convert a WordPress settings string to the plain text the panels render.
 *
 * The catalogue is generated from the plugin's section classes and carries
 * their descriptions verbatim. Those descriptions are HTML — `<strong>`,
 * `<code>`, `<em>` and links — because the WordPress form renders them. The
 * Filament panels render `label()` and `helperText()` as text, so 44 fields
 * across seven tabs were printing the markup itself: `<strong>Advanced:</strong>`
 * in Files & Media, `&quot;Same Origin&quot;` in the CORS helper.
 *
 * The conversion belongs here, at the plain-text presentation boundary, not in
 * the catalogue: the JSON stays the faithful mirror of the WordPress surface,
 * and a regenerated catalogue cannot reintroduce the defect.
 */
final class SettingsText
{
    /**
     * Flatten an HTML fragment into one line of readable text.
     *
     * A link keeps its destination as `Label (URL)`: dropping the anchor would
     * otherwise leave the administrator with a label and no way to reach the
     * page it names.
     */
    public static function fromHtml(?string $text): string
    {
        if (null === $text || '' === $text) {
            return '';
        }

        // Preserve the destination before `strip_tags()` discards it.
        $text = (string) preg_replace(
            '/<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
            '$3 ($2)',
            $text
        );

        // A separating tag must become whitespace, or stripping it would join
        // the words around it.
        $text = (string) preg_replace(
            '/<br\s*\/?>|<\/(?:p|div|li|ul|ol|h[1-6]|tr|td|th)>/i',
            ' ',
            $text
        );

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // `html_entity_decode()` turns `&nbsp;` into U+00A0, which `\s` does
        // not always match; normalize it before collapsing.
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}

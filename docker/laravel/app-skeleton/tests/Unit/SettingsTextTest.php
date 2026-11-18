<?php

namespace Tests\Unit;

use App\Support\Settings\SettingsText;
use PHPUnit\Framework\TestCase;

/**
 * 149 §67 — WordPress descriptions are HTML; the panels render plain text.
 */
class SettingsTextTest extends TestCase
{
    public function test_it_strips_inline_markup(): void
    {
        $this->assertSame(
            'Advanced: Intercepts direct media file requests.',
            SettingsText::fromHtml('<strong>Advanced:</strong> Intercepts direct media file requests.')
        );

        $this->assertSame(
            'HIGGSFIELD_API_KEY_SECRET',
            SettingsText::fromHtml('<code>HIGGSFIELD_API_KEY_SECRET</code>')
        );

        $this->assertSame('note', SettingsText::fromHtml('<em>note</em>'));

        // The tools tab's Google credential descriptions carry this aside.
        $this->assertSame(
            'OAuth 2.0 Client ID from Google Cloud Console for Gmail integration. (Pro enables multiple connections via Remote Sites)',
            SettingsText::fromHtml(
                'OAuth 2.0 Client ID from Google Cloud Console for Gmail integration. <em>(Pro enables multiple connections via Remote Sites)</em>'
            )
        );

        // A provider description combines a link and a code span.
        $this->assertSame(
            'Your OpenRouter API key. Get one from OpenRouter Keys (https://openrouter.ai/keys). The same key works for every model in the OpenRouter catalogue. You can also set the OPENROUTER_API_KEY environment variable.',
            SettingsText::fromHtml(
                'Your OpenRouter API key. Get one from <a href="https://openrouter.ai/keys" target="_blank">OpenRouter Keys</a>. The same key works for every model in the OpenRouter catalogue. You can also set the <code>OPENROUTER_API_KEY</code> environment variable.'
            )
        );
    }

    public function test_it_keeps_a_link_destination(): void
    {
        $this->assertSame(
            'Hugging Face Settings (https://huggingface.co/settings/tokens)',
            SettingsText::fromHtml(
                '<a href="https://huggingface.co/settings/tokens" target="_blank">Hugging Face Settings</a>'
            )
        );
    }

    public function test_it_decodes_entities(): void
    {
        $this->assertSame('"Same Origin" & more', SettingsText::fromHtml('&quot;Same Origin&quot; &amp; more'));
    }

    public function test_it_turns_separating_tags_into_spaces(): void
    {
        $this->assertSame('one two three', SettingsText::fromHtml('one<br>two</p>three'));
    }

    public function test_it_collapses_whitespace_and_handles_empty_values(): void
    {
        $this->assertSame('one two', SettingsText::fromHtml("  one\n\t two  "));
        $this->assertSame('', SettingsText::fromHtml(null));
        $this->assertSame('', SettingsText::fromHtml(''));
    }
}

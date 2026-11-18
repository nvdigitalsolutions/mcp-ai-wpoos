<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Blade attributes in the panels must not collide with Livewire's JS quoting.
 *
 * Two defects of the same family shipped on four pages (§51, §52, §53 and the
 * OKF page) and passed every PHP test, because `Livewire::test()->call('open',
 * $id)` invokes the method directly and never parses the attribute. In a
 * browser the buttons were dead:
 *
 * 1. **`@js()` inside a `<x-filament::…>` attribute.** The component tag
 *    compiler turns the attribute value into a PHP string literal *before* the
 *    directive pass, so `@js($row["id"])` reached the page verbatim — and
 *    Livewire's client threw `Missing closing parenthesis for method "open"`.
 * 2. **`@js()` inside a single-quoted attribute on a plain element.** `@js()`
 *    compiles here (no component), but on this stack `Js::from()` emits a
 *    SINGLE-quoted JS literal, so `wire:click='open(@js($id))'` produced
 *    `wire:click='open('abc')'` — the attribute ends at the first inner quote
 *    and the button becomes `wire:click="open("` plus garbage attributes.
 *
 * The one working form for both cases is an **echo** inside a DOUBLE-quoted
 * attribute, because an echo is a bound attribute and `Js::from()`'s single
 * quotes then sit safely inside the double quotes:
 *
 *   wire:click="open({{ \Illuminate\Support\Js::from($id) }})"
 *
 * Both rules below are therefore conventions with a rendering defect behind
 * them, not preferences.
 */
final class AdminPanelBladeTest extends TestCase
{
    public function test_no_panel_view_uses_the_js_directive(): void
    {
        $source = $this->panelViews();

        $this->assertDoesNotMatchRegularExpression(
            '/@js\s*\(/',
            $source,
            '`@js()` does not work in a `<x-filament::…>` attribute (the component tag compiler makes '
            .'the value a string literal) and breaks a single-quoted attribute on a plain element '
            .'(Js::from() emits single quotes on this stack). Use '
            .'`wire:click="method({{ \\Illuminate\\Support\\Js::from($value) }})"` — an echo inside a '
            .'double-quoted attribute.'
        );
    }

    public function test_every_livewire_attribute_is_double_quoted(): void
    {
        $source = $this->panelViews();

        $this->assertDoesNotMatchRegularExpression(
            '/\swire:[a-z0-9.:_-]+\s*=\s*\'/i',
            $source,
            'A single-quoted `wire:*` attribute cannot host a JS argument list: `Js::from()` emits '
            .'single quotes, so the first argument closes the attribute. Use double quotes.'
        );
    }

    /**
     * Every panel view, concatenated.
     *
     * Concatenating keeps the assertion messages honest (`assertDoesNotMatch…`
     * on one big string reports the pattern, not the file), so each rule names
     * its cause instead of a line number that drifts with every edit.
     *
     * Two roots: the panels themselves, and the shared components they render.
     * A component is panel Blade — the §55.4 data table lives in
     * `views/components/admin/` — so leaving that tree out would let the next
     * component reintroduce the defect with the lint still green.
     */
    private function panelViews(): string
    {
        $files = array();

        foreach (array('views/filament', 'views/components') as $relative) {
            $root = resource_path($relative);

            if (! is_dir($root)) {
                continue;
            }

            // Recursive on purpose: `views/filament/settings-form.blade.php`
            // sits one level above the panels, and a future nested resource
            // view must not escape the lint by being in a subdirectory.
            foreach (File::allFiles($root) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $this->assertNotEmpty($files, 'The panels have Blade views; these lints would otherwise pass vacuously.');

        $source = '';

        foreach ($files as $file) {
            $source .= "\n".file_get_contents($file);
        }

        // Prose is not code: a view may legitimately *describe* the ban (the
        // portability page does, to stop the next author reintroducing it).
        // Blade comments are stripped so the rule tests what renders.
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }
}

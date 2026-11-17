<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Brand\BrandMark;
use DOMDocument;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proposal 149 §38 — the panel brand lockup.
 *
 * The lockup is the colour mark followed by the wordmark. The wordmark is a black
 * core with a white rim (six white fills plus six unclassed black copies offset by
 * +3,+3), so one file cannot serve both themes: it reads as a solid wordmark on
 * light and nearly vanishes on dark. The panels therefore ship two wordmark
 * variants and let Filament pick by mode. The mark is saturated and outlined, so
 * one file serves both.
 *
 * What is pinned here, because each has already gone wrong once:
 *
 *  - the two wordmark variants are **geometrically identical**, differing only by a
 *    root `fill`, so a brand refresh cannot silently desynchronise them.
 *  - the paths are **root-relative**, like the favicon. `asset()` would bake in
 *    APP_URL and 404 on a panel reached through another host or port.
 *  - the lockup is **not wrapped in a coloured chip**. An earlier revision put the
 *    artwork on a `#0f172a` badge to force contrast in one mode; with a real dark
 *    variant that wrapper only made the mark look glued to the sidebar.
 *  - the icon is **decorative**, so the link is not announced twice.
 *  - the files are committed and are valid SVG, so a broken copy cannot ship.
 */
class PanelBrandingTest extends TestCase
{
    use RefreshDatabase;

    /** Geometry attributes that define the artwork. Fill is deliberately excluded. */
    private const GEOMETRY = array('d', 'x', 'y', 'width', 'height', 'rx', 'ry');

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    /**
     * Every drawn shape in an SVG, with its geometry attributes only.
     *
     * @return array<int, array{0: string, 1: array<string, string>}>
     */
    private function shapes(string $path): array
    {
        $dom = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML((string) file_get_contents($path));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, $path.' must parse as XML.');

        $shapes = array();

        foreach ($dom->getElementsByTagName('*') as $element) {
            if (! in_array($element->nodeName, array('path', 'rect', 'circle', 'polygon'), true)) {
                continue;
            }

            $attributes = array();

            foreach (self::GEOMETRY as $name) {
                if ($element->hasAttribute($name)) {
                    $attributes[ $name ] = $element->getAttribute($name);
                }
            }

            $shapes[] = array($element->nodeName, $attributes);
        }

        return $shapes;
    }

    private function publicPath(string $url): string
    {
        return public_path(ltrim($url, '/'));
    }

    public function test_all_brand_assets_are_committed_and_are_valid_svg(): void
    {
        $urls = array(BrandMark::MARK_PATH, BrandMark::LIGHT_LOGO_PATH, BrandMark::DARK_LOGO_PATH);

        foreach ($urls as $url) {
            $path = $this->publicPath($url);

            $this->assertFileExists($path, $url.' must be committed under public/.');

            $xml = simplexml_load_file($path);

            $this->assertNotFalse($xml, $url.' must parse as XML.');
            $this->assertSame('svg', strtolower($xml->getName()), $url.' root element');
        }
    }

    /**
     * The only permitted difference between the two files is the root `fill`.
     *
     * This is what lets the dark variant exist at all: the six unclassed shapes
     * inherit from the root, so promoting the root to white turns the black core
     * white without touching a single coordinate.
     */
    public function test_the_dark_variant_only_changes_the_root_fill(): void
    {
        $light = $this->shapes($this->publicPath(BrandMark::LIGHT_LOGO_PATH));
        $dark = $this->shapes($this->publicPath(BrandMark::DARK_LOGO_PATH));

        $this->assertCount(12, $light, 'The brand file is 12 shapes in two stacked layers.');

        $this->assertSame(
            $light,
            $dark,
            'The two variants must share identical geometry. Regenerate them with the same script, not by hand.'
        );

        $light_dom = new DOMDocument();
        $light_dom->loadXML((string) file_get_contents($this->publicPath(BrandMark::LIGHT_LOGO_PATH)));

        $dark_dom = new DOMDocument();
        $dark_dom->loadXML((string) file_get_contents($this->publicPath(BrandMark::DARK_LOGO_PATH)));

        $this->assertFalse(
            $light_dom->documentElement->hasAttribute('fill'),
            'The light variant must not set a root fill: the black core is what makes it legible on white.'
        );

        $this->assertSame(
            '#fff',
            $dark_dom->documentElement->getAttribute('fill'),
            'The dark variant promotes the unclassed layer to white via a root fill.'
        );
    }

    public function test_the_logo_paths_are_root_relative(): void
    {
        foreach (array(BrandMark::MARK_PATH, BrandMark::LIGHT_LOGO_PATH, BrandMark::DARK_LOGO_PATH) as $url) {
            $this->assertStringStartsWith(
                '/',
                $url,
                'A root-relative path survives a host/port mismatch with APP_URL; asset() does not.'
            );

            $this->assertStringNotContainsString('http', $url);
        }

        $this->assertNotSame(
            BrandMark::LIGHT_LOGO_PATH,
            BrandMark::DARK_LOGO_PATH,
            'A single file cannot serve both themes: this artwork has a black core.'
        );
    }

    public function test_both_panels_share_the_brand_name_and_light_mark(): void
    {
        foreach (array('admin', 'operator') as $id) {
            $panel = Filament::getPanel($id);

            $this->assertSame(BrandMark::NAME, $panel->getBrandName(), $id.' brand name');

            $logo = $panel->getBrandLogo();

            $this->assertInstanceOf(Htmlable::class, $logo, $id.' should render explicit markup, not a bare URL');
            $this->assertStringContainsString(BrandMark::LIGHT_LOGO_PATH, $logo->toHtml(), $id.' light mark');
        }
    }

    public function test_both_panels_supply_a_dark_mode_mark(): void
    {
        foreach (array('admin', 'operator') as $id) {
            $dark = Filament::getPanel($id)->getDarkModeBrandLogo();

            $this->assertInstanceOf(
                Htmlable::class,
                $dark,
                $id.' must set darkModeBrandLogo(), or the black-core artwork is near-invisible on a dark panel.'
            );

            $this->assertStringContainsString(BrandMark::DARK_LOGO_PATH, $dark->toHtml(), $id.' dark mark');
        }
    }

    /**
     * No wrapper colour: the two wordmark variants do the work now.
     */
    public function test_the_lockup_is_not_wrapped_in_a_chip(): void
    {
        foreach (array(BrandMark::logo(), BrandMark::darkLogo()) as $mark) {
            $html = $mark->toHtml();

            $this->assertStringNotContainsString('background', $html, 'The chip is gone; the variant handles contrast.');
            $this->assertStringNotContainsString('padding', $html);
            $this->assertStringContainsString('alt="NV oOS"', $html, 'An image link needs a label.');
            $this->assertStringContainsString('height:', $html);
        }
    }

    /**
     * The lockup is mark-then-wordmark, and only the wordmark is announced.
     */
    public function test_the_lockup_shows_the_mark_before_the_wordmark(): void
    {
        foreach (array(BrandMark::logo(), BrandMark::darkLogo()) as $mark) {
            $html = $mark->toHtml();

            $mark_at = strpos($html, BrandMark::MARK_PATH);
            $word_at = strpos($html, BrandMark::LIGHT_LOGO_PATH);

            if ($word_at === false) {
                $word_at = strpos($html, BrandMark::DARK_LOGO_PATH);
            }

            $this->assertNotFalse($mark_at, 'The lockup must contain the mark.');
            $this->assertNotFalse($word_at, 'The lockup must contain a wordmark.');
            $this->assertLessThan($word_at, $mark_at, 'The mark comes before the wordmark.');

            // The icon is decorative; announcing it would make the link read
            // "NV oOS, NV oOS".
            $this->assertStringContainsString('alt=""', $html, 'The mark must carry an empty alt.');
            $this->assertStringContainsString('aria-hidden="true"', $html, 'The mark must be hidden from AT.');
        }
    }

    /**
     * One mark file serves both modes: it is saturated and outlined, so it holds up
     * on white and on navy. Only the wordmark needs a variant.
     */
    public function test_the_mark_is_shared_by_both_modes(): void
    {
        $this->assertStringContainsString(BrandMark::MARK_PATH, BrandMark::logo()->toHtml());
        $this->assertStringContainsString(BrandMark::MARK_PATH, BrandMark::darkLogo()->toHtml());
    }

    public function test_both_panels_size_the_logo_wrapper_to_the_mark(): void
    {
        foreach (array('admin', 'operator') as $id) {
            $this->assertSame(
                BrandMark::HEIGHT,
                Filament::getPanel($id)->getBrandLogoHeight(),
                $id.' must pass BrandMark::HEIGHT to brandLogoHeight(), or Filament uses its 1.5rem default.'
            );
        }

        // The wrapper and the image must agree: there is no chip to absorb a
        // difference, so a taller wrapper leaves dead space and a shorter one crops.
        foreach (array(BrandMark::logo(), BrandMark::darkLogo()) as $mark) {
            $this->assertStringContainsString('height:'.BrandMark::HEIGHT, $mark->toHtml());
        }
    }

    public function test_the_favicon_is_unchanged(): void
    {
        $this->assertFileExists(public_path('nvoos-icon.svg'));

        foreach (array('admin', 'operator') as $id) {
            $this->assertSame('/nvoos-icon.svg', Filament::getPanel($id)->getFavicon());
        }
    }

    public function test_the_operator_panel_renders_both_variants(): void
    {
        $operator = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching(array($operator->id => array('role' => 'owner')));

        $this->actingAs($operator)
            ->get('/ops')
            ->assertOk()
            ->assertSee(BrandMark::LIGHT_LOGO_PATH, false)
            ->assertSee(BrandMark::DARK_LOGO_PATH, false);
    }

    public function test_the_customer_panel_renders_both_variants(): void
    {
        $admin = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($admin->id => array('role' => 'owner')));

        $this->actingAs($admin)
            ->get('/admin/default')
            ->assertOk()
            ->assertSee(BrandMark::LIGHT_LOGO_PATH, false)
            ->assertSee(BrandMark::DARK_LOGO_PATH, false);
    }
}

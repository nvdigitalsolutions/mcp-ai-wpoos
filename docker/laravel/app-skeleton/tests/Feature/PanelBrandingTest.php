<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Brand\BrandMark;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proposal 149 §38 — the panel brand mark.
 *
 * Three things are pinned, because each has already gone wrong once elsewhere:
 *
 *  - the mark is **root-relative**, like the favicon. `asset()` would bake in
 *    APP_URL and 404 on a panel reached through another host or port.
 *  - the artwork is legible in both themes. The logo file is white on
 *    transparent, so a bare `<img>` would vanish on the light sidebar; the chip
 *    is what prevents that, and this test asserts the chip is present.
 *  - the file is committed and is valid SVG, so a broken copy cannot ship.
 */
class PanelBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_logo_file_is_committed_and_is_valid_svg(): void
    {
        $path = public_path(ltrim(BrandMark::LOGO_PATH, '/'));

        $this->assertFileExists($path, 'The brand mark must be committed under public/.');

        $xml = simplexml_load_file($path);

        $this->assertNotFalse($xml, 'The brand mark must parse as XML.');
        $this->assertSame('svg', strtolower($xml->getName()));
    }

    public function test_the_logo_path_is_root_relative(): void
    {
        $this->assertStringStartsWith(
            '/',
            BrandMark::LOGO_PATH,
            'A root-relative path survives a host/port mismatch with APP_URL; asset() does not.'
        );

        $this->assertStringNotContainsString('http', BrandMark::LOGO_PATH);
    }

    public function test_both_panels_share_the_brand_name_and_mark(): void
    {
        foreach (array('admin', 'operator') as $id) {
            $panel = Filament::getPanel($id);

            $this->assertSame(BrandMark::NAME, $panel->getBrandName(), $id.' brand name');

            $logo = $panel->getBrandLogo();

            $this->assertInstanceOf(Htmlable::class, $logo, $id.' should use the chip markup, not a bare URL');
            $this->assertStringContainsString(BrandMark::LOGO_PATH, $logo->toHtml(), $id.' logo path');
        }
    }

    /**
     * The chip is the whole reason the white artwork is legible in light mode.
     */
    public function test_the_mark_is_anchored_on_a_dark_chip(): void
    {
        $html = BrandMark::logo()->toHtml();

        $this->assertStringContainsString('background:#0f172a', $html);
        $this->assertStringContainsString('alt="NV oOS"', $html, 'An image link needs a label.');
    }

    public function test_the_favicon_is_unchanged(): void
    {
        $this->assertFileExists(public_path('nvoos-icon.svg'));

        foreach (array('admin', 'operator') as $id) {
            $this->assertSame('/nvoos-icon.svg', Filament::getPanel($id)->getFavicon());
        }
    }

    public function test_the_operator_panel_renders_the_mark(): void
    {
        $operator = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching(array($operator->id => array('role' => 'owner')));

        $this->actingAs($operator)
            ->get('/ops')
            ->assertOk()
            ->assertSee(BrandMark::LOGO_PATH, false);
    }

    public function test_the_customer_panel_renders_the_mark(): void
    {
        $admin = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($admin->id => array('role' => 'owner')));

        $this->actingAs($admin)
            ->get('/admin/default')
            ->assertOk()
            ->assertSee(BrandMark::LOGO_PATH, false);
    }
}

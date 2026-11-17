<?php

namespace App\Support\Brand;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * The panel brand mark — Proposal 149 §38.
 *
 * One definition for both panels, for the same reason the palette is shared: a
 * customer estate and the vendor panel must not look like two products.
 *
 * ## Why the mark sits on a chip
 *
 * `assets/images/nvoos-logo-blk-v2.svg` is white artwork on a transparent
 * background — it was drawn for a dark surface. Filament's sidebar and topbar are
 * white in light mode and follow the OS theme unless a panel forces one, so the
 * logo rendered bare would be **invisible in light mode** while looking correct in
 * dark mode.
 *
 * Rather than force the whole admin into dark mode (a product-wide change nobody
 * asked for) or invent a second logo file, the mark is anchored on a dark chip.
 * That keeps the exact artwork the brand uses, makes it legible in both modes, and
 * stays one code path.
 *
 * Root-relative URL, not `asset()`, matching the favicon's reasoning: `asset()`
 * bakes in `APP_URL`, so a panel reached on a different host than `APP_URL` (a
 * second local container, a preview domain) would point the image at the wrong
 * origin and 404. The file is committed at `public/nvoos-logo-white.svg`.
 */
final class BrandMark
{
    /** The wordmark shown in the page title and when a logo cannot render. */
    public const NAME = 'NV oOS';

    /** Committed at `public/nvoos-logo-white.svg`. */
    public const LOGO_PATH = '/nvoos-logo-white.svg';

    /** Chip surface behind the white mark. Matches the panel's dark sidebar. */
    private const CHIP_BACKGROUND = '#0f172a';

    /**
     * The mark, as markup Filament renders verbatim.
     *
     * `brandLogo()` accepts an `Htmlable`, which is how the chip wraps the image.
     * The `alt` text carries the product name so the link is not an unlabelled
     * image link to a screen reader.
     */
    public static function logo(): Htmlable
    {
        return new HtmlString(sprintf(
            '<span style="display:inline-flex;align-items:center;background:%s;border-radius:0.5rem;padding:0.3rem 0.65rem;">'
            .'<img src="%s" alt="%s" style="height:1.35rem;width:auto;display:block;">'
            .'</span>',
            e(self::CHIP_BACKGROUND),
            e(self::LOGO_PATH),
            e(self::NAME)
        ));
    }
}

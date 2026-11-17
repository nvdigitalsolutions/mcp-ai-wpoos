<?php

namespace App\Support\Brand;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * The panel brand lockup — Proposal 149 §38.
 *
 * One definition for both panels, for the same reason the palette is shared: a
 * customer estate and the vendor panel must not look like two products.
 *
 * The lockup is **the mark, then the wordmark**: a colour molecule icon followed
 * by the NV oOS wordmark, vertically centred.
 *
 * ## The wordmark is two layers, not one colour
 *
 * `assets/images/nvoos-logo-blk-v2.svg` is 12 shapes in two stacked layers: six
 * white fills (`st0`/`st1` plus one `st0` rect) and six *unclassed* copies offset
 * by `+3,+3` that therefore inherit `fill: black`. The layers compose into a black
 * wordmark with a white highlight rim.
 *
 * That has a consequence worth stating plainly, because an earlier revision of
 * this class got it backwards: on a **light** surface the black core reads as a
 * clean solid wordmark (the white rim merges with the page), and on a **dark**
 * surface the black core all but disappears, leaving only a hairline rim. Bare
 * artwork is therefore legible in light mode and nearly invisible in dark mode —
 * the opposite of what white-on-transparent artwork would do.
 *
 * The fix is the framework's own mechanism rather than a wrapper colour: Filament
 * accepts a separate `darkModeBrandLogo()` and renders exactly one of the two
 * based on the panel's mode (`fi-logo-light` / `fi-logo-dark`). So:
 *
 * | Surface | Wordmark variant | How it is produced |
 * |---|---|---|
 * | Light | `nvoos-logo-light.svg` | the brand file, byte for byte |
 * | Dark | `nvoos-logo-dark.svg` | the same file with `fill="#fff"` on the root `<svg>` |
 *
 * The dark variant promotes the six unclassed shapes to white, so all 12 shapes
 * render white. **No path data is touched** — the two files are geometrically
 * identical and a test pins that, so a brand refresh cannot silently desynchronise
 * them.
 *
 * An earlier revision anchored the white rim on a `#0f172a` chip instead. That was
 * a workaround for not having a second variant; it made the mark look like a badge
 * glued to the sidebar in both modes, and it misread the artwork as
 * white-on-transparent when it is in fact a black core with a white rim.
 *
 * ## The mark needs no variant
 *
 * `nvoos-mark.svg` is a copy of `assets/images/nvoos-icon-old.svg` — a
 * nine-colour molecule with dark outlines on a 600×600 canvas. Its saturation and
 * outlines hold up on white and on navy alike, so unlike the wordmark it is one
 * file used by both modes. Its few near-white shapes are highlights; losing them
 * against a light page changes nothing structural.
 *
 * The lockup is composed from two `<img>` elements rather than a pre-composited
 * SVG so each half keeps the artwork the brand owns, and so the wordmark can swap
 * per mode while the mark stays shared.
 *
 * ## Optical sizing
 *
 * The two boxes are not equal on purpose. The mark is full-bleed in its square
 * canvas while the wordmark's viewBox carries built-in clear space — its
 * letterforms occupy about 62% of the 249-unit canvas height. At `2rem` of
 * wordmark the ink is ~`1.25rem`, so a `1.6rem` mark sits just proud of the cap
 * height: the classic lockup balance. Equal heights would let the icon dominate;
 * anything from `1.8rem` up reads as an icon with a caption.
 *
 * Root-relative URLs, not `asset()`, matching the favicon's reasoning: `asset()`
 * bakes in `APP_URL`, so a panel reached on a different host than `APP_URL` (a
 * second local container, a preview domain) would point the images at the wrong
 * origin and 404. The files are committed under `public/`.
 */
final class BrandMark
{
    /** The wordmark shown in the page title and as the wordmark image `alt`. */
    public const NAME = 'NV oOS';

    /** The molecule mark, shared by both modes. */
    public const MARK_PATH = '/nvoos-mark.svg';

    /** Wordmark for light surfaces: the brand file unchanged, black core preserved. */
    public const LIGHT_LOGO_PATH = '/nvoos-logo-light.svg';

    /** Wordmark for dark surfaces: the same geometry with every shape white. */
    public const DARK_LOGO_PATH = '/nvoos-logo-dark.svg';

    /**
     * The lockup's rendered height, in rem — the wordmark's box height.
     *
     * Filament puts the logo in a fixed-height box and only grows it when
     * `brandLogoHeight()` is set, so the panel passes this value through. It equals
     * the wordmark image height on purpose: there is no wrapper chip, so a taller
     * wrapper leaves dead space and a shorter one crops the mark. The icon is
     * shorter and simply centres inside it.
     */
    public const HEIGHT = '2rem';

    /** The mark's height, in rem. See the class docblock for why it is not `HEIGHT`. */
    private const MARK_HEIGHT = '1.6rem';

    /**
     * Gap between mark and wordmark, in rem.
     *
     * Deliberately tight: the wordmark's viewBox already contributes a little over
     * `0.3rem` of transparent clear space before the first letter, so a larger gap
     * here would read as a double gap.
     */
    private const GAP = '0.4rem';

    /** The lockup for light surfaces. */
    public static function logo(): Htmlable
    {
        return self::lockup(self::LIGHT_LOGO_PATH);
    }

    /** The lockup for dark surfaces. */
    public static function darkLogo(): Htmlable
    {
        return self::lockup(self::DARK_LOGO_PATH);
    }

    /**
     * `brandLogo()` / `darkModeBrandLogo()` accept an `Htmlable`, which is how the
     * explicit `alt` survives: Filament's own fallback alt is a generic "Logo", and
     * this lockup is the label on a link to the panel home.
     *
     * The icon is decorative — the wordmark already names the product — so it
     * carries an empty `alt` and `aria-hidden` rather than being announced twice.
     * Without this the link would read "NV oOS, NV oOS".
     */
    private static function lockup(string $wordmark): Htmlable
    {
        return new HtmlString(sprintf(
            '<span style="display:flex;align-items:center;gap:%s;">'
            .'<img src="%s" alt="" aria-hidden="true" style="height:%s;width:auto;display:block;">'
            .'<img src="%s" alt="%s" style="height:%s;width:auto;display:block;">'
            .'</span>',
            e(self::GAP),
            e(self::MARK_PATH),
            e(self::MARK_HEIGHT),
            e($wordmark),
            e(self::NAME),
            e(self::HEIGHT)
        ));
    }
}

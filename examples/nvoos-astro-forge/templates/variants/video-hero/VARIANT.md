# `video-hero` template variant

A **poster-first hero video**. The still image is the LCP element; the video is
an enhancement that a reduced-motion, Save-Data or 2G visitor never downloads.

```bash
nvoos-forge <site> --target ./site --preset landing --variant video-hero
```

## What it adds

| File | Job |
|---|---|
| `src/lib/hero-video.ts` | `resolveHeroVideo()`, `shouldAutoplay()` — pure, DOM-free, unit-tested |
| `src/components/VideoHero.astro` | the hero: eager poster, `preload="none"` video, pause control |

## Authoring

```json
{
	"heroVideo": {
		"poster": "/media/hero-poster.jpg",
		"mp4": "/media/hero.mp4",
		"webm": "/media/hero.webm",
		"alt": "The workshop floor at sunrise",
		"caption": "Filmed in one take."
	}
}
```

`poster` and `mp4` are **required** — a WebM-only hero fails on Safari, and a
broken hero is worse than a still one. `webm` is offered first when present.
Every URL must be root-relative or `https`; protocol-relative (`//`) and any
other scheme are rejected by the resolver.

## Wiring it in

```astro
---
import VideoHero from '../components/VideoHero.astro';
import { resolveHeroVideo } from '../lib/hero-video';

const hero = resolveHeroVideo(page.heroVideo);
---

{hero && <VideoHero {...hero} />}
```

## Why it is built this way

- **The poster is the LCP.** It loads `eager` with `fetchpriority="high"`; the
  video's `preload="none"` means zero media bytes until playback actually
  starts.
- **Autoplay is gated, not assumed.** `shouldAutoplay()` vetoes
  `prefers-reduced-motion: reduce`, `Save-Data`, and `2g`/`slow-2g`.
- **The poster fades, it does not disappear.** `opacity: 0` keeps its `alt` in
  the accessibility tree after the video takes over — `display: none` would
  silently erase the hero's accessible name.
- **Muted-only, with a pause control.** Browsers allow no other autoplay, and
  motion you started owes the visitor a way to stop it.
- **No runtime dependency.** Native `<video>`; self-host the media under
  `public/media/`.

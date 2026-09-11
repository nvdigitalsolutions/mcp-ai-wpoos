# Konva (vendored)

[Konva](https://konvajs.org/) — MIT-licensed 2D canvas library used by
the markup subsystem to render the inline canvas widget.

* Version: **9.3.16**
* Source: <https://www.npmjs.com/package/konva>
* License: see `LICENSE` (MIT)

The bundle is intentionally vendored (no CDN) to satisfy WordPress.org
plugin compliance which forbids loading scripts from external CDNs.

## Updating

```bash
npm pack konva@<new-version>
tar -xzf konva-<new-version>.tgz
cp package/konva.min.js assets/js/vendor/konva/konva-<new-version>.min.js
cp package/LICENSE assets/js/vendor/konva/LICENSE
```

Then bump the version in `includes/markup/class-wp-mcp-ai-markup-assets.php`.

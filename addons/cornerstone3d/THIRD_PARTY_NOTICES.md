# Third-Party Notices — NV oOS Cornerstone3D Addon

This file is the per-addon attribution surface for `addons/cornerstone3d/`.
The canonical, repo-wide attribution index is the root [`CREDITS.md`](../../CREDITS.md);
this file mirrors the Cornerstone3D-specific entries with full
name / version / license / copyright / homepage metadata.

> **License of the addon itself:** the NV Digital Solutions PHP plugin code in
> this addon is **proprietary** (see [`LICENSE`](LICENSE) in this directory).
> The third-party components listed below remain governed by their own upstream
> licenses (MIT as applicable); their inclusion does not relicense any
> NV Digital Solutions code, and the proprietary `LICENSE` does not modify the
> terms of those upstream components.

> **Last reviewed:** May 2026

---

## Bundled ESM Modules

The pre-built JavaScript bundles distributed in `assets/cornerstone/` are
compiled from the following upstream packages. Each bundled file retains the
upstream copyright header in its source.

| Package | Version | License | Copyright | Homepage |
|---|---|---|---|---|
| `@cornerstonejs/core` | 1.86.1 | MIT | © OHIF and Cornerstone.js contributors | <https://github.com/cornerstonejs/cornerstone3D> |
| `@cornerstonejs/tools` | 1.86.1 | MIT | © OHIF and Cornerstone.js contributors | <https://github.com/cornerstonejs/cornerstone3D> |
| `@cornerstonejs/dicom-image-loader` | 1.86.0 | MIT | © OHIF and Cornerstone.js contributors | <https://github.com/cornerstonejs/cornerstone3D> |
| `dicom-parser` | 1.8.21 | MIT | © Cornerstone.js contributors | <https://github.com/cornerstonejs/dicomParser> |
| `xmlbuilder2` | 3.0.2 | MIT | © Ozan Gülle (oozcitak) | <https://github.com/oozcitak/xmlbuilder2> |

### MIT License text (Cornerstone3D family)

```
MIT License

Copyright (c) 2022 OHIF and Cornerstone.js contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

### MIT License text (dicom-parser)

```
MIT License

Copyright (c) 2014 Cornerstone.js contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

### MIT License text (xmlbuilder2)

```
MIT License

Copyright (c) 2013 Ozan Gülle

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

## Build-time Only Dependencies (not shipped at runtime)

The following package is used only to build the ESM bundles and is **not**
included in the distributed plugin ZIP.

| Package | Version | License | Copyright | Homepage |
|---|---|---|---|---|
| `esbuild` | ^0.25.0 | MIT | © Evan Wallace | <https://github.com/evanw/esbuild> |

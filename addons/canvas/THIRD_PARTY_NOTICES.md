# Third-Party Notices — NV oOS Canvas Addon

This file is the per-addon attribution surface for `addons/canvas/`.
The canonical, repo-wide attribution index is the root [`CREDITS.md`](../../CREDITS.md);
this file mirrors the Canvas-specific entries with full
name / version / license / copyright / homepage metadata.

> **License of the addon itself:** the NV Digital Solutions PHP plugin code in
> this addon is **proprietary** (see [`LICENSE`](LICENSE) in this directory).
> The third-party components listed below remain governed by their own upstream
> licenses; their inclusion does not relicense any NV Digital Solutions code,
> and the proprietary `LICENSE` does not modify the terms of those upstream
> components.

> **Last reviewed:** May 2026

---

## Bundled Component — canvas npm package

The pre-compiled native binary (`canvas.node`) distributed in
`assets/canvas/build/Release/` is built from the `canvas` npm package.

| Field | Value |
|---|---|
| **Package** | `canvas` |
| **Version** | 2.11.2 |
| **License** | MIT |
| **Copyright** | © Automattic, Inc. and contributors |
| **Homepage** | <https://github.com/Automattic/node-canvas> |

### MIT License text (canvas)

```
MIT License

Copyright (c) Automattic, Inc. and node-canvas contributors

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

## Dynamically Linked System Libraries

The `canvas.node` binary links at runtime against the following system-level
shared libraries. These are **not** bundled in the plugin ZIP — they are
provided by the server operating system.

### Cairo graphics library

| Field | Value |
|---|---|
| **Library** | Cairo |
| **Version** | System-provided (≥ 1.14.0 typically) |
| **License** | LGPL-2.1-or-later (MPL-1.1-or-later for some portions) |
| **Copyright** | © Cairo contributors |
| **Homepage** | <https://www.cairographics.org/> |

Because Cairo is dynamically linked (not statically compiled into
`canvas.node`), users may replace the system Cairo shared library
(`libcairo.so.2`) with a modified version to satisfy the LGPL's
relinking freedom requirement. Dynamic linking fulfils this requirement
without any additional steps.

### Other system image libraries (MIT / BSD / zlib / ISC)

| Library | Typical package | License | Notes |
|---|---|---|---|
| libpng | `libpng-dev` | PNG Reference Library License (permissive) | PNG encoding/decoding |
| libjpeg-turbo | `libjpeg-turbo8` | BSD / IJG License | JPEG encoding/decoding |
| libgif | `libgif-dev` | MIT | GIF decoding |
| libpango | `libpango1.0-dev` | LGPL-2.0-or-later | Text rendering (dynamically linked) |
| librsvg | `librsvg2-dev` | LGPL-2.1-or-later | SVG rendering (optional, dynamically linked) |

All of the above are system-provided shared libraries and are **not**
redistributed by this addon.

---

## Build-time Only Dependencies (not shipped at runtime)

The following packages are used only during the binary build process and are
**not** included in the distributed plugin ZIP.

| Package | Version | License | Copyright | Homepage |
|---|---|---|---|---|
| `@mapbox/node-pre-gyp` | ^1.0.0 | BSD-3-Clause | © Mapbox | <https://github.com/mapbox/node-pre-gyp> |
| `nan` | ^2.17.0 | MIT | © Node.js NaN contributors | <https://github.com/nodejs/nan> |
| `node-gyp` | (transitive) | MIT | © node-gyp contributors | <https://github.com/nodejs/node-gyp> |

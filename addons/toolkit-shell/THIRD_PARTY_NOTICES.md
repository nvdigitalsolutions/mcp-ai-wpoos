# Third-Party Notices — NV oOS Toolkit Shell

This addon bundles the following third-party software. Each entry retains its
upstream license; the per-package license texts are reproduced below.

| Package | Version | License | Source |
|---------|---------|---------|--------|
| react | 19.1.0 | MIT | https://github.com/facebook/react |
| react-dom | 19.1.0 | MIT | https://github.com/facebook/react |
| esbuild (devDep, build-time only) | 0.25.4 | MIT | https://github.com/evanw/esbuild |
| typescript (devDep, build-time only) | 5.8.3 | Apache-2.0 | https://github.com/microsoft/TypeScript |
| @types/react (devDep, build-time only) | 19.1.4 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped |
| @types/react-dom (devDep, build-time only) | 19.1.4 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped |

Only the **runtime** dependencies (react, react-dom) are bundled into the
final SPA artifact under `assets/dist/toolkit-shell.js`. The remaining
packages are build-time only and are not redistributed.

When adding a new dependency, append a row above and reproduce the upstream
license text below. Update the root [`CREDITS.md`](../../CREDITS.md) in the
same commit.

---

## React (MIT)

Copyright (c) Meta Platforms, Inc. and affiliates.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

---

## esbuild (MIT)

Copyright (c) 2020 Evan Wallace

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

---

## TypeScript (Apache-2.0)

Apache License, Version 2.0 — full text:
https://www.apache.org/licenses/LICENSE-2.0

Copyright (c) Microsoft Corporation. All rights reserved.

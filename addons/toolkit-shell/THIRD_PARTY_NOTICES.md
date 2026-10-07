# Third-Party Notices — NV oOS Toolkit Shell

This addon bundles the following third-party software. Each entry retains its
upstream license; the per-package license texts are reproduced below.

| Package | Version | License | Source |
|---------|---------|---------|--------|
| react | 19.1.0 | MIT | https://github.com/facebook/react |
| react-dom | 19.1.0 | MIT | https://github.com/facebook/react |
| @dnd-kit/core, @dnd-kit/sortable, @dnd-kit/utilities | ^6/^10/^3 | MIT | https://github.com/clauderic/dnd-kit |
| @hookform/resolvers | ^3.9.1 | MIT | https://github.com/react-hook-form/resolvers |
| @radix-ui/react-checkbox, -dialog, -dropdown-menu, -select, -tabs | ^1/^2 | MIT | https://github.com/radix-ui/primitives |
| @tanstack/react-table | ^8.21.0 | MIT | https://github.com/TanStack/table |
| class-variance-authority | ^0.7.1 | Apache-2.0 | https://github.com/joe-bell/cva |
| clsx | ^2.1.1 | MIT | https://github.com/lukeed/clsx |
| react-hook-form | ^7.54.2 | MIT | https://github.com/react-hook-form/react-hook-form |
| sonner | ^2.0.1 | MIT | https://github.com/emilkowalski/sonner |
| zod | ^3.25.76 | MIT | https://github.com/colinhacks/zod |
| esbuild (devDep, build-time only) | 0.25.4 | MIT | https://github.com/evanw/esbuild |
| typescript (devDep, build-time only) | 5.8.3 | Apache-2.0 | https://github.com/microsoft/TypeScript |
| @types/react (devDep, build-time only) | 19.1.4 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped |
| @types/react-dom (devDep, build-time only) | 19.1.4 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped |

Only the **runtime** dependencies are bundled into the
final SPA artifact under `assets/dist/toolkit-shell.js`. Dev dependencies
are build-time only and are not redistributed. `@wordpress/i18n` is loaded
from WordPress core (`window.wp.i18n`) and is not bundled.

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

## Radix UI primitives (@radix-ui/react-*) — MIT

Copyright (c) 2022 WorkOS

Radix Primitives are released under the MIT license; the full text matches
the React license text reproduced above (MIT). Source: https://github.com/radix-ui/primitives

---

## @tanstack/react-table — MIT

Copyright (c) 2016 Tanner Linsley

Released under the MIT license (text as reproduced above).
Source: https://github.com/TanStack/table

---

## @dnd-kit/core, @dnd-kit/sortable, @dnd-kit/utilities — MIT

Copyright (c) 2021 Claudéric Demers

Released under the MIT license (text as reproduced above).
Source: https://github.com/clauderic/dnd-kit

---

## react-hook-form / @hookform/resolvers — MIT

Copyright (c) 2019-present Beier(Bill) Luo

Released under the MIT license (text as reproduced above).
Source: https://github.com/react-hook-form/react-hook-form

---

## zod — MIT

Copyright (c) 2020 Colin McDonnell

Released under the MIT license (text as reproduced above).
Source: https://github.com/colinhacks/zod

---

## sonner — MIT

Copyright (c) 2023 Emil Kowalski

Released under the MIT license (text as reproduced above).
Source: https://github.com/emilkowalski/sonner

---

## clsx — MIT

Copyright (c) Luke Edwards (lukeed.com)

Released under the MIT license (text as reproduced above).
Source: https://github.com/lukeed/clsx

---

## class-variance-authority — Apache-2.0

Copyright 2022 Joe Bell

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.

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

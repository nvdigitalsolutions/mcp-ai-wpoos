"use strict";
// Internal types for the effects system (exported via `remotion` → `./internals.js`).
//
// An effect is a description of how to transform an input image into an output
// image, executed inside a per-frame chain runtime owned by a source component
// (`<Solid>`, `<HtmlInCanvas>`, ...). The chain runtime owns scratch canvases
// and ping-pongs between them, so effects do not allocate per frame.
//
// Cross-backend contract: all canvases store premultiplied alpha and are
// sRGB-encoded. Effects that perform color math in linear space are responsible
// for converting to/from sRGB themselves.
Object.defineProperty(exports, "__esModule", { value: true });

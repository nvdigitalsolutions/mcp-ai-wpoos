"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createDescriptor = exports.defineEffect = void 0;
// Identity helper for declaring an effect definition with proper type
// inference. Wrapping the literal in `defineEffect(...)` lets TypeScript infer
// `<P, S>` from the `setup` and `apply` signatures while still enforcing the
// shape of the definition.
const defineEffect = (definition) => definition;
exports.defineEffect = defineEffect;
// Factory helper for constructing per-frame descriptors from a definition.
// Effect authors typically expose a small wrapper (e.g.
// `export const blur = (params) => createDescriptor(blurDef, params)`) so users
// don't reach into the internal definition object.
//
// `params` is type-checked against `P` at the call site, but the returned
// descriptor erases both `P` and `S` to `unknown` so it can be freely
// composed in `EffectsProp` arrays alongside descriptors of other effects.
// Without this erasure the descriptor would be contravariant in `P` (via
// `apply`'s argument), which would prevent assigning a concrete
// `EffectDescriptor<MyParams>` into an `EffectDescriptor<unknown>` slot.
const createDescriptor = (definition, params) => {
    return {
        definition: definition,
        params,
        stack: new Error().stack,
    };
};
exports.createDescriptor = createDescriptor;

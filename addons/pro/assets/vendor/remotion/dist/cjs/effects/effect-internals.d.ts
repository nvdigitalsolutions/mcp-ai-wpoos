import type { Backend, EffectDescriptor, EffectsProp } from './effect-types.js';
export declare const flattenEffects: (effects: EffectsProp) => EffectDescriptor<unknown>[];
export type Run = {
    readonly backend: Backend;
    readonly effects: ReadonlyArray<EffectDescriptor<unknown>>;
};
export declare const groupByBackend: (effects: ReadonlyArray<EffectDescriptor<unknown>>) => Run[];

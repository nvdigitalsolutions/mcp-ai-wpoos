import type { EffectDefinition, EffectDescriptor } from './effect-types.js';
export declare const defineEffect: <P, S>(definition: EffectDefinition<P, S>) => EffectDefinition<P, S>;
export declare const createDescriptor: <P, S>(definition: EffectDefinition<P, S>, params: P) => EffectDescriptor<unknown>;

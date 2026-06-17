import type { SequenceSchema } from '../sequence-field-schema.js';
export type Backend = '2d' | 'webgl2' | 'webgpu';
type AnyGpuDevice = unknown;
export type EffectApplyParams<P, S> = {
    readonly source: CanvasImageSource;
    readonly target: HTMLCanvasElement;
    readonly state: S;
    readonly params: P;
    readonly frame: number;
    readonly width: number;
    readonly height: number;
    readonly gpuDevice: AnyGpuDevice | null;
};
export type EffectDefinition<P, S = unknown> = {
    readonly type: string;
    readonly label: string;
    readonly backend: Backend;
    readonly setup: (target: HTMLCanvasElement) => S;
    readonly apply: (params: EffectApplyParams<P, S>) => void;
    readonly cleanup: (state: S) => void;
    readonly schema: SequenceSchema | null;
};
export type EffectDefinitionAndStack<P = unknown> = {
    readonly definition: EffectDefinition<P, unknown>;
    readonly stack: string;
};
export type EffectDescriptor<P = unknown> = EffectDefinitionAndStack<P> & {
    readonly params: P;
};
export type EffectsProp = ReadonlyArray<EffectDescriptor<unknown> | ReadonlyArray<EffectDescriptor<unknown>>>;
export {};

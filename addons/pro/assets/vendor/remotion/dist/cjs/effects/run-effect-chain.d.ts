import { CanvasPool } from './canvas-pool.js';
import type { EffectDefinition, EffectsProp } from './effect-types.js';
export type EffectChainState = {
    pool: CanvasPool;
    setupCache: WeakMap<EffectDefinition<unknown, unknown>, unknown>;
    cleanupRegistry: Array<{
        definition: EffectDefinition<unknown, unknown>;
        state: unknown;
    }>;
    currentRunId: number;
};
export declare const createEffectChainState: (width: number, height: number) => EffectChainState;
export declare const cleanupEffectChainState: (state: EffectChainState) => void;
export type RunEffectChainOptions = {
    readonly state: EffectChainState;
    readonly source: CanvasImageSource;
    readonly effects: EffectsProp;
    readonly output: HTMLCanvasElement;
    readonly frame: number;
    readonly width: number;
    readonly height: number;
};
export declare const runEffectChain: ({ state, source, effects, output, frame, width, height, }: RunEffectChainOptions) => Promise<boolean>;

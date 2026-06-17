import type { EffectChainState } from './run-effect-chain.js';
export declare const useEffectChainState: () => {
    get: (width: number, height: number) => EffectChainState | null;
};

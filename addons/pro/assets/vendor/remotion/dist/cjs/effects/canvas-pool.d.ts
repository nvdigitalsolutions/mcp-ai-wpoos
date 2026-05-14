import type { Backend } from './effect-types.js';
type CanvasPair = readonly [HTMLCanvasElement, HTMLCanvasElement];
export declare class CanvasPool {
    private readonly width;
    private readonly height;
    private readonly pairs;
    private readonly lostContexts;
    constructor(width: number, height: number);
    getPair(backend: Backend): CanvasPair;
    assertContextNotLost(canvas: HTMLCanvasElement): void;
    private allocateCanvas;
}
export {};

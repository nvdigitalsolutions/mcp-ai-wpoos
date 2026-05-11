import React from 'react';
import type { EffectsProp } from './effects/effect-types.js';
import type { AbsoluteFillLayout, LayoutAndStyle, SequenceProps } from './Sequence.js';
declare global {
    interface ElementImage {
        readonly width: number;
        readonly height: number;
        close(): void;
    }
    interface CanvasRenderingContext2D {
        drawElementImage(element: Element | ElementImage, dx: number, dy: number): DOMMatrix;
        drawElementImage(element: Element | ElementImage, dx: number, dy: number, dwidth: number, dheight: number): DOMMatrix;
        drawElementImage(element: Element | ElementImage, sx: number, sy: number, swidth: number, sheight: number, dx: number, dy: number): DOMMatrix;
        drawElementImage(element: Element | ElementImage, sx: number, sy: number, swidth: number, sheight: number, dx: number, dy: number, dwidth: number, dheight: number): DOMMatrix;
    }
    interface OffscreenCanvasRenderingContext2D {
        drawElementImage(element: ElementImage, dx: number, dy: number): DOMMatrix;
        drawElementImage(element: ElementImage, dx: number, dy: number, dwidth: number, dheight: number): DOMMatrix;
        drawElementImage(element: ElementImage, sx: number, sy: number, swidth: number, sheight: number, dx: number, dy: number): DOMMatrix;
        drawElementImage(element: ElementImage, sx: number, sy: number, swidth: number, sheight: number, dx: number, dy: number, dwidth: number, dheight: number): DOMMatrix;
    }
    interface WebGLRenderingContextBase {
        texElementImage2D(target: GLenum, level: GLint, internalformat: GLint, format: GLenum, type: GLenum, element: Element | ElementImage): void;
        texElementImage2D(target: GLenum, level: GLint, internalformat: GLint, width: GLsizei, height: GLsizei, format: GLenum, type: GLenum, element: Element | ElementImage): void;
        texElementImage2D(target: GLenum, level: GLint, internalformat: GLint, sx: GLfloat, sy: GLfloat, swidth: GLfloat, sheight: GLfloat, format: GLenum, type: GLenum, element: Element | ElementImage): void;
        texElementImage2D(target: GLenum, level: GLint, internalformat: GLint, sx: GLfloat, sy: GLfloat, swidth: GLfloat, sheight: GLfloat, width: GLsizei, height: GLsizei, format: GLenum, type: GLenum, element: Element | ElementImage): void;
    }
    interface HTMLCanvasElementEventMap {
        paint: Event;
    }
    interface HTMLCanvasElement {
        layoutSubtree?: boolean;
        onpaint: ((this: HTMLCanvasElement, ev: Event) => unknown) | null;
        requestPaint?(): void;
        captureElementImage(element: Element): ElementImage;
        getElementTransform(element: Element | ElementImage, drawTransform: DOMMatrix): DOMMatrix;
    }
}
export type HtmlInCanvasOnPaintParams = {
    readonly canvas: OffscreenCanvas;
    readonly element: HTMLDivElement;
    readonly elementImage: ElementImage;
};
export declare const isHtmlInCanvasSupported: () => boolean;
export type HtmlInCanvasOnPaint = (params: HtmlInCanvasOnPaintParams) => void | Promise<void>;
export type HtmlInCanvasOnInitCleanup = () => void;
export type HtmlInCanvasOnInit = (params: HtmlInCanvasOnPaintParams) => HtmlInCanvasOnInitCleanup | Promise<HtmlInCanvasOnInitCleanup>;
export type HtmlInCanvasProps = Omit<SequenceProps, 'children' | 'durationInFrames' | keyof LayoutAndStyle> & Omit<AbsoluteFillLayout, 'layout' | 'styleWhilePostmounted' | 'postmountFor' | 'premountFor' | 'styleWhilePremounted'> & {
    readonly durationInFrames?: number;
    readonly width: number;
    readonly height: number;
    readonly _experimentalEffects?: EffectsProp;
    readonly children: React.ReactNode;
    readonly onPaint?: HtmlInCanvasOnPaint;
    readonly onInit?: HtmlInCanvasOnInit;
};
export declare const HtmlInCanvas: React.ForwardRefExoticComponent<HtmlInCanvasProps & React.RefAttributes<HTMLCanvasElement>> & {
    readonly isSupported: typeof isHtmlInCanvasSupported;
};

import React from 'react';
export declare function truncateSrcForLabel(src: string): string;
type NativeImgProps = Omit<React.DetailedHTMLProps<React.ImgHTMLAttributes<HTMLImageElement>, HTMLImageElement>, 'src'>;
export type ImgProps = NativeImgProps & {
    readonly maxRetries?: number;
    readonly pauseWhenLoading?: boolean;
    readonly delayRenderRetries?: number;
    readonly delayRenderTimeoutInMilliseconds?: number;
    readonly onImageFrame?: (imageElement: HTMLImageElement) => void;
    readonly src: string;
    readonly showInTimeline?: boolean;
    readonly name?: string;
    /**
     * @deprecated For internal use only
     */
    readonly stack?: string;
};
export declare const Img: React.ComponentType<NativeImgProps & {
    readonly maxRetries?: number;
    readonly pauseWhenLoading?: boolean;
    readonly delayRenderRetries?: number;
    readonly delayRenderTimeoutInMilliseconds?: number;
    readonly onImageFrame?: (imageElement: HTMLImageElement) => void;
    readonly src: string;
    readonly showInTimeline?: boolean;
    readonly name?: string;
    /**
     * @deprecated For internal use only
     */
    readonly stack?: string;
}>;
export {};

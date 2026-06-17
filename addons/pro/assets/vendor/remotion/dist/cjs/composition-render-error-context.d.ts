export type CompositionRenderErrorContextType = {
    setError: (error: Error) => void;
    clearError: () => void;
};
export declare const CompositionRenderErrorContext: import("react").Context<CompositionRenderErrorContextType>;

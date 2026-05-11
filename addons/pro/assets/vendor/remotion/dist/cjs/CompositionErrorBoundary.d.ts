import React from 'react';
type Props = {
    readonly children: React.ReactNode;
    readonly onError: (error: Error) => void;
    readonly onClear: () => void;
};
type State = {
    hasError: boolean;
};
export declare class CompositionErrorBoundary extends React.Component<Props, State> {
    state: State;
    static getDerivedStateFromError(): Partial<State>;
    componentDidCatch(error: Error): void;
    componentDidUpdate(_prevProps: Props): void;
    render(): React.ReactNode;
}
export {};

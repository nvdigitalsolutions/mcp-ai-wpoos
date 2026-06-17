"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.CompositionErrorBoundary = void 0;
const react_1 = __importDefault(require("react"));
class CompositionErrorBoundary extends react_1.default.Component {
    constructor() {
        super(...arguments);
        this.state = { hasError: false };
    }
    static getDerivedStateFromError() {
        return { hasError: true };
    }
    componentDidCatch(error) {
        this.props.onError(error);
    }
    componentDidUpdate(_prevProps) {
        if (!this.state.hasError) {
            this.props.onClear();
        }
    }
    render() {
        if (this.state.hasError) {
            return null;
        }
        return this.props.children;
    }
}
exports.CompositionErrorBoundary = CompositionErrorBoundary;

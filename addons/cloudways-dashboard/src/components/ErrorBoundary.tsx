/**
 * NV oOS Cloudways Dashboard — Error Boundary
 *
 * Catches render errors in the component tree and displays a
 * user-friendly fallback instead of a blank screen.
 *
 * @since 0.1.0
 */

import { createElement, Component, type ReactNode, type ErrorInfo } from 'react';

interface Props {
  children: ReactNode;
  fallback?: ReactNode;
}

interface State {
  hasError: boolean;
  error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('[Cloudways Dashboard] Render error:', error, info.componentStack);
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return this.props.fallback || createElement(
        'div',
        { className: 'cwd-error-page', role: 'alert' },
        createElement('div', { className: 'cwd-error-icon' }, '⚠️'),
        createElement('h2', null, 'Something went wrong'),
        createElement('p', null, this.state.error?.message || 'An unexpected error occurred.'),
        createElement(
          'button',
          {
            className: 'cwd-btn cwd-btn-primary',
            onClick: () => this.setState({ hasError: false, error: null }),
          },
          'Try Again'
        )
      );
    }
    return this.props.children;
  }
}

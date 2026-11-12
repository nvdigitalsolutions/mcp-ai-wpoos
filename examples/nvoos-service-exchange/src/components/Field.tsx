import type { ReactNode } from 'react';

export function Field( {
	label,
	hint,
	htmlFor,
	children,
}: {
	label: string;
	hint?: string;
	htmlFor?: string;
	children: ReactNode;
} ) {
	return (
		<label className="field" htmlFor={ htmlFor }>
			<span className="field__label">{ label }</span>
			{ children }
			{ hint ? <span className="field__hint">{ hint }</span> : null }
		</label>
	);
}

export function StatusPill( { tone, children }: { tone: 'ok' | 'pending' | 'error' | 'info'; children: ReactNode } ) {
	return <span className={ `pill pill--${ tone }` }>{ children }</span>;
}

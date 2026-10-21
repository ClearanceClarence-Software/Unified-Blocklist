import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { useArmed } from '../lib/hooks';

// Same icon set and stroke style as Restbench, plus a few for this plugin.
const PATHS = {
	search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14Zm9 16-4.35-4.35',
	chevron: 'm9 6 6 6-6 6',
	check: 'm5 12 5 5 9-10',
	x: 'M6 6l12 12M18 6 6 18',
	sun: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4',
	moon: 'M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z',
	refresh: 'M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7',
	clock: 'M12 7v5l3 2M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z',
	list: 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
	globe: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18ZM3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3Z',
	trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13',
	wand: 'm4 20 11-11M14 4l1 2 2 1-2 1-1 2-1-2-2-1 2-1 1-2ZM19 11l.6 1.4L21 13l-1.4.6L19 15l-.6-1.4L17 13l1.4-.6L19 11Z',
	alert: 'M12 8v5M12 16h.01M10.3 3.9 2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z',
	sliders:
		'M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1M15 4v4M9 10v4M17 16v4',
	book: 'M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2V5ZM8 7h7',
	shield: 'M12 3 4 6v6c0 4.5 3.4 8.2 8 9 4.6-.8 8-4.5 8-9V6l-8-3Z',
	gauge: 'M12 14l4-4M4 18a9 9 0 1 1 16 0',
	download: 'M12 4v11M7 10l5 5 5-5M5 20h14',
	eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12ZM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z',
	undo: 'M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',
	pulse: 'M3 12h4l3-8 4 16 3-8h4',
	broom: 'M19 4 11 12M8 13l3 3-4 5H3l1-4 4-4ZM11 12l2 2',
	info: 'M12 11v6M12 7h.01M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z',
	external: 'M14 4h6v6M20 4l-9 9M18 14v5H5V6h5',
} as const;

export type IconName = keyof typeof PATHS;

export function Icon( { name, size = 16 }: { name: IconName; size?: number } ) {
	return (
		<svg
			className="ub-icon"
			width={ size }
			height={ size }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth={ 1.8 }
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			focusable="false"
		>
			<path d={ PATHS[ name ] } />
		</svg>
	);
}

// Logo: a shield with a funnel cut into it. Fills come from CSS, so it follows the theme.
export function Mark( { size = 24 }: { size?: number } ) {
	return (
		<svg
			className="ub-mark"
			width={ size }
			height={ size }
			viewBox="0 0 48 48"
			aria-hidden="true"
			focusable="false"
		>
			<path
				className="ub-mark-shield"
				d="M24 4 41 10v13c0 10.2-7 18.2-17 21-10-2.8-17-10.8-17-21V10L24 4Z"
			/>
			<rect
				className="ub-mark-line"
				x="14"
				y="17"
				width="20"
				height="3.5"
				rx="1.75"
			/>
			<rect
				className="ub-mark-line"
				x="17.5"
				y="24"
				width="13"
				height="3.5"
				rx="1.75"
				opacity="0.85"
			/>
			<rect
				className="ub-mark-line"
				x="21"
				y="31"
				width="6"
				height="3.5"
				rx="1.75"
				opacity="0.7"
			/>
		</svg>
	);
}

export function Toggle( {
	checked,
	onChange,
	label,
	disabled = false,
}: {
	checked: boolean;
	onChange: ( v: boolean ) => void;
	label: string;
	disabled?: boolean;
} ) {
	return (
		<button
			type="button"
			role="switch"
			aria-checked={ checked }
			aria-label={ label }
			title={ label }
			disabled={ disabled }
			className={ `ub-switch${ checked ? ' is-on' : '' }` }
			onClick={ () => onChange( ! checked ) }
		>
			<span />
		</button>
	);
}

export function Seg< T extends string | number >( {
	value,
	options,
	onChange,
	label,
}: {
	value: T;
	options: Array< { value: T; label: string } >;
	onChange: ( v: T ) => void;
	label: string;
} ) {
	return (
		<div className="ub-seg" role="group" aria-label={ label }>
			{ options.map( ( o ) => (
				<button
					key={ String( o.value ) }
					type="button"
					aria-pressed={ value === o.value }
					className={ value === o.value ? 'is-on' : '' }
					onClick={ () => onChange( o.value ) }
				>
					{ o.label }
				</button>
			) ) }
		</div>
	);
}

export function Row( {
	label,
	help,
	htmlFor,
	children,
	wide = false,
}: {
	label: ReactNode;
	help?: ReactNode;
	htmlFor?: string;
	children: ReactNode;
	wide?: boolean;
} ) {
	return (
		<div className={ `ub-setting${ wide ? ' ub-setting--wide' : '' }` }>
			<div className="ub-setting-text">
				{ htmlFor ? (
					<label className="ub-setting-label" htmlFor={ htmlFor }>
						{ label }
					</label>
				) : (
					<span className="ub-setting-label">{ label }</span>
				) }
				{ help && <p className="ub-setting-help">{ help }</p> }
			</div>
			<div className="ub-setting-control">{ children }</div>
		</div>
	);
}

export function Section( {
	title,
	aside,
	children,
	note,
}: {
	title: ReactNode;
	aside?: ReactNode;
	children: ReactNode;
	note?: ReactNode;
} ) {
	return (
		<section className="ub-section">
			<div className="ub-section-head">
				<h3 className="ub-section-title">{ title }</h3>
				{ aside && <div className="ub-section-aside">{ aside }</div> }
			</div>
			{ note && <p className="ub-note">{ note }</p> }
			{ children }
		</section>
	);
}

export function PageHead( {
	title,
	lede,
	aside,
}: {
	title: ReactNode;
	lede?: ReactNode;
	aside?: ReactNode;
} ) {
	return (
		<header className="ub-page-head">
			<div>
				<h2 className="ub-page-title">{ title }</h2>
				{ lede && <p className="ub-page-lede">{ lede }</p> }
			</div>
			{ aside && <div className="ub-page-aside">{ aside }</div> }
		</header>
	);
}

export function Callout( {
	tone,
	icon = 'info',
	children,
	action,
}: {
	tone: 'info' | 'warn' | 'err' | 'ok';
	icon?: IconName;
	children: ReactNode;
	action?: ReactNode;
} ) {
	return (
		<div
			className={ `ub-callout ub-callout--${ tone }` }
			role={ tone === 'err' ? 'alert' : undefined }
		>
			<Icon name={ icon } size={ 16 } />
			<div className="ub-callout-body">{ children }</div>
			{ action && <div className="ub-callout-action">{ action }</div> }
		</div>
	);
}

export function Dot( { tone }: { tone: 'ok' | 'warn' | 'err' | 'muted' } ) {
	return <span className={ `ub-dot is-${ tone }` } aria-hidden="true" />;
}

// Two clicks within a few seconds instead of a browser confirm() dialog.
export function ArmedButton( {
	children,
	armedLabel,
	onConfirm,
	className = 'ub-btn ub-btn--outline',
	disabled = false,
}: {
	children: ReactNode;
	armedLabel?: string;
	onConfirm: () => void;
	className?: string;
	disabled?: boolean;
} ) {
	const [ armed, press ] = useArmed();
	return (
		<button
			type="button"
			className={ `${ className }${ armed ? ' is-armed' : '' }` }
			disabled={ disabled }
			onClick={ () => {
				if ( press() ) {
					onConfirm();
				}
			} }
		>
			{ armed
				? ( armedLabel ??
					__( 'Click again to confirm', 'unified-blocklist' ) )
				: children }
		</button>
	);
}

export function AllowButton( {
	term,
	onAllow,
	compact = false,
}: {
	term: string;
	onAllow: ( t: string ) => Promise< void >;
	compact?: boolean;
} ) {
	const [ busy, setBusy ] = useState( false );
	return (
		<button
			type="button"
			className={ `ub-btn ub-btn--ghost ub-btn--sm${ compact ? ' ub-btn--icon-sm' : '' }` }
			disabled={ busy }
			/* translators: %s: term */
			title={ __( 'Stop blocking this term', 'unified-blocklist' ) }
			onClick={ async () => {
				setBusy( true );
				await onAllow( term );
				setBusy( false );
			} }
		>
			{ busy ? (
				<span className="ub-spinner" />
			) : (
				<Icon name="check" size={ 14 } />
			) }
			{ ! compact && __( 'Allow', 'unified-blocklist' ) }
		</button>
	);
}

export function Empty( {
	icon,
	title,
	children,
}: {
	icon: IconName;
	title: string;
	children?: ReactNode;
} ) {
	return (
		<div className="ub-empty">
			<span className="ub-empty-icon">
				<Icon name={ icon } size={ 22 } />
			</span>
			<p className="ub-empty-title">{ title }</p>
			{ children && <div className="ub-empty-body">{ children }</div> }
		</div>
	);
}

export function Highlighted( {
	parts,
}: {
	parts: Array< { text: string; mark: boolean } >;
} ) {
	return (
		<>
			{ parts.map( ( p, i ) =>
				p.mark ? (
					<mark key={ i }>{ p.text }</mark>
				) : (
					<span key={ i }>{ p.text }</span>
				)
			) }
		</>
	);
}

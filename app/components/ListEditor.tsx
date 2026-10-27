import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { num } from '../lib/format';

const toText = ( list: string[] ) => list.join( '\n' );
const toList = ( text: string ) =>
	Array.from(
		new Set(
			text
				.split( /\r?\n/ )
				.map( ( l ) => l.trim() )
				.filter( Boolean )
		)
	);

// A one-item-per-line editor with its own Save / Revert, for lists where saving has side
// effects (a sync, a rebuild), so typing does not trigger them on every key stroke.
export default function ListEditor( {
	id,
	value,
	onSave,
	placeholder,
	rows = 8,
	disabled = false,
	unit,
	footer,
}: {
	id: string;
	value: string[];
	onSave: ( list: string[] ) => Promise< boolean >;
	placeholder?: string;
	rows?: number;
	disabled?: boolean;
	unit: ( n: number ) => string;
	// Rendered under the editor with the lines that are new since the last save.
	footer?: ( added: string[] ) => ReactNode;
} ) {
	const [ text, setText ] = useState( toText( value ) );
	const [ saving, setSaving ] = useState( false );
	const saved = toText( value );

	// Follow outside changes (an Allow click elsewhere) unless there are unsaved edits.
	const [ base, setBase ] = useState( saved );
	useEffect( () => {
		if ( text === base ) {
			setText( saved );
		}
		setBase( saved );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ saved ] );

	const list = useMemo( () => toList( text ), [ text ] );
	const dirty = toText( list ) !== saved;
	const added = useMemo( () => {
		const before = new Set( value.map( ( v ) => v.toLowerCase() ) );
		return list.filter( ( l ) => ! before.has( l.toLowerCase() ) );
	}, [ list, value ] );

	const save = async () => {
		setSaving( true );
		const ok = await onSave( list );
		setSaving( false );
		if ( ok ) {
			setText( toText( list ) );
		}
	};

	return (
		<div className={ `ub-list-editor${ dirty ? ' is-dirty' : '' }` }>
			<textarea
				id={ id }
				className="ub-textarea"
				rows={ rows }
				spellCheck={ false }
				value={ text }
				disabled={ disabled }
				placeholder={ placeholder }
				onChange={ ( e ) => setText( e.target.value ) }
				onKeyDown={ ( e ) => {
					if ( ( e.ctrlKey || e.metaKey ) && e.key === 's' ) {
						e.preventDefault();
						if ( dirty && ! saving ) {
							save();
						}
					}
				} }
			/>
			<div className="ub-list-editor-bar">
				<span className="ub-muted">{ unit( list.length ) }</span>
				{ dirty && (
					<span className="ub-unsaved">
						{ __( 'Unsaved changes', 'unified-blocklist' ) }
					</span>
				) }
				<span className="ub-spacer" />
				{ dirty && (
					<button
						type="button"
						className="ub-btn ub-btn--ghost"
						onClick={ () => setText( saved ) }
						disabled={ saving }
					>
						{ __( 'Revert', 'unified-blocklist' ) }
					</button>
				) }
				<button
					type="button"
					className="ub-btn ub-btn--primary ub-btn--md"
					onClick={ save }
					disabled={ ! dirty || saving || disabled }
				>
					{ saving && <span className="ub-spinner" /> }
					{ __( 'Save', 'unified-blocklist' ) }
				</button>
			</div>
			{ footer && added.length > 0 && (
				<div className="ub-list-editor-foot">{ footer( added ) }</div>
			) }
		</div>
	);
}

export const termsUnit = ( n: number ) =>
	/* translators: %s: number of terms */
	sprintf( _n( '%s term', '%s terms', n, 'unified-blocklist' ), num( n ) );

export const urlsUnit = ( n: number ) =>
	/* translators: %s: number of URLs */
	sprintf( _n( '%s list', '%s lists', n, 'unified-blocklist' ), num( n ) );

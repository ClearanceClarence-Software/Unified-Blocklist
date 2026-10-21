import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import type { View } from '../types';

const VIEWS: View[] = [
	'overview',
	'log',
	'test',
	'sources',
	'terms',
	'protection',
];

// The current view lives in the hash, so reloads and bookmarks land in the same place.
export function useHashView(): [ View, ( v: View ) => void ] {
	const read = (): View => {
		const hash = window.location.hash.slice( 1 ) as View;
		return VIEWS.includes( hash ) ? hash : 'overview';
	};
	const [ view, setView ] = useState< View >( read );

	useEffect( () => {
		const onHash = () => setView( read() );
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	const go = useCallback( ( next: View ) => {
		setView( next );
		const url =
			window.location.pathname +
			window.location.search +
			( next === 'overview' ? '' : '#' + next );
		window.history.replaceState( null, '', url );
	}, [] );

	return [ view, go ];
}

export function useDebounced< T >( value: T, ms: number ): T {
	const [ out, setOut ] = useState( value );
	useEffect( () => {
		const t = window.setTimeout( () => setOut( value ), ms );
		return () => window.clearTimeout( t );
	}, [ value, ms ] );
	return out;
}

// A button that needs a second click within a few seconds, instead of a browser confirm().
export function useArmed( ms = 3500 ): [ boolean, () => boolean ] {
	const [ armed, setArmed ] = useState( false );
	const timer = useRef< number >( 0 );
	useEffect( () => () => window.clearTimeout( timer.current ), [] );
	const press = useCallback( () => {
		if ( armed ) {
			window.clearTimeout( timer.current );
			setArmed( false );
			return true;
		}
		setArmed( true );
		timer.current = window.setTimeout( () => setArmed( false ), ms );
		return false;
	}, [ armed, ms ] );
	return [ armed, press ];
}

// Runs the callback when "/" is pressed outside a text field.
export function useSlashFocus( ref: {
	current: HTMLInputElement | HTMLTextAreaElement | null;
} ) {
	useEffect( () => {
		const onKey = ( e: KeyboardEvent ) => {
			const t = e.target as HTMLElement;
			const typing =
				[ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( t.tagName ) ||
				t.isContentEditable;
			if ( e.key === '/' && ! typing ) {
				e.preventDefault();
				ref.current?.focus();
				ref.current?.select();
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ ref ] );
}

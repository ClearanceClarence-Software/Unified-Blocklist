import {
	createContext,
	useCallback,
	useContext,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { api, errorMessage, type SettingsResponse } from './api';
import type { Settings, State, SyncResult, View } from './types';

export type SaveStatus = 'idle' | 'saving' | 'saved' | 'error';

export interface Toast {
	id: number;
	tone: 'ok' | 'err' | 'info' | 'warn';
	message: string;
	action?: { label: string; run: () => void };
}

interface Store {
	settings: Settings;
	state: State;
	saveStatus: SaveStatus;
	syncing: boolean;
	toasts: Toast[];
	update: (
		patch: Partial< Settings >,
		quiet?: boolean
	) => Promise< boolean >;
	sync: ( acceptSmaller?: boolean ) => Promise< void >;
	allow: ( term: string ) => Promise< void >;
	refresh: () => Promise< void >;
	apply: ( res: { settings?: Settings; state?: State } ) => void;
	toast: ( t: Omit< Toast, 'id' > ) => void;
	dismiss: ( id: number ) => void;
	go: ( view: View ) => void;
	logVersion: number;
	bumpLog: () => void;
}

const Ctx = createContext< Store | null >( null );

export function useStore(): Store {
	const store = useContext( Ctx );
	if ( ! store ) {
		throw new Error( 'useStore outside provider' );
	}
	return store;
}

export function StoreProvider( {
	boot,
	go,
	children,
}: {
	boot: { settings: Settings; state: State };
	go: ( v: View ) => void;
	children: ReactNode;
} ) {
	const [ settings, setSettings ] = useState< Settings >( boot.settings );
	const [ state, setState ] = useState< State >( boot.state );
	const [ saveStatus, setSaveStatus ] = useState< SaveStatus >( 'idle' );
	const [ syncing, setSyncing ] = useState( false );
	const [ toasts, setToasts ] = useState< Toast[] >( [] );
	const [ logVersion, setLogVersion ] = useState( 0 );
	const seq = useRef( 0 );
	const savedTimer = useRef< number >( 0 );

	const dismiss = useCallback(
		( id: number ) =>
			setToasts( ( t ) => t.filter( ( x ) => x.id !== id ) ),
		[]
	);

	const toast = useCallback(
		( t: Omit< Toast, 'id' > ) => {
			const id = ++seq.current;
			setToasts( ( list ) => [ ...list.slice( -3 ), { ...t, id } ] );
			window.setTimeout( () => dismiss( id ), t.action ? 7000 : 4500 );
		},
		[ dismiss ]
	);

	const apply = useCallback(
		( res: { settings?: Settings; state?: State } ) => {
			if ( res.settings ) {
				setSettings( res.settings );
			}
			if ( res.state ) {
				setState( res.state );
			}
		},
		[]
	);

	const bumpLog = useCallback( () => setLogVersion( ( v ) => v + 1 ), [] );

	const reportSync = useCallback(
		( results: SyncResult[] | null | undefined ) => {
			if ( ! results ) {
				return;
			}
			const failed = results.filter( ( r ) => r.status !== 'ok' );
			failed.forEach( ( r ) =>
				toast( {
					tone: r.status === 'shrink' ? 'warn' : 'err',
					message: `${ r.label }: ${ r.error }`,
				} )
			);
			if ( ! failed.length ) {
				const changed = results.some( ( r ) => r.note === 'updated' );
				toast( {
					tone: 'ok',
					message: changed
						? __(
								'Synced. The list was updated.',
								'unified-blocklist'
							)
						: __(
								'Synced. Everything was already up to date.',
								'unified-blocklist'
							),
				} );
			}
		},
		[ toast ]
	);

	// Applied right away, saved in the background. A failed save puts the old values back,
	// so the screen never shows something that is not stored.
	const update = useCallback(
		async ( patch: Partial< Settings >, quiet = true ) => {
			let before: Settings | null = null;
			setSettings( ( s ) => {
				before = s;
				return { ...s, ...patch };
			} );
			setSaveStatus( 'saving' );
			window.clearTimeout( savedTimer.current );
			try {
				const res: SettingsResponse = await api.saveSettings( patch );
				apply( res );
				reportSync( res.sync );
				setSaveStatus( 'saved' );
				savedTimer.current = window.setTimeout(
					() => setSaveStatus( 'idle' ),
					2200
				);
				// Toggles only show "Saved" in the top bar; editors that ask for a toast pass quiet = false.
				if ( ! quiet ) {
					toast( {
						tone: 'ok',
						message: __( 'Saved.', 'unified-blocklist' ),
					} );
				}
				return true;
			} catch ( err ) {
				if ( before ) {
					setSettings( before );
				}
				setSaveStatus( 'error' );
				toast( {
					tone: 'err',
					message: errorMessage(
						err,
						__( 'Could not save. Try again?', 'unified-blocklist' )
					),
				} );
				return false;
			}
		},
		[ apply, reportSync, toast ]
	);

	const sync = useCallback(
		async ( acceptSmaller = false ) => {
			setSyncing( true );
			try {
				const res = await api.sync( acceptSmaller );
				apply( res );
				reportSync( res.sync );
			} catch ( err ) {
				toast( {
					tone: 'err',
					message: errorMessage(
						err,
						__( 'Sync failed.', 'unified-blocklist' )
					),
				} );
			} finally {
				setSyncing( false );
			}
		},
		[ apply, reportSync, toast ]
	);

	const allow = useCallback(
		async ( term: string ) => {
			try {
				apply( await api.allow( term ) );
				bumpLog();
				toast( {
					tone: 'ok',
					message: sprintf(
						/* translators: %s: term */
						__(
							'"%s" will no longer block anything.',
							'unified-blocklist'
						),
						term
					),
					action: {
						label: __( 'Undo', 'unified-blocklist' ),
						run: () => {
							api.unallow( term )
								.then( ( res ) => {
									apply( res );
									bumpLog();
								} )
								.catch( ( err ) =>
									toast( {
										tone: 'err',
										message: errorMessage(
											err,
											__(
												'Could not undo.',
												'unified-blocklist'
											)
										),
									} )
								);
						},
					},
				} );
			} catch ( err ) {
				toast( {
					tone: 'err',
					message: errorMessage(
						err,
						__(
							'Could not add the exception.',
							'unified-blocklist'
						)
					),
				} );
			}
		},
		[ apply, bumpLog, toast ]
	);

	const refresh = useCallback( async () => {
		try {
			setState( await api.state() );
		} catch {
			// The page keeps showing the last known state.
		}
	}, [] );

	const value = useMemo(
		() => ( {
			settings,
			state,
			saveStatus,
			syncing,
			toasts,
			update,
			sync,
			allow,
			refresh,
			apply,
			toast,
			dismiss,
			go,
			logVersion,
			bumpLog,
		} ),
		[
			settings,
			state,
			saveStatus,
			syncing,
			toasts,
			update,
			sync,
			allow,
			refresh,
			apply,
			toast,
			dismiss,
			go,
			logVersion,
			bumpLog,
		]
	);

	return <Ctx.Provider value={ value }>{ children }</Ctx.Provider>;
}

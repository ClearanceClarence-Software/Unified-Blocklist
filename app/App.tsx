import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ComponentType } from 'react';

import { api } from './api';
import Sidebar from './components/Sidebar';
import Toasts from './components/Toasts';
import { Icon, Mark } from './components/ui';
import { useHashView } from './lib/hooks';
import { StoreProvider, useStore } from './store';
import type { Boot, Theme, View } from './types';
import Log from './views/Log';
import Overview from './views/Overview';
import Protection from './views/Protection';
import Sources from './views/Sources';
import Terms from './views/Terms';
import Tester from './views/Tester';

const VIEWS: Record< View, ComponentType > = {
	overview: Overview,
	log: Log,
	test: Tester,
	sources: Sources,
	terms: Terms,
	protection: Protection,
};

export default function App( { boot }: { boot: Boot } ) {
	const [ view, go ] = useHashView();
	return (
		<StoreProvider boot={ boot } go={ go }>
			<Shell view={ view } boot={ boot } />
		</StoreProvider>
	);
}

function Shell( { view, boot }: { view: View; boot: Boot } ) {
	const { settings, saveStatus, sync, syncing, refresh, go } = useStore();
	const [ theme, setTheme ] = useState< Theme >( boot.theme );
	const mainRef = useRef< HTMLElement >( null );
	const Current = VIEWS[ view ];

	// Fresh numbers when returning to the tab, and scroll to the top on view changes.
	useEffect( () => {
		const onVisible = () =>
			document.visibilityState === 'visible' && refresh();
		document.addEventListener( 'visibilitychange', onVisible );
		return () =>
			document.removeEventListener( 'visibilitychange', onVisible );
	}, [ refresh ] );
	// On localhost, WordPress's scheduled tasks often never run, so the first download
	// would never happen. Opening the app starts it once instead.
	const autoSynced = useRef( false );
	useEffect( () => {
		if (
			! autoSynced.current &&
			! boot.state.sync.last &&
			boot.state.sources.length > 0
		) {
			autoSynced.current = true;
			sync();
		}
	}, [ boot, sync ] );

	useEffect( () => {
		mainRef.current?.scrollTo( { top: 0 } );
		refresh();
	}, [ view, refresh ] );

	const flipTheme = () => {
		const next: Theme = theme === 'light' ? 'dark' : 'light';
		setTheme( next );
		api.theme( next ).catch( () => setTheme( theme ) );
	};
	const themeLabel =
		theme === 'light'
			? __( 'Switch to dark theme', 'unified-blocklist' )
			: __( 'Switch to light theme', 'unified-blocklist' );

	return (
		<div className="ub" data-theme={ theme }>
			<header className="ub-topbar">
				<button
					type="button"
					className="ub-brand"
					onClick={ () => go( 'overview' ) }
					title={ __( 'Overview', 'unified-blocklist' ) }
				>
					<Mark size={ 28 } />
					<span className="ub-brand-name">
						Unified{ ' ' }<em>Blocklist</em>
					</span>
					<span className="ub-brand-version">{ boot.version }</span>
				</button>

				<button
					type="button"
					className={ `ub-mode-pill${ settings.mode === 'monitor' ? ' is-monitor' : '' }` }
					onClick={ () => go( 'protection' ) }
					title={ __( 'Change in Protection', 'unified-blocklist' ) }
				>
					<span className="ub-status-pulse" aria-hidden="true" />
					{ settings.mode === 'monitor'
						? __( 'Monitor only', 'unified-blocklist' )
						: __( 'Enforcing', 'unified-blocklist' ) }
				</button>

				<span className="ub-spacer" />

				<span
					className={ `ub-save-status is-${ saveStatus }` }
					aria-live="polite"
				>
					{ saveStatus === 'saving' &&
						__( 'Saving…', 'unified-blocklist' ) }
					{ saveStatus === 'saved' && (
						<>
							<Icon name="check" size={ 14 } />{ ' ' }
							{ __( 'Saved', 'unified-blocklist' ) }
						</>
					) }
					{ saveStatus === 'error' &&
						__( 'Not saved', 'unified-blocklist' ) }
				</span>

				<button
					type="button"
					className={ `ub-btn ub-btn--ghost${ syncing ? ' is-spinning' : '' }` }
					onClick={ () => sync() }
					disabled={ syncing }
				>
					<Icon name="refresh" size={ 15 } />
					<span className="ub-hide-sm">
						{ syncing
							? __( 'Syncing…', 'unified-blocklist' )
							: __( 'Sync now', 'unified-blocklist' ) }
					</span>
				</button>
				<button
					type="button"
					className="ub-btn ub-btn--icon ub-btn--ghost"
					onClick={ flipTheme }
					title={ themeLabel }
					aria-label={ themeLabel }
				>
					<Icon
						name={ theme === 'light' ? 'moon' : 'sun' }
						size={ 16 }
					/>
				</button>
			</header>

			<div className="ub-body">
				<Sidebar view={ view } />
				<main className="ub-main" ref={ mainRef }>
					<div className="ub-view" key={ view }>
						<Current />
					</div>
				</main>
			</div>
			<Toasts />
		</div>
	);
}

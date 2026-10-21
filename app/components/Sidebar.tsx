import { __, sprintf } from '@wordpress/i18n';

import { ago, compact } from '../lib/format';
import { useStore } from '../store';
import type { View } from '../types';
import { Dot, Icon, type IconName } from './ui';

interface Item {
	id: View;
	label: string;
	icon: IconName;
	trail?: string;
	dot?: 'ok' | 'warn' | 'err' | 'muted';
}

export default function Sidebar( { view }: { view: View } ) {
	const { state, settings, go } = useStore();
	const failing = state.sources.filter(
		( s ) => s.status === 'error' || s.status === 'shrink'
	).length;

	const monitor: Item[] = [
		{
			id: 'overview',
			label: __( 'Overview', 'unified-blocklist' ),
			icon: 'gauge',
		},
		{
			id: 'log',
			label: __( 'Activity', 'unified-blocklist' ),
			icon: 'pulse',
			trail: state.activity.total
				? compact( state.activity.total )
				: undefined,
		},
		{
			id: 'test',
			label: __( 'Test a message', 'unified-blocklist' ),
			icon: 'wand',
		},
	];
	const configure: Item[] = [
		{
			id: 'sources',
			label: __( 'Sources', 'unified-blocklist' ),
			icon: 'globe',
			dot: failing
				? 'err'
				: state.sources.length && state.sync.last
					? 'ok'
					: 'muted',
		},
		{
			id: 'terms',
			label: __( 'Terms', 'unified-blocklist' ),
			icon: 'book',
			trail: compact( state.terms.count ),
		},
		{
			id: 'protection',
			label: __( 'Protection', 'unified-blocklist' ),
			icon: 'shield',
			dot: settings.mode === 'monitor' ? 'warn' : undefined,
		},
	];

	const renderItems = ( items: Item[] ) => (
		<ul>
			{ items.map( ( item ) => (
				<li key={ item.id }>
					<button
						type="button"
						className={ `ub-nav-item${ view === item.id ? ' is-active' : '' }` }
						aria-current={ view === item.id ? 'page' : undefined }
						onClick={ () => go( item.id ) }
					>
						<Icon name={ item.icon } size={ 17 } />
						<span className="ub-nav-label">{ item.label }</span>
						{ item.dot && <Dot tone={ item.dot } /> }
						{ item.trail && (
							<span className="ub-nav-count">{ item.trail }</span>
						) }
					</button>
				</li>
			) ) }
		</ul>
	);

	const enforcing = settings.mode === 'enforce';

	return (
		<nav
			className="ub-sidebar"
			aria-label={ __( 'Unified Blocklist', 'unified-blocklist' ) }
		>
			<div className="ub-nav">
				<p className="ub-nav-heading">
					{ __( 'Monitor', 'unified-blocklist' ) }
				</p>
				{ renderItems( monitor ) }
				<p className="ub-nav-heading">
					{ __( 'Configure', 'unified-blocklist' ) }
				</p>
				{ renderItems( configure ) }
			</div>

			<button
				type="button"
				className={ `ub-status-card${ enforcing ? ' is-on' : ' is-monitor' }` }
				onClick={ () => go( 'protection' ) }
			>
				<span className="ub-status-card-top">
					<span className="ub-status-pulse" aria-hidden="true" />
					<strong>
						{ enforcing
							? __( 'Protection on', 'unified-blocklist' )
							: __( 'Monitor only', 'unified-blocklist' ) }
					</strong>
				</span>
				<span className="ub-status-card-meta">
					{ state.sync.last
						? sprintf(
								/* translators: %s: relative time, such as "3 minutes ago" */
								__( 'Synced %s', 'unified-blocklist' ),
								ago( state.sync.last, state.now )
							)
						: __( 'Not synced yet', 'unified-blocklist' ) }
				</span>
			</button>
		</nav>
	);
}

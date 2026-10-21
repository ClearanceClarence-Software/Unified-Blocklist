import { __, _n, sprintf } from '@wordpress/i18n';

import ActivityChart from '../components/ActivityChart';
import LegacyCard from '../components/LegacyCard';
import {
	AllowButton,
	Callout,
	Dot,
	Empty,
	Icon,
	PageHead,
	Section,
} from '../components/ui';
import { ago, num } from '../lib/format';
import { useStore } from '../store';
import type { Context } from '../types';

export const CONTEXT_LABELS: Record< Context, string > = {
	comment: __( 'Comments', 'unified-blocklist' ),
	cf7: 'Contact Form 7',
	gravityforms: 'Gravity Forms',
	wpforms: 'WPForms',
	formidable: 'Formidable Forms',
};

export default function Overview() {
	const { state, settings, sync, syncing, update, allow, go } = useStore();
	const a = state.activity;
	const failing = state.sources.filter(
		( s ) => s.status === 'error' || s.status === 'shrink'
	);
	const protectedCount = (
		Object.keys( CONTEXT_LABELS ) as Context[]
	 ).filter( ( c ) => isProtected( c ) ).length;
	const topMax = Math.max( 1, ...a.topTerms.map( ( t ) => t.hits ) );
	const next = state.sync.next;

	function isProtected( c: Context ) {
		if ( ! state.available[ c ] ) {
			return false;
		}
		return c === 'comment'
			? settings.comment_action !== 'off'
			: settings.forms[ c ];
	}

	return (
		<div className="ub-page">
			<PageHead
				title={ __( 'Overview', 'unified-blocklist' ) }
				lede={ sprintf(
					/* translators: 1: number of terms, 2: protected places, 3: places that could be protected */
					__(
						'%1$s terms, guarding %2$d of %3$d places where visitors can post.',
						'unified-blocklist'
					),
					num( state.terms.count ),
					protectedCount,
					( Object.keys( CONTEXT_LABELS ) as Context[] ).filter(
						( c ) => state.available[ c ]
					).length
				) }
			/>

			<div className="ub-stack">
				{ state.legacy && <LegacyCard report={ state.legacy } /> }

				{ state.terms.error && (
					<Callout tone="err" icon="alert">
						<strong>
							{ __(
								'The list could not be stored.',
								'unified-blocklist'
							) }
						</strong>{ ' ' }
						{ state.terms.error }
					</Callout>
				) }

				{ settings.mode === 'monitor' && (
					<Callout
						tone="warn"
						icon="eye"
						action={
							<button
								type="button"
								className="ub-btn ub-btn--outline"
								onClick={ () => update( { mode: 'enforce' } ) }
							>
								{ __( 'Start enforcing', 'unified-blocklist' ) }
							</button>
						}
					>
						<strong>
							{ __( 'Monitor only is on.', 'unified-blocklist' ) }
						</strong>{ ' ' }
						{ __(
							'Matches are logged but nothing is blocked.',
							'unified-blocklist'
						) }
					</Callout>
				) }

				{ ! state.sync.last && state.sources.length > 0 && (
					<Callout
						tone="info"
						icon="refresh"
						action={
							<button
								type="button"
								className="ub-btn ub-btn--primary ub-btn--md"
								onClick={ () => sync() }
								disabled={ syncing }
							>
								{ syncing ? (
									<span className="ub-spinner" />
								) : (
									<Icon name="refresh" size={ 15 } />
								) }
								{ __( 'Sync now', 'unified-blocklist' ) }
							</button>
						}
					>
						{ __(
							'The first download has not run yet. It starts with the next scheduled task, or right now if you like.',
							'unified-blocklist'
						) }
					</Callout>
				) }

				{ failing.length > 0 && (
					<Callout
						tone="err"
						icon="alert"
						action={
							<button
								type="button"
								className="ub-btn ub-btn--outline"
								onClick={ () => go( 'sources' ) }
							>
								{ __( 'Open sources', 'unified-blocklist' ) }
							</button>
						}
					>
						{ sprintf(
							/* translators: %d: number of sources */
							_n(
								'%d source failed to update. Its last good copy is still in use.',
								'%d sources failed to update. Their last good copies are still in use.',
								failing.length,
								'unified-blocklist'
							),
							failing.length
						) }
					</Callout>
				) }
			</div>

			<div className="ub-tiles">
				<button
					type="button"
					className="ub-tile"
					onClick={ () => go( 'terms' ) }
				>
					<span className="ub-tile-label">
						{ __( 'Active terms', 'unified-blocklist' ) }
					</span>
					<span className="ub-tile-value">
						{ num( state.terms.count ) }
					</span>
					<span className="ub-tile-meta">
						{ sprintf(
							/* translators: 1: from sources, 2: own terms, 3: from Discussion settings */
							__(
								'%1$s downloaded · %2$s yours · %3$s Discussion',
								'unified-blocklist'
							),
							num( state.terms.counts.sources ),
							num( state.terms.counts.local ),
							num( state.terms.counts.core )
						) }
					</span>
				</button>
				<button
					type="button"
					className="ub-tile"
					onClick={ () => go( 'log' ) }
				>
					<span className="ub-tile-label">
						{ __( 'Matches, 30 days', 'unified-blocklist' ) }
					</span>
					<span className="ub-tile-value">{ num( a.total ) }</span>
					<span className="ub-tile-meta">
						{ sprintf(
							/* translators: 1: stopped, 2: logged only */
							__(
								'%1$s stopped · %2$s logged only',
								'unified-blocklist'
							),
							num( a.stopped ),
							num( a.logged )
						) }
					</span>
				</button>
				<button
					type="button"
					className="ub-tile"
					onClick={ () => go( 'sources' ) }
				>
					<span className="ub-tile-label">
						{ __( 'Sources', 'unified-blocklist' ) }
					</span>
					<span
						className={ `ub-tile-value ub-tile-value--sm${ failing.length ? ' is-bad' : '' }` }
					>
						<Dot
							tone={
								failing.length
									? 'err'
									: state.sync.last
										? 'ok'
										: 'muted'
							}
						/>
						{ failing.length
							? sprintf(
									/* translators: 1: failing, 2: total */
									__(
										'%1$d of %2$d failing',
										'unified-blocklist'
									),
									failing.length,
									state.sources.length
								)
							: sprintf(
									/* translators: %d: number of sources */
									_n(
										'%d healthy',
										'%d healthy',
										state.sources.length,
										'unified-blocklist'
									),
									state.sources.length
								) }
					</span>
					<span className="ub-tile-meta">
						{ state.sources.map( ( s ) => s.label ).join( ', ' ) ||
							__( 'Only your own terms', 'unified-blocklist' ) }
					</span>
				</button>
				<div className="ub-tile">
					<span className="ub-tile-label">
						{ __( 'Last sync', 'unified-blocklist' ) }
					</span>
					<span className="ub-tile-value ub-tile-value--sm">
						{ ago( state.sync.last, state.now ) }
					</span>
					<span className="ub-tile-meta">
						{ next
							? sprintf(
									/* translators: %s: relative time */
									__( 'Next check %s', 'unified-blocklist' ),
									next <= state.now
										? __(
												'is due now',
												'unified-blocklist'
											)
										: ago( next, state.now )
								)
							: __( 'No check scheduled', 'unified-blocklist' ) }
					</span>
				</div>
			</div>

			<Section
				title={ __( 'Activity', 'unified-blocklist' ) }
				aside={
					<button
						type="button"
						className="ub-link"
						onClick={ () => go( 'log' ) }
					>
						{ __( 'Open the log', 'unified-blocklist' ) }
					</button>
				}
			>
				<div className="ub-card ub-card--pad">
					{ a.total === 0 ? (
						<Empty
							icon="pulse"
							title={ __(
								'Nothing has matched in the last 30 days.',
								'unified-blocklist'
							) }
						>
							{ __(
								'Matches show up here as soon as a comment or form hits a term.',
								'unified-blocklist'
							) }
						</Empty>
					) : (
						<ActivityChart days={ a.daily } />
					) }
				</div>
			</Section>

			<div className="ub-two">
				<Section title={ __( 'Coverage', 'unified-blocklist' ) }>
					<ul className="ub-card ub-coverage">
						{ ( Object.keys( CONTEXT_LABELS ) as Context[] ).map(
							( c ) => {
								const available = state.available[ c ];
								const on = isProtected( c );
								const effect = coverageEffect(
									c,
									settings.comment_action
								);
								return (
									<li key={ c }>
										<Dot
											tone={
												! available
													? 'muted'
													: on
														? 'ok'
														: 'warn'
											}
										/>
										<span className="ub-coverage-name">
											{ CONTEXT_LABELS[ c ] }
										</span>
										<span className="ub-coverage-state">
											{ ! available &&
												__(
													'Not installed',
													'unified-blocklist'
												) }
											{ available &&
												! on &&
												__(
													'Off',
													'unified-blocklist'
												) }
											{ available && on && effect }
										</span>
										{ !! a.byContext[ c ] && (
											<span className="ub-coverage-count">
												{ num( a.byContext[ c ] ?? 0 ) }
											</span>
										) }
									</li>
								);
							}
						) }
					</ul>
				</Section>

				<Section
					title={ __( 'Most matched terms', 'unified-blocklist' ) }
				>
					<div className="ub-card">
						{ a.topTerms.length === 0 ? (
							<Empty
								icon="list"
								title={ __(
									'No terms have matched yet.',
									'unified-blocklist'
								) }
							/>
						) : (
							<ul className="ub-top">
								{ a.topTerms.map( ( t ) => (
									<li key={ t.term }>
										<code className="ub-term">
											{ t.term }
										</code>
										<span
											className="ub-top-bar"
											aria-hidden="true"
										>
											<span
												style={ {
													width: `${ ( t.hits / topMax ) * 100 }%`,
												} }
											/>
										</span>
										<span className="ub-top-hits">
											{ num( t.hits ) }
										</span>
										<AllowButton
											term={ t.term }
											onAllow={ allow }
										/>
									</li>
								) ) }
							</ul>
						) }
					</div>
				</Section>
			</div>
		</div>
	);
}

export function coverageEffect( c: Context, commentAction: string ): string {
	if ( c === 'comment' ) {
		return (
			{
				spam: __( 'Sent to Spam', 'unified-blocklist' ),
				hold: __( 'Held for moderation', 'unified-blocklist' ),
				trash: __( 'Sent to Trash', 'unified-blocklist' ),
				off: __( 'Off', 'unified-blocklist' ),
			} as Record< string, string >
		 )[ commentAction ];
	}
	return {
		cf7: __( 'Marked as spam, no email', 'unified-blocklist' ),
		gravityforms: __( 'Saved as spam entry', 'unified-blocklist' ),
		wpforms: __( 'Rejected with a message', 'unified-blocklist' ),
		formidable: __( 'Rejected with a message', 'unified-blocklist' ),
	}[ c ];
}

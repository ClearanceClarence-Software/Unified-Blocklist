import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { api, errorMessage } from '../api';
import { ago, highlight, num } from '../lib/format';
import { useStore } from '../store';
import type { Context, ReplayResult } from '../types';
import { AllowButton, Callout, Highlighted, Icon } from './ui';

// "Checked 120 approved comments, 30 Formidable entries".
const SCANNED_LABELS: Record< Context | 'other', string > = {
	comment: __( 'approved comments', 'unified-blocklist' ),
	cf7: __( 'Contact Form 7 messages', 'unified-blocklist' ),
	gravityforms: __( 'Gravity Forms entries', 'unified-blocklist' ),
	wpforms: __( 'WPForms entries', 'unified-blocklist' ),
	formidable: __( 'Formidable entries', 'unified-blocklist' ),
	other: __( 'other entries', 'unified-blocklist' ),
};

const ITEM_LABELS: Record< Context | 'other', string > = {
	comment: __( 'Comment', 'unified-blocklist' ),
	cf7: 'Contact Form 7',
	gravityforms: 'Gravity Forms',
	wpforms: 'WPForms',
	formidable: 'Formidable',
	other: __( 'Entry', 'unified-blocklist' ),
};

// Replays the active list, or a few candidate terms, over recent messages the site accepted:
// approved comments and form entries that were not spam. Nothing is logged or changed.
export default function ReplayPanel( {
	terms,
	buttonLabel,
	compact = false,
}: {
	terms?: string[];
	buttonLabel?: string;
	compact?: boolean;
} ) {
	const { allow, settings, state } = useStore();
	const [ result, setResult ] = useState< ReplayResult | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ ranFor, setRanFor ] = useState( '' );

	const key = terms ? terms.join( '\n' ) : `list:${ state.terms.builtAt }`;
	const stale = result !== null && ranFor !== key;
	const exceptions = new Set(
		settings.exceptions.map( ( e ) => e.toLowerCase() )
	);

	const run = () => {
		setBusy( true );
		setError( null );
		api.preview( terms )
			.then( ( res ) => {
				setResult( res );
				setRanFor( key );
			} )
			.catch( ( err ) =>
				setError(
					errorMessage(
						err,
						__( 'The check failed.', 'unified-blocklist' )
					)
				)
			)
			.finally( () => setBusy( false ) );
	};

	const scanned = result
		? ( Object.keys( result.scanned ) as Array< Context | 'other' > )
				.map( ( k ) =>
					sprintf(
						/* translators: 1: number of messages, 2: where they come from */
						__( '%1$s %2$s', 'unified-blocklist' ),
						num( result.scanned[ k ] ?? 0 ),
						SCANNED_LABELS[ k ] ?? k
					)
				)
				.join( ', ' )
		: '';

	return (
		<div className={ `ub-replay${ compact ? ' is-compact' : '' }` }>
			<div className="ub-replay-bar">
				<button
					type="button"
					className={ `ub-btn ${ result && ! stale ? 'ub-btn--outline' : 'ub-btn--primary ub-btn--md' }` }
					onClick={ run }
					disabled={
						busy || ( terms !== undefined && ! terms.length )
					}
				>
					{ busy ? (
						<span className="ub-spinner" />
					) : (
						<Icon name="refresh" size={ 15 } />
					) }
					{ result && ! stale
						? __( 'Check again', 'unified-blocklist' )
						: ( buttonLabel ??
							__(
								'Check recent messages',
								'unified-blocklist'
							) ) }
				</button>
				{ result && ! busy && (
					<span className="ub-muted" aria-live="polite">
						{ stale
							? __(
									'The terms changed since this check.',
									'unified-blocklist'
								)
							: sprintf(
									/* translators: 1: list of counts such as "120 comments, 30 formidable forms", 2: milliseconds */
									__(
										'Checked %1$s in %2$s ms.',
										'unified-blocklist'
									),
									scanned ||
										__( '0 messages', 'unified-blocklist' ),
									num( result.ms )
								) }
					</span>
				) }
			</div>

			{ error && <p className="ub-error-line">{ error }</p> }

			{ result && result.total === 0 && (
				<p className="ub-note">
					{ __(
						'There are no approved comments or saved form entries to check yet. Contact Form 7 and WPForms Lite keep no copies of messages unless you add a plugin such as Flamingo.',
						'unified-blocklist'
					) }
				</p>
			) }

			{ result && result.total > 0 && result.matched === 0 && (
				<Callout tone="ok" icon="check">
					<strong>
						{ __(
							'Nothing real gets caught.',
							'unified-blocklist'
						) }
					</strong>{ ' ' }
					{ sprintf(
						/* translators: %s: number of messages */
						_n(
							'The %s recent message you accepted would still go through.',
							'All %s recent messages you accepted would still go through.',
							result.total,
							'unified-blocklist'
						),
						num( result.total )
					) }
				</Callout>
			) }

			{ result && result.matched > 0 && (
				<>
					<Callout tone="warn" icon="alert">
						<strong>
							{ sprintf(
								/* translators: 1: matching messages, 2: all messages checked */
								_n(
									'%1$s of %2$s accepted message would have been stopped.',
									'%1$s of %2$s accepted messages would have been stopped.',
									result.total,
									'unified-blocklist'
								),
								num( result.matched ),
								num( result.total )
							) }
						</strong>{ ' ' }
						{ __(
							'If these are real messages, allow the terms that caught them.',
							'unified-blocklist'
						) }
					</Callout>

					{ result.byTerm.length > 0 && (
						<ul className="ub-card ub-hits ub-replay-terms">
							{ result.byTerm.map( ( t ) => (
								<li key={ t.term }>
									<code className="ub-term">{ t.term }</code>
									<span className="ub-hit-from">
										{ sprintf(
											/* translators: %s: number of messages */
											_n(
												'%s message',
												'%s messages',
												t.messages,
												'unified-blocklist'
											),
											num( t.messages )
										) }
									</span>
									{ compact ? null : exceptions.has(
											t.term
									  ) ? (
										<span className="ub-allowed">
											<Icon name="check" size={ 14 } />{ ' ' }
											{ __(
												'Allowed',
												'unified-blocklist'
											) }
										</span>
									) : (
										<AllowButton
											term={ t.term }
											onAllow={ allow }
										/>
									) }
								</li>
							) ) }
						</ul>
					) }

					{ result.matches.length > 0 && (
						<ol className="ub-log ub-replay-list">
							{ result.matches
								.slice( 0, compact ? 3 : undefined )
								.map( ( m ) => (
									<li
										key={ `${ m.kind }:${ m.id }` }
										className="ub-log-row"
									>
										<div className="ub-log-when">
											{ m.date
												? ago(
														Math.floor(
															Date.parse(
																m.date
															) / 1000
														),
														state.now
													)
												: '' }
										</div>
										<div className="ub-log-where">
											<span className="ub-log-context">
												{ ITEM_LABELS[ m.kind ] ??
													m.kind }
											</span>
											{ m.label && (
												<span className="ub-log-ref">
													{ m.label }
												</span>
											) }
										</div>
										<div className="ub-log-main">
											{ m.hits.map( ( h ) => (
												<div key={ h.term }>
													<div className="ub-log-line">
														<code className="ub-term">
															{ h.term }
														</code>
														{ h.field && (
															<span className="ub-log-field">
																{ h.field }
															</span>
														) }
													</div>
													{ settings.log_excerpts && (
														<p className="ub-excerpt">
															<Highlighted
																parts={ highlight(
																	h.excerpt,
																	[ h.term ]
																) }
															/>
														</p>
													) }
												</div>
											) ) }
										</div>
										<div className="ub-log-actions">
											{ m.url && (
												<a
													className="ub-btn ub-btn--ghost ub-btn--sm"
													href={ m.url }
												>
													<Icon
														name="external"
														size={ 14 }
													/>
													{ __(
														'Open',
														'unified-blocklist'
													) }
												</a>
											) }
										</div>
									</li>
								) ) }
						</ol>
					) }
					{ result.matched >
						Math.min(
							result.matches.length,
							compact ? 3 : Infinity
						) && (
						<p className="ub-note">
							{ sprintf(
								/* translators: %s: number of messages shown */
								__(
									'Showing the first %s.',
									'unified-blocklist'
								),
								num(
									Math.min(
										result.matches.length,
										compact ? 3 : Infinity
									)
								)
							) }
						</p>
					) }
				</>
			) }
		</div>
	);
}

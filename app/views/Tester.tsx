import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { api, errorMessage } from '../api';
import {
	AllowButton,
	Callout,
	Highlighted,
	Icon,
	PageHead,
} from '../components/ui';
import { highlight, num } from '../lib/format';
import { useDebounced } from '../lib/hooks';
import { useStore } from '../store';
import type { Skipped, TestHit } from '../types';

const SAMPLES = [
	{
		label: __( 'A normal comment', 'unified-blocklist' ),
		text: __(
			'Thanks for writing this up! The second step fixed our checkout page. Do you know if it works with WooCommerce 9?',
			'unified-blocklist'
		),
	},
	{
		label: __( 'Obvious spam', 'unified-blocklist' ),
		text: 'Buy cheap viagra online, best casino bonus and payday loans with instant approval!',
	},
	{
		label: __( 'A tricky one', 'unified-blocklist' ),
		text: 'Thanks for the great article! I run a small bakery and found this really useful.',
	},
];

// Checks as you type. Nothing is logged or blocked.
export default function Tester() {
	const { allow, settings, state, update, apply, sync, syncing, toast } =
		useStore();
	const [ text, setText ] = useState( '' );
	const [ skipped, setSkipped ] = useState< Skipped[] >( [] );
	const debounced = useDebounced( text, 300 );
	const [ hits, setHits ] = useState< TestHit[] | null >( null );
	const [ meta, setMeta ] = useState< {
		checked: number;
		ms: number;
	} | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const ref = useRef< HTMLTextAreaElement >( null );

	useEffect( () => ref.current?.focus(), [] );

	useEffect( () => {
		if ( ! debounced.trim() ) {
			setHits( null );
			setMeta( null );
			setSkipped( [] );
			return;
		}
		const ctrl = new AbortController();
		setBusy( true );
		setError( null );
		api.test( debounced, ctrl.signal )
			.then( ( res ) => {
				setHits( res.hits );
				setSkipped( res.skipped ?? [] );
				setMeta( { checked: res.checked, ms: res.ms } );
			} )
			.catch( ( err ) => {
				if ( ( err as { name?: string } )?.name !== 'AbortError' ) {
					setError(
						errorMessage(
							err,
							__( 'The check failed.', 'unified-blocklist' )
						)
					);
				}
			} )
			.finally( () => setBusy( false ) );
		return () => ctrl.abort();
		// Re-run when exceptions change, so an Allow click updates the verdict right away.
	}, [
		debounced,
		settings.exceptions,
		settings.match_mode,
		settings.min_length,
		state.terms.builtAt,
	] );

	const empty = state.terms.count === 0;
	const failing = state.sources.find(
		( src ) => src.status === 'error' || src.status === 'shrink'
	);
	const shortTerms = skipped.filter( ( k ) => k.reason === 'short' );
	const excepted = skipped.filter( ( k ) => k.reason === 'exception' );
	const inWords = skipped.filter( ( k ) => k.reason === 'word' );
	const phrases = skipped.filter( ( k ) => k.reason === 'phrase' );
	const typed = debounced.trim();

	const blockTyped = () =>
		update(
			{
				local_terms: Array.from(
					new Set( [ ...settings.local_terms, typed ] )
				),
			},
			false
		);
	const removeException = ( term: string ) =>
		api
			.unallow( term )
			.then( apply )
			.catch( ( err ) =>
				toast( {
					tone: 'err',
					message: errorMessage(
						err,
						__(
							'Could not remove the exception.',
							'unified-blocklist'
						)
					),
				} )
			);

	const verdict =
		hits === null
			? null
			: hits.length === 0 && empty
				? 'empty'
				: hits.length === 0
					? 'pass'
					: settings.mode === 'monitor'
						? 'logged'
						: 'stop';
	const parts = hits
		? highlight(
				text,
				hits.map( ( h ) => h.term )
			)
		: [ { text, mark: false } ];

	return (
		<div className="ub-page">
			<PageHead
				title={ __( 'Test a message', 'unified-blocklist' ) }
				lede={ __(
					'Type or paste a comment or form message. It is checked against the full list as you type, with your current settings. Nothing is logged or blocked.',
					'unified-blocklist'
				) }
			/>

			{ empty && (
				<div className="ub-stack">
					<Callout
						tone="warn"
						icon="alert"
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
						<strong>
							{ __( 'The list is empty.', 'unified-blocklist' ) }
						</strong>{ ' ' }
						{ state.terms.error ||
							( failing
								? sprintf(
										/* translators: 1: source name, 2: error message */
										__(
											'The last download from %1$s failed: %2$s',
											'unified-blocklist'
										),
										failing.label,
										failing.error
									)
								: __(
										'Nothing has been downloaded yet, so nothing can match.',
										'unified-blocklist'
									) ) }
					</Callout>
				</div>
			) }

			<div
				className={ `ub-tester${ verdict ? ` is-${ verdict }` : '' }` }
			>
				<div className="ub-tester-input">
					<textarea
						ref={ ref }
						className="ub-textarea ub-textarea--prose"
						rows={ 6 }
						placeholder={ __(
							'Start typing…',
							'unified-blocklist'
						) }
						value={ text }
						onChange={ ( e ) => setText( e.target.value ) }
						aria-describedby="ub-verdict"
					/>
					<div className="ub-tester-samples">
						<span className="ub-muted">
							{ __( 'Try:', 'unified-blocklist' ) }
						</span>
						{ SAMPLES.map( ( s ) => (
							<button
								key={ s.label }
								type="button"
								className="ub-chip"
								onClick={ () => setText( s.text ) }
							>
								{ s.label }
							</button>
						) ) }
					</div>
				</div>

				<div className="ub-verdict" id="ub-verdict" aria-live="polite">
					{ verdict === null && ! busy && (
						<span className="ub-verdict-idle">
							<Icon name="wand" size={ 18 } />{ ' ' }
							{ __(
								'The verdict shows up here.',
								'unified-blocklist'
							) }
						</span>
					) }
					{ verdict === null && busy && (
						<span className="ub-spinner" />
					) }
					{ verdict && (
						<>
							<span className="ub-verdict-icon">
								<Icon
									name={
										verdict === 'pass'
											? 'check'
											: verdict === 'logged'
												? 'eye'
												: 'shield'
									}
									size={ 20 }
								/>
							</span>
							<div className="ub-verdict-text">
								<strong>
									{ verdict === 'empty' &&
										__(
											'Nothing to check against yet.',
											'unified-blocklist'
										) }
									{ verdict === 'pass' &&
										__(
											'This would go through.',
											'unified-blocklist'
										) }
									{ verdict === 'stop' &&
										__(
											'This would be stopped.',
											'unified-blocklist'
										) }
									{ verdict === 'logged' &&
										__(
											'This would be logged, not stopped (Monitor only).',
											'unified-blocklist'
										) }
								</strong>
								{ meta && (
									<span>
										{ sprintf(
											/* translators: 1: number of terms, 2: milliseconds */
											__(
												'Checked %1$s terms in %2$s ms',
												'unified-blocklist'
											),
											num( meta.checked ),
											meta.ms.toLocaleString()
										) }
									</span>
								) }
							</div>
							{ busy && <span className="ub-spinner" /> }
						</>
					) }
				</div>
			</div>

			{ error && <p className="ub-error-line">{ error }</p> }

			{ verdict === 'pass' && skipped.length > 0 && (
				<>
					<h3 className="ub-section-title ub-mt">
						{ __( 'Why this goes through', 'unified-blocklist' ) }
					</h3>
					<ul className="ub-card ub-why">
						{ shortTerms.length > 0 && (
							<li>
								<p>
									{ sprintf(
										/* translators: %d: minimum number of characters */
										__(
											'These listed terms are shorter than %d characters, so they are skipped:',
											'unified-blocklist'
										),
										settings.min_length
									) }{ ' ' }
									{ shortTerms.map( ( k ) => (
										<code
											className="ub-term"
											key={ k.term }
										>
											{ k.term }
										</code>
									) ) }
								</p>
								<button
									type="button"
									className="ub-btn ub-btn--outline"
									onClick={ () =>
										update( { min_length: 1 } )
									}
								>
									{ __(
										'Use all terms',
										'unified-blocklist'
									) }
								</button>
							</li>
						) }
						{ excepted.map( ( k ) => (
							<li key={ k.term }>
								<p>
									<code className="ub-term">{ k.term }</code>{ ' ' }
									{ __(
										'is in your exceptions.',
										'unified-blocklist'
									) }
								</p>
								<button
									type="button"
									className="ub-btn ub-btn--outline"
									onClick={ () => removeException( k.term ) }
								>
									{ __(
										'Remove exception',
										'unified-blocklist'
									) }
								</button>
							</li>
						) ) }
						{ inWords.length > 0 && (
							<li>
								<p>
									{ __(
										'Whole-word matching is on, and these only appear inside other words:',
										'unified-blocklist'
									) }{ ' ' }
									{ inWords.map( ( k ) => (
										<code
											className="ub-term"
											key={ k.term }
										>
											{ k.term }
										</code>
									) ) }
								</p>
								<button
									type="button"
									className="ub-btn ub-btn--outline"
									onClick={ () =>
										update( { match_mode: 'substring' } )
									}
								>
									{ __(
										'Match anywhere',
										'unified-blocklist'
									) }
								</button>
							</li>
						) }
						{ phrases.length > 0 && (
							<li>
								<p>
									{ __(
										'The list has longer entries that contain this, but not this text on its own:',
										'unified-blocklist'
									) }{ ' ' }
									{ phrases.map( ( k ) => (
										<code
											className="ub-term"
											key={ k.term }
										>
											{ k.term }
										</code>
									) ) }
								</p>
								{ typed.length <= 60 && (
									<button
										type="button"
										className="ub-btn ub-btn--outline"
										onClick={ blockTyped }
									>
										{ sprintf(
											/* translators: %s: the typed text */
											__(
												'Block "%s" too',
												'unified-blocklist'
											),
											typed
										) }
									</button>
								) }
							</li>
						) }
					</ul>
				</>
			) }

			{ hits && hits.length > 0 && (
				<>
					<div className="ub-card ub-card--pad ub-preview">
						<Highlighted parts={ parts } />
					</div>
					<h3 className="ub-section-title ub-mt">
						{ sprintf(
							/* translators: %d: number of matching terms */
							_n(
								'%d matching term',
								'%d matching terms',
								hits.length,
								'unified-blocklist'
							),
							hits.length
						) }
					</h3>
					<ul className="ub-card ub-hits">
						{ hits.map( ( h ) => (
							<li key={ h.term }>
								<code className="ub-term">{ h.term }</code>
								<span className="ub-hit-from">
									{ h.origins.join( ', ' ) }
								</span>
								<AllowButton
									term={ h.term }
									onAllow={ allow }
								/>
							</li>
						) ) }
					</ul>
				</>
			) }
		</div>
	);
}

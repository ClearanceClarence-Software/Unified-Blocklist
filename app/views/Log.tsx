import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { api, errorMessage } from '../api';
import {
	AllowButton,
	ArmedButton,
	Empty,
	Highlighted,
	Icon,
	PageHead,
	Seg,
} from '../components/ui';
import { ago, dateTime, highlight, num } from '../lib/format';
import { useDebounced, useSlashFocus } from '../lib/hooks';
import { useStore } from '../store';
import type { Context, LogRow } from '../types';
import { CONTEXT_LABELS } from './Overview';

const PER_PAGE = 40;

const RESULT_LABELS: Record< LogRow[ 'action' ], string > = {
	spam: __( 'Spam', 'unified-blocklist' ),
	hold: __( 'Held', 'unified-blocklist' ),
	trash: __( 'Trash', 'unified-blocklist' ),
	blocked: __( 'Rejected', 'unified-blocklist' ),
	logged: __( 'Logged only', 'unified-blocklist' ),
};

export default function Log() {
	const { state, settings, allow, apply, toast, logVersion } = useStore();
	const [ context, setContext ] = useState< '' | Context >( '' );
	const [ outcome, setOutcome ] = useState< '' | 'stopped' | 'logged' >( '' );
	const [ search, setSearch ] = useState( '' );
	const q = useDebounced( search.trim(), 250 );
	const [ rows, setRows ] = useState< LogRow[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const searchRef = useRef< HTMLInputElement >( null );
	useSlashFocus( searchRef );

	const load = useCallback(
		( p: number ) => {
			setLoading( true );
			setError( null );
			api.log( {
				page: p,
				perPage: PER_PAGE,
				context,
				outcome,
				search: q,
			} )
				.then( ( res ) => {
					setRows( ( prev ) =>
						p === 1 ? res.rows : [ ...prev, ...res.rows ]
					);
					setTotal( res.total );
					setPage( p );
				} )
				.catch( ( err ) =>
					setError(
						errorMessage(
							err,
							__( 'Could not load the log.', 'unified-blocklist' )
						)
					)
				)
				.finally( () => setLoading( false ) );
		},
		[ context, outcome, q ]
	);

	useEffect( () => load( 1 ), [ load, logVersion ] );

	const filtered = context !== '' || outcome !== '' || q !== '';
	const exceptions = new Set(
		settings.exceptions.map( ( e ) => e.toLowerCase() )
	);

	return (
		<div className="ub-page ub-page--wide">
			<PageHead
				title={ __( 'Activity', 'unified-blocklist' ) }
				lede={ sprintf(
					/* translators: %d: retention in days */
					__(
						'Every match from comments and forms, kept for %d days. If something legitimate was caught, allow the term and it stops matching right away.',
						'unified-blocklist'
					),
					settings.log_retention
				) }
			/>

			<div className="ub-toolbar">
				<label className="ub-search" htmlFor="ub-log-search">
					<Icon name="search" size={ 16 } />
					<input
						id="ub-log-search"
						ref={ searchRef }
						type="search"
						className="ub-search-input"
						placeholder={ __(
							'Search terms and text',
							'unified-blocklist'
						) }
						value={ search }
						onChange={ ( e ) => setSearch( e.target.value ) }
					/>
					<kbd className="ub-kbd">/</kbd>
				</label>
				<div
					className="ub-chips"
					role="group"
					aria-label={ __( 'Where', 'unified-blocklist' ) }
				>
					<button
						type="button"
						className={ `ub-chip${ context === '' ? ' is-on' : '' }` }
						onClick={ () => setContext( '' ) }
					>
						{ __( 'Everywhere', 'unified-blocklist' ) }
					</button>
					{ ( Object.keys( CONTEXT_LABELS ) as Context[] )
						.filter(
							( c ) =>
								state.available[ c ] ||
								state.activity.byContext[ c ]
						)
						.map( ( c ) => (
							<button
								key={ c }
								type="button"
								className={ `ub-chip${ context === c ? ' is-on' : '' }` }
								onClick={ () =>
									setContext( context === c ? '' : c )
								}
							>
								{ CONTEXT_LABELS[ c ] }
								{ !! state.activity.byContext[ c ] && (
									<span className="ub-chip-count">
										{ num(
											state.activity.byContext[ c ] ?? 0
										) }
									</span>
								) }
							</button>
						) ) }
				</div>
				<Seg
					label={ __( 'Result', 'unified-blocklist' ) }
					value={ outcome }
					onChange={ setOutcome }
					options={ [
						{ value: '', label: __( 'All', 'unified-blocklist' ) },
						{
							value: 'stopped',
							label: __( 'Stopped', 'unified-blocklist' ),
						},
						{
							value: 'logged',
							label: __( 'Logged only', 'unified-blocklist' ),
						},
					] }
				/>
			</div>

			<div className="ub-log-meta">
				<span aria-live="polite">
					{ loading && rows.length === 0
						? __( 'Loading…', 'unified-blocklist' )
						: sprintf(
								/* translators: %s: number of entries */
								_n(
									'%s entry',
									'%s entries',
									total,
									'unified-blocklist'
								),
								num( total )
							) }
				</span>
				{ loading && rows.length > 0 && (
					<span className="ub-spinner" />
				) }
			</div>

			{ error && <p className="ub-error-line">{ error }</p> }

			{ ! loading && rows.length === 0 && ! error && (
				<div className="ub-card">
					<Empty
						icon={ filtered ? 'search' : 'pulse' }
						title={
							filtered
								? __(
										'Nothing matches these filters.',
										'unified-blocklist'
									)
								: __( 'The log is empty.', 'unified-blocklist' )
						}
					>
						{ ! filtered &&
							__(
								'Matches from comments and forms will show up here.',
								'unified-blocklist'
							) }
					</Empty>
				</div>
			) }

			{ rows.length > 0 && (
				<ol className={ `ub-log${ loading ? ' is-stale' : '' }` }>
					{ rows.map( ( row ) => {
						const allowed = exceptions.has(
							row.term.toLowerCase()
						);
						return (
							<li key={ row.id } className="ub-log-row">
								<div
									className="ub-log-when"
									title={ dateTime( row.time ) }
								>
									{ ago(
										Math.floor(
											Date.parse( row.time ) / 1000
										),
										state.now
									) }
								</div>
								<div className="ub-log-where">
									<span className="ub-log-context">
										{ CONTEXT_LABELS[ row.context ] ??
											row.context }
									</span>
									{ row.refLabel &&
										( row.refUrl ? (
											<a
												className="ub-log-ref"
												href={ row.refUrl }
											>
												{ row.refLabel }
											</a>
										) : (
											<span className="ub-log-ref">
												{ row.refLabel }
											</span>
										) ) }
								</div>
								<div className="ub-log-main">
									<div className="ub-log-line">
										<span
											className={ `ub-result ub-result--${ row.action }` }
										>
											{ RESULT_LABELS[ row.action ] ??
												row.action }
										</span>
										<code className="ub-term">
											{ row.term }
										</code>
										{ row.field && (
											<span className="ub-log-field">
												{ row.field }
											</span>
										) }
									</div>
									{ row.excerpt && (
										<p className="ub-excerpt">
											<Highlighted
												parts={ highlight(
													row.excerpt,
													[ row.term ]
												) }
											/>
										</p>
									) }
								</div>
								<div className="ub-log-actions">
									{ allowed ? (
										<span className="ub-allowed">
											<Icon name="check" size={ 14 } />{ ' ' }
											{ __(
												'Allowed',
												'unified-blocklist'
											) }
										</span>
									) : (
										<AllowButton
											term={ row.term }
											onAllow={ allow }
										/>
									) }
								</div>
							</li>
						);
					} ) }
				</ol>
			) }

			{ rows.length < total && (
				<button
					type="button"
					className="ub-btn ub-btn--dashed"
					onClick={ () => load( page + 1 ) }
					disabled={ loading }
				>
					{ loading ? <span className="ub-spinner" /> : null }
					{ sprintf(
						/* translators: %s: number of remaining entries */
						__( 'Show more (%s left)', 'unified-blocklist' ),
						num( total - rows.length )
					) }
				</button>
			) }

			{ state.activity.total > 0 && (
				<div className="ub-log-foot">
					<ArmedButton
						className="ub-btn ub-btn--ghost ub-btn--danger"
						armedLabel={ __(
							'Click again to delete every entry',
							'unified-blocklist'
						) }
						onConfirm={ () =>
							api
								.clearLog()
								.then( ( res ) => {
									apply( res );
									setRows( [] );
									setTotal( 0 );
									toast( {
										tone: 'ok',
										message: __(
											'Log cleared.',
											'unified-blocklist'
										),
									} );
								} )
								.catch( ( err ) =>
									toast( {
										tone: 'err',
										message: errorMessage(
											err,
											__(
												'Could not clear the log.',
												'unified-blocklist'
											)
										),
									} )
								)
						}
					>
						<Icon name="trash" size={ 15 } />{ ' ' }
						{ __( 'Clear the log', 'unified-blocklist' ) }
					</ArmedButton>
				</div>
			) }
		</div>
	);
}

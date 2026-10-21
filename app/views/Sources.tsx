import { __, sprintf } from '@wordpress/i18n';

import ListEditor, { urlsUnit } from '../components/ListEditor';
import {
	ArmedButton,
	Callout,
	Dot,
	Empty,
	Icon,
	PageHead,
	Row,
	Section,
	Seg,
	Toggle,
} from '../components/ui';
import { ago, num } from '../lib/format';
import { COMMUNITY_LIST } from '../lib/community';
import { useStore } from '../store';
import type { SourceState } from '../types';

function tone( s: SourceState ): 'ok' | 'warn' | 'err' | 'muted' {
	if ( s.status === 'ok' ) {
		return 'ok';
	}
	if ( s.status === 'shrink' ) {
		return 'warn';
	}
	return s.status === 'pending' ? 'muted' : 'err';
}

export default function Sources() {
	const { state, settings, update, sync, syncing } = useStore();
	const hasCommunity = settings.sources.includes( COMMUNITY_LIST.url );
	const addCommunity = () =>
		update( { sources: [ ...settings.sources, COMMUNITY_LIST.url ] } );

	return (
		<div className="ub-page">
			<PageHead
				title={ __( 'Sources', 'unified-blocklist' ) }
				lede={ __(
					'Plain-text lists downloaded on a schedule, one term per line. Each keeps its last good copy, so a failed download never weakens protection.',
					'unified-blocklist'
				) }
				aside={
					<button
						type="button"
						className={ `ub-btn ub-btn--primary ub-btn--md${ syncing ? ' is-spinning' : '' }` }
						onClick={ () => sync() }
						disabled={ syncing || ! state.sources.length }
					>
						<Icon name="refresh" size={ 15 } />{ ' ' }
						{ syncing
							? __( 'Syncing…', 'unified-blocklist' )
							: __( 'Sync now', 'unified-blocklist' ) }
					</button>
				}
			/>

			{ state.sources.length === 0 ? (
				<div className="ub-card">
					<Empty
						icon="globe"
						title={ __( 'No sources yet.', 'unified-blocklist' ) }
					>
						<p>
							{ __(
								'Only your own terms and the Discussion list are used. Add a list to download, or start with the community list:',
								'unified-blocklist'
							) }
						</p>
						<p className="ub-community">
							<strong>{ COMMUNITY_LIST.name }</strong>
							<br />
							{ COMMUNITY_LIST.describe() }{ ' ' }
							<a
								href={ COMMUNITY_LIST.homepage }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'About this list', 'unified-blocklist' ) }
							</a>
						</p>
						<button
							type="button"
							className="ub-btn ub-btn--primary ub-btn--md"
							onClick={ addCommunity }
							disabled={ state.sourcesLocked }
						>
							<Icon name="download" size={ 15 } />
							{ __(
								'Add the community list',
								'unified-blocklist'
							) }
						</button>
					</Empty>
				</div>
			) : (
				<ul className="ub-sources">
					{ state.sources.map( ( s ) => (
						<li
							key={ s.url }
							className={ `ub-card ub-source is-${ tone( s ) }` }
						>
							<div className="ub-source-head">
								<Dot tone={ tone( s ) } />
								<div className="ub-source-name">
									<strong>{ s.label }</strong>
									<a
										href={ s.url }
										target="_blank"
										rel="noopener noreferrer"
										className="ub-source-url"
									>
										{ s.url }
										<Icon name="external" size={ 12 } />
									</a>
								</div>
								<span className="ub-source-count">
									{ num( s.count ) }
									<small>
										{ __( 'terms', 'unified-blocklist' ) }
									</small>
								</span>
							</div>
							{ s.status !== 'ok' && s.status !== 'pending' && (
								<p
									className={ `ub-source-error is-${ tone( s ) }` }
								>
									<Icon name="alert" size={ 14 } />{ ' ' }
									{ s.error }
									{ s.count > 0 && (
										<span className="ub-muted">
											{ ' ' }
											{ __(
												'The last good copy is in use.',
												'unified-blocklist'
											) }
										</span>
									) }
								</p>
							) }
							<dl className="ub-source-meta">
								<div>
									<dt>
										{ __( 'Status', 'unified-blocklist' ) }
									</dt>
									<dd>
										{ s.status === 'pending' &&
											__(
												'Waiting for first sync',
												'unified-blocklist'
											) }
										{ s.status === 'ok' &&
											( s.note === 'unchanged'
												? __(
														'Up to date',
														'unified-blocklist'
													)
												: __(
														'Updated',
														'unified-blocklist'
													) ) }
										{ s.status === 'error' &&
											__(
												'Failed',
												'unified-blocklist'
											) }
										{ s.status === 'shrink' &&
											__(
												'Held back',
												'unified-blocklist'
											) }
									</dd>
								</div>
								<div>
									<dt>
										{ __(
											'Content changed',
											'unified-blocklist'
										) }
									</dt>
									<dd>{ ago( s.updatedAt, state.now ) }</dd>
								</div>
								<div>
									<dt>
										{ __(
											'Last checked',
											'unified-blocklist'
										) }
									</dt>
									<dd>{ ago( s.checkedAt, state.now ) }</dd>
								</div>
							</dl>
						</li>
					) ) }
				</ul>
			) }

			{ state.sources.some( ( s ) => s.status === 'shrink' ) && (
				<Callout
					tone="warn"
					icon="alert"
					action={
						<ArmedButton
							armedLabel={ __(
								'Click again to accept',
								'unified-blocklist'
							) }
							onConfirm={ () => sync( true ) }
						>
							{ __(
								'Accept the smaller list',
								'unified-blocklist'
							) }
						</ArmedButton>
					}
				>
					{ __(
						'A list shrank by more than half since the last sync, so the previous copy was kept. If the change is expected, accept it.',
						'unified-blocklist'
					) }
				</Callout>
			) }

			<Section title={ __( 'List URLs', 'unified-blocklist' ) }>
				{ state.sourcesLocked ? (
					<>
						<Callout tone="info" icon="info">
							{ __(
								'These are set by UNIFIED_BLOCKLIST_SOURCES in wp-config.php, so they can only be changed there.',
								'unified-blocklist'
							) }
						</Callout>
						<pre className="ub-codebox">
							{ settings.sources.join( '\n' ) }
						</pre>
					</>
				) : (
					<div className="ub-card ub-card--pad">
						<ListEditor
							id="ub-sources"
							value={ settings.sources }
							rows={ 4 }
							unit={ urlsUnit }
							placeholder="https://example.com/blocklist.txt"
							onSave={ ( sources ) => update( { sources } ) }
						/>
						<p className="ub-help">
							{ __(
								'One URL per line. Saving downloads new lists right away. Local and private addresses are refused.',
								'unified-blocklist'
							) }
							{ ! hasCommunity && state.sources.length > 0 && (
								<>
									{ ' ' }
									<button
										type="button"
										className="ub-link"
										onClick={ addCommunity }
									>
										{ __(
											'Add the community list',
											'unified-blocklist'
										) }
									</button>
								</>
							) }
						</p>
					</div>
				) }
			</Section>

			<Section title={ __( 'Schedule', 'unified-blocklist' ) }>
				<div className="ub-card">
					<Row
						label={ __( 'Check for updates', 'unified-blocklist' ) }
						help={ sprintf(
							/* translators: %s: relative time */
							__(
								'Unchanged lists are not downloaded again. Next check: %s.',
								'unified-blocklist'
							),
							state.sync.next
								? state.sync.next <= state.now
									? __( 'due now', 'unified-blocklist' )
									: ago( state.sync.next, state.now )
								: '—'
						) }
					>
						<Seg
							label={ __(
								'Check for updates',
								'unified-blocklist'
							) }
							value={ settings.interval }
							onChange={ ( interval ) => update( { interval } ) }
							options={ [
								{
									value: 'hourly',
									label: __( 'Hourly', 'unified-blocklist' ),
								},
								{
									value: 'twicedaily',
									label: __(
										'Twice a day',
										'unified-blocklist'
									),
								},
								{
									value: 'daily',
									label: __( 'Daily', 'unified-blocklist' ),
								},
								{
									value: 'weekly',
									label: __( 'Weekly', 'unified-blocklist' ),
								},
							] }
						/>
					</Row>
					<Row
						label={ __(
							'Hold back lists that shrink',
							'unified-blocklist'
						) }
						help={ __(
							'If a list suddenly loses more than half its terms, keep the previous copy until you accept the change.',
							'unified-blocklist'
						) }
					>
						<Toggle
							checked={ settings.shrink_guard }
							onChange={ ( shrink_guard ) =>
								update( { shrink_guard } )
							}
							label={ __(
								'Hold back lists that shrink',
								'unified-blocklist'
							) }
						/>
					</Row>
				</div>
			</Section>
		</div>
	);
}

import { __, sprintf } from '@wordpress/i18n';

import ListEditor, { termsUnit } from '../components/ListEditor';
import { Icon, PageHead, Row, Section, Seg, Toggle } from '../components/ui';
import { num } from '../lib/format';
import { useStore } from '../store';

export default function Terms() {
	const { state, settings, update } = useStore();
	const c = state.terms.counts;
	const boot = window.unifiedBlocklist;

	const parts = [
		{
			label: __( 'Downloaded', 'unified-blocklist' ),
			value: c.sources,
			tone: 'a',
		},
		{
			label: __( 'Yours', 'unified-blocklist' ),
			value: c.local,
			tone: 'b',
		},
		{
			label: __( 'Discussion', 'unified-blocklist' ),
			value: c.core,
			tone: 'c',
		},
	];

	return (
		<div className="ub-page">
			<PageHead
				title={ __( 'Terms', 'unified-blocklist' ) }
				lede={ __(
					'One list built from your sources, your own terms and, if you like, the Discussion settings. Exceptions are taken out last.',
					'unified-blocklist'
				) }
			/>

			<div className="ub-card ub-card--pad ub-composition">
				<div className="ub-composition-total">
					<span className="ub-tile-value">
						{ num( state.terms.count ) }
					</span>
					<span className="ub-muted">
						{ __( 'active terms', 'unified-blocklist' ) }
					</span>
				</div>
				<dl className="ub-composition-parts">
					{ parts.map( ( p ) => (
						<div key={ p.label }>
							<dt>{ p.label }</dt>
							<dd>{ num( p.value ) }</dd>
						</div>
					) ) }
					<div>
						<dt>
							{ __(
								'Skipped as too short',
								'unified-blocklist'
							) }
						</dt>
						<dd className="ub-muted">
							{ c.skipped ? `−${ num( c.skipped ) }` : '0' }
						</dd>
					</div>
					<div>
						<dt>
							{ __(
								'Removed by exceptions',
								'unified-blocklist'
							) }
						</dt>
						<dd className="ub-muted">
							{ c.removed ? `−${ num( c.removed ) }` : '0' }
						</dd>
					</div>
				</dl>
				<a
					className="ub-btn ub-btn--outline"
					href={ boot?.downloadUrl }
				>
					<Icon name="download" size={ 15 } />{ ' ' }
					{ __( 'Download .txt', 'unified-blocklist' ) }
				</a>
			</div>
			<p className="ub-note ub-note--below">
				{ __(
					'Community lists contain slurs and explicit language. To check one word or message, use Test a message instead of reading the file.',
					'unified-blocklist'
				) }
			</p>

			<div className="ub-two">
				<Section
					title={ __( 'Your terms', 'unified-blocklist' ) }
					note={ __(
						'Added to the downloaded lists and kept across syncs.',
						'unified-blocklist'
					) }
				>
					<div className="ub-card ub-card--pad">
						<ListEditor
							id="ub-local"
							value={ settings.local_terms }
							unit={ termsUnit }
							rows={ 10 }
							placeholder={ __(
								'one term per line',
								'unified-blocklist'
							) }
							onSave={ ( local_terms ) =>
								update( { local_terms }, false )
							}
						/>
					</div>
				</Section>
				<Section
					title={ __( 'Exceptions', 'unified-blocklist' ) }
					note={ __(
						'Never blocked, whichever list they come from. The Allow buttons add to this.',
						'unified-blocklist'
					) }
				>
					<div className="ub-card ub-card--pad">
						<ListEditor
							id="ub-exceptions"
							value={ settings.exceptions }
							unit={ termsUnit }
							rows={ 10 }
							placeholder={ __(
								'one term per line',
								'unified-blocklist'
							) }
							onSave={ ( exceptions ) =>
								update( { exceptions }, false )
							}
						/>
					</div>
				</Section>
			</div>

			<Section title={ __( 'Matching', 'unified-blocklist' ) }>
				<div className="ub-card">
					<Row
						label={ __( 'Match terms', 'unified-blocklist' ) }
						help={ __(
							'Whole words gives fewer false positives: "cash" no longer matches "cashew". Community lists are written for matching anywhere.',
							'unified-blocklist'
						) }
					>
						<Seg
							label={ __( 'Match terms', 'unified-blocklist' ) }
							value={ settings.match_mode }
							onChange={ ( match_mode ) =>
								update( { match_mode } )
							}
							options={ [
								{
									value: 'substring',
									label: __(
										'Anywhere',
										'unified-blocklist'
									),
								},
								{
									value: 'word',
									label: __(
										'Whole words',
										'unified-blocklist'
									),
								},
							] }
						/>
					</Row>
					<Row
						label={ __(
							'Shortest downloaded term',
							'unified-blocklist'
						) }
						help={ __(
							'Very short terms from downloaded lists cause most accidental matches. Your own terms are always used.',
							'unified-blocklist'
						) }
						htmlFor="ub-min"
					>
						<select
							id="ub-min"
							className="ub-input"
							value={ settings.min_length }
							onChange={ ( e ) =>
								update( {
									min_length: Number( e.target.value ),
								} )
							}
						>
							{ [ 1, 2, 3, 4, 5, 6, 8 ].map( ( n ) => (
								<option key={ n } value={ n }>
									{ n === 1
										? __(
												'Use everything',
												'unified-blocklist'
											)
										: sprintf(
												/* translators: %d: number of characters */
												__(
													'%d characters',
													'unified-blocklist'
												),
												n
											) }
								</option>
							) ) }
						</select>
					</Row>
					<Row
						label={ __(
							'Include the Discussion list',
							'unified-blocklist'
						) }
						help={
							<>
								{ sprintf(
									/* translators: %s: number of lines */
									__(
										'Use the %s lines in Settings › Discussion › Disallowed Comment Keys for forms too.',
										'unified-blocklist'
									),
									num( state.coreLines )
								) }{ ' ' }
								<a href={ boot?.discussion }>
									{ __(
										'Open Discussion settings',
										'unified-blocklist'
									) }
								</a>
							</>
						}
					>
						<Toggle
							checked={ settings.include_core }
							onChange={ ( include_core ) =>
								update( { include_core } )
							}
							label={ __(
								'Include the Discussion list',
								'unified-blocklist'
							) }
						/>
					</Row>
				</div>
			</Section>
		</div>
	);
}

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { api, errorMessage } from '../api';
import { ago, highlight } from '../lib/format';
import { useStore } from '../store';
import type { Rescue } from '../types';
import { AllowButton, Highlighted, Icon } from './ui';

const WHERE: Record< string, string > = {
	comment: __( 'comments', 'unified-blocklist' ),
	gravityforms: 'Gravity Forms',
};

// Terms that caught messages a moderator later rescued ("Not spam", restored, approved).
// Nothing is allowed automatically; each suggestion waits for Allow or Keep blocking.
export default function RescueCard( { limit }: { limit?: number } ) {
	const { state, allow, apply, toast } = useStore();
	const [ busy, setBusy ] = useState( '' );
	const list = state.rescues ?? [];
	if ( ! list.length ) {
		return null;
	}
	const shown = limit ? list.slice( 0, limit ) : list;

	const keep = ( r: Rescue ) => {
		setBusy( r.term );
		api.dismissRescue( r.term )
			.then( apply )
			.catch( ( err ) =>
				toast( {
					tone: 'err',
					message: errorMessage(
						err,
						__( 'Could not dismiss.', 'unified-blocklist' )
					),
				} )
			)
			.finally( () => setBusy( '' ) );
	};

	return (
		<section
			className="ub-card ub-rescues"
			aria-labelledby="ub-rescues-title"
		>
			<header className="ub-rescues-head">
				<span className="ub-rescues-icon">
					<Icon name="undo" size={ 16 } />
				</span>
				<div>
					<h3 className="ub-rescues-title" id="ub-rescues-title">
						{ sprintf(
							/* translators: %d: number of terms */
							_n(
								'Moderators rescued messages stopped by %d term',
								'Moderators rescued messages stopped by %d terms',
								list.length,
								'unified-blocklist'
							),
							list.length
						) }
					</h3>
					<p className="ub-muted">
						{ __(
							'Allow a term if it keeps catching real people. Keep blocking if the rescue was a one-off.',
							'unified-blocklist'
						) }
					</p>
				</div>
			</header>
			<ul className="ub-rescues-list">
				{ shown.map( ( r ) => (
					<li key={ r.term }>
						<div className="ub-rescues-main">
							<div className="ub-log-line">
								<code className="ub-term">{ r.term }</code>
								<span className="ub-muted">
									{ sprintf(
										/* translators: 1: number of rescues, 2: where (comments, Gravity Forms), 3: time ago */
										_n(
											'Rescued %1$d time in %2$s, %3$s',
											'Rescued %1$d times in %2$s, last %3$s',
											r.count,
											'unified-blocklist'
										),
										r.count,
										WHERE[ r.context ] ?? r.context,
										ago( r.last, state.now )
									) }
								</span>
							</div>
							{ r.example && (
								<p className="ub-excerpt">
									<Highlighted
										parts={ highlight( r.example, [
											r.term,
										] ) }
									/>
								</p>
							) }
						</div>
						<div className="ub-rescues-actions">
							<AllowButton term={ r.term } onAllow={ allow } />
							<button
								type="button"
								className="ub-btn ub-btn--ghost ub-btn--sm"
								onClick={ () => keep( r ) }
								disabled={ busy === r.term }
							>
								{ busy === r.term ? (
									<span className="ub-spinner" />
								) : null }
								{ __( 'Keep blocking', 'unified-blocklist' ) }
							</button>
						</div>
					</li>
				) ) }
			</ul>
			{ limit && list.length > limit && (
				<p className="ub-note ub-rescues-more">
					{ sprintf(
						/* translators: %d: number of more suggestions */
						__(
							'%d more on the Activity page.',
							'unified-blocklist'
						),
						list.length - limit
					) }
				</p>
			) }
		</section>
	);
}

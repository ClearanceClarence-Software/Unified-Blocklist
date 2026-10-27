import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { api, errorMessage } from '../api';
import { dateTime, num } from '../lib/format';
import { useStore } from '../store';
import { ArmedButton, Callout, Icon } from './ui';

// WordPress checks every line in Settings › Discussion › Disallowed Comment Keys itself, one
// regular expression per line per comment field. Moving the lines here means comments are
// checked once, with the fast matcher. IP addresses stay, since only WordPress checks the IP.
export function useDiscussionMove() {
	const { state, apply, toast } = useStore();
	const [ busy, setBusy ] = useState( false );
	const movable = Math.max( 0, state.coreLines - state.coreIps );

	const restore = () =>
		api
			.restoreDiscussion()
			.then( ( res ) => {
				apply( res );
				toast( {
					tone: 'ok',
					message: __(
						'The Discussion list is back as it was.',
						'unified-blocklist'
					),
				} );
			} )
			.catch( ( err ) =>
				toast( {
					tone: 'err',
					message: errorMessage(
						err,
						__( 'Could not put it back.', 'unified-blocklist' )
					),
				} )
			);

	const move = () => {
		setBusy( true );
		api.moveDiscussion()
			.then( ( res ) => {
				apply( res );
				const { moved, added } = res.result;
				const movedText = sprintf(
					/* translators: %s: number of lines */
					_n(
						'Moved %s line.',
						'Moved %s lines.',
						moved,
						'unified-blocklist'
					),
					num( moved )
				);
				let addedText: string = __(
					'All of them were already in your lists.',
					'unified-blocklist'
				);
				if ( added > 0 && added === moved ) {
					addedText = __(
						'They are now in Your terms.',
						'unified-blocklist'
					);
				} else if ( added > 0 ) {
					addedText = sprintf(
						/* translators: %s: number of terms */
						_n(
							'%s new term was added to Your terms; the rest were already in your lists.',
							'%s new terms were added to Your terms; the rest were already in your lists.',
							added,
							'unified-blocklist'
						),
						num( added )
					);
				}
				toast( {
					tone: 'ok',
					message: `${ movedText } ${ addedText }`,
					action: {
						label: __( 'Undo', 'unified-blocklist' ),
						run: restore,
					},
				} );
			} )
			.catch( ( err ) =>
				toast( {
					tone: 'err',
					message: errorMessage(
						err,
						__( 'Could not move the list.', 'unified-blocklist' )
					),
				} )
			)
			.finally( () => setBusy( false ) );
	};

	return { movable, busy, move, restore };
}

export function MoveButton( { primary = false }: { primary?: boolean } ) {
	const { movable, busy, move } = useDiscussionMove();
	return (
		<button
			type="button"
			className={ `ub-btn ${ primary ? 'ub-btn--primary ub-btn--md' : 'ub-btn--outline' }` }
			onClick={ move }
			disabled={ busy || movable === 0 }
		>
			{ busy ? (
				<span className="ub-spinner" />
			) : (
				<Icon name="download" size={ 15 } />
			) }
			{ __( 'Move them here', 'unified-blocklist' ) }
		</button>
	);
}

// Shown in Terms › Matching.
export function DiscussionMoveRow() {
	const { state } = useStore();
	const { movable, restore } = useDiscussionMove();

	if ( movable > 0 ) {
		return (
			<Callout tone="info" icon="gauge" action={ <MoveButton /> }>
				<strong>
					{ sprintf(
						/* translators: %s: number of lines */
						_n(
							'WordPress also checks %s Discussion line on every comment, the slow way.',
							'WordPress also checks %s Discussion lines on every comment, the slow way.',
							movable,
							'unified-blocklist'
						),
						num( movable )
					) }
				</strong>{ ' ' }
				{ __(
					'Move them into Unified Blocklist and comments are checked once, in milliseconds, and the lines cover your forms too. Lines your downloaded lists already have are not copied.',
					'unified-blocklist'
				) }
				{ state.coreIps > 0 &&
					' ' +
						sprintf(
							/* translators: %s: number of IP addresses */
							_n(
								'%s IP address stays in Discussion settings, since only WordPress checks the commenter’s address.',
								'%s IP addresses stay in Discussion settings, since only WordPress checks the commenter’s address.',
								state.coreIps,
								'unified-blocklist'
							),
							num( state.coreIps )
						) }
			</Callout>
		);
	}

	if ( state.coreMoved ) {
		return (
			<Callout
				tone="ok"
				icon="check"
				action={
					<ArmedButton
						onConfirm={ restore }
						armedLabel={ __(
							'Click again to put them back',
							'unified-blocklist'
						) }
					>
						<Icon name="undo" size={ 15 } />{ ' ' }
						{ __( 'Put them back', 'unified-blocklist' ) }
					</ArmedButton>
				}
			>
				{ sprintf(
					/* translators: 1: number of lines, 2: date */
					_n(
						'%1$s Discussion line was moved here on %2$s. WordPress no longer checks it on its own.',
						'%1$s Discussion lines were moved here on %2$s. WordPress no longer checks them on its own.',
						state.coreMoved.moved,
						'unified-blocklist'
					),
					num( state.coreMoved.moved ),
					dateTime(
						new Date( state.coreMoved.at * 1000 ).toISOString()
					)
				) }
			</Callout>
		);
	}

	return null;
}

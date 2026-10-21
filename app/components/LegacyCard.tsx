import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { api, errorMessage } from '../api';
import { num } from '../lib/format';
import { useStore } from '../store';
import type { LegacyReport } from '../types';
import { Icon } from './ui';

interface Step {
	id: string;
	label: string;
	help?: string;
	show: boolean;
	initial: boolean;
}

// Cleanup for sites that ran Dynamic Blacklist Updater.
export default function LegacyCard( { report }: { report: LegacyReport } ) {
	const { apply, toast } = useStore();
	const steps: Step[] = [
		{
			id: 'deactivate',
			label: __(
				'Deactivate Dynamic Blacklist Updater',
				'unified-blocklist'
			),
			help: __(
				'Otherwise it refills the Discussion fields on its next run.',
				'unified-blocklist'
			),
			show: report.active_plugin !== '',
			initial: true,
		},
		{
			id: 'import_terms',
			label: sprintf(
				/* translators: %d: number of terms */
				_n(
					'Import its %d custom term into Your terms',
					'Import its %d custom terms into Your terms',
					report.user_terms.length,
					'unified-blocklist'
				),
				report.user_terms.length
			),
			help:
				report.user_terms.slice( 0, 6 ).join( ', ' ) +
				( report.user_terms.length > 6 ? ' …' : '' ),
			show: report.user_terms.length > 0,
			initial: true,
		},
		{
			id: 'clear_disallowed',
			label: sprintf(
				/* translators: %s: number of lines */
				__(
					'Empty "Disallowed Comment Keys" (%s lines)',
					'unified-blocklist'
				),
				num( report.disallowed_lines )
			),
			help: __(
				'WordPress loads this on every page view and checks every comment against it.',
				'unified-blocklist'
			),
			show: report.disallowed_lines > 0,
			initial: report.disallowed_lines > 1000,
		},
		{
			id: 'clear_moderation',
			label: sprintf(
				/* translators: %s: number of lines */
				__(
					'Empty "Comment Moderation" keys (%s lines)',
					'unified-blocklist'
				),
				num( report.moderation_lines )
			),
			show: report.moderation_lines > 0,
			initial: report.moderation_lines > 1000,
		},
		{
			id: 'fix_flag',
			label: __(
				'Turn off "Comment must be manually approved"',
				'unified-blocklist'
			),
			help: __(
				'The old plugin switched this on by mistake, so every comment has been waiting for approval.',
				'unified-blocklist'
			),
			show: report.moderation_flag_bad,
			initial: true,
		},
		{
			id: 'remove_options',
			label: __(
				'Remove its settings and scheduled task',
				'unified-blocklist'
			),
			show: report.old_options,
			initial: true,
		},
	].filter( ( s ) => s.show );

	const [ picked, setPicked ] = useState< Record< string, boolean > >( () =>
		Object.fromEntries( steps.map( ( s ) => [ s.id, s.initial ] ) )
	);
	const [ busy, setBusy ] = useState( false );
	const chosen = steps.filter( ( s ) => picked[ s.id ] ).map( ( s ) => s.id );

	const run = async () => {
		setBusy( true );
		try {
			const res = await api.legacy( chosen );
			apply( res );
			toast( {
				tone: 'ok',
				message: __( 'Cleanup finished.', 'unified-blocklist' ),
			} );
		} catch ( err ) {
			toast( {
				tone: 'err',
				message: errorMessage(
					err,
					__( 'Cleanup failed.', 'unified-blocklist' )
				),
			} );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="ub-card ub-legacy">
			<div className="ub-legacy-head">
				<span className="ub-legacy-icon">
					<Icon name="broom" size={ 18 } />
				</span>
				<div>
					<h3>
						{ __(
							'Clean up after Dynamic Blacklist Updater',
							'unified-blocklist'
						) }
					</h3>
					<p>
						{ __(
							'The old plugin copied its whole list into your Discussion settings. Unified Blocklist keeps its own copy, so those can go.',
							'unified-blocklist'
						) }
					</p>
				</div>
			</div>
			<ul className="ub-checks">
				{ steps.map( ( s ) => (
					<li key={ s.id }>
						<label htmlFor={ `ub-legacy-${ s.id }` }>
							<input
								id={ `ub-legacy-${ s.id }` }
								type="checkbox"
								checked={ !! picked[ s.id ] }
								onChange={ ( e ) =>
									setPicked( {
										...picked,
										[ s.id ]: e.target.checked,
									} )
								}
							/>
							<span>
								<span className="ub-checks-label">
									{ s.label }
								</span>
								{ s.help && (
									<span className="ub-checks-help">
										{ s.help }
									</span>
								) }
							</span>
						</label>
					</li>
				) ) }
			</ul>
			<div className="ub-legacy-foot">
				<p className="ub-muted">
					{ __(
						'If you added your own lines to the Discussion fields, copy them into Terms first. The cleanup cannot tell them apart.',
						'unified-blocklist'
					) }
				</p>
				<button
					type="button"
					className="ub-btn ub-btn--primary ub-btn--md"
					onClick={ run }
					disabled={ busy || chosen.length === 0 }
				>
					{ busy ? (
						<span className="ub-spinner" />
					) : (
						<Icon name="broom" size={ 15 } />
					) }
					{ __( 'Run cleanup', 'unified-blocklist' ) }
				</button>
			</div>
		</div>
	);
}

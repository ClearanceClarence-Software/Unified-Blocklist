import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Icon, PageHead, Row, Section, Seg, Toggle } from '../components/ui';
import { useStore } from '../store';
import type { FormId } from '../types';
import { CONTEXT_LABELS, coverageEffect } from './Overview';

const FORM_HELP: Record< FormId, string > = {
	cf7: __(
		"Uses Contact Form 7's spam handling. The visitor sees its spam message and no email is sent.",
		'unified-blocklist'
	),
	gravityforms: __(
		'The entry is saved under Entries › Spam with a note. The visitor sees the normal confirmation.',
		'unified-blocklist'
	),
	wpforms: __(
		'The form is rejected with your message. Nothing is saved or sent.',
		'unified-blocklist'
	),
	formidable: __(
		'The form is rejected with your message. Nothing is saved or sent.',
		'unified-blocklist'
	),
};

export default function Protection() {
	const { state, settings, update } = useStore();
	const [ message, setMessage ] = useState( settings.form_message );
	useEffect(
		() => setMessage( settings.form_message ),
		[ settings.form_message ]
	);
	const saveMessage = () => {
		if ( message.trim() !== settings.form_message ) {
			update( { form_message: message.trim() } );
		}
	};

	return (
		<div className="ub-page">
			<PageHead
				title={ __( 'Protection', 'unified-blocklist' ) }
				lede={ __(
					'What happens when a comment or form matches. Changes save as you make them.',
					'unified-blocklist'
				) }
			/>

			<div
				className="ub-modes"
				role="radiogroup"
				aria-label={ __( 'Mode', 'unified-blocklist' ) }
			>
				<button
					type="button"
					role="radio"
					aria-checked={ settings.mode === 'enforce' }
					className={ `ub-mode${ settings.mode === 'enforce' ? ' is-on' : '' }` }
					onClick={ () => update( { mode: 'enforce' } ) }
				>
					<span className="ub-mode-icon">
						<Icon name="shield" size={ 20 } />
					</span>
					<span className="ub-mode-text">
						<strong>
							{ __( 'Enforce', 'unified-blocklist' ) }
						</strong>
						<span>
							{ __(
								'Stop matching comments and forms as set below.',
								'unified-blocklist'
							) }
						</span>
					</span>
					<span className="ub-mode-radio" aria-hidden="true" />
				</button>
				<button
					type="button"
					role="radio"
					aria-checked={ settings.mode === 'monitor' }
					className={ `ub-mode ub-mode--monitor${ settings.mode === 'monitor' ? ' is-on' : '' }` }
					onClick={ () => update( { mode: 'monitor' } ) }
				>
					<span className="ub-mode-icon">
						<Icon name="eye" size={ 20 } />
					</span>
					<span className="ub-mode-text">
						<strong>
							{ __( 'Monitor only', 'unified-blocklist' ) }
						</strong>
						<span>
							{ __(
								'Log matches, let everything through. Try it for a week on a busy site first.',
								'unified-blocklist'
							) }
						</span>
					</span>
					<span className="ub-mode-radio" aria-hidden="true" />
				</button>
			</div>

			<Section title={ __( 'Comments', 'unified-blocklist' ) }>
				<div className="ub-card">
					<Row
						label={ __(
							'When a comment matches',
							'unified-blocklist'
						) }
						help={ __(
							'Spam keeps comments recoverable and trains spam filters. The Discussion list keeps working on its own either way.',
							'unified-blocklist'
						) }
						wide
					>
						<Seg
							label={ __(
								'When a comment matches',
								'unified-blocklist'
							) }
							value={ settings.comment_action }
							onChange={ ( comment_action ) =>
								update( { comment_action } )
							}
							options={ [
								{
									value: 'spam',
									label: __( 'Spam', 'unified-blocklist' ),
								},
								{
									value: 'hold',
									label: __( 'Hold', 'unified-blocklist' ),
								},
								{
									value: 'trash',
									label: __( 'Trash', 'unified-blocklist' ),
								},
								{
									value: 'off',
									label: __( 'Off', 'unified-blocklist' ),
								},
							] }
						/>
					</Row>
				</div>
			</Section>

			<Section title={ __( 'Forms', 'unified-blocklist' ) }>
				<div className="ub-card">
					{ (
						[
							'cf7',
							'gravityforms',
							'wpforms',
							'formidable',
						] as FormId[]
					 ).map( ( f ) => (
						<Row
							key={ f }
							label={
								<>
									{ CONTEXT_LABELS[ f ] }
									{ ! state.available[ f ] && (
										<span className="ub-badge">
											{ __(
												'Not installed',
												'unified-blocklist'
											) }
										</span>
									) }
									{ state.available[ f ] &&
										settings.forms[ f ] && (
											<span className="ub-badge ub-badge--ok">
												{ coverageEffect(
													f,
													settings.comment_action
												) }
											</span>
										) }
								</>
							}
							help={ FORM_HELP[ f ] }
						>
							<Toggle
								checked={ settings.forms[ f ] }
								onChange={ ( v ) =>
									update( {
										forms: { ...settings.forms, [ f ]: v },
									} )
								}
								label={ CONTEXT_LABELS[ f ] }
							/>
						</Row>
					) ) }
					<Row
						label={ __( 'Rejection message', 'unified-blocklist' ) }
						help={ __(
							'Shown by WPForms and Formidable. It never says which term matched.',
							'unified-blocklist'
						) }
						htmlFor="ub-message"
						wide
					>
						<input
							id="ub-message"
							type="text"
							className="ub-input ub-input--wide"
							value={ message }
							placeholder={ __(
								'Your message could not be sent because it contains blocked content.',
								'unified-blocklist'
							) }
							onChange={ ( e ) => setMessage( e.target.value ) }
							onBlur={ saveMessage }
							onKeyDown={ ( e ) =>
								e.key === 'Enter' &&
								( e.target as HTMLInputElement ).blur()
							}
						/>
					</Row>
				</div>
			</Section>

			<Section title={ __( 'Who is checked', 'unified-blocklist' ) }>
				<div className="ub-card">
					<Row
						label={ __(
							'Skip trusted users',
							'unified-blocklist'
						) }
						help={ __(
							'Logged-in users who can moderate comments are never checked.',
							'unified-blocklist'
						) }
					>
						<Toggle
							checked={ settings.skip_trusted }
							onChange={ ( skip_trusted ) =>
								update( { skip_trusted } )
							}
							label={ __(
								'Skip trusted users',
								'unified-blocklist'
							) }
						/>
					</Row>
				</div>
			</Section>

			<Section title={ __( 'Log and privacy', 'unified-blocklist' ) }>
				<div className="ub-card">
					<Row
						label={ __(
							'Keep log entries for',
							'unified-blocklist'
						) }
						htmlFor="ub-retention"
					>
						<select
							id="ub-retention"
							className="ub-input"
							value={ settings.log_retention }
							onChange={ ( e ) =>
								update( {
									log_retention: Number( e.target.value ),
								} )
							}
						>
							{ [ 7, 14, 30, 60, 90, 180, 365 ].map( ( d ) => (
								<option key={ d } value={ d }>
									{ sprintf(
										/* translators: %d: number of days */
										_n(
											'%d day',
											'%d days',
											d,
											'unified-blocklist'
										),
										d
									) }
								</option>
							) ) }
						</select>
					</Row>
					<Row
						label={ __(
							'Store an excerpt with each match',
							'unified-blocklist'
						) }
						help={ __(
							'Excerpts make false positives easy to spot. IP addresses are never stored. Turn this off if submissions may contain personal data you do not want kept.',
							'unified-blocklist'
						) }
					>
						<Toggle
							checked={ settings.log_excerpts }
							onChange={ ( log_excerpts ) =>
								update( { log_excerpts } )
							}
							label={ __(
								'Store an excerpt with each match',
								'unified-blocklist'
							) }
						/>
					</Row>
				</div>
			</Section>
		</div>
	);
}

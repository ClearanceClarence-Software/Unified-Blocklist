import { __ } from '@wordpress/i18n';

import { useStore } from '../store';
import { Icon, type IconName } from './ui';

const ICONS: Record< string, IconName > = {
	ok: 'check',
	err: 'alert',
	warn: 'alert',
	info: 'info',
};

export default function Toasts() {
	const { toasts, dismiss } = useStore();
	return (
		<div className="ub-toasts" aria-live="polite" aria-relevant="additions">
			{ toasts.map( ( t ) => (
				<div
					key={ t.id }
					className={ `ub-toast ub-toast--${ t.tone }` }
				>
					<span className="ub-toast-icon">
						<Icon name={ ICONS[ t.tone ] } size={ 15 } />
					</span>
					<span className="ub-toast-msg">{ t.message }</span>
					{ t.action && (
						<button
							type="button"
							className="ub-toast-action"
							onClick={ () => {
								t.action?.run();
								dismiss( t.id );
							} }
						>
							{ t.action.label }
						</button>
					) }
					<button
						type="button"
						className="ub-toast-close"
						aria-label={ __( 'Dismiss', 'unified-blocklist' ) }
						onClick={ () => dismiss( t.id ) }
					>
						<Icon name="x" size={ 14 } />
					</button>
				</div>
			) ) }
		</div>
	);
}

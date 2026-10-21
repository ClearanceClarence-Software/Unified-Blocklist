import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { num, shortDate } from '../lib/format';
import type { DailyCount } from '../types';

// Matches per day for the last 30 days. One series (total matches), so no legend; the
// tooltip breaks a day into stopped and logged. Bars are thin, rounded at the data end,
// anchored to the baseline, with a 2px gap.
export default function ActivityChart( { days }: { days: DailyCount[] } ) {
	const [ hover, setHover ] = useState< number | null >( null );
	const totals = days.map( ( d ) => d.stopped + d.logged );
	const max = Math.max( 1, ...totals );
	// A round top value keeps the single gridline honest.
	const step =
		max <= 4
			? 1
			: max <= 10
				? 5
				: Math.pow( 10, Math.floor( Math.log10( max ) ) );
	const top = Math.ceil( max / step ) * step;

	const H = 120;
	const W = 600;
	const gap = 2;
	const bw = W / days.length - gap;
	const active = hover !== null ? days[ hover ] : null;

	return (
		<div className="ub-chart">
			<div
				className="ub-chart-plot"
				onMouseLeave={ () => setHover( null ) }
			>
				<span className="ub-chart-axis ub-chart-axis--top">
					{ num( top ) }
				</span>
				<svg
					viewBox={ `0 0 ${ W } ${ H }` }
					preserveAspectRatio="none"
					role="img"
					aria-label={ __(
						'Matches per day, last 30 days',
						'unified-blocklist'
					) }
				>
					<line
						className="ub-chart-grid"
						x1="0"
						x2={ W }
						y1="0.5"
						y2="0.5"
					/>
					<line
						className="ub-chart-base"
						x1="0"
						x2={ W }
						y1={ H - 0.5 }
						y2={ H - 0.5 }
					/>
					{ days.map( ( d, i ) => {
						const total = totals[ i ];
						const h = total
							? Math.max( 3, ( total / top ) * ( H - 4 ) )
							: 0;
						const x = i * ( bw + gap ) + gap / 2;
						return (
							<g key={ d.date }>
								{ /* Hit target is the full column, wider and taller than the mark. */ }
								<rect
									className="ub-chart-hit"
									x={ x - gap / 2 }
									y={ 0 }
									width={ bw + gap }
									height={ H }
									onMouseEnter={ () => setHover( i ) }
								/>
								{ h > 0 && (
									<path
										className={ `ub-chart-bar${ hover === i ? ' is-hover' : '' }${ d.stopped === 0 ? ' is-logged' : '' }` }
										d={ roundedTop(
											x,
											H - h,
											bw,
											h,
											Math.min( 4, bw / 2, h )
										) }
									/>
								) }
							</g>
						);
					} ) }
				</svg>
				{ active && hover !== null && (
					<div
						className="ub-chart-tip"
						style={ {
							left: `${ ( ( hover + 0.5 ) / days.length ) * 100 }%`,
						} }
						role="status"
					>
						<strong>{ shortDate( active.date ) }</strong>
						<span>
							{ sprintf(
								/* translators: %s: number of matches */
								_n(
									'%s match',
									'%s matches',
									active.stopped + active.logged,
									'unified-blocklist'
								),
								num( active.stopped + active.logged )
							) }
						</span>
						{ active.stopped + active.logged > 0 && (
							<span className="ub-muted">
								{ sprintf(
									/* translators: 1: stopped count, 2: logged-only count */
									__(
										'%1$s stopped · %2$s logged only',
										'unified-blocklist'
									),
									num( active.stopped ),
									num( active.logged )
								) }
							</span>
						) }
					</div>
				) }
			</div>
			<div className="ub-chart-x">
				<span>{ days.length ? shortDate( days[ 0 ].date ) : '' }</span>
				<span>{ __( 'Today', 'unified-blocklist' ) }</span>
			</div>
			<table className="screen-reader-text">
				<caption>
					{ __( 'Matches per day', 'unified-blocklist' ) }
				</caption>
				<tbody>
					{ days.map( ( d ) => (
						<tr key={ d.date }>
							<th scope="row">{ d.date }</th>
							<td>{ d.stopped + d.logged }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function roundedTop( x: number, y: number, w: number, h: number, r: number ) {
	return `M${ x },${ y + h }V${ y + r }Q${ x },${ y } ${ x + r },${ y }H${ x + w - r }Q${ x + w },${ y } ${ x + w },${ y + r }V${ y + h }Z`;
}

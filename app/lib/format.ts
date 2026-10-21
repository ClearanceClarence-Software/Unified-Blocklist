import { __ } from '@wordpress/i18n';

const locale = () => document.documentElement.lang || 'en-US';

export function num( n: number ): string {
	return new Intl.NumberFormat( locale() ).format( n );
}

// 65570 -> "65.6k". Used where space is tight, such as sidebar counts.
export function compact( n: number ): string {
	return new Intl.NumberFormat( locale(), {
		notation: 'compact',
		maximumFractionDigits: 1,
	} ).format( n );
}

const UNITS: Array< [ Intl.RelativeTimeFormatUnit, number ] > = [
	[ 'year', 31536000 ],
	[ 'month', 2592000 ],
	[ 'week', 604800 ],
	[ 'day', 86400 ],
	[ 'hour', 3600 ],
	[ 'minute', 60 ],
];

// Relative time from unix seconds. "now" comes from the server so clocks never disagree.
export function ago( ts: number, now: number ): string {
	if ( ! ts ) {
		return __( 'never', 'unified-blocklist' );
	}
	const diff = ts - now;
	const abs = Math.abs( diff );
	if ( abs < 45 ) {
		return __( 'just now', 'unified-blocklist' );
	}
	const rtf = new Intl.RelativeTimeFormat( locale(), { numeric: 'auto' } );
	for ( const [ unit, secs ] of UNITS ) {
		if ( abs >= secs ) {
			return rtf.format( Math.round( diff / secs ), unit );
		}
	}
	return rtf.format( Math.round( diff / 60 ), 'minute' );
}

export function dateTime( iso: string ): string {
	return new Intl.DateTimeFormat( locale(), {
		dateStyle: 'medium',
		timeStyle: 'short',
	} ).format( new Date( iso ) );
}

export function shortDate( ymd: string ): string {
	return new Intl.DateTimeFormat( locale(), {
		month: 'short',
		day: 'numeric',
		timeZone: 'UTC',
	} ).format( new Date( ymd + 'T00:00:00Z' ) );
}

// Splits text into plain and marked parts for every case-insensitive occurrence of the terms.
export function highlight(
	text: string,
	terms: string[]
): Array< { text: string; mark: boolean } > {
	const lower = text.toLowerCase();
	const ranges: Array< [ number, number ] > = [];
	terms.forEach( ( term ) => {
		const t = term.toLowerCase();
		if ( ! t ) {
			return;
		}
		let i = lower.indexOf( t );
		while ( i !== -1 ) {
			ranges.push( [ i, i + t.length ] );
			i = lower.indexOf( t, i + 1 );
		}
	} );
	if ( ! ranges.length ) {
		return [ { text, mark: false } ];
	}
	ranges.sort( ( a, b ) => a[ 0 ] - b[ 0 ] );
	const merged: Array< [ number, number ] > = [];
	ranges.forEach( ( r ) => {
		const last = merged[ merged.length - 1 ];
		if ( last && r[ 0 ] <= last[ 1 ] ) {
			last[ 1 ] = Math.max( last[ 1 ], r[ 1 ] );
		} else {
			merged.push( [ ...r ] );
		}
	} );
	const out: Array< { text: string; mark: boolean } > = [];
	let pos = 0;
	merged.forEach( ( [ a, b ] ) => {
		if ( a > pos ) {
			out.push( { text: text.slice( pos, a ), mark: false } );
		}
		out.push( { text: text.slice( a, b ), mark: true } );
		pos = b;
	} );
	if ( pos < text.length ) {
		out.push( { text: text.slice( pos ), mark: false } );
	}
	return out;
}

import apiFetch from '@wordpress/api-fetch';

import type {
	LegacyReport,
	LogRow,
	ReplayResult,
	Settings,
	State,
	SyncResult,
	Skipped,
	TestHit,
	Theme,
} from './types';

const NS = '/unified-blocklist/v1';

export interface SettingsResponse {
	settings: Settings;
	state: State;
	sync?: SyncResult[] | null;
}

export interface LogQuery {
	page: number;
	perPage: number;
	context: string;
	outcome: string;
	search: string;
}

function withQuery( path: string, query: Record< string, string | number > ) {
	const params = new URLSearchParams();
	Object.entries( query ).forEach( ( [ k, v ] ) => {
		if ( v !== '' ) {
			params.set( k, String( v ) );
		}
	} );
	const qs = params.toString();
	return qs ? `${ path }?${ qs }` : path;
}

export const api = {
	state: () => apiFetch< State >( { path: `${ NS }/state` } ),

	// Only what changed is sent. The server answers with the full, validated settings.
	saveSettings: ( patch: Partial< Settings > ) =>
		apiFetch< SettingsResponse >( {
			path: `${ NS }/settings`,
			method: 'POST',
			data: patch,
		} ),

	sync: ( acceptSmaller = false ) =>
		apiFetch< { sync: SyncResult[]; state: State } >( {
			path: `${ NS }/sync`,
			method: 'POST',
			data: { acceptSmaller },
		} ),

	log: ( query: LogQuery ) =>
		apiFetch< { rows: LogRow[]; total: number } >( {
			path: withQuery( `${ NS }/log`, { ...query } ),
		} ),

	clearLog: () =>
		apiFetch< { state: State } >( {
			path: `${ NS }/log`,
			method: 'DELETE',
		} ),

	allow: ( term: string ) =>
		apiFetch< SettingsResponse >( {
			path: `${ NS }/exceptions`,
			method: 'POST',
			data: { term },
		} ),

	unallow: ( term: string ) =>
		apiFetch< SettingsResponse >( {
			path: `${ NS }/exceptions`,
			method: 'DELETE',
			data: { term },
		} ),

	test: ( text: string, signal?: AbortSignal ) =>
		apiFetch< {
			hits: TestHit[];
			checked: number;
			ms: number;
			skipped: Skipped[];
		} >( {
			path: `${ NS }/test`,
			method: 'POST',
			data: { text },
			signal,
		} ),

	// Without terms, the active list is replayed.
	preview: ( terms?: string[] ) =>
		apiFetch< ReplayResult >( {
			path: `${ NS }/preview`,
			method: 'POST',
			data: terms ? { terms } : {},
		} ),

	dismissRescue: ( term: string ) =>
		apiFetch< { state: State } >( {
			path: `${ NS }/rescues`,
			method: 'DELETE',
			data: { term },
		} ),

	moveDiscussion: () =>
		apiFetch<
			SettingsResponse & {
				result: { moved: number; added: number; kept: number };
			}
		>( {
			path: `${ NS }/discussion`,
			method: 'POST',
		} ),

	restoreDiscussion: () =>
		apiFetch< SettingsResponse >( {
			path: `${ NS }/discussion`,
			method: 'DELETE',
		} ),

	legacy: ( steps: string[] ) =>
		apiFetch< {
			done: string[];
			settings: Settings;
			state: State;
			report?: LegacyReport;
		} >( {
			path: `${ NS }/legacy`,
			method: 'POST',
			data: { steps },
		} ),

	theme: ( theme: Theme ) =>
		apiFetch< { theme: Theme } >( {
			path: `${ NS }/preferences`,
			method: 'POST',
			data: { theme },
		} ),
};

export function errorMessage( err: unknown, fallback: string ): string {
	if (
		err &&
		typeof err === 'object' &&
		'message' in err &&
		typeof ( err as { message: unknown } ).message === 'string'
	) {
		return ( err as { message: string } ).message;
	}
	return fallback;
}

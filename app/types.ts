export type FormId = 'cf7' | 'gravityforms' | 'wpforms' | 'formidable';
export type Context = 'comment' | FormId;
export type Theme = 'light' | 'dark';
export type View =
	'overview' | 'log' | 'test' | 'sources' | 'terms' | 'protection';

export interface Settings {
	sources: string[];
	interval: 'hourly' | 'twicedaily' | 'daily' | 'weekly';
	shrink_guard: boolean;
	local_terms: string[];
	exceptions: string[];
	include_core: boolean;
	match_mode: 'substring' | 'word';
	min_length: number;
	mode: 'enforce' | 'monitor';
	comment_action: 'spam' | 'hold' | 'trash' | 'off';
	forms: Record< FormId, boolean >;
	form_message: string;
	skip_trusted: boolean;
	log_retention: number;
	log_excerpts: boolean;
}

export interface SourceState {
	url: string;
	label: string;
	status: 'ok' | 'error' | 'shrink' | 'pending';
	note: string;
	error: string;
	count: number;
	updatedAt: number;
	checkedAt: number;
}

export interface DailyCount {
	date: string;
	stopped: number;
	logged: number;
}

export interface LegacyReport {
	active_plugin: string;
	disallowed_lines: number;
	moderation_lines: number;
	moderation_flag_bad: boolean;
	user_terms: string[];
	old_options: boolean;
}

export interface State {
	now: number;
	terms: {
		count: number;
		counts: {
			sources: number;
			local: number;
			core: number;
			skipped: number;
			removed: number;
		};
		builtAt: number;
		error: string;
	};
	sync: { last: number; next: number };
	sources: SourceState[];
	sourcesLocked: boolean;
	activity: {
		total: number;
		stopped: number;
		logged: number;
		byContext: Partial< Record< Context, number > >;
		daily: DailyCount[];
		topTerms: Array< { term: string; hits: number; lastSeen: string } >;
	};
	available: Record< Context, boolean >;
	coreLines: number;
	legacy: LegacyReport | null;
}

export interface SyncResult {
	url: string;
	label: string;
	status: SourceState[ 'status' ];
	note: string;
	error: string;
	count: number;
}

export interface LogRow {
	id: number;
	time: string;
	context: Context;
	action: 'spam' | 'hold' | 'trash' | 'blocked' | 'logged';
	term: string;
	field: string;
	ref: string;
	refLabel: string;
	refUrl: string;
	excerpt: string | null;
}

export interface TestHit {
	term: string;
	excerpt: string;
	origins: string[];
}

export interface Skipped {
	term: string;
	reason: 'short' | 'exception' | 'word' | 'phrase';
}

export interface Boot {
	version: string;
	theme: Theme;
	settings: Settings;
	state: State;
	downloadUrl: string;
	discussion: string;
}

declare global {
	interface Window {
		unifiedBlocklist?: Boot;
	}
}

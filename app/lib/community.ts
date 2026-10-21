import { __ } from '@wordpress/i18n';

// The community list the plugin suggests. It is never added without the admin clicking,
// and the plugin ships with no remote source configured.
export const COMMUNITY_LIST = {
	url: 'https://raw.githubusercontent.com/splorp/wordpress-comment-blacklist/master/blacklist.txt',
	name: 'splorp/wordpress-comment-blacklist',
	homepage: 'https://github.com/splorp/wordpress-comment-blacklist',
	describe: () =>
		__(
			'A community-maintained list of about 66,000 spam terms, updated several times a month and downloaded from GitHub.',
			'unified-blocklist'
		),
};

<?php
/**
 * Dashboard widget.
 *
 * @package UnifiedBlocklist
 */

namespace ClearanceClarence\UnifiedBlocklist\Admin;

use ClearanceClarence\UnifiedBlocklist\Log;
use ClearanceClarence\UnifiedBlocklist\Rest;
use ClearanceClarence\UnifiedBlocklist\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A small status card on the WordPress Dashboard: what is protected, what was caught,
 * and anything that needs attention. Server-rendered, so it costs one query set and no JS.
 */
final class Widget {

	const ID = 'unified_blocklist_dashboard';

	/**
	 * Hooks into WordPress.
	 */
	public static function register() {
		add_action( 'wp_dashboard_setup', array( self::class, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/**
	 * Adds the widget for users who can manage the plugin.
	 */
	public static function add() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			self::ID,
			__( 'Unified Blocklist', 'unified-blocklist' ),
			array( self::class, 'render' ),
			null,
			null,
			'normal',
			'high'
		);
	}

	/**
	 * Styles for the Dashboard screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( $hook ) {
		if ( 'index.php' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'unified-blocklist-widget', UNIFIED_BLOCKLIST_URL . 'src/Admin/widget.css', array(), UNIFIED_BLOCKLIST_VERSION );
	}

	/**
	 * Renders the widget.
	 */
	public static function render() {
		$state    = Rest::state();
		$settings = Settings::all();
		$monitor  = 'monitor' === $settings['mode'];
		$a        = $state['activity'];
		$failing  = array_filter(
			$state['sources'],
			static function ( $s ) {
				return in_array( $s['status'], array( 'error', 'shrink' ), true );
			}
		);
		$days     = array_slice( $a['daily'], -14 );
		$week     = 0;
		foreach ( array_slice( $days, -7 ) as $d ) {
			$week += $d['stopped'] + $d['logged'];
		}
		$protected = 0;
		$possible  = 0;
		foreach ( $state['available'] as $context => $available ) {
			if ( ! $available ) {
				continue;
			}
			++$possible;
			$on = 'comment' === $context ? 'off' !== $settings['comment_action'] : ! empty( $settings['forms'][ $context ] );
			if ( $on ) {
				++$protected;
			}
		}

		$issues = array();
		if ( '' !== $state['terms']['error'] ) {
			$issues[] = array( 'err', $state['terms']['error'], App::url( 'sources' ) );
		} elseif ( 0 === $state['terms']['count'] ) {
			$issues[] = array( 'warn', __( 'The list is empty. Nothing can match until a sync succeeds.', 'unified-blocklist' ), App::url( 'sources' ) );
		}
		foreach ( $failing as $s ) {
			/* translators: 1: source name, 2: error message */
			$issues[] = array( 'shrink' === $s['status'] ? 'warn' : 'err', sprintf( __( '%1$s: %2$s', 'unified-blocklist' ), $s['label'], $s['error'] ), App::url( 'sources' ) );
		}
		if ( $monitor ) {
			$issues[] = array( 'warn', __( 'Monitor only is on: matches are logged, nothing is blocked.', 'unified-blocklist' ), App::url( 'protection' ) );
		}
		if ( null !== $state['legacy'] ) {
			$issues[] = array( 'warn', __( 'Data from Dynamic Blacklist Updater is still in your Discussion settings.', 'unified-blocklist' ), App::url( 'overview' ) );
		}
		?>
		<div class="ubw">
			<div class="ubw-head">
				<span class="ubw-pill <?php echo $monitor ? 'is-warn' : 'is-ok'; ?>">
					<span class="ubw-dot"></span>
					<?php echo $monitor ? esc_html__( 'Monitor only', 'unified-blocklist' ) : esc_html__( 'Protection on', 'unified-blocklist' ); ?>
				</span>
				<span class="ubw-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: protected places, 2: places that could be protected */
							__( 'Guarding %1$d of %2$d places', 'unified-blocklist' ),
							$protected,
							$possible
						)
					);
					?>
				</span>
			</div>

			<?php foreach ( $issues as $issue ) : ?>
				<a class="ubw-issue is-<?php echo esc_attr( $issue[0] ); ?>" href="<?php echo esc_url( $issue[2] ); ?>">
					<span class="dashicons dashicons-warning"></span>
					<span><?php echo esc_html( $issue[1] ); ?></span>
				</a>
			<?php endforeach; ?>

			<div class="ubw-stats">
				<a class="ubw-stat" href="<?php echo esc_url( App::url( 'log' ) ); ?>">
					<strong><?php echo esc_html( number_format_i18n( $week ) ); ?></strong>
					<span><?php esc_html_e( 'matches, 7 days', 'unified-blocklist' ); ?></span>
				</a>
				<a class="ubw-stat" href="<?php echo esc_url( App::url( 'log' ) ); ?>">
					<strong><?php echo esc_html( number_format_i18n( (int) $a['total'] ) ); ?></strong>
					<span><?php esc_html_e( 'matches, 30 days', 'unified-blocklist' ); ?></span>
				</a>
				<a class="ubw-stat" href="<?php echo esc_url( App::url( 'terms' ) ); ?>">
					<strong><?php echo esc_html( number_format_i18n( (int) $state['terms']['count'] ) ); ?></strong>
					<span><?php esc_html_e( 'active terms', 'unified-blocklist' ); ?></span>
				</a>
			</div>

			<?php self::sparkline( $days ); ?>

			<?php if ( $a['topTerms'] ) : ?>
				<ul class="ubw-top">
					<?php foreach ( array_slice( $a['topTerms'], 0, 4 ) as $t ) : ?>
						<li>
							<code><?php echo esc_html( $t['term'] ); ?></code>
							<span><?php echo esc_html( number_format_i18n( (int) $t['hits'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="ubw-foot">
				<span class="ubw-muted">
					<?php
					echo $state['sync']['last']
						? esc_html(
							sprintf(
								/* translators: %s: relative time */
								__( 'Synced %s ago', 'unified-blocklist' ),
								human_time_diff( (int) $state['sync']['last'] )
							)
						)
						: esc_html__( 'Not synced yet', 'unified-blocklist' );
					?>
				</span>
				<a class="button button-small" href="<?php echo esc_url( App::url( 'test' ) ); ?>"><?php esc_html_e( 'Test a message', 'unified-blocklist' ); ?></a>
				<a class="button button-small button-primary" href="<?php echo esc_url( App::url() ); ?>"><?php esc_html_e( 'Open', 'unified-blocklist' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Fourteen thin bars, matches per day.
	 *
	 * @param array $days Daily counts.
	 */
	private static function sparkline( array $days ) {
		$totals = array();
		foreach ( $days as $d ) {
			$totals[] = (int) $d['stopped'] + (int) $d['logged'];
		}
		$max = max( 1, $totals ? max( $totals ) : 0 );
		$n   = max( 1, count( $days ) );
		$w   = 280;
		$h   = 40;
		$gap = 2;
		$bw  = $w / $n - $gap;
		?>
		<div class="ubw-chart">
			<svg viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" preserveAspectRatio="none" role="img" aria-label="<?php esc_attr_e( 'Matches per day, last 14 days', 'unified-blocklist' ); ?>">
				<line class="ubw-base" x1="0" x2="<?php echo (int) $w; ?>" y1="<?php echo (int) $h - 0.5; ?>" y2="<?php echo (int) $h - 0.5; ?>"></line>
				<?php foreach ( $days as $i => $d ) : ?>
					<?php
					$total = $totals[ $i ];
					if ( 0 === $total ) {
						continue;
					}
					$bar_h = max( 2, ( $total / $max ) * ( $h - 3 ) );
					$x     = $i * ( $bw + $gap ) + $gap / 2;
					?>
					<rect class="ubw-bar<?php echo 0 === (int) $d['stopped'] ? ' is-logged' : ''; ?>" x="<?php echo esc_attr( round( $x, 1 ) ); ?>" y="<?php echo esc_attr( round( $h - $bar_h, 1 ) ); ?>" width="<?php echo esc_attr( round( $bw, 1 ) ); ?>" height="<?php echo esc_attr( round( $bar_h, 1 ) ); ?>" rx="2">
						<title><?php echo esc_html( $d['date'] . ': ' . number_format_i18n( $total ) ); ?></title>
					</rect>
				<?php endforeach; ?>
			</svg>
			<div class="ubw-chart-x">
				<span><?php echo esc_html( $days ? wp_date( 'M j', strtotime( $days[0]['date'] . ' UTC' ) ) : '' ); ?></span>
				<span><?php esc_html_e( 'Today', 'unified-blocklist' ); ?></span>
			</div>
		</div>
		<?php
	}
}

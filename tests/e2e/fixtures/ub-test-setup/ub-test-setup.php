<?php
/**
 * Plugin Name: Unified Blocklist Test Setup
 * Description: Only for Unified Blocklist's end-to-end tests. Creates real forms in the installed form plugins and adds a reset route. Never install this on a real site.
 * Version: 1.0.0
 */

use ClearanceClarence\UnifiedBlocklist\Log;
use ClearanceClarence\UnifiedBlocklist\Rescues;
use ClearanceClarence\UnifiedBlocklist\Settings;
use ClearanceClarence\UnifiedBlocklist\Storage;
use ClearanceClarence\UnifiedBlocklist\TermList;

defined( 'ABSPATH' ) || exit;

// The tests post many comments quickly from one address, some of them identical on purpose.
add_filter( 'comment_flood_filter', '__return_false' );
add_filter( 'duplicate_comment_id', '__return_false' );
// No mail server in the test site: report mail as sent, so form plugins show their success message.
add_filter( 'pre_wp_mail', '__return_true' );

/**
 * Terms the tests use. Remote sources stay off, so the tests never depend on the network.
 */
const UB_TEST_TERMS = array( 'e2e-spamword', 'cheap viagra', 'casino-e2e' );

/**
 * Creates the forms and pages once, and returns where they are.
 *
 * @return array
 */
function ub_test_fixtures() {
	$saved = get_option( 'ub_test_fixtures' );
	if ( is_array( $saved ) && get_post( $saved['post'] ) ) {
		return ub_test_urls( $saved );
	}

	$ids = array(
		'post'       => wp_insert_post(
			array(
				'post_title'     => 'E2E post',
				'post_content'   => 'A post visitors can comment on.',
				'post_status'    => 'publish',
				'comment_status' => 'open',
			)
		),
		'cf7'        => 0,
		'wpforms'    => 0,
		'formidable' => 0,
	);

	if ( class_exists( 'WPCF7_ContactForm' ) ) {
		$form = WPCF7_ContactForm::get_template( array( 'title' => 'E2E contact' ) );
		$form->set_properties(
			array(
				'form' => "<label>Your name [text* your-name]</label>\n<label>Your email [email* your-email]</label>\n<label>Your message [textarea your-message]</label>\n[submit \"Send\"]",
			)
		);
		$form->save();
		$ids['cf7'] = ub_test_page( 'E2E Contact Form 7', sprintf( '[contact-form-7 id="%d" title="E2E contact"]', $form->id() ) );
	}

	if ( function_exists( 'wpforms' ) ) {
		$form_id = wp_insert_post(
			array(
				'post_type'   => 'wpforms',
				'post_status' => 'publish',
				'post_title'  => 'E2E WPForms',
			)
		);
		$data    = array(
			'id'       => $form_id,
			'field_id' => 3,
			'fields'   => array(
				'1' => array(
					'id'       => '1',
					'type'     => 'text',
					'label'    => 'Your name',
					'required' => '1',
					'size'     => 'medium',
				),
				'2' => array(
					'id'    => '2',
					'type'  => 'textarea',
					'label' => 'Your message',
					'size'  => 'medium',
				),
			),
			'settings' => array(
				'form_title'          => 'E2E WPForms',
				'submit_text'         => 'Submit',
				'ajax_submit'         => '0',
				'antispam'            => '0',
				'antispam_v3'         => '0',
				'notification_enable' => '0',
				'confirmations'       => array(
					'1' => array(
						'type'           => 'message',
						'message'        => 'Thanks, WPForms received it.',
						'message_scroll' => '1',
					),
				),
			),
			'meta'     => array( 'template' => 'blank' ),
		);
		wp_update_post(
			array(
				'ID'           => $form_id,
				'post_content' => function_exists( 'wpforms_encode' ) ? wpforms_encode( $data ) : wp_slash( wp_json_encode( $data ) ),
			)
		);
		$ids['wpforms'] = ub_test_page( 'E2E WPForms', sprintf( '[wpforms id="%d"]', $form_id ) );
	}

	if ( class_exists( 'FrmForm' ) && class_exists( 'FrmField' ) ) {
		$form_id = FrmForm::create(
			array(
				'name'        => 'E2E Formidable',
				'description' => '',
				'form_key'    => 'e2eformidable',
				'status'      => 'published',
				'options'     => array(
					'submit_value' => 'Submit',
					'success_msg'  => 'Thanks, Formidable received it.',
				),
			)
		);
		FrmField::create(
			array(
				'form_id'     => $form_id,
				'type'        => 'text',
				'name'        => 'Your name',
				'field_key'   => 'e2ename',
				'field_order' => 1,
			)
		);
		FrmField::create(
			array(
				'form_id'     => $form_id,
				'type'        => 'textarea',
				'name'        => 'Your message',
				'field_key'   => 'e2emessage',
				'field_order' => 2,
			)
		);
		$ids['formidable'] = ub_test_page( 'E2E Formidable', sprintf( '[formidable id=%d]', $form_id ) );
	}

	update_option( 'ub_test_fixtures', $ids, false );
	return ub_test_urls( $ids );
}

/**
 * Publishes a page.
 *
 * @param string $title   Title.
 * @param string $content Content.
 * @return int
 */
function ub_test_page( $title, $content ) {
	return (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
		)
	);
}

/**
 * Relative URLs, so the tests work behind any host name.
 *
 * @param array $ids Post IDs.
 * @return array
 */
function ub_test_urls( array $ids ) {
	$out = array( 'postId' => (int) $ids['post'] );
	foreach ( array( 'post', 'cf7', 'wpforms', 'formidable' ) as $key ) {
		$out[ $key ] = $ids[ $key ] ? ( 'post' === $key ? '/?p=' : '/?page_id=' ) . (int) $ids[ $key ] : null;
	}
	return $out;
}

add_action(
	'rest_api_init',
	function () {
		$admin_only = function () {
			return current_user_can( 'manage_options' );
		};

		// Puts the site back to a known state and returns where the forms are.
		register_rest_route(
			'ub-test/v1',
			'/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => function () {
					$settings                   = Settings::defaults();
					$settings['local_terms']    = UB_TEST_TERMS;
					$settings['mode']           = 'enforce';
					$settings['comment_action'] = 'spam';
					update_option( Settings::OPTION, $settings, true );
					Settings::flush();

					foreach ( array( 'all', 'spam', 'trash' ) as $status ) {
						foreach ( get_comments( array( 'fields' => 'ids', 'status' => $status ) ) as $id ) {
							wp_delete_comment( $id, true );
						}
					}
					if ( post_type_exists( 'flamingo_inbound' ) ) {
						foreach ( get_posts( array( 'post_type' => 'flamingo_inbound', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
							wp_delete_post( $id, true );
						}
					}

					if ( class_exists( 'FrmEntry' ) ) {
						foreach ( (array) FrmEntry::getAll( array(), '', '', false, false ) as $entry ) {
							FrmEntry::destroy( $entry->id );
						}
					}

					Log::clear();
					delete_option( Rescues::OPTION );
					delete_option( TermList::MOVE_OPTION );
					Storage::delete( TermList::MOVE_BACKUP );
					Storage::delete( TermList::MOVE_ADDED );
					update_option( 'disallowed_keys', '' );
					TermList::rebuild();

					return array(
						'fixtures' => ub_test_fixtures(),
						'plugins'  => array(
							'cf7'        => class_exists( 'WPCF7_ContactForm' ),
							'flamingo'   => post_type_exists( 'flamingo_inbound' ),
							'wpforms'    => function_exists( 'wpforms' ),
							'formidable' => class_exists( 'FrmForm' ),
						),
					);
				},
			)
		);

		// The Discussion setting the tests move and put back.
		register_rest_route(
			'ub-test/v1',
			'/discussion',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $admin_only,
					'callback'            => function () {
						return array( 'value' => (string) get_option( 'disallowed_keys', '' ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $admin_only,
					'callback'            => function ( WP_REST_Request $request ) {
						update_option( 'disallowed_keys', (string) $request['value'] );
						return array( 'value' => (string) get_option( 'disallowed_keys', '' ) );
					},
				),
			)
		);

		// An approved comment, as if a moderator had let it through long ago.
		register_rest_route(
			'ub-test/v1',
			'/approved-comment',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => function ( WP_REST_Request $request ) {
					$fixtures = ub_test_fixtures();
					$id       = wp_insert_comment(
						array(
							'comment_post_ID'      => $fixtures['postId'],
							'comment_author'       => (string) $request['author'],
							'comment_author_email' => 'reader@example.test',
							'comment_content'      => (string) $request['content'],
							'comment_approved'     => 1,
						)
					);
					return array( 'id' => (int) $id );
				},
			)
		);
	}
);

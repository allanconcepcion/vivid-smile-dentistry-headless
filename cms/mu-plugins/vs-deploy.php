<?php
/**
 * Plugin Name:  Vivid Smiles — Deploy trigger
 * Description:  Rebuilds the Astro front end when content changes, by calling a
 *               Vercel deploy hook. Debounced, so a burst of edits produces one
 *               build rather than a queue of them.
 * Author:       Concepcion.Work
 * Version:      0.1.0
 *
 * The front end is a static build. Publishing in WordPress changes nothing a
 * visitor can see until Vercel builds again, and the only triggers used to be a
 * push to main or a click in the Vercel dashboard. Editors should not have to
 * know Vercel exists.
 *
 * WHY THE HOOK URL IS NOT IN THIS FILE
 *
 * A deploy hook URL is a credential: anyone holding it can start builds on the
 * project. This repository is public, so the URL lives in vs-config.php on the
 * host — the same file that already carries VS_FRONTEND_URL, and the same file
 * the managed platform leaves alone during its own updates. With
 * VS_DEPLOY_HOOK_URL undefined this plugin loads and does nothing, which is what
 * a local or staging copy wants: neither should be able to rebuild production.
 *
 * WHY IT IS DEBOUNCED, AND WHY IT RUNS ON CRON
 *
 * One save fires transition_post_status once; a working session fires it many
 * times. Firing per save would queue builds that cancel each other and would
 * hammer the CMS through Cloudflare, which answers a burst of build traffic with
 * 429. So the first change schedules a single event a couple of minutes out and
 * every later change inside that window reuses it. Running on cron also keeps
 * the HTTP request out of the editor's own request, so a slow or unreachable
 * Vercel never makes Publish appear broken.
 *
 * WHAT IT DOES NOT COVER
 *
 * Media library changes. Replacing an image does not fire any of these hooks;
 * the page referencing it has to be re-saved, or a build started by hand.
 */

declare( strict_types=1 );

namespace VividSmiles\Deploy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Post types whose changes can alter the built site. */
const WATCHED_POST_TYPES = [ 'post', 'page', 'vs_testimonial' ];

/** How long to wait before building, so a burst of edits becomes one build. */
const DEBOUNCE_SECONDS = 120;

const CRON_HOOK      = 'vs_deploy_build';
const PENDING_OPTION = 'vs_deploy_pending';
const RESULT_OPTION  = 'vs_deploy_last_result';

/**
 * The deploy hook URL, or an empty string when deploys are not configured.
 */
function hook_url(): string {
	$url = defined( 'VS_DEPLOY_HOOK_URL' ) ? (string) VS_DEPLOY_HOOK_URL : '';

	/**
	 * Filters the Vercel deploy hook URL. Return '' to switch deploys off.
	 */
	return (string) apply_filters( 'vs_deploy_hook_url', $url );
}

/**
 * Queue a build, unless one is already queued. The "unless" is the debounce.
 */
function queue( string $reason ): void {
	if ( '' === hook_url() ) {
		return;
	}

	if ( wp_next_scheduled( CRON_HOOK ) ) {
		return;
	}

	update_option(
		PENDING_OPTION,
		[
			'reason' => $reason,
			'since'  => time(),
		],
		false
	);

	wp_schedule_single_event( time() + DEBOUNCE_SECONDS, CRON_HOOK );
}

/**
 * Content going public, leaving public, or being edited while public.
 */
function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
	if ( ! in_array( $post->post_type, WATCHED_POST_TYPES, true ) ) {
		return;
	}

	if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
		return;
	}

	// A draft saved as a draft changes nothing a visitor could see.
	if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
		return;
	}

	queue( sprintf( '%s %d: %s to %s', $post->post_type, $post->ID, $old_status, $new_status ) );
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\on_transition', 10, 3 );

/** Menus are read at build time, so a reordered menu needs a rebuild. */
function on_menu_update( int $menu_id ): void {
	queue( sprintf( 'nav menu %d updated', $menu_id ) );
}
add_action( 'wp_update_nav_menu', __NAMESPACE__ . '\\on_menu_update' );

/** Practice Settings — phone, hours, address — are baked into every page. */
function on_options_save( $post_id ): void {
	if ( is_string( $post_id ) && str_starts_with( $post_id, 'options' ) ) {
		queue( 'practice settings saved' );
	}
}
add_action( 'acf/save_post', __NAMESPACE__ . '\\on_options_save', 20 );

/**
 * Call the hook. Runs on cron, never inside an editor's request.
 */
function build(): void {
	$url = hook_url();
	if ( '' === $url ) {
		return;
	}

	$pending = get_option( PENDING_OPTION );
	delete_option( PENDING_OPTION );

	$response = wp_remote_post(
		$url,
		[
			'timeout'  => 20,
			'blocking' => true,
			'body'     => [],
		]
	);

	$failed  = is_wp_error( $response );
	$code    = $failed ? 0 : (int) wp_remote_retrieve_response_code( $response );
	$ok      = ! $failed && $code >= 200 && $code < 300;

	update_option(
		RESULT_OPTION,
		[
			'at'     => time(),
			'ok'     => $ok,
			'code'   => $code,
			'detail' => $failed ? $response->get_error_message() : '',
			'reason' => is_array( $pending ) ? ( $pending['reason'] ?? '' ) : '',
		],
		false
	);

	if ( ! $ok ) {
		error_log(
			sprintf(
				'[vs-deploy] deploy hook did not fire: %s',
				$failed ? $response->get_error_message() : 'HTTP ' . $code
			)
		);
	}
}
add_action( CRON_HOOK, __NAMESPACE__ . '\\build' );

/**
 * Tell editors the site is about to rebuild, so "I published it and nothing
 * changed" stops being a mystery.
 */
function notice(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$next = wp_next_scheduled( CRON_HOOK );
	if ( ! $next ) {
		return;
	}

	$minutes = max( 1, (int) ceil( ( $next - time() ) / 60 ) );

	printf(
		'<div class="notice notice-info"><p>%s</p></div>',
		esc_html(
			sprintf(
				_n(
					'Front-end rebuild queued — the live site updates in about %d minute.',
					'Front-end rebuild queued — the live site updates in about %d minutes.',
					$minutes
				),
				$minutes
			)
		)
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\notice' );

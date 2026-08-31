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
 * WHAT AN EDITOR IS TOLD WHEN IT FAILS
 *
 * A static site gives an editor no feedback of its own: Publish looks identical
 * whether Vercel rebuilt or refused, and the queued-build notice below promises
 * a rebuild in about two minutes whatever actually happens next. So the outcome
 * of every attempt is recorded, one retry is allowed, and a failure that
 * survives the retry is stated plainly in wp-admin — the plain sentence for
 * anyone who can edit, the HTTP code and the thing to check for whoever can act
 * on it. Leaving a failure silent would let that "about 2 minutes" promise stand
 * as the last word, which is worse than never having made it.
 *
 * WHAT IT DOES NOT COVER
 *
 * Media library changes. Replacing an image does not fire any of these hooks;
 * the page referencing it has to be re-saved, or a build started by hand.
 *
 * Builds started anywhere else — a push to main, a click in Vercel — are
 * invisible here. WordPress only knows the outcome of requests it made itself,
 * so a recorded failure keeps being reported until a build WordPress asked for
 * succeeds.
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

/**
 * How long to wait before the one retry of a failed request.
 *
 * Short, because the failures worth retrying are the transient ones — a dropped
 * connection, a 5xx while Vercel is having a moment — and because a retry that
 * lands after the editor has stopped looking at wp-admin tells them nothing.
 */
const RETRY_SECONDS = 60;

/**
 * How long a due-but-unrun build is treated as merely in progress.
 *
 * WP-Cron is not a scheduler; it runs on an incoming request. A build a few
 * seconds past due is normal on a quiet site and means nothing is wrong. Past
 * this, on a site with any traffic at all, the event is not late — it is stuck,
 * usually because DISABLE_WP_CRON is set on the host with no system cron behind
 * it. That is worth saying out loud rather than counting down forever.
 */
const OVERDUE_SECONDS = 300;

const CRON_HOOK      = 'vs_deploy_build';
const PENDING_OPTION = 'vs_deploy_pending';
const RESULT_OPTION  = 'vs_deploy_last_result';

/**
 * Set only while the next scheduled event is the retry of a failed request.
 *
 * This is the whole bound on retrying. build() consumes it the same way it
 * consumes PENDING_OPTION, so a run that finds it set knows it is already the
 * second attempt and must not schedule a third.
 */
const RETRY_OPTION = 'vs_deploy_retry';

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
	// State is consumed with the event that carried it, BEFORE the hook URL is
	// checked. Doing it the other way round leaks both flags whenever the
	// constant has gone missing between the edit and the build — which is not
	// hypothetical: docs/CUTOVER-PROMPT.md documents a step that removes it. The
	// leak is quiet and it bites later, because a stale retry flag makes the
	// next genuine first attempt believe it has already retried, and silently
	// spend the one retry it was entitled to.
	$pending = get_option( PENDING_OPTION );
	delete_option( PENDING_OPTION );

	$is_retry = (bool) get_option( RETRY_OPTION );
	delete_option( RETRY_OPTION );

	$url = hook_url();
	if ( '' === $url ) {
		return;
	}

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

	// Written on every attempt, success included. A success overwrites the
	// failure that came before it, so failure_notice() stops firing by itself
	// and no stale warning can outlive the problem it described.
	update_option(
		RESULT_OPTION,
		[
			'at'     => time(),
			'ok'     => $ok,
			'code'   => $code,
			'detail' => $failed ? $response->get_error_message() : '',
			'reason' => is_array( $pending ) ? ( $pending['reason'] ?? '' ) : '',
			'retry'  => $is_retry,
		],
		false
	);

	if ( $ok ) {
		return;
	}

	error_log(
		sprintf(
			'[vs-deploy] deploy hook did not fire: %s',
			$failed ? $response->get_error_message() : 'HTTP ' . $code
		)
	);

	// One retry, once. $is_retry means this run already was it; a scheduled
	// event means an editor's save has queued a build in the meantime and
	// reusing it is the same debounce as everywhere else. Either way, scheduling
	// here would stack a second event on the same hook, which is the one thing
	// this must not do.
	if ( $is_retry || wp_next_scheduled( CRON_HOOK ) ) {
		return;
	}

	if ( false === wp_schedule_single_event( time() + RETRY_SECONDS, CRON_HOOK ) ) {
		return;
	}

	update_option( RETRY_OPTION, time(), false );

	// Put the reason back so the retry's record still names the edit that
	// started all this, rather than reporting a build nothing asked for.
	if ( is_array( $pending ) ) {
		update_option( PENDING_OPTION, $pending, false );
	}
}
add_action( CRON_HOOK, __NAMESPACE__ . '\\build' );

/**
 * Tell editors the site is about to rebuild, so "I published it and nothing
 * changed" stops being a mystery.
 */
function pending_notice(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$next = wp_next_scheduled( CRON_HOOK );
	if ( ! $next ) {
		return;
	}

	$seconds_late = time() - $next;

	// Overdue past the grace window: scheduled tasks are not running on this
	// site, so the build is not coming. The old `max( 1, ... )` here reported
	// "about 1 minute" forever in exactly this state — a countdown that never
	// counted down, which is worse than saying nothing.
	if ( $seconds_late > OVERDUE_SECONDS ) {
		// failure_notice() covers this same ground and carries the diagnosis an
		// administrator needs, so it speaks alone when there is a failure on
		// record. Two warnings agreeing with each other is still two warnings,
		// and the second one teaches the reader to skim.
		$result = get_option( RESULT_OPTION );

		if ( is_array( $result ) && isset( $result['ok'] ) && ! $result['ok'] ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__(
				'The website rebuild has not started. Your changes are saved, but the live '
				. 'site has not been updated — please pass this on to whoever looks after the website.'
			)
		);

		return;
	}

	if ( $seconds_late >= 0 ) {
		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__( 'Front-end rebuild in progress — the live site updates shortly.' )
		);

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
add_action( 'admin_notices', __NAMESPACE__ . '\\pending_notice' );

/**
 * Say so when the last build request failed.
 *
 * WHO SEES WHAT, AND WHY
 *
 * Everyone who can edit sees the plain sentence, because the person who made
 * the change is the one being misled about whether it is live — that is the
 * whole failure being fixed here. They cannot repair a rejected hook URL, so
 * they are not shown one; they are told what is true of their own work and who
 * to tell.
 *
 * The technical line is added for manage_options only. It is the line that lets
 * someone act — the HTTP code separates a revoked hook (401/403) from a Vercel
 * outage (5xx) from a DNS or firewall problem (a WP_Error with no code at all)
 * — and it names the file to check, because an error that names the fix is
 * worth ten that do not.
 *
 * The notice is not dismissible. A dismissed notice returns on the next page
 * load anyway unless the dismissal is stored per user, and storing it would
 * mean offering to hide a live site that is out of date.
 */
function failure_notice(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	// Deploys switched off: this copy cannot build and cannot clear an old
	// result either. A staging site restored from a production database carries
	// production's last result with it, and warning about a build that another
	// installation failed to make is pure noise.
	if ( '' === hook_url() ) {
		return;
	}

	$result = get_option( RESULT_OPTION );
	if ( ! is_array( $result ) || ! isset( $result['ok'] ) || $result['ok'] ) {
		return;
	}

	// A build is queued — either the automatic retry or somebody's newer edit —
	// so pending_notice() is already describing the state, and this failure is
	// about to be superseded one way or the other. Two notices contradicting
	// each other on the same screen would teach an editor to read neither. If
	// the queued build fails as well, this comes straight back.
	// Deliberately `> time()` rather than a bare truthiness test. A stalled
	// WP-Cron leaves the event scheduled in the past forever, and treating that
	// as "a build is coming" would suppress this notice permanently — the exact
	// silence this function exists to break.
	$next = wp_next_scheduled( CRON_HOOK );

	if ( $next && $next > time() ) {
		return;
	}

	$technical = '';

	if ( current_user_can( 'manage_options' ) ) {
		// A transport failure carries a message and no status code; an HTTP
		// failure carries a code and no message. Report whichever exists.
		// Read defensively throughout: this option is stored data, and a notice
		// that fatals takes wp-admin down with it.
		$detail = isset( $result['detail'] ) && is_string( $result['detail'] ) ? $result['detail'] : '';
		if ( '' === $detail ) {
			$code   = isset( $result['code'] ) && is_numeric( $result['code'] ) ? (int) $result['code'] : 0;
			$detail = sprintf( 'HTTP %d', $code );
		}

		$at   = isset( $result['at'] ) && is_numeric( $result['at'] ) ? (int) $result['at'] : 0;
		$line = sprintf(
			$at
				? 'Vercel deploy hook did not fire (%1$s), %2$s ago.'
				: 'Vercel deploy hook did not fire (%1$s).',
			$detail,
			$at ? human_time_diff( $at, time() ) : ''
		);

		if ( ! empty( $result['retry'] ) ) {
			$line .= ' The automatic retry failed as well.';
		}

		$reason = isset( $result['reason'] ) && is_string( $result['reason'] ) ? $result['reason'] : '';
		if ( '' !== $reason ) {
			$line .= sprintf( ' Triggered by: %s.', $reason );
		}

		$line .= ' Check VS_DEPLOY_HOOK_URL in wp-content/mu-plugins/vs-config.php'
			. ' against the deploy hook in the Vercel project.';

		// Escaped here so the printf below only ever concatenates safe markup.
		$technical = '<p><em>' . esc_html( $line ) . '</em></p>';
	}

	printf(
		'<div class="notice notice-error"><p><strong>%s</strong> %s</p>%s</div>',
		esc_html( 'The website could not be updated.' ),
		esc_html(
			'Your changes are saved, but the live site has not been rebuilt, so '
			. 'visitors are still seeing the previous version. Nothing you can do '
			. 'in here will fix this — please pass it on to whoever looks after '
			. 'the website.'
		),
		$technical // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped above.
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\failure_notice' );

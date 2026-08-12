<?php
/**
 * Plugin Name:  Vivid Smiles — Headless Hardening
 * Description:  Turns this WordPress install into a content API only. The public
 *               front end is the Astro site at vividsmilesdentistry.com; this
 *               install exists to serve WPGraphQL and wp-admin.
 * Author:       Concepcion.Work
 * Version:      0.1.0
 *
 * Loaded as a must-use plugin so it cannot be deactivated from wp-admin by
 * accident. Mapped into the container by cms/.wp-env.json.
 */

declare( strict_types=1 );

namespace VividSmiles\Headless;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the real site lives. Front-end requests are bounced here so the raw
 * WordPress theme is never served to a visitor or crawled by a search engine
 * (duplicate content against the Astro site is the thing we're avoiding).
 *
 * Override per-environment with the VS_FRONTEND_URL constant in wp-config.
 */
function frontend_url(): ?string {
	if ( defined( 'VS_FRONTEND_URL' ) && VS_FRONTEND_URL ) {
		return rtrim( (string) VS_FRONTEND_URL, '/' );
	}

	// No fallback, deliberately. This used to default to http://localhost:4321,
	// which is correct locally and actively broken anywhere else: deployed to a
	// real host without the constant set, every front-end request 302'd visitors
	// to a dead localhost address. Returning null instead serves WordPress
	// normally — still noindexed and Disallow: / — which is a harmless outcome,
	// unlike a redirect to a machine that is not theirs.
	return null;
}

/**
 * Origins allowed to call the GraphQL endpoint from a browser.
 *
 * Build-time fetches (Astro's loader, running in Node) are not subject to CORS
 * at all — this exists for browser-side calls: the GraphiQL IDE, and any future
 * client-side query from the Astro site.
 */
function allowed_origins(): array {
	$origins = [
		'http://localhost:4321',   // astro dev (local only; harmless elsewhere)
		'http://127.0.0.1:4321',
		'http://localhost:4322',   // astro dev, fallback port
		'https://vividsmilesdentistry.com',
		'https://www.vividsmilesdentistry.com',
	];

	if ( defined( 'VS_FRONTEND_URL' ) && VS_FRONTEND_URL ) {
		$origins[] = rtrim( (string) VS_FRONTEND_URL, '/' );
	}

	/**
	 * Filter the CORS allowlist. Vercel preview deployments have generated
	 * hostnames, so those get added here rather than hardcoded above.
	 */
	return array_values( array_unique( apply_filters( 'vs_headless_allowed_origins', $origins ) ) );
}

/**
 * Bounce public front-end requests to the Astro site.
 *
 * Deliberately does NOT touch: wp-admin, wp-login, the REST API, the GraphQL
 * endpoint, cron, or any AJAX call — those are the surfaces we still need.
 * Logged-in users are also exempt so an editor can use preview links.
 */
function redirect_frontend(): void {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	// WPGraphQL sets this once it has taken over the request.
	if ( defined( 'GRAPHQL_HTTP_REQUEST' ) && GRAPHQL_HTTP_REQUEST ) {
		return;
	}

	// robots.txt must be SERVED, not redirected — a 302 to the Astro site would
	// hand crawlers that site's permissive robots.txt and leave the CMS host
	// with no disallow rule at all. See robots_txt() below.
	if ( is_robots() ) {
		return;
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';

	// Let the GraphQL endpoint and login/admin through even before the
	// constants above are defined.
	foreach ( [ '/graphql', '/wp-admin', '/wp-login.php', '/wp-json', '/wp-cron.php', '/robots.txt' ] as $passthrough ) {
		if ( str_starts_with( $uri, $passthrough ) ) {
			return;
		}
	}

	// An editor previewing a draft should see the preview, not a redirect.
	if ( is_user_logged_in() ) {
		return;
	}

	$frontend = frontend_url();

	// Unconfigured: serve WordPress rather than redirect somewhere wrong.
	if ( $frontend === null ) {
		return;
	}

	wp_safe_redirect( $frontend . $uri, 302 );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\redirect_frontend', 0 );

/**
 * wp_safe_redirect() only allows the current host by default; the whole point
 * here is redirecting off-host, so the front-end host is allowlisted.
 */
function allow_frontend_host( array $hosts ): array {
	$frontend = frontend_url();

	if ( $frontend === null ) {
		return $hosts;
	}

	$host = wp_parse_url( $frontend, PHP_URL_HOST );

	if ( is_string( $host ) && $host !== '' ) {
		$hosts[] = $host;
	}

	return $hosts;
}
add_filter( 'allowed_redirect_hosts', __NAMESPACE__ . '\\allow_frontend_host' );

/**
 * Send CORS headers on GraphQL responses.
 *
 * WPGraphQL ships its own CORS handling but only for a single origin; this
 * reflects any origin on the allowlist instead, and adds Vary: Origin so a
 * shared cache can't serve one origin's headers to another.
 */
function graphql_cors_headers( array $headers ): array {
	$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : '';

	if ( $origin !== '' && in_array( $origin, allowed_origins(), true ) ) {
		$headers['Access-Control-Allow-Origin']      = $origin;
		$headers['Access-Control-Allow-Credentials'] = 'true';
	}

	$existing_vary    = isset( $headers['Vary'] ) ? (string) $headers['Vary'] : '';
	$headers['Vary']  = $existing_vary ? $existing_vary . ', Origin' : 'Origin';

	return $headers;
}
add_filter( 'graphql_response_headers_to_send', __NAMESPACE__ . '\\graphql_cors_headers' );

/**
 * Belt and braces: tell every crawler to stay away from the CMS host.
 *
 * The redirect above already keeps crawlers off the front end, but wp-admin and
 * the GraphQL endpoint can still be discovered and requested directly.
 */
function noindex_headers(): void {
	header( 'X-Robots-Tag: noindex, nofollow', true );
}
add_action( 'send_headers', __NAMESPACE__ . '\\noindex_headers' );

/**
 * Serve a single, unambiguous disallow-everything robots.txt.
 *
 * Runs at PHP_INT_MAX so it is the LAST word: Yoast also filters robots_txt and
 * appends a permissive "User-agent: * / Disallow:" block plus a sitemap link,
 * which would both contradict this rule and advertise the CMS host. Returning a
 * fresh string here discards anything earlier filters added.
 */
function robots_txt( string $output ): string {
	return "User-agent: *\nDisallow: /\n";
}
add_filter( 'robots_txt', __NAMESPACE__ . '\\robots_txt', PHP_INT_MAX );

/**
 * The Astro site generates the real sitemap (see astro.config.mjs). Yoast's
 * sitemap here would only ever list CMS-host URLs that must not be indexed.
 */
add_filter( 'wpseo_enable_xml_sitemap', '__return_false' );

/**
 * Trim surfaces a headless install has no use for.
 */
add_filter( 'xmlrpc_enabled', '__return_false' );

// The block editor's front-end stylesheet, emoji script, and oEmbed discovery
// all target a theme we never render.
function dequeue_frontend_cruft(): void {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_generator' );
}
add_action( 'init', __NAMESPACE__ . '\\dequeue_frontend_cruft' );

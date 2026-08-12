<?php
/**
 * Plugin Name:  Vivid Smiles — Environment Config
 * Description:  Defines the per-environment constants the other vs-* mu-plugins
 *               read. Loads before them: mu-plugins run in filename order, and
 *               "vs-config" sorts ahead of "vs-content-model" and "vs-headless".
 * Author:       Concepcion.Work
 * Version:      0.1.0
 *
 * VS_FRONTEND_URL is the public address of the Astro front end. vs-headless.php
 * reads it for three things: bouncing visitors off the CMS domain, the CORS
 * allow-list on /graphql, and the canonical link it emits.
 *
 * WHY THIS IS A MU-PLUGIN AND NOT wp-config.php
 *
 * The docs used to say "set VS_FRONTEND_URL in wp-config.php". On the managed
 * host that is the wrong file: the platform rewrites wp-config.php during its
 * own updates and migrations, and anything hand-added there disappears without
 * a warning. When the constant vanishes the site does not break loudly — it
 * just stops redirecting, and the raw WordPress theme starts answering on the
 * CMS domain. mu-plugins are ordinary files in wp-content and survive that.
 *
 * WHY THE defined() GUARD
 *
 * Locally, wp-env defines this constant in wp-config.php (see cms/.wp-env.json,
 * which points it at http://localhost:4321) and wp-config.php loads first. A
 * bare define() would then raise "Constant VS_FRONTEND_URL already defined" on
 * every single request. The guard lets the local value win and this file act as
 * the fallback it is.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Production front end.
 *
 * Change this at domain cutover — see docs/DEPLOYING.md. It is the only place
 * the front-end URL is configured on the CMS side.
 */
defined( 'VS_FRONTEND_URL' ) || define( 'VS_FRONTEND_URL', 'https://vivid-smiles-headless.vercel.app' );

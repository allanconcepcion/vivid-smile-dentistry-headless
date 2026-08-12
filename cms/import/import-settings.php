<?php
/**
 * Seed Practice Settings from the values that were hardcoded in
 * src/data/contact.ts and src/data/hours.ts.
 *
 *   npm run import:settings
 *
 * Idempotent, but it does NOT overwrite a field an editor has already changed —
 * it only fills blanks. Re-running after someone corrects the phone number in
 * wp-admin must not quietly restore the old one.
 */

// No `declare(strict_types=1)` / `namespace` — see import-reviews.php for why.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run through WP-CLI.\n" );
	exit( 1 );
}

if ( ! function_exists( 'update_field' ) ) {
	WP_CLI::error( 'Secure Custom Fields is not active.' );
}

$defaults = [
	'field_vs_phone_label' => '(303) 841-5313',
	'field_vs_phone_e164'  => '+1-303-841-5313',
	'field_vs_email'       => 'info@vividsmilesdentistry.com',
	'field_vs_book_href'   => 'https://app.nexhealth.com/appt/vivid-smiles?lid=135014',
	'field_vs_addr_street' => '17167 Cedar Gulch Pkwy Ste 102',
	'field_vs_addr_city'   => 'Parker',
	'field_vs_addr_state'  => 'CO',
	'field_vs_addr_zip'    => '80134',
	'field_vs_directions'  => 'https://maps.google.com/?q=17167+Cedar+Gulch+Pkwy+Ste+102,+Parker,+CO+80134',
	'field_vs_typeform'    => '01KQVS3YDH3E0TNG9N208Y6FBC',
];

$written = 0;
$kept    = 0;

foreach ( $defaults as $key => $value ) {
	$current = get_field( $key, 'option' );

	if ( $current !== null && $current !== '' && $current !== false ) {
		$kept++;
		continue;
	}

	update_field( $key, $value, 'option' );
	$written++;
	WP_CLI::log( sprintf( '  set   %-24s %s', str_replace( 'field_vs_', '', $key ), $value ) );
}

// Hours. Display strings ("8a–5p") are deliberately NOT stored — they are
// derived in src/data/hours.ts from opens/closes, so an editor cannot leave the
// label saying one thing while the structured data says another.
$hours = get_field( 'field_vs_hours', 'option' );

if ( empty( $hours ) ) {
	update_field(
		'field_vs_hours',
		[
			[
				'label'  => 'Mon–Wed',
				'days'   => [ 'Monday', 'Tuesday', 'Wednesday' ],
				'closed' => false,
				'opens'  => '08:00',
				'closes' => '17:00',
			],
			[
				'label'  => 'Thu–Fri',
				'days'   => [ 'Thursday', 'Friday' ],
				'closed' => false,
				'opens'  => '08:00',
				'closes' => '13:00',
			],
			[
				'label'  => 'Sat–Sun',
				'days'   => [ 'Saturday', 'Sunday' ],
				'closed' => true,
				'opens'  => '',
				'closes' => '',
			],
		],
		'option'
	);
	WP_CLI::log( '  set   office_hours             3 schedules' );
	$written++;
} else {
	$kept++;
}

WP_CLI::success( sprintf( '%d field(s) seeded, %d left as-is (already set).', $written, $kept ) );

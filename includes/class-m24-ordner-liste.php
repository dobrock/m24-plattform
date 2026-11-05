<?php
/**
 * Wartung: Unterordner im Webroot auflisten (nur Admins, nur lesend) – Daniel 05.11.2026: Login der alten
 * Teile-/Preisverwaltung von Lars Klein (Tabellen mb_*) wiederfinden.
 * GET /wp-json/m24/v1/wartung/ordner → [{ordner, dateien, php, hinweis_mb, login}]
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'rest_api_init', function () {
	register_rest_route( 'm24/v1', '/wartung/ordner', array(
		'methods'             => 'GET',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function () {
			$root = rtrim( ABSPATH, '/' );
			$out  = array();
			foreach ( (array) scandir( $root ) as $d ) {
				if ( '.' === $d || '..' === $d || ! is_dir( $root . '/' . $d ) ) { continue; }
				$dateien = array_values( array_filter( (array) @scandir( $root . '/' . $d ), function ( $f ) { return '.' !== $f[0]; } ) );
				$php = array_values( array_filter( $dateien, function ( $f ) { return preg_match( '/\.php$/i', $f ); } ) );
				$mb  = false; $login = array();
				foreach ( array_slice( $php, 0, 40 ) as $f ) {
					$t = (string) @file_get_contents( $root . '/' . $d . '/' . $f, false, null, 0, 200000 );
					if ( false !== stripos( $t, 'mb_product' ) || false !== stripos( $t, 'mb_car' ) ) { $mb = true; }
					if ( preg_match( '/type=["\']password["\']/i', $t ) ) { $login[] = $f; }
				}
				$out[] = array( 'ordner' => $d, 'anzahl' => count( $dateien ), 'beispiele' => array_slice( $dateien, 0, 8 ), 'php' => count( $php ), 'hinweis_mb' => $mb, 'login' => $login );
			}
			return $out;
		},
	) );
} );

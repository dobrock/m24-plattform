<?php
/**
 * M24 Plattform — Import-Diagnose-Log (konsolenlos sichtbar)
 * Modul: modules/importer/import-log.php
 *
 * EINE Verantwortung: einen schlanken, plugin-eigenen Klartext-Log in
 * wp-content/uploads/m24-logs/import.log schreiben und lesen, damit der echte
 * Grund eines Import-Abbruchs (Timeout vs. OOM) OHNE Server-Konsole sichtbar
 * wird. Geschrieben wird VOR der riskanten Aktion (Bild-Download) und danach —
 * killt ein harter FPM-/OOM-Abbruch den Request, ist die letzte Zeile der
 * Tatort.
 *
 * Sicherheit: Verzeichnis per .htaccess + index.html gesperrt. Datei wird bei
 * Ueberlaenge gekuerzt (kein unbeschraenktes Wachstum).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Import_Log {

	const FILE     = 'import.log';
	const MAX_SIZE = 524288; // 512 KB → danach auf die letzte Haelfte kuerzen.

	/** Absoluter Pfad zum Log-Verzeichnis (uploads/m24-logs). */
	public static function dir() {
		$up = wp_upload_dir();
		return trailingslashit( $up['basedir'] ) . 'm24-logs';
	}

	/** Absoluter Pfad zur Log-Datei. */
	public static function path() {
		return trailingslashit( self::dir() ) . self::FILE;
	}

	/** Verzeichnis sicherstellen + gegen Web-Zugriff sperren. */
	private static function ensure_dir() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
		$ht = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $ht ) ) { @file_put_contents( $ht, "Require all denied\nDeny from all\n" ); } // phpcs:ignore
		$ix = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $ix ) ) { @file_put_contents( $ix, '' ); } // phpcs:ignore
		return $dir;
	}

	/** Eine Zeile anhaengen (sofort geflusht — ueberlebt einen harten Request-Kill). */
	public static function log( $msg ) {
		self::ensure_dir();
		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . (string) $msg . "\n";
		$path = self::path();
		if ( file_exists( $path ) && filesize( $path ) > self::MAX_SIZE ) {
			$keep = (string) substr( (string) file_get_contents( $path ), -self::MAX_SIZE / 2 );
			@file_put_contents( $path, "… (gekuerzt)\n" . $keep ); // phpcs:ignore
		}
		@file_put_contents( $path, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore
	}

	/** Letzte $n Zeilen (neueste zuletzt). */
	public static function tail( $n = 80 ) {
		$path = self::path();
		if ( ! file_exists( $path ) ) { return array(); }
		$lines = preg_split( '/\r?\n/', (string) file_get_contents( $path ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $lines ) ) { return array(); }
		return array_slice( $lines, -1 * max( 1, (int) $n ) );
	}

	/** Log leeren. */
	public static function clear() {
		$path = self::path();
		if ( file_exists( $path ) ) { @file_put_contents( $path, '' ); } // phpcs:ignore
	}

	/** Relevante PHP-Limits (fuer Timeout-vs-OOM-Diagnose). */
	public static function limits() {
		return array(
			'memory_limit'        => (string) ini_get( 'memory_limit' ),
			'max_execution_time'  => (string) ini_get( 'max_execution_time' ),
			'post_max_size'       => (string) ini_get( 'post_max_size' ),
			'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
			'memory_peak'         => size_format( memory_get_peak_usage( true ) ),
		);
	}
}

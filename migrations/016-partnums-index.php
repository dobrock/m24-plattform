<?php
/**
 * Migration 016 — Teilenummern-Index (_m24_partnums): Erst-Backfill anstoßen (läuft in Häppchen per Cron).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
function m24_migration_016() {
	if ( class_exists( 'M24_Catalog_Partnums' ) ) {
		M24_Catalog_Partnums::start_backfill();
	}
}

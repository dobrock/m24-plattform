<?php
/**
 * Migration 017 — Teilenummern-Index neu aufbauen: Beschreibungs-Meta (_m24_beschreibung_de/_en) jetzt
 * berücksichtigt (Hotfix zu 0.11.306). Startet den Backfill erneut in Häppchen.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
function m24_migration_017() {
	if ( class_exists( 'M24_Catalog_Partnums' ) ) {
		M24_Catalog_Partnums::start_backfill();
	}
}

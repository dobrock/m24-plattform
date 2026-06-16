<?php
/**
 * M24 Plattform — Shopware-Importer WP-CLI Command (Phase 2 + 3 + CSV-Export)
 * Modul: modules/importer/import-shopware-cli.php
 *
 * Phasen:
 *   Phase 2: Mapping + Upsert ueber `_m24_sw_id`
 *   Phase 3: Bilder (media_sideload_image) mit Hash-Dedupe ueber `_m24_sw_media_hash`
 *   CSV-Export: --export=<path> listet alle Produkte als CSV (implicit dry-run, kein Limit)
 *
 * Usage:
 *   wp m24 import-shopware --dry-run --limit=3
 *   wp m24 import-shopware --limit=3                     # LIVE 3 Posts
 *   wp m24 import-shopware --export=gebrauchtteile.csv   # CSV aller ~523 Produkte
 *
 * Schutz:
 *   - `_m24_manual_lock` → kompletter Skip
 *   - `_m24_logo_anzeigen` + `_m24_leichtbau` bei Re-Import nicht ueberschrieben
 *   - Galerie-Reihenfolge bleibt bei Re-Import erhalten, neue Bilder hinten angehaengt
 *   - Featured-Image nur gesetzt wenn aktuell leer (manuell gesetztes Cover bleibt)
 *   - SEO-Felder (_wpseo_edit_title/description) + Hash-Marker werden via
 *     M24_Catalog_SEO::sync_post() geschrieben → importierte Teile sind re-sync-faehig;
 *     manuelle SEO-Edits (Feld != Marker-Hash) bleiben unangetastet
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

class M24_Import_Shopware_CLI {

	// Per-Produkt-Importlogik (Mapping, Upsert, Steuer, Terms, Medien) — geteilt mit
	// dem Hintergrund-Worker. EINE Quelle, kein Duplikat. Siehe class-m24-shopware-import-core.php.
	use M24_Shopware_Import_Core;

	private $stats = array(
		'created'       => 0,
		'updated'       => 0,
		'skipped_lock'  => 0,
		'skipped_error' => 0,
	);

	/** @var resource|null CSV-Datei-Handle (nur wenn --export). */
	private $export_handle = null;

	/**
	 * Importiert Gebrauchtteile aus Shopware in M24-CPT (m24_teil).
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Aktuell nur 'gebraucht'.
	 * ---
	 * default: gebraucht
	 * options:
	 *   - gebraucht
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Max. Anzahl Produkte (Default 25; bei --export ohne --limit = alle).
	 *
	 * [--dry-run]
	 * : Nichts schreiben, nur Listing + Stats.
	 *
	 * [--export=<path>]
	 * : CSV-Listen-Export (Name; Art.-Nr.; Brutto; Modell; Shopware-Kategorie; Steuer). Implicit --dry-run.
	 *
	 * [--force]
	 * : Re-Import ueberschreibt bestehende post_content / _m24_beschreibung_de.
	 *   Ohne --force bleiben befuellte Inhalte unangetastet (Default).
	 *
	 * [--queue]
	 * : Statt synchron zu importieren, die Produkte als Action-Scheduler-Batches
	 *   einreihen und ueber WP-Cron im Hintergrund abarbeiten (kein Konsolen-Timeout).
	 *   Optional mit --batch-size=<n> (Default 10). Reiner Enqueue-Schritt, laeuft in < 10 s.
	 *
	 * [--batch-size=<n>]
	 * : Produkte pro Hintergrund-Batch (nur mit --queue, Default 10).
	 *
	 * ## EXAMPLES
	 *
	 *     wp m24 import-shopware --dry-run --limit=3
	 *     wp m24 import-shopware --limit=3
	 *     wp m24 import-shopware --limit=3 --force          # erzwingt Beschreibungs-Resync
	 *     wp m24 import-shopware --export=gebrauchtteile.csv
	 *     wp m24 import-shopware --queue                    # Hintergrund-Import via WP-Cron
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		// Hintergrund-Modus: rein additive Abzweigung. Der synchrone Pfad darunter
		// bleibt unveraendert, wenn --queue nicht gesetzt ist.
		if ( isset( $assoc_args['queue'] ) ) {
			M24_Shopware_Queue::cli_enqueue( $args, $assoc_args );
			return;
		}

		$type        = (string) ( $assoc_args['type']  ?? 'gebraucht' );
		$dry_run     = isset( $assoc_args['dry-run'] );
		$force       = isset( $assoc_args['force'] );
		$export_path = isset( $assoc_args['export'] ) ? (string) $assoc_args['export'] : '';

		if ( 'gebraucht' !== $type ) {
			WP_CLI::error( 'Nur --type=gebraucht ist aktuell unterstuetzt.' );
		}

		// Limit-Logik: bei --export ohne --limit = unlimited (0). Sonst Default 25.
		$limit_explicit = isset( $assoc_args['limit'] );
		$default_limit  = ( '' !== $export_path ) ? 0 : 25;
		$limit          = $limit_explicit ? max( 1, (int) $assoc_args['limit'] ) : $default_limit;

		// CSV-Export aktiviert: implicit dry-run.
		if ( '' !== $export_path ) {
			$dry_run = true;
			$this->open_export( $export_path );
		}

		try {
			$client = new M24_Shopware_Client();
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::log( '── M24 Importer (Phase 2 + 3) ──' );
		WP_CLI::log( 'Modus:    ' . ( $dry_run ? 'DRY-RUN (nichts schreiben)' : 'LIVE (schreibt!)' ) );
		WP_CLI::log( 'Force:    ' . ( $force  ? 'ja (Beschreibungs-Resync)' : 'nein (Inhalte bleiben)' ) );
		WP_CLI::log( 'Typ:      ' . $type );
		WP_CLI::log( 'Limit:    ' . ( 0 === $limit ? 'unlimited' : (string) $limit ) );
		if ( '' !== $export_path ) {
			WP_CLI::log( 'Export:   ' . $export_path . ' (CSV)' );
		}
		WP_CLI::log( '' );

		$exclude   = array( '018af11a2e6f7c16a9ed62487f1b3978' ); // Porsche raus
		$processed = 0;
		$page      = 1;
		$page_size = 25;

		while ( true ) {
			try {
				$result = $client->search_used_products( $page, $page_size, $exclude );
			} catch ( Exception $e ) {
				WP_CLI::error( 'Search fehlgeschlagen (page ' . $page . '): ' . $e->getMessage() );
			}

			$products = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
			if ( 1 === $page ) {
				$total = (int) ( $result['total'] ?? 0 );
				WP_CLI::log( sprintf( 'API: %d Produkte gesamt (mit Filter, exact count)', $total ) );
				WP_CLI::log( '' );
			}
			if ( empty( $products ) ) { break; }

			foreach ( $products as $product ) {
				if ( $limit > 0 && $processed >= $limit ) { break 2; }
				$this->process_product( $product, $dry_run, $processed + 1, $force );
				$processed++;
			}
			$page++;
		}

		// Export schliessen
		if ( $this->export_handle ) {
			fclose( $this->export_handle );
			$this->export_handle = null;
		}

		// Stats
		WP_CLI::log( '' );
		WP_CLI::log( '── Stats ──' );
		WP_CLI::log( '  Created:                ' . $this->stats['created'] );
		WP_CLI::log( '  Updated:                ' . $this->stats['updated'] );
		WP_CLI::log( '  Skipped (manual_lock):  ' . $this->stats['skipped_lock'] );
		WP_CLI::log( '  Skipped (error):        ' . $this->stats['skipped_error'] );

		if ( '' !== $export_path ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'CSV geschrieben: ' . $export_path );
		}

		$label = $dry_run ? 'DRY-RUN' : 'LIVE';
		WP_CLI::success( sprintf( 'Importer %s fertig. %d Produkt(e) verarbeitet.', $label, $processed ) );
	}

	private function open_export( $path ) {
		$this->export_handle = fopen( $path, 'w' );
		if ( ! $this->export_handle ) {
			WP_CLI::error( 'Konnte Export-Datei nicht oeffnen: ' . $path );
		}
		// UTF-8-BOM fuer Excel-Kompatibilitaet, dann Header.
		fwrite( $this->export_handle, "\xEF\xBB\xBF" );
		fputcsv( $this->export_handle, array( 'Name', 'Art.-Nr.', 'Brutto', 'Modell', 'Shopware-Kategorie', 'Quell-Steuer', 'Mwst-Modus', 'Beschreibung' ), ';' );
	}

	private function process_product( $product, $dry_run, $n, $force = false ) {
		$sw_id = (string) ( $product['id'] ?? '' );
		$pno   = (string) ( $product['productNumber'] ?? '' );
		$name  = (string) ( $product['name'] ?? '' );
		if ( '' === $sw_id || '' === $name ) {
			WP_CLI::warning( sprintf( '%d. SKIP — fehlende id/name (sw_id="%s")', $n, $sw_id ) );
			$this->stats['skipped_error']++;
			return;
		}

		$existing = $this->find_by_sw_id( $sw_id );

		// Manual-Lock-Check
		if ( $existing && (int) get_post_meta( $existing, '_m24_manual_lock', true ) === 1 ) {
			WP_CLI::log( sprintf( '%d. %s — SKIP (manual_lock, post_id=%d)', $n, $name, $existing ) );
			$this->stats['skipped_lock']++;
			return;
		}

		$action  = $existing ? 'UPDATE' : 'CREATE';
		$mapping = $this->build_mapping( $product );
		$media_count = isset( $product['media'] ) && is_array( $product['media'] ) ? count( $product['media'] ) : 0;

		// Preis-Anzeige je Modus.
		$preis_log = number_format( $mapping['brutto'], 2, ',', '.' ) . ' € Brutto';
		if ( 'regel' === $mapping['mwst_modus'] && null !== $mapping['netto'] ) {
			$preis_log .= ' (Netto ' . number_format( $mapping['netto'], 2, ',', '.' ) . ' € + ' . rtrim( rtrim( number_format( (float) $mapping['tax_rate'], 2, ',', '' ), '0' ), ',' ) . '%)';
		} elseif ( 'paragraf25a' === $mapping['mwst_modus'] ) {
			$preis_log .= ' (§25a Differenz)';
		}

		WP_CLI::log( sprintf( '%d. %s', $n, $name ) );
		WP_CLI::log( '   Action:    ' . $action . ( $existing ? ' (post_id=' . $existing . ')' : '' ) );
		WP_CLI::log( '   Number:    ' . $pno );
		WP_CLI::log( '   Preis:     ' . $preis_log );
		WP_CLI::log( '   Steuer:    Quelle="' . $mapping['quell_steuer'] . '" → Ziel=' . $mapping['mwst_modus'] );
		WP_CLI::log( '   Beschr.:   ' . ( '' !== trim( (string) $mapping['desc'] ) ? mb_substr( wp_strip_all_tags( (string) $mapping['desc'] ), 0, 80 ) . '…' : '— LEER —' ) );
		WP_CLI::log( '   Stand:     ' . ( '' !== $mapping['stand'] ? $mapping['stand'] : '—' ) );
		WP_CLI::log( '   OEM:       ' . ( '' !== $mapping['oem']   ? $mapping['oem']   : '—' ) );
		WP_CLI::log( '   Modell:    ' . ( '' !== $mapping['modell_display'] ? $mapping['modell_display'] . ' (' . $mapping['modell_chassis'] . ')' : '— kein Match' ) );
		$mt_log = ! empty( $mapping['modell_terms'] ) ? implode( ' + ', $mapping['modell_terms'] ) : ( $mapping['modell_term'] ?: '—' );
		WP_CLI::log( '   Term:      Modell(e)="' . $mt_log . '" · Baugruppen=[' . implode( ', ', $this->extract_baugruppe_names( $product ) ) . ']' );
		WP_CLI::log( '   Media:     ' . $media_count . ' Bilder' . ( $dry_run ? ' (nicht heruntergeladen)' : '' ) );

		// CSV-Export-Zeile.
		if ( $this->export_handle ) {
			$mt_csv = ! empty( $mapping['modell_terms'] )
				? implode( ' + ', $mapping['modell_terms'] )
				: ( $mapping['modell_term'] ?: '—' );
			$desc_csv = '' !== trim( (string) $mapping['desc'] ) ? mb_substr( wp_strip_all_tags( (string) $mapping['desc'] ), 0, 120 ) : '—';
			fputcsv( $this->export_handle, array(
				$name,
				$pno,
				number_format( $mapping['brutto'], 2, ',', '.' ),
				$mt_csv,
				$this->categories_path( $product ),
				$mapping['quell_steuer'],
				$mapping['mwst_modus'],
				$desc_csv,
			), ';' );
		}

		if ( $dry_run ) {
			if ( $existing ) { $this->stats['updated']++; }
			else            { $this->stats['created']++; }
			return;
		}

		try {
			$post_id = $this->upsert_post( $existing ?: null, $mapping, $force );
			$this->upsert_meta( $post_id, $mapping, (bool) $existing, $force );
			$this->assign_taxonomies( $post_id, $product, $mapping );
			$this->import_product_media( $product, $post_id, (bool) $existing );
			if ( $existing ) { $this->stats['updated']++; }
			else            { $this->stats['created']++; }
			WP_CLI::log( '   → post_id=' . $post_id );
		} catch ( Exception $e ) {
			WP_CLI::warning( '   Fehler: ' . $e->getMessage() );
			$this->stats['skipped_error']++;
		}
	}

	/** Joined Kategorie-Namen aus `categories`-Association (z.B. "Wurzel > BMW > X5 F15"). */
	private function categories_path( $product ) {
		$cats = isset( $product['categories'] ) && is_array( $product['categories'] ) ? $product['categories'] : array();
		$names = array();
		foreach ( $cats as $c ) {
			if ( isset( $c['name'] ) && '' !== $c['name'] ) {
				$names[] = (string) $c['name'];
			}
		}
		return implode( ' > ', $names );
	}

}

WP_CLI::add_command( 'm24 import-shopware', 'M24_Import_Shopware_CLI' );

// Hintergrund-Queue (Action Scheduler): Enqueue-Alias + Status. Logik in import-shopware-queue.php.
WP_CLI::add_command( 'm24 import-queue',  array( 'M24_Shopware_Queue', 'cli_enqueue' ) );
WP_CLI::add_command( 'm24 import-status', array( 'M24_Shopware_Queue', 'cli_status' ) );

// Rennsport-Import (kategorie-getrieben, eigener AS-Hook). Logik in import-shopware-rennsport.php.
if ( class_exists( 'M24_Shopware_Rennsport' ) ) {
	WP_CLI::add_command( 'm24 import-rennsport', array( 'M24_Shopware_Rennsport', 'cli' ) );
}

/**
 * Extrahiert BMW-Teilenummern aus den Beschreibungen aller m24_teil-Posts und schreibt
 * sie in _m24_bmw_teilenummer — nur wenn das Feld leer ist (idempotent, kein Overwrite).
 *
 * Usage:
 *   wp m24 extract-bmw-teilenummer --dry-run --export=audit.csv
 *   wp m24 extract-bmw-teilenummer                    # mit Bestaetigungs-Prompt
 *   wp m24 extract-bmw-teilenummer --yes              # ohne Prompt
 *   wp m24 extract-bmw-teilenummer --limit=50         # Limit
 *
 * CSV-Format: Art.-Nr.; gefundene BMW-Nr.; Quelle (cue/muster/skip/none); Kandidaten
 */
/**
 * T22b: Entfernt Leerzeichen in BMW-Teilenummer-Patterns aus allen m24_teil-Titeln.
 * Slug regeneriert sich automatisch via save_post-Hook (catalog-fields::auto_slug).
 *
 * Usage:
 *   wp m24 compact-bmw-nummer-im-titel --dry-run [--limit=N]
 *   wp m24 compact-bmw-nummer-im-titel              # mit Confirm
 *   wp m24 compact-bmw-nummer-im-titel --yes
 */
WP_CLI::add_command( 'm24 compact-bmw-nummer-im-titel', function( $args, $assoc ) {
	$dry      = ! empty( $assoc['dry-run'] );
	$auto_yes = ! empty( $assoc['yes'] );
	$limit    = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 0;

	$ids = get_posts( array(
		'post_type'      => 'm24_teil',
		'post_status'    => 'any',
		'posts_per_page' => $limit > 0 ? $limit : -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );
	if ( empty( $ids ) ) { WP_CLI::log( 'Keine m24_teil-Posts gefunden.' ); return; }

	$changes = array();
	foreach ( $ids as $pid ) {
		$old = (string) get_post_field( 'post_title', $pid );
		$new = M24_BMW_Teilenummer_Extractor::compact_in_title( $old );
		if ( $new !== $old ) { $changes[ $pid ] = array( 'old' => $old, 'new' => $new ); }
	}

	WP_CLI::log( sprintf( 'Posts gescannt:      %d', count( $ids ) ) );
	WP_CLI::log( sprintf( 'Aenderungen erkannt: %d', count( $changes ) ) );
	WP_CLI::log( '' );
	if ( empty( $changes ) ) { WP_CLI::success( 'Nichts zu tun.' ); return; }

	foreach ( $changes as $pid => $c ) {
		WP_CLI::log( '  [' . $pid . ']' );
		WP_CLI::log( '    alt: ' . $c['old'] );
		WP_CLI::log( '    neu: ' . $c['new'] );
	}

	if ( $dry ) { WP_CLI::warning( 'Dry-run: nichts geaendert.' ); return; }
	if ( ! $auto_yes ) {
		WP_CLI::confirm( sprintf( 'Titel auf %d Posts wirklich aendern? Slug regeneriert sich + 301 vom alten.', count( $changes ) ) );
	}
	$ok = 0;
	foreach ( $changes as $pid => $c ) {
		$r = wp_update_post( array( 'ID' => $pid, 'post_title' => $c['new'] ), true );
		if ( ! is_wp_error( $r ) ) { $ok++; }
	}
	WP_CLI::success( sprintf( 'Titel auf %d Posts geaendert.', $ok ) );
} );

WP_CLI::add_command( 'm24 extract-bmw-teilenummer', function( $args, $assoc ) {
	$dry      = ! empty( $assoc['dry-run'] );
	$auto_yes = ! empty( $assoc['yes'] );
	$limit    = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 0;
	$export   = isset( $assoc['export'] ) ? (string) $assoc['export'] : '';
	if ( '' !== $export ) { $dry = true; }

	$query = array(
		'post_type'      => 'm24_teil',
		'post_status'    => 'any',
		'posts_per_page' => $limit > 0 ? $limit : -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array( array( 'key' => '_m24_typ', 'value' => 'gebraucht' ) ),
	);
	$ids = get_posts( $query );
	if ( empty( $ids ) ) { WP_CLI::log( 'Keine Gebrauchtteile gefunden.' ); return; }

	$handle = null;
	if ( '' !== $export ) {
		$handle = fopen( $export, 'w' );
		if ( ! $handle ) { WP_CLI::error( 'Konnte Export-Datei nicht oeffnen: ' . $export ); }
		fwrite( $handle, "\xEF\xBB\xBF" );
		fputcsv( $handle, array( 'Art.-Nr.', 'BMW-Nr. gefunden', 'Quelle', 'Kandidaten', 'BMW-Nr. bereits gesetzt' ), ';' );
	}

	$stats = array(
		'cue'        => 0,
		'muster'     => 0,
		'titel'      => 0,
		'skip'       => 0,
		'none'       => 0,
		'pre_filled' => 0,
		'written'    => 0,
	);

	foreach ( $ids as $pid ) {
		$artnr   = (string) get_post_meta( $pid, '_m24_artikelnummer', true );
		$current = trim( (string) get_post_meta( $pid, '_m24_bmw_teilenummer', true ) );
		$desc    = (string) get_post_field( 'post_content', $pid );
		if ( '' === trim( $desc ) ) {
			$desc = (string) get_post_meta( $pid, '_m24_beschreibung_de', true );
		}

		$res        = M24_BMW_Teilenummer_Extractor::extract( $desc );
		$number     = in_array( $res['source'], array( 'cue', 'muster' ), true ) ? $res['number'] : null;
		$source     = $res['source'];
		$candidates = $res['candidates'];

		// Fallback: 11-stellige BMW-Nummer aus dem TITEL (z.B. „… Frontspoiler 51712238178").
		// Die Beschreibung liefert sie oft nicht; im Titel steht sie kompakt + eindeutig.
		if ( null === $number ) {
			$from_title = M24_BMW_Teilenummer_Extractor::from_title( get_post_field( 'post_title', $pid ) );
			if ( null !== $from_title['number'] ) {
				$number     = $from_title['number'];
				$source     = 'titel';
				$candidates = $from_title['candidates'];
			}
		}

		$stats[ $source ]++;
		$pre = ( '' !== $current );
		if ( $pre ) { $stats['pre_filled']++; }

		if ( $handle ) {
			fputcsv( $handle, array(
				$artnr,
				$number ?: '—',
				$source,
				implode( ' | ', $candidates ),
				$pre ? $current : '—',
			), ';' );
		}

		if ( ! $dry && ! $pre && null !== $number ) {
			update_post_meta( $pid, '_m24_bmw_teilenummer', $number );
			$stats['written']++;
		}
	}

	if ( $handle ) { fclose( $handle ); }

	WP_CLI::log( '' );
	WP_CLI::log( '── Extract-BMW-Teilenummer Stats ──' );
	WP_CLI::log( sprintf( '  Posts gescannt:     %d', count( $ids ) ) );
	WP_CLI::log( sprintf( '  Cue (hoch):         %d', $stats['cue'] ) );
	WP_CLI::log( sprintf( '  Muster:             %d', $stats['muster'] ) );
	WP_CLI::log( sprintf( '  Titel (11-stellig): %d', $stats['titel'] ) );
	WP_CLI::log( sprintf( '  Skip (multi):       %d', $stats['skip'] ) );
	WP_CLI::log( sprintf( '  None:               %d', $stats['none'] ) );
	WP_CLI::log( sprintf( '  Schon befuellt:     %d', $stats['pre_filled'] ) );
	if ( '' !== $export ) {
		WP_CLI::log( '' );
		WP_CLI::log( 'CSV geschrieben: ' . $export );
	}

	if ( $dry ) {
		WP_CLI::warning( 'Dry-run: nichts geschrieben.' );
		return;
	}
	WP_CLI::success( sprintf( 'BMW-Teilenummer in %d Posts geschrieben.', $stats['written'] ) );
} );

/**
 * Cleanup-Befehl: loescht alle Baugruppe-Terms, die NICHT in der Whitelist
 * (M24_Import_Shopware_CLI::BAUGRUPPE_WHITELIST) stehen.
 *
 * Usage:
 *   wp m24 cleanup-baugruppen --dry-run     # nur anzeigen, nichts loeschen
 *   wp m24 cleanup-baugruppen               # mit Bestaetigungs-Prompt
 *   wp m24 cleanup-baugruppen --yes         # ohne Bestaetigung
 *
 * Beim Term-Delete entfernt WP die Post-Term-Verknuepfungen automatisch — manuell
 * gesetzte Zuordnungen zu Nicht-Whitelist-Terms gehen also verloren. Re-Import danach
 * weist die korrekten Whitelist-Terms wieder zu.
 */
WP_CLI::add_command( 'm24 cleanup-baugruppen', function( $args, $assoc ) {
	$tax       = M24_Catalog_CPT::TAXONOMY_BAUGRUPPE;
	$dry_run   = ! empty( $assoc['dry-run'] );
	$auto_yes  = ! empty( $assoc['yes'] );
	$whitelist = M24_Import_Shopware_CLI::baugruppe_whitelist();

	$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		WP_CLI::log( 'Keine Baugruppe-Terms vorhanden.' );
		return;
	}

	$to_keep   = array();
	$to_delete = array();
	foreach ( $terms as $t ) {
		if ( in_array( $t->name, $whitelist, true ) ) {
			$to_keep[] = $t;
		} else {
			$to_delete[] = $t;
		}
	}

	WP_CLI::log( sprintf( 'Whitelist: %d Eintraege.', count( $whitelist ) ) );
	WP_CLI::log( sprintf( 'Bestand:   %d Terms (behalten=%d, loeschen=%d).', count( $terms ), count( $to_keep ), count( $to_delete ) ) );
	WP_CLI::log( '' );

	if ( ! empty( $to_keep ) ) {
		WP_CLI::log( '✓ Behalten:' );
		foreach ( $to_keep as $t ) {
			WP_CLI::log( sprintf( '    [%d] %s (count=%d)', $t->term_id, $t->name, (int) $t->count ) );
		}
		WP_CLI::log( '' );
	}

	if ( empty( $to_delete ) ) {
		WP_CLI::success( 'Nichts zu loeschen. Taxonomie ist sauber.' );
		return;
	}

	WP_CLI::log( '✗ Loeschen:' );
	foreach ( $to_delete as $t ) {
		WP_CLI::log( sprintf( '    [%d] %s (count=%d)', $t->term_id, $t->name, (int) $t->count ) );
	}
	WP_CLI::log( '' );

	if ( $dry_run ) {
		WP_CLI::warning( 'Dry-run: nichts geloescht.' );
		return;
	}

	if ( ! $auto_yes ) {
		WP_CLI::confirm( sprintf( '%d Term(s) wirklich loeschen? Post-Verknuepfungen werden mit entfernt.', count( $to_delete ) ) );
	}

	$ok = 0; $fail = 0;
	foreach ( $to_delete as $t ) {
		$r = wp_delete_term( $t->term_id, $tax );
		if ( true === $r ) { $ok++; } else { $fail++; WP_CLI::warning( 'Loeschen fehlgeschlagen: ' . $t->name ); }
	}
	WP_CLI::success( sprintf( 'Geloescht: %d · Fehler: %d', $ok, $fail ) );
} );

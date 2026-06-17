<?php
/**
 * M24 Plattform — Shopware-Import Kern-Logik (kontextfrei, ohne WP-CLI-Pflicht)
 * Modul: modules/importer/class-m24-shopware-import-core.php
 *
 * Trait mit der per-Produkt-Importlogik (Mapping, Upsert, Steuer, Modell/Terms,
 * Medien-Sideload + Hash-Dedupe). Bewusst aus dem WP-CLI-Command herausgezogen,
 * damit DIESELBE Logik sowohl synchron (wp m24 import-shopware) als auch im
 * Hintergrund-Worker (Action Scheduler, WP-Cron-Kontext) genutzt werden kann —
 * EINE Quelle, kein Duplikat.
 *
 * Alle WP_CLI-Ausgaben sind geschuetzt (`defined('WP_CLI')`), sodass die Methoden
 * im Cron-/Web-Kontext gefahrlos laufen. Verhalten im CLI-Pfad bleibt identisch.
 *
 * Verifiziertes Verhalten (unveraendert wiederverwendet):
 *   - Idempotent ueber `_m24_sw_id` → Re-Run = UPDATE statt Duplikat.
 *   - Medien-Reuse ueber `_m24_sw_media_hash` → kein Re-Download.
 *   - `_m24_manual_lock` → kompletter Skip.
 *   - Galerie-Reihenfolge bleibt bei Re-Import, neue Bilder hinten an.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait M24_Shopware_Import_Core {

	/**
	 * Kontextfreie Orchestrierung fuer EIN Produkt — identische Schrittfolge wie der
	 * synchrone CLI-Pfad (upsert_post → upsert_meta → assign_taxonomies → media),
	 * aber ohne WP-CLI-Ausgabe. Fuer den Hintergrund-Worker.
	 *
	 * @param array $product Rohes Shopware-Produkt (mit media/tax/categories-Associations).
	 * @param bool  $force   Beschreibungs-Resync erzwingen.
	 * @return array { status: created|updated|skipped_lock|skipped_error, post_id:int, name:string, error:string }
	 */
	public function import_product_core( array $product, $force = false ) {
		$sw_id = (string) ( $product['id'] ?? '' );
		$name  = (string) ( $product['name'] ?? '' );
		if ( '' === $sw_id || '' === $name ) {
			return array( 'status' => 'skipped_error', 'post_id' => 0, 'name' => $name, 'error' => 'fehlende id/name' );
		}

		$existing = $this->find_by_sw_id( $sw_id );
		if ( $existing && (int) get_post_meta( $existing, '_m24_manual_lock', true ) === 1 ) {
			return array( 'status' => 'skipped_lock', 'post_id' => $existing, 'name' => $name, 'error' => '' );
		}

		$mapping = $this->build_mapping( $product );
		try {
			$post_id = $this->upsert_post( $existing ?: null, $mapping, $force );
			$this->upsert_meta( $post_id, $mapping, (bool) $existing, $force );
			$this->assign_taxonomies( $post_id, $product, $mapping );
			// Produkt steht ab hier garantiert. Medien optional entkoppeln (Rennsport-Import:
			// Bilder NACH der Anlage best-effort, blockieren nie die Produktanlage).
			if ( ! apply_filters( 'm24_sw_skip_media', false, $product ) ) {
				$this->import_product_media( $product, $post_id, (bool) $existing );
			}
			return array(
				'status'  => $existing ? 'updated' : 'created',
				'post_id' => $post_id,
				'name'    => $name,
				'error'   => '',
			);
		} catch ( Exception $e ) {
			return array(
				'status'  => 'skipped_error',
				'post_id' => $existing ?: 0,
				'name'    => $name,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Nur-Medien-Resync fuer ein bestehendes Teil — laedt FEHLENDE Bilder erneut von
	 * Shopware (Hash-Dedupe → vorhandene unangetastet, Featured nur wenn leer). Titel,
	 * Preis, Meta bleiben unberuehrt (im Gegensatz zum vollen import_product_core).
	 */
	public function import_media( array $product, $post_id, $is_update = true ) {
		$this->import_product_media( $product, (int) $post_id, (bool) $is_update );
	}

	/**
	 * NUR Featured Image: laedt das Cover-Bild (coverId, sonst erstes nach position) und
	 * setzt es als Beitragsbild — Galerie wird uebersprungen. Schnell + Konsolen-Timeout-safe
	 * (1 Download/Teil). Tut nichts, wenn schon ein Featured Image existiert.
	 *
	 * @return bool true wenn ein Cover gesetzt wurde.
	 */
	public function import_cover_only( array $product, $post_id ) {
		$post_id = (int) $post_id;
		if ( get_post_thumbnail_id( $post_id ) ) { return false; }
		$media = isset( $product['media'] ) && is_array( $product['media'] ) ? $product['media'] : array();
		if ( empty( $media ) ) { return false; }
		usort( $media, function ( $a, $b ) {
			return ( (int) ( $a['position'] ?? 0 ) ) <=> ( (int) ( $b['position'] ?? 0 ) );
		} );
		$cover_sw_id = (string) ( $product['coverId'] ?? '' );
		$pick = null;
		if ( '' !== $cover_sw_id ) {
			foreach ( $media as $m ) {
				if ( (string) ( $m['id'] ?? '' ) === $cover_sw_id ) { $pick = $m; break; }
			}
		}
		if ( null === $pick ) { $pick = $media[0]; }

		$mob  = isset( $pick['media'] ) && is_array( $pick['media'] ) ? $pick['media'] : array();
		$url  = (string) ( $mob['url'] ?? '' );
		$hash = (string) ( $mob['metaData']['hash'] ?? '' );
		if ( '' === $url ) { return false; }

		// Dedup-Guard 0.9.26: globaler Wiederverwendungs-/Platzhalter-Schutz (eine Quelle).
		$att = class_exists( 'M24_Shopware_Media' ) ? M24_Shopware_Media::get_or_create_attachment( $hash, $url, $post_id ) : $this->sideload_image( $url, $post_id, $hash );
		if ( $att > 0 ) {
			set_post_thumbnail( $post_id, $att );
			if ( defined( 'WP_CLI' ) && WP_CLI ) { WP_CLI::log( '   → Cover gesetzt (ID ' . $att . ')' ); }
			return true;
		}
		return false;
	}

	protected function find_by_sw_id( $sw_id ) {
		$q = get_posts( array(
			'post_type'      => M24_Catalog_CPT::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => '_m24_sw_id', 'value' => $sw_id, 'compare' => '=' ) ),
			'no_found_rows'  => true,
		) );
		return ! empty( $q ) ? (int) $q[0] : 0;
	}

	protected function build_mapping( $product ) {
		$price  = isset( $product['price'][0]['gross'] ) ? (float) $product['price'][0]['gross'] : 0.0;
		$stand  = (string) ( $product['customFields']['migration_newshopware5_product_attr2'] ?? '' );
		$oem    = (string) ( $product['manufacturerNumber'] ?? '' );
		$name   = (string) ( $product['name'] ?? '' );
		$pno    = (string) ( $product['productNumber'] ?? '' );
		// T22b: BMW-Teilenummer im Titel kompakten (Spaces in OEM-Nummer raus → Google-Findbarkeit).
		$name   = M24_BMW_Teilenummer_Extractor::compact_in_title( $name );

		// T2: Beschreibung — Shopware liefert je nach Locale entweder als `description`
		// (de_DE-Default) oder in `translated.description`. Beides pruefen, erstes nicht-leeres nehmen.
		$desc_tr  = isset( $product['translated']['description'] ) ? (string) $product['translated']['description'] : '';
		$desc_raw = isset( $product['description'] )               ? (string) $product['description']               : '';
		$desc     = '' !== trim( $desc_tr ) ? $desc_tr : $desc_raw;

		// BMW-Teilenummer: bevorzugt manufacturerNumber, sonst aus Beschreibung, sonst aus Titel.
		if ( '' === trim( $oem ) && '' !== trim( $desc ) ) {
			$extracted = M24_BMW_Teilenummer_Extractor::extract( $desc );
			if ( in_array( $extracted['source'], array( 'cue', 'muster' ), true ) ) {
				$oem = (string) $extracted['number'];
			}
		}
		// Fallback: eindeutige 11-stellige BMW-Nummer aus dem (bereits kompaktierten) Titel,
		// z.B. „… Frontspoiler 51712238178". Spiegelt den CLI-Command extract-bmw-teilenummer.
		if ( '' === trim( $oem ) ) {
			$from_title = M24_BMW_Teilenummer_Extractor::from_title( $name );
			if ( null !== $from_title['number'] ) {
				$oem = (string) $from_title['number'];
			}
		}

		// T1: Steuer-Info aus Tax-Association lesen. Default 'regel' — NIE blind §25a.
		$tax_name = trim( (string) ( $product['tax']['name']    ?? '' ) );
		$tax_rate = isset( $product['tax']['taxRate'] ) ? (float) $product['tax']['taxRate'] : null;
		$mwst_modus = self::infer_mwst_modus( $tax_name, $tax_rate );
		// Netto-Ableitung: bei Regel-Steuer aus Brutto/Tax-Rate. Bei §25a bleibt null.
		$netto = null;
		if ( 'regel' === $mwst_modus ) {
			$rate_eff = ( null !== $tax_rate && $tax_rate > 0 ) ? $tax_rate : 19.0; // Default 19% wenn Tax leer
			$netto = round( $price / ( 1.0 + $rate_eff / 100.0 ), 2 );
		}
		// Audit-String fuer CSV/Log.
		$quell_steuer = '';
		if ( '' !== $tax_name )       { $quell_steuer  = $tax_name; }
		if ( null !== $tax_rate )     { $quell_steuer .= ( '' !== $quell_steuer ? ' ' : '' ) . rtrim( rtrim( number_format( $tax_rate, 2, ',', '' ), '0' ), ',' ) . '%'; }
		if ( '' === $quell_steuer )   { $quell_steuer  = '— (kein tax)'; }

		// Kategorien-Namen als zusaetzlicher Such-Kontext.
		$cat_names = array();
		if ( isset( $product['categories'] ) && is_array( $product['categories'] ) ) {
			foreach ( $product['categories'] as $c ) {
				if ( isset( $c['name'] ) && '' !== $c['name'] ) { $cat_names[] = (string) $c['name']; }
			}
		}
		$parsed = M24_BMW_Models::parse_from_name( $name, implode( ' ', $cat_names ) );
		return array(
			'sw_id'          => (string) ( $product['id'] ?? '' ),
			'pno'            => $pno,
			'name'           => $name,
			'desc'           => $desc,
			'brutto'         => round( $price, 2 ),
			'netto'          => $netto,           // null bei §25a
			'mwst_modus'     => $mwst_modus,      // 'regel' | 'paragraf25a'
			'tax_name'       => $tax_name,
			'tax_rate'       => $tax_rate,
			'quell_steuer'   => $quell_steuer,    // Audit-String
			'stand'          => trim( $stand ),
			'oem'            => trim( $oem ),
			'modell_chassis' => $parsed['chassis']    ?? '',
			'modell_display' => $parsed['display']    ?? '',
			'modell_slug'    => $parsed['slug']       ?? '',
			'modell_term'    => $parsed['term_name']  ?? '',
			'modell_terms'   => isset( $parsed['term_names'] ) && is_array( $parsed['term_names'] ) ? $parsed['term_names'] : array(),
			'modell_is_m3'   => (bool) ( $parsed['is_m3'] ?? false ),
		);
	}

	/**
	 * §25a-Detektion. Konservativ: Default 'regel', NIE blind §25a.
	 *
	 * Signale (in dieser Reihenfolge):
	 *  1. tax.name matched /25a|differenz/i → §25a
	 *  2. tax.taxRate = 0 (mit tax.name gesetzt) → §25a
	 *  3. sonst → 'regel' (Default)
	 *
	 * Anpassbar via Filter `m24_sw_mwst_modus`.
	 */
	protected static function infer_mwst_modus( $tax_name, $tax_rate ) {
		$modus = 'regel';
		if ( '' !== $tax_name && preg_match( '/25a|differenz/i', $tax_name ) ) {
			$modus = 'paragraf25a';
		} elseif ( '' !== $tax_name && null !== $tax_rate && 0.0 === (float) $tax_rate ) {
			$modus = 'paragraf25a';
		}
		return apply_filters( 'm24_sw_mwst_modus', $modus, $tax_name, $tax_rate );
	}

	protected function upsert_post( $existing_id, $mapping, $force = false ) {
		$post_data = array(
			'post_title'   => $mapping['name'],
			'post_type'    => M24_Catalog_CPT::POST_TYPE,
			'post_status'  => 'publish',
		);
		// T2: post_content — neu Posts immer, Re-Import nur wenn leer (oder --force).
		$desc_clean = trim( wp_kses_post( (string) $mapping['desc'] ) );
		if ( '' !== $desc_clean ) {
			if ( ! $existing_id ) {
				$post_data['post_content'] = $desc_clean;
			} else {
				$existing_content = trim( (string) get_post_field( 'post_content', $existing_id ) );
				if ( '' === $existing_content || $force ) {
					$post_data['post_content'] = $desc_clean;
				}
			}
		}
		if ( $existing_id ) {
			$post_data['ID'] = $existing_id;
			$r = wp_update_post( $post_data, true );
		} else {
			$r = wp_insert_post( $post_data, true );
		}
		if ( is_wp_error( $r ) ) {
			throw new Exception( $r->get_error_message() );
		}
		return (int) $r;
	}

	protected function upsert_meta( $post_id, $mapping, $is_update, $force = false ) {
		update_post_meta( $post_id, '_m24_sw_id',         $mapping['sw_id'] );
		update_post_meta( $post_id, '_m24_artikelnummer', $mapping['pno'] );
		// Teil-Typ: Default 'gebraucht'; der Rennsport-Importer setzt per Filter 'neu'.
		$typ = apply_filters( 'm24_sw_import_typ', 'gebraucht', $mapping );
		update_post_meta( $post_id, '_m24_typ',           in_array( $typ, array( 'neu', 'gebraucht' ), true ) ? $typ : 'gebraucht' );
		// T1: mwst_modus aus mapping (nicht mehr blind §25a).
		update_post_meta( $post_id, '_m24_mwst_modus',    $mapping['mwst_modus'] );

		// Preisoption: bei Regel netto kalkuliert, bei §25a netto = null.
		$option = array(
			'label'  => '',
			'art_nr' => $mapping['pno'],
			'netto'  => $mapping['netto'],   // null bei §25a, sonst Brutto/(1+rate)
			'brutto' => $mapping['brutto'],
		);
		update_post_meta( $post_id, '_m24_preisoptionen', wp_json_encode( array( $option ) ) );
		// Legacy _m24_preis_netto: bei Regel netto, bei §25a brutto (Basispreis).
		$legacy_basis = ( 'regel' === $mapping['mwst_modus'] && null !== $mapping['netto'] )
			? (float) $mapping['netto']
			: (float) $mapping['brutto'];
		update_post_meta( $post_id, '_m24_preis_netto',   $legacy_basis );
		// Preis-Eingabe-Modus passt zum Steuermodus. Nur bei Neu-Post, manuelle Edits bleiben.
		if ( ! $is_update ) {
			update_post_meta( $post_id, '_m24_preis_eingabe', 'brutto' );
		}

		// T2: Beschreibung-Meta — analog post_content nur befuellen wenn leer / force.
		if ( '' !== trim( (string) $mapping['desc'] ) ) {
			$existing_meta = trim( (string) get_post_meta( $post_id, '_m24_beschreibung_de', true ) );
			if ( '' === $existing_meta || $force ) {
				update_post_meta( $post_id, '_m24_beschreibung_de', wp_kses_post( (string) $mapping['desc'] ) );
			}
		}

		if ( '' !== $mapping['oem'] ) {
			// Override-Schutz: nicht ueberschreiben wenn manuell etwas eingetragen wurde. Idempotent.
			$existing_oem = trim( (string) get_post_meta( $post_id, '_m24_bmw_teilenummer', true ) );
			if ( '' === $existing_oem ) {
				update_post_meta( $post_id, '_m24_bmw_teilenummer', $mapping['oem'] );
			}
		}
		if ( '' !== $mapping['stand'] ) {
			update_post_meta( $post_id, '_m24_stand', $mapping['stand'] );
		}
		if ( '' !== $mapping['modell_chassis'] ) {
			update_post_meta( $post_id, '_m24_modell', $mapping['modell_chassis'] );
		}

		if ( ! $is_update ) {
			update_post_meta( $post_id, '_m24_status',          'aktiv' );
			update_post_meta( $post_id, '_m24_logo_anzeigen',   1 );
			update_post_meta( $post_id, '_m24_leichtbau',       0 );
			update_post_meta( $post_id, '_m24_rennsport_hinweis', 0 );
		}

		// SEO-Feld + Hash-Marker schreiben/auto-syncen. Manuelle Edits bleiben unangetastet.
		if ( class_exists( 'M24_Catalog_SEO' ) ) {
			M24_Catalog_SEO::sync_post( $post_id );
		}
	}

	/**
	 * Term-Zuweisung (multi-term, APPEND): Modell (m24_fahrzeugkat) + Baugruppe.
	 * Manuelle Zuweisungen bleiben beim Re-Import erhalten.
	 */
	protected function assign_taxonomies( $post_id, $product, $mapping ) {
		// Modell-Terms (Multi)
		$modell_term_names = array();
		if ( isset( $mapping['modell_terms'] ) && is_array( $mapping['modell_terms'] ) && ! empty( $mapping['modell_terms'] ) ) {
			$modell_term_names = $mapping['modell_terms'];
		} elseif ( '' !== trim( (string) ( $mapping['modell_term'] ?? '' ) ) ) {
			$modell_term_names = array( $mapping['modell_term'] );
		}
		// Rennsport-Importer erzwingt hier den Hub-Modell-Term (z.B. „Z4 GT3"); Default = geparst.
		$modell_term_names = (array) apply_filters( 'm24_sw_import_modell_terms', $modell_term_names, $product, $mapping );
		$modell_ids      = array();
		$modell_assigned = array();
		foreach ( $modell_term_names as $tn ) {
			$tn = trim( (string) $tn );
			if ( '' === $tn ) { continue; }
			$tid = $this->ensure_term( $tn, M24_Catalog_CPT::TAXONOMY );
			if ( $tid > 0 ) {
				$modell_ids[]      = $tid;
				$modell_assigned[] = $tn;
			}
		}
		if ( ! empty( $modell_ids ) ) {
			// APPEND-Modus: bestehende Terms (auch manuell hinzugefuegte) bleiben.
			wp_set_object_terms( $post_id, $modell_ids, M24_Catalog_CPT::TAXONOMY, true );
		}

		// Baugruppe-Terms (Multi, ebenfalls APPEND)
		$bg_names = $this->extract_baugruppe_names( $product );
		$bg_ids   = array();
		foreach ( $bg_names as $bn ) {
			$tid = $this->ensure_term( $bn, M24_Catalog_CPT::TAXONOMY_BAUGRUPPE );
			if ( $tid > 0 ) { $bg_ids[] = $tid; }
		}
		if ( ! empty( $bg_ids ) ) {
			wp_set_object_terms( $post_id, $bg_ids, M24_Catalog_CPT::TAXONOMY_BAUGRUPPE, true );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::log( sprintf(
				'   → Terms: Modell(e)=%s · Baugruppe(n)=%s',
				empty( $modell_assigned ) ? '—' : implode( ' + ', $modell_assigned ),
				empty( $bg_names )         ? '—' : implode( ' / ', $bg_names )
			) );
		}
	}

	/**
	 * Whitelist der erlaubten Baugruppe-Term-Namen (exakte String-Matches, case-sensitive).
	 * Nicht-gelistete Shopware-Kategorien (Modellkategorien, Motorcodes, „Sonstiges" …)
	 * werden uebersprungen. Aenderungen + `wp m24 cleanup-baugruppen` halten die Taxonomie konsistent.
	 */
	public static function baugruppe_whitelist() {
		return array(
			'Motor, Kühlung',
			'Getriebe, Kupplung',
			'Fahrwerk, Achsen, Lenkung',
			'Abgasanlage, Kraftstoffanlage',
			'Karosserie',
			'Räder, Bremsen',
			'Felgen',
			'Sitze, Fahrzeugausstattung',
			'Audio, Navigation, Anzeigeinstrumente',
			'Beleuchtung, Elektrik',
			'Heizung, Klimaanlage',
		);
	}

	/**
	 * Filter Shopware-Kategorien fuer Baugruppe-Taxonomie: STRIKT gegen Whitelist.
	 */
	protected function extract_baugruppe_names( $product ) {
		$cats = isset( $product['categories'] ) && is_array( $product['categories'] ) ? $product['categories'] : array();
		$whitelist = self::baugruppe_whitelist();
		$out = array();
		foreach ( $cats as $c ) {
			$name = trim( (string) ( $c['name'] ?? '' ) );
			if ( '' === $name ) { continue; }
			if ( in_array( $name, $whitelist, true ) ) {
				$out[] = $name;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Term-Lookup, legt neu an wenn nicht vorhanden. Returnt term_id (0 bei Fehler). */
	protected function ensure_term( $name, $taxonomy ) {
		$name = trim( (string) $name );
		if ( '' === $name ) { return 0; }
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$r = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $r ) ) {
			// Race: ggf. inzwischen angelegt
			$term = get_term_by( 'name', $name, $taxonomy );
			return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
		}
		return (int) $r['term_id'];
	}

	/**
	 * Bilder importieren mit Hash-Dedupe ueber `_m24_sw_media_hash`.
	 * Re-Import-Schutz: bestehende Galerie-Reihenfolge bleibt, neue Bilder hinten an.
	 * Featured nur setzen wenn aktuell leer.
	 */
	protected function import_product_media( $product, $post_id, $is_update ) {
		$media = isset( $product['media'] ) && is_array( $product['media'] ) ? $product['media'] : array();
		if ( empty( $media ) ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) { WP_CLI::log( '   → Media: keine' ); }
			return;
		}

		// Sortierung nach position (Shopware product_media.position)
		usort( $media, function( $a, $b ) {
			return ( (int) ( $a['position'] ?? 0 ) ) <=> ( (int) ( $b['position'] ?? 0 ) );
		} );

		$cover_sw_id  = (string) ( $product['coverId'] ?? '' );
		$existing_csv = (string) get_post_meta( $post_id, '_m24_galerie', true );
		$existing_ids = array_filter( array_map( 'intval', explode( ',', $existing_csv ) ) );

		// Hash → Attachment-ID (existing-Mapping)
		$hash_idx = array();
		foreach ( $existing_ids as $att_id ) {
			$h = (string) get_post_meta( $att_id, '_m24_sw_media_hash', true );
			if ( '' !== $h ) { $hash_idx[ $h ] = (int) $att_id; }
		}
		$current_featured = (int) get_post_thumbnail_id( $post_id );
		if ( $current_featured ) {
			$fh = (string) get_post_meta( $current_featured, '_m24_sw_media_hash', true );
			if ( '' !== $fh ) { $hash_idx[ $fh ] = $current_featured; }
		}

		$imported  = 0;
		$reused    = 0;
		$errors    = 0;
		$cover_att = 0;
		$new_gallery_ordered = array();

		foreach ( $media as $m ) {
			$pm_id = (string) ( $m['id'] ?? '' );
			$mob   = isset( $m['media'] ) && is_array( $m['media'] ) ? $m['media'] : array();
			$url   = (string) ( $mob['url'] ?? '' );
			$hash  = (string) ( $mob['metaData']['hash'] ?? '' );
			if ( '' === $url ) { continue; }

			$att_id = 0;
			if ( '' !== $hash && isset( $hash_idx[ $hash ] ) ) {
				$att_id = $hash_idx[ $hash ];
				$reused++;
			} else {
				// Dedup-Guard 0.9.26: GLOBALE Wiederverwendung (nicht nur post-lokal) → kein
				// neues Attachment, wenn der Hash bereits irgendwo existiert. Platzhalter werden
				// uebersprungen (att_id=0). Eine Quelle der Wahrheit fuer beide Import-Pfade.
				$existing = ( '' !== $hash && class_exists( 'M24_Shopware_Media' ) ) ? M24_Shopware_Media::find_by_hash( $hash ) : 0;
				$att_id   = class_exists( 'M24_Shopware_Media' ) ? M24_Shopware_Media::get_or_create_attachment( $hash, $url, $post_id ) : $this->sideload_image( $url, $post_id, $hash );
				if ( $att_id > 0 ) {
					if ( $existing > 0 ) { $reused++; } else { $imported++; }
					if ( '' !== $hash ) { $hash_idx[ $hash ] = $att_id; }
				} else {
					$errors++;
				}
			}

			if ( $att_id > 0 ) {
				if ( $pm_id === $cover_sw_id ) {
					$cover_att = $att_id;
				} else {
					$new_gallery_ordered[] = $att_id;
				}
			}
		}

		// Re-Import-Schutz: bestehende Reihenfolge behalten, neue hinten anhaengen
		if ( $is_update && ! empty( $existing_ids ) ) {
			$final_gallery = $existing_ids;
			foreach ( $new_gallery_ordered as $att_id ) {
				if ( ! in_array( $att_id, $final_gallery, true ) ) {
					$final_gallery[] = $att_id;
				}
			}
		} else {
			$final_gallery = $new_gallery_ordered;
		}

		update_post_meta( $post_id, '_m24_galerie', implode( ',', $final_gallery ) );

		// Featured nur setzen wenn aktuell leer (manuelles Cover bewahren)
		if ( $cover_att > 0 && ! $current_featured ) {
			set_post_thumbnail( $post_id, $cover_att );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::log( sprintf(
				'   → Media: %d/%d neu, %d reused%s · Cover: %s · Galerie: %d IDs',
				$imported, count( $media ), $reused,
				$errors > 0 ? ", $errors Fehler" : '',
				$cover_att > 0 ? ( 'ID ' . $cover_att ) : '—',
				count( $final_gallery )
			) );
		}
	}

	/** Sideload eines Bildes von URL ins WP-Medien. Setzt `_m24_sw_media_hash` als Dedupe-Key. */
	protected function sideload_image( $url, $post_id, $hash ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$att_id = media_sideload_image( $url, $post_id, null, 'id' );
		if ( is_wp_error( $att_id ) ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				WP_CLI::warning( '   sideload Fehler: ' . $att_id->get_error_message() . ' [' . $url . ']' );
			}
			return 0;
		}
		if ( '' !== $hash ) {
			update_post_meta( $att_id, '_m24_sw_media_hash', $hash );
		}
		return (int) $att_id;
	}
}

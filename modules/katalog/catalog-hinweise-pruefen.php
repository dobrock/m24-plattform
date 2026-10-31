<?php
/**
 * M24 Plattform — Katalog: „Teile · Hinweise prüfen" (Admin-Liste, Entwurf A)
 * Modul: modules/katalog/catalog-hinweise-pruefen.php
 *
 * WP-Admin-Tabelle aller veröffentlichten Teile mit zwei Häkchen-Spalten:
 *   - „Ohne Straßenzulassung"   → Meta _m24_ohne_zulassung  ('1'|'0')
 *   - „Kein Original-BMW-Teil"  → Meta _m24_kein_original   ('1'|'0')
 *   - Status geprüft            → Meta _m24_hinweis_geprueft (Datum, current_time mysql)
 *
 * Solange ein Teil ungeprüft ist, werden die Häkchen aus Regeln vorausgefüllt (hellgelb = Vorschlag).
 * „Änderungen speichern" schreibt ALLE sichtbaren Zeilen und setzt sie auf geprüft.
 *
 * Detailseite (catalog-template-detail.php) liest die Metas: gesetzt → entscheidet die Meta,
 * nicht gesetzt → bisheriges Verhalten (Rennsport-Flag / Typ „neu").
 *
 * Menü: MOTORSPORT24 › Teile · Hinweise prüfen (admin.php?page=m24-teile-hinweise), direkt
 * unter „Teile-Verwaltung". Bewusst unter dem Dach-Slug registriert (nicht unter
 * edit.php?post_type=m24_teil) — siehe M24_Admin_Menu::fix_cpt_parent_pages().
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Hinweise_Pruefen {

	const PAGE     = 'm24-teile-hinweise';
	const CAP      = 'manage_options';
	const NONCE    = 'm24_hinweise_save';
	const PER_PAGE = 50;

	const META_ZUL     = '_m24_ohne_zulassung';
	const META_ORIG    = '_m24_kein_original';
	const META_GEPRUEFT = '_m24_hinweis_geprueft';

	/** Text für die Detailseite (Englisch via GTranslate). */
	const TEXT_KEIN_ORIGINAL = 'Kein Original-BMW-Teil – Zubehör/Nachbau, die Angabe ‚passend für‘ beschreibt nur die Verwendbarkeit.';

	/** @var string Page-Hook von add_submenu_page(). */
	private static $hook = '';

	/** @var bool Rekursions-Schutz für den Meta-Spiegel. */
	private static $mirroring = false;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		// Spiegel: Inline-Toggle/Editor „Rennsport-Hinweis" → _m24_ohne_zulassung (nur wenn schon gesetzt),
		// damit die alte Schaltfläche nach der Prüfung nicht wirkungslos wird.
		add_action( 'updated_post_meta', array( __CLASS__, 'mirror_rennsport_flag' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'mirror_rennsport_flag' ), 10, 4 );
	}

	public static function menu() {
		self::$hook = add_submenu_page(
			'm24-plattform',
			'Teile · Hinweise prüfen', 'Teile · Hinweise prüfen',
			self::CAP, self::PAGE, array( __CLASS__, 'render' )
		);
		if ( self::$hook ) {
			add_action( 'load-' . self::$hook, array( __CLASS__, 'handle_save' ) );
		}
	}

	/* ── Frontend-Helfer (von der Detailseite genutzt) ───────────────────────── */

	/**
	 * '1' | '0' | '' (nicht gesetzt → Fallback auf altes Verhalten).
	 */
	public static function meta_flag( $post_id, $key ) {
		if ( ! metadata_exists( 'post', $post_id, $key ) ) { return ''; }
		return '1' === (string) get_post_meta( $post_id, $key, true ) ? '1' : '0';
	}

	/* ── Regel-Vorschlag ─────────────────────────────────────────────────────── */

	/** Zubehör-/Fremdhersteller (Regex-Fragmente, case-insensitiv außer KW). */
	private static function brands() {
		return array(
			'PFC'              => '\bPFC\b',
			'Pagid'            => '\bPagid\b',
			'Endless'          => '\bEndless\b',
			'AP Racing'        => '\bAP[\s-]*Racing\b',
			'Brembo Racing'    => '\bBrembo[\s-]*Racing\b',
			'Öhlins'           => '(?:Öhlins|Ohlins|Oehlins)',
			'Bilstein Clubsport' => '\bBilstein[\s-]*Clubsport\b',
			'TFL'              => '\bTFL\b',
			'NTM'              => '\bNTM\b',
			'Tracktools'       => '\bTrack[\s-]*tools\b',
			'FVD'              => '\bFVD\b',
			'Sachs Race'       => '\bSachs[\s-]*(?:Race|Racing)\b',
			'Sparco'           => '\bSparco\b',
			'OMP'              => '\bOMP\b',
			'Recaro'           => '\bRecaro\b',
		);
	}

	/**
	 * Vorschlag aus Typ + Titel/Beschreibung.
	 *
	 * @return array{zul:bool,orig:bool,why:string[]}
	 */
	public static function suggest( $post ) {
		$id   = (int) $post->ID;
		$typ  = (string) get_post_meta( $id, '_m24_typ', true );
		$text = $post->post_title . ' ' . get_post_meta( $id, '_m24_beschreibung_de', true ) . ' ' . $post->post_content;
		$text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
		$text = mb_substr( preg_replace( '/\s+/u', ' ', $text ), 0, 6000 );

		$why = array();
		$zul = false;
		if ( 'neu' === $typ ) {
			$zul   = true;
			$why[] = 'Rennsport-Teil (Typ „neu") → ohne Zulassung';
		}
		if ( preg_match( '/\b(Rennsport\w*|Race|Racing|Cup|GTR|GT3|Clubsport)\b|Carbon[\s-]*Body[\s-]*kit/iu', $text, $m ) ) {
			if ( ! $zul ) { $why[] = 'Text nennt „' . $m[0] . '" → ohne Zulassung'; }
			$zul = true;
		}

		$orig = false;
		$hits = array();
		foreach ( self::brands() as $label => $re ) {
			if ( preg_match( '/' . $re . '/iu', $text ) ) { $hits[] = $label; }
		}
		// KW (Fahrwerk) case-sensitiv und nicht als Leistungsangabe („140 KW").
		if ( preg_match( '/(?<![\d.,])(?<![\d.,]\s)\bKW\b/u', $text ) ) { $hits[] = 'KW'; }
		if ( $hits ) {
			$orig  = true;
			$why[] = 'Hersteller ' . implode( ', ', array_slice( $hits, 0, 4 ) ) . ' → Zubehör, kein Original';
		}
		if ( ! $orig && $zul && preg_match( '/passend\s+f(?:ü|ue)r/iu', $text ) ) {
			$orig  = true;
			$why[] = 'Rennsport + „passend für" → kein Original';
		}
		if ( $orig && '1' === (string) get_post_meta( $id, '_m24_original_teil', true ) ) {
			$why[] = 'Achtung: im Teil als „Original BMW-Teil" markiert';
		}
		if ( ! $why ) { $why[] = 'Keine Regel greift → Straßenteil, Original'; }

		return array( 'zul' => $zul, 'orig' => $orig, 'why' => $why );
	}

	/* ── Speichern (PRG) ─────────────────────────────────────────────────────── */

	public static function handle_save() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['m24_hinweise_action'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'Keine Berechtigung.', 403 ); }
		check_admin_referer( self::NONCE );

		$ids  = isset( $_POST['m24_ids'] ) ? array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['m24_ids'] ) ) ) ) : array();
		$zul  = isset( $_POST['m24_zul'] ) ? array_map( 'absint', array_keys( (array) wp_unslash( $_POST['m24_zul'] ) ) ) : array();
		$orig = isset( $_POST['m24_orig'] ) ? array_map( 'absint', array_keys( (array) wp_unslash( $_POST['m24_orig'] ) ) ) : array();
		$now  = current_time( 'mysql' );

		$saved   = 0;
		$changed = 0;
		foreach ( array_slice( $ids, 0, 200 ) as $id ) {
			if ( M24_Catalog_CPT::POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) { continue; }
			$new_zul  = in_array( $id, $zul, true ) ? '1' : '0';
			$new_orig = in_array( $id, $orig, true ) ? '1' : '0';
			if ( self::meta_flag( $id, self::META_ZUL ) !== $new_zul || self::meta_flag( $id, self::META_ORIG ) !== $new_orig ) { ++$changed; }

			update_post_meta( $id, self::META_ZUL, $new_zul );
			update_post_meta( $id, self::META_ORIG, $new_orig );
			update_post_meta( $id, self::META_GEPRUEFT, $now );
			// Alt-Flag synchron halten (Inline-Toggle in der Teile-Verwaltung / Editor-Checkbox).
			self::$mirroring = true;
			update_post_meta( $id, '_m24_rennsport_hinweis', (int) $new_zul );
			self::$mirroring = false;
			++$saved;
		}

		$back = array( 'page' => self::PAGE, 'm24saved' => $saved, 'm24changed' => $changed );
		foreach ( array( 's', 'modell', 'typ', 'pruef', 'paged' ) as $k ) {
			if ( isset( $_POST[ 'f_' . $k ] ) && '' !== $_POST[ 'f_' . $k ] ) {
				$back[ $k ] = sanitize_text_field( wp_unslash( $_POST[ 'f_' . $k ] ) );
			}
		}
		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $back ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** _m24_rennsport_hinweis geändert (Inline-Toggle/Editor) → _m24_ohne_zulassung nachziehen, falls vorhanden. */
	public static function mirror_rennsport_flag( $meta_id, $post_id, $meta_key, $value ) {
		if ( self::$mirroring || '_m24_rennsport_hinweis' !== $meta_key ) { return; }
		if ( ! metadata_exists( 'post', $post_id, self::META_ZUL ) ) { return; }
		$v = (int) $value ? '1' : '0';
		if ( (string) get_post_meta( $post_id, self::META_ZUL, true ) === $v ) { return; }
		self::$mirroring = true;
		update_post_meta( $post_id, self::META_ZUL, $v );
		self::$mirroring = false;
	}

	/* ── Abfrage ─────────────────────────────────────────────────────────────── */

	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$f = array(
			's'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'modell' => isset( $_GET['modell'] ) ? absint( $_GET['modell'] ) : 0,
			'typ'    => isset( $_GET['typ'] ) ? sanitize_key( wp_unslash( $_GET['typ'] ) ) : '',
			'pruef'  => isset( $_GET['pruef'] ) ? sanitize_key( wp_unslash( $_GET['pruef'] ) ) : 'offen',
			'paged'  => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);
		// phpcs:enable
		if ( ! in_array( $f['typ'], array( '', 'neu', 'gebraucht' ), true ) ) { $f['typ'] = ''; }
		if ( ! in_array( $f['pruef'], array( 'offen', 'geprueft', 'alle' ), true ) ) { $f['pruef'] = 'offen'; }
		return $f;
	}

	/** IDs per Titel / Artikel- / BMW-Teilenummer / Teilenummern-Index. */
	private static function search_ids( $s ) {
		global $wpdb;
		$like   = '%' . $wpdb->esc_like( $s ) . '%';
		$digits = preg_replace( '/\D+/', '', $s );
		$dlike  = strlen( $digits ) >= 4 ? '%' . $wpdb->esc_like( $digits ) . '%' : '';
		$sql    = "SELECT p.ID FROM {$wpdb->posts} p
			WHERE p.post_type = %s AND p.post_status = 'publish' AND (
				p.post_title LIKE %s
				OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID
					AND m.meta_key IN ('_m24_artikelnummer','_m24_bmw_teilenummer') AND m.meta_value LIKE %s )";
		$args   = array( M24_Catalog_CPT::POST_TYPE, $like, $like );
		if ( '' !== $dlike ) {
			$sql   .= " OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} n WHERE n.post_id = p.ID AND n.meta_key = '_m24_partnums' AND n.meta_value LIKE %s )";
			$args[] = $dlike;
		}
		$sql .= ' ) LIMIT 5000';
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $ids ? array_map( 'intval', $ids ) : array( 0 );
	}

	private static function query( array $f ) {
		$args = array(
			'post_type'      => M24_Catalog_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $f['paged'],
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => array( 'relation' => 'AND' ), // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		if ( '' !== $f['s'] ) { $args['post__in'] = self::search_ids( $f['s'] ); }
		if ( $f['modell'] ) {
			$args['tax_query'] = array( array( 'taxonomy' => M24_Catalog_CPT::TAXONOMY, 'field' => 'term_id', 'terms' => $f['modell'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( 'neu' === $f['typ'] ) {
			$args['meta_query'][] = array( 'key' => '_m24_typ', 'value' => 'neu' );
		} elseif ( 'gebraucht' === $f['typ'] ) {
			$args['meta_query'][] = array(
				'relation' => 'OR',
				array( 'key' => '_m24_typ', 'value' => 'neu', 'compare' => '!=' ),
				array( 'key' => '_m24_typ', 'compare' => 'NOT EXISTS' ),
			);
		}
		if ( 'offen' === $f['pruef'] ) {
			$args['meta_query'][] = array( 'key' => self::META_GEPRUEFT, 'compare' => 'NOT EXISTS' );
		} elseif ( 'geprueft' === $f['pruef'] ) {
			$args['meta_query'][] = array( 'key' => self::META_GEPRUEFT, 'compare' => 'EXISTS' );
		}
		return new WP_Query( $args );
	}

	/* ── Ausgabe ─────────────────────────────────────────────────────────────── */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'Keine Berechtigung.', 403 ); }
		$f = self::filters();
		$q = self::query( $f );

		echo '<div class="wrap m24-hp">';
		echo '<h1 class="wp-heading-inline">Teile · Hinweise prüfen</h1>';
		echo '<p class="description">Häkchen steuern die Hinweise auf der Teile-Detailseite. <span class="m24-hp-key">Hellgelb</span> = Vorschlag aus den Regeln (noch nicht gespeichert). „Änderungen speichern" übernimmt <strong>alle sichtbaren Zeilen</strong> und markiert sie als geprüft.</p>';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['m24saved'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s Teile als geprüft gespeichert, davon %s mit geändertem Hinweis.</p></div>',
				esc_html( number_format_i18n( absint( $_GET['m24saved'] ) ) ),
				esc_html( number_format_i18n( absint( $_GET['m24changed'] ?? 0 ) ) )
			);
		}
		// phpcs:enable

		self::render_filters( $f, (int) $q->found_posts );

		echo '<form method="post" id="m24-hp-form">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="m24_hinweise_action" value="save">';
		foreach ( $f as $k => $v ) {
			printf( '<input type="hidden" name="f_%s" value="%s">', esc_attr( $k ), esc_attr( (string) $v ) );
		}

		self::render_savebar( $q );

		// Feste Spaltenbreiten per <colgroup> (Titel = Restbreite, min. 320px über min-width der Tabelle).
		// Bewusst ohne WP-Klasse „fixed" und ohne column-primary/has-row-actions: deren Core-Regeln
		// (u. a. Mobil-Ansicht < 782px) verengen bzw. verstecken sonst Spalten.
		echo '<div class="m24-hp-scroll"><table class="wp-list-table widefat striped m24-hp-table">'
			. '<colgroup><col class="c-thumb"><col class="c-title"><col class="c-typ"><col class="c-modell">'
			. '<col class="c-chk"><col class="c-chk"><col class="c-why"><col class="c-status"></colgroup>'
			. '<thead><tr>'
			. '<th class="c-thumb"></th><th class="c-title">Titel</th><th class="c-typ">Typ</th><th class="c-modell">Modell</th>'
			. '<th class="c-chk">Ohne Straßen&shy;zulassung<br><label><input type="checkbox" class="m24-hp-all" data-col="zul"> alle</label></th>'
			. '<th class="c-chk">Kein Original-BMW-Teil<br><label><input type="checkbox" class="m24-hp-all" data-col="orig"> alle</label></th>'
			. '<th class="c-why">Vorschlag</th><th class="c-status">Status</th>'
			. '</tr></thead><tbody>';

		if ( ! $q->have_posts() ) {
			echo '<tr><td colspan="8">Keine Teile für diesen Filter.</td></tr>';
		}
		foreach ( $q->posts as $post ) {
			self::render_row( $post );
		}
		echo '</tbody></table></div>';

		self::render_savebar( $q, true );
		echo '</form>';
		self::render_pagination( $q, $f );
		echo '</div>';
		self::styles_and_script();
	}

	private static function render_filters( array $f, $found ) {
		echo '<form method="get" class="m24-hp-filter">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::PAGE ) );
		printf( '<input type="search" name="s" value="%s" placeholder="Titel oder Teilenummer" class="m24-hp-s"> ', esc_attr( $f['s'] ) );
		wp_dropdown_categories( array(
			'taxonomy'        => M24_Catalog_CPT::TAXONOMY,
			'name'            => 'modell',
			'selected'        => $f['modell'],
			'show_option_all' => 'Alle Modelle',
			'hierarchical'    => true,
			'hide_empty'      => true,
			'orderby'         => 'name',
			'value_field'     => 'term_id',
		) );
		echo ' <select name="typ">';
		foreach ( array( '' => 'Alle Typen', 'neu' => 'Rennsport (neu)', 'gebraucht' => 'Gebraucht' ) as $v => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( $f['typ'], $v, false ), esc_html( $l ) );
		}
		echo '</select> <select name="pruef">';
		foreach ( array( 'offen' => 'Ungeprüft', 'geprueft' => 'Geprüft', 'alle' => 'Alle' ) as $v => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( $f['pruef'], $v, false ), esc_html( $l ) );
		}
		echo '</select> <button class="button">Filtern</button> ';
		printf( '<a class="button-link" href="%s">Zurücksetzen</a>', esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) );
		printf( ' <span class="m24-hp-count">%s Teile</span>', esc_html( number_format_i18n( $found ) ) );
		echo '</form>';
	}

	private static function render_savebar( WP_Query $q, $bottom = false ) {
		if ( ! $q->have_posts() ) { return; }
		printf(
			'<div class="m24-hp-savebar%s"><button type="submit" class="button button-primary m24-hp-save">Änderungen speichern <span class="m24-hp-n">(0 geändert)</span></button> <span class="description">speichert %d sichtbare Zeilen als geprüft</span></div>',
			$bottom ? ' is-bottom' : '',
			(int) count( $q->posts )
		);
	}

	private static function render_row( WP_Post $post ) {
		$id       = (int) $post->ID;
		$geprueft = (string) get_post_meta( $id, self::META_GEPRUEFT, true );
		$sug      = self::suggest( $post );

		if ( '' !== $geprueft ) {
			$zul      = '1' === self::meta_flag( $id, self::META_ZUL );
			$orig     = '1' === self::meta_flag( $id, self::META_ORIG );
			$from_sug = false;
		} else {
			// Ungeprüft: bereits gesetzte Metas respektieren, sonst Regel-Vorschlag.
			$mz       = self::meta_flag( $id, self::META_ZUL );
			$mo       = self::meta_flag( $id, self::META_ORIG );
			$zul      = '' !== $mz ? '1' === $mz : $sug['zul'];
			$orig     = '' !== $mo ? '1' === $mo : $sug['orig'];
			$from_sug = true;
		}

		$typ   = (string) get_post_meta( $id, '_m24_typ', true );
		$terms = get_the_terms( $id, M24_Catalog_CPT::TAXONOMY );
		$names = ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : array();
		$thumb = get_the_post_thumbnail( $id, 'thumbnail', array( 'loading' => 'lazy', 'class' => 'm24-hp-img' ) );
		$artnr = (string) get_post_meta( $id, '_m24_artikelnummer', true );

		echo '<tr data-id="' . esc_attr( (string) $id ) . '">';
		echo '<td class="c-thumb">' . ( $thumb ? $thumb : '<span class="m24-hp-nothumb">–</span>' ) . '<input type="hidden" name="m24_ids[]" value="' . esc_attr( (string) $id ) . '"></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generiertes <img>
		printf(
			'<td class="c-title"><strong class="m24-hp-ttl"><a href="%s">%s</a></strong>%s<div class="row-actions"><a href="%s">Bearbeiten</a> | <a href="%s" target="_blank" rel="noopener">Ansehen</a></div></td>',
			esc_url( (string) get_edit_post_link( $id ) ),
			esc_html( get_the_title( $id ) ),
			'' !== $artnr ? '<div class="m24-hp-artnr">' . esc_html( $artnr ) . '</div>' : '',
			esc_url( (string) get_edit_post_link( $id ) ),
			esc_url( (string) get_permalink( $id ) )
		);
		printf( '<td class="c-typ">%s</td>', 'neu' === $typ ? '<span class="m24-hp-tag is-rs">Rennsport</span>' : '<span class="m24-hp-tag">Gebraucht</span>' );
		printf( '<td class="c-modell">%s</td>', $names ? esc_html( implode( ', ', $names ) ) : '<span class="m24-hp-muted">–</span>' );

		foreach ( array( 'zul' => $zul, 'orig' => $orig ) as $col => $on ) {
			$sug_cls = ( $from_sug && $on ) ? ' is-sug' : '';
			printf(
				'<td class="c-chk%s"><label><input type="checkbox" class="m24-hp-cb" data-col="%s" name="m24_%s[%d]" value="1" data-init="%d"%s></label></td>',
				esc_attr( $sug_cls ),
				esc_attr( $col ),
				esc_attr( $col ),
				$id,
				$on ? 1 : 0,
				checked( $on, true, false )
			);
		}

		printf( '<td class="c-why">%s</td>', esc_html( implode( ' · ', $sug['why'] ) ) );
		if ( '' !== $geprueft ) {
			printf( '<td class="c-status"><span class="m24-hp-ok">✓ geprüft</span><br><small>%s</small></td>', esc_html( mysql2date( 'd.m.Y H:i', $geprueft ) ) );
		} else {
			echo '<td class="c-status"><span class="m24-hp-open">ungeprüft</span></td>';
		}
		echo '</tr>';
	}

	private static function render_pagination( WP_Query $q, array $f ) {
		if ( $q->max_num_pages < 2 ) { return; }
		$base = add_query_arg( array_filter( array(
			'page'   => self::PAGE,
			's'      => $f['s'],
			'modell' => $f['modell'] ? $f['modell'] : '',
			'typ'    => $f['typ'],
			'pruef'  => $f['pruef'],
		), 'strlen' ), admin_url( 'admin.php' ) );
		$links = paginate_links( array(
			'base'      => add_query_arg( 'paged', '%#%', $base ),
			'format'    => '',
			'current'   => $f['paged'],
			'total'     => (int) $q->max_num_pages,
			'prev_text' => '‹',
			'next_text' => '›',
		) );
		echo '<div class="tablenav"><div class="tablenav-pages m24-hp-pages">' . wp_kses_post( (string) $links ) . '</div></div>';
	}

	private static function styles_and_script() {
		?>
		<style>
			.m24-hp-filter{margin:12px 0;display:flex;flex-wrap:wrap;gap:6px;align-items:center}
			.m24-hp-s{min-width:240px}
			.m24-hp-count{color:#646970;margin-left:6px}
			.m24-hp-key{background:#fff6c7;padding:0 4px;border-radius:2px}
			.m24-hp-savebar{margin:10px 0;display:flex;gap:10px;align-items:center}
			/* Tabelle: feste Spaltenbreiten, Titel flexibel (min. 320px); schmaler → horizontal scrollen. */
			.m24-hp-scroll{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
			.m24-hp .wp-list-table.m24-hp-table{table-layout:fixed;width:100%;min-width:1236px;border-collapse:collapse}
			.m24-hp-table col.c-thumb{width:96px}
			.m24-hp-table col.c-title{width:auto}
			.m24-hp-table col.c-typ{width:110px}
			.m24-hp-table col.c-modell{width:130px}
			.m24-hp-table col.c-chk{width:130px}
			.m24-hp-table col.c-why{width:220px}
			.m24-hp-table col.c-status{width:100px}
			.m24-hp .wp-list-table.m24-hp-table th,.m24-hp .wp-list-table.m24-hp-table td{width:auto;vertical-align:top;word-break:normal;overflow-wrap:break-word;white-space:normal}
			.m24-hp .wp-list-table.m24-hp-table th{vertical-align:bottom;font-weight:600}
			.m24-hp-table .c-thumb{padding:8px}
			.m24-hp-table .c-thumb img{display:block;width:80px;height:80px;max-width:none;object-fit:cover;border-radius:6px;background:#f0f0f1}
			.m24-hp-table .m24-hp-nothumb{display:flex;width:80px;height:80px;align-items:center;justify-content:center;border-radius:6px;background:#f0f0f1}
			.m24-hp-table td.c-title{min-width:320px}
			.m24-hp-table .m24-hp-ttl{display:block;font-size:14px;line-height:1.4;word-break:normal;overflow-wrap:anywhere;hyphens:manual}
			.m24-hp-table .m24-hp-artnr{margin-top:3px;font-size:12px}
			.m24-hp-table .row-actions{position:static;left:auto;padding-top:4px}
			.m24-hp-table .c-chk{text-align:center}
			.m24-hp-table th.c-chk label{font-weight:400;font-size:11px;color:#646970}
			.m24-hp-table td.c-chk input{transform:scale(1.25);margin:4px 0 0}
			.m24-hp-table td.c-chk.is-sug{background:#fff6c7!important}
			.m24-hp-table tr.is-changed td{box-shadow:inset 0 0 0 9999px rgba(34,113,177,.06)}
			.m24-hp-table .c-why{font-size:12px;color:#50575e}
			/* WP-Core-Mobilregeln (< 782px) neutralisieren: keine versteckten Spalten, keine Label-Pseudoelemente. */
			@media screen and (max-width:782px){
				.m24-hp .wp-list-table.m24-hp-table tr th,.m24-hp .wp-list-table.m24-hp-table tr td{display:table-cell!important;position:static;padding:8px 10px}
				.m24-hp .wp-list-table.m24-hp-table tr{display:table-row}
				.m24-hp .wp-list-table.m24-hp-table td::before{content:none;display:none}
				.m24-hp .wp-list-table.m24-hp-table .row-actions{display:block}
			}
			.m24-hp-artnr,.m24-hp-muted,.m24-hp-nothumb{color:#8c8f94}
			.m24-hp-tag{display:inline-block;padding:1px 6px;border-radius:9px;background:#f0f0f1;font-size:11px}
			.m24-hp-tag.is-rs{background:#fbe7e7;color:#8a1f1f}
			.m24-hp-ok{color:#007017;font-weight:600}
			.m24-hp-open{color:#996800}
		</style>
		<script>
		(function(){
			var form=document.getElementById('m24-hp-form'); if(!form){return;}
			var dirty=false;
			function recount(){
				var n=0;
				form.querySelectorAll('tbody tr[data-id]').forEach(function(tr){
					var ch=false;
					tr.querySelectorAll('.m24-hp-cb').forEach(function(cb){ if((cb.checked?1:0)!==parseInt(cb.getAttribute('data-init'),10)){ch=true;} });
					tr.classList.toggle('is-changed',ch); if(ch){n++;}
				});
				form.querySelectorAll('.m24-hp-n').forEach(function(s){ s.textContent='('+n+' geändert)'; });
			}
			form.addEventListener('change',function(e){
				var t=e.target;
				if(t.classList.contains('m24-hp-all')){
					var col=t.getAttribute('data-col');
					form.querySelectorAll('.m24-hp-cb[data-col="'+col+'"]').forEach(function(cb){ cb.checked=t.checked; cb.closest('td').classList.remove('is-sug'); });
				} else if(t.classList.contains('m24-hp-cb')){
					t.closest('td').classList.remove('is-sug');
				} else { return; }
				dirty=true; recount();
			});
			form.addEventListener('submit',function(){ dirty=false; });
			window.addEventListener('beforeunload',function(e){ if(dirty){ e.preventDefault(); e.returnValue=''; } });
			recount();
		})();
		</script>
		<?php
	}
}

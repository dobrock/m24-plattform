<?php
/**
 * M24 Plattform — Katalog: Erweiterte Teile-Verwaltung (Admin-Liste)
 * Modul: catalog-admin-list.php
 *
 * Erweitert die native WP-Postliste fuer m24_teil. Reine Bordmittel: keine eigene
 * Tabelle, integriert sich in edit.php?post_type=m24_teil.
 *
 * Spalten:   Thumbnail · Titel · Art.-Nr. · Preis (inline editierbar) · Modell · Baugruppe · Status
 * Filter:    Modell, Baugruppe, Typ, Status, "ohne Modell"
 * Quick-Edit: Preis + Modell + Baugruppe (zusaetzlich)
 * Row-Actions: Bearbeiten · Beschreibung Quick-View · Ausblenden/Einblenden · Duplizieren · Loeschen
 * Bulk-Actions: Modell zuweisen · Baugruppe zuweisen · Ausblenden
 * AJAX:      Inline-Preis-Edit (Nonce + edit_posts-Check)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Admin_List {

	const PT       = 'm24_teil';
	const TAX_MODELL    = 'm24_fahrzeugkat';
	const TAX_BAUGRUPPE = 'm24_baugruppe';
	const NONCE_PRICE   = 'm24_inline_price';
	const NONCE_BULK    = 'm24_bulk_assign';
	const NONCE_MODELL_TOGGLE = 'm24_modell_toggle';
	const NONCE_TITLE   = 'm24_inline_title';

	public static function init() {
		$pt = self::PT;

		// Spalten + Sortierung
		add_filter( "manage_{$pt}_posts_columns",              array( __CLASS__, 'columns' ) );
		add_action( "manage_{$pt}_posts_custom_column",        array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$pt}_sortable_columns",      array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts',                           array( __CLASS__, 'apply_sort_and_filter' ) );

		// Filter-Dropdowns
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );

		// Such-Erweiterung (Artnr + BMW-Teilenr)
		add_filter( 'posts_join',   array( __CLASS__, 'search_join' ), 10, 2 );
		add_filter( 'posts_search', array( __CLASS__, 'search_meta' ), 10, 2 );

		// Row-Actions + Quick-View
		add_filter( 'post_row_actions',  array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_m24_teil_action', array( __CLASS__, 'handle_action' ) );

		// Quick-Edit Erweiterung
		add_action( "quick_edit_custom_box", array( __CLASS__, 'quick_edit_box' ), 10, 2 );
		add_action( "save_post_{$pt}",       array( __CLASS__, 'quick_edit_save' ), 20, 2 );

		// Bulk-Actions
		add_filter( "bulk_actions-edit-{$pt}",        array( __CLASS__, 'bulk_actions' ) );
		add_filter( "handle_bulk_actions-edit-{$pt}", array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices',                  array( __CLASS__, 'bulk_notices' ) );

		// AJAX Inline-Price
		add_action( 'wp_ajax_m24_inline_price', array( __CLASS__, 'ajax_inline_price' ) );
		// AJAX Modell Multi-Term Toggle (Multi-Select pro Zeile)
		add_action( 'wp_ajax_m24_modell_toggle', array( __CLASS__, 'ajax_modell_toggle' ) );
		// AJAX Inline-Title (Rename mit SEO-Sync)
		add_action( 'wp_ajax_m24_inline_title',  array( __CLASS__, 'ajax_inline_title' ) );

		// Assets
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	// ─── SPALTEN ────────────────────────────────────────────────

	public static function columns( $cols ) {
		return array(
			'cb'              => isset( $cols['cb'] ) ? $cols['cb'] : '',
			'm24_bild'        => 'Bild',
			'title'           => 'Titel',
			'm24_artnr'       => 'Art.-Nr.',
			'm24_preis'       => 'Preis',
			'm24_modell'      => 'Modell',
			'm24_baugruppe'   => 'Baugruppe',
			'm24_status'      => 'Status',
			'date'            => isset( $cols['date'] ) ? $cols['date'] : 'Datum',
		);
	}

	public static function sortable_columns( $cols ) {
		$cols['m24_artnr']  = 'm24_artnr';
		$cols['m24_preis']  = 'm24_preis';
		$cols['m24_status'] = 'm24_status';
		return $cols;
	}

	public static function render_column( $col, $post_id ) {
		switch ( $col ) {
			case 'm24_bild':
				$thumb = get_the_post_thumbnail( $post_id, array( 66, 45 ), array( 'class' => 'm24-thumb' ) );
				echo $thumb ? $thumb : '<span class="m24-thumb-empty">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;

			case 'm24_artnr':
				echo esc_html( get_post_meta( $post_id, '_m24_artikelnummer', true ) ?: '—' );
				break;

			case 'm24_preis':
				$p = M24_Catalog_Pricing::get( $post_id );
				$brutto_raw = (float) $p['brutto'];
				$brutto_str = number_format( $brutto_raw, 2, ',', '.' );
				printf(
					'<span class="m24-price-cell"><input type="text" class="m24-inline-price" data-post="%d" value="%s"> €<small class="m24-price-status"></small></span>',
					(int) $post_id, esc_attr( $brutto_str )
				);
				break;

			case 'm24_modell':
				echo self::render_modell_cell( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput
				break;

			case 'm24_baugruppe':
				$terms = get_the_terms( $post_id, self::TAX_BAUGRUPPE );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$names = wp_list_pluck( $terms, 'name' );
					echo esc_html( implode( ', ', $names ) );
				} else {
					echo '<span style="color:#999">—</span>';
				}
				break;

			case 'm24_status':
				$s   = get_post_meta( $post_id, '_m24_status', true ) ?: 'aktiv';
				$map = array( 'aktiv' => '#2f7d52', 'ausgeblendet' => '#777', 'verkauft' => '#9e2b2b' );
				printf( '<span style="color:%s;font-weight:600">%s</span>', esc_attr( $map[ $s ] ?? '#333' ), esc_html( ucfirst( $s ) ) );
				break;
		}
	}

	// ─── FILTER (Dropdowns) ─────────────────────────────────────

	public static function filters() {
		global $typenow;
		if ( self::PT !== $typenow ) { return; }

		// Modell-Dropdown (mit Special-Option "ohne Modell")
		$cur_modell = isset( $_GET['m24_modell_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['m24_modell_filter'] ) ) : '';
		$modell_terms = get_terms( array( 'taxonomy' => self::TAX_MODELL, 'hide_empty' => false ) );
		echo '<select name="m24_modell_filter"><option value="">Alle Modelle</option>';
		echo '<option value="__none__"' . selected( $cur_modell, '__none__', false ) . '>— ohne Modell —</option>';
		if ( ! is_wp_error( $modell_terms ) ) {
			foreach ( $modell_terms as $t ) {
				printf( '<option value="%s"%s>%s (%d)</option>', esc_attr( $t->slug ), selected( $cur_modell, $t->slug, false ), esc_html( $t->name ), (int) $t->count );
			}
		}
		echo '</select>';

		// Baugruppe-Dropdown
		$cur_bg = isset( $_GET['m24_baugruppe_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['m24_baugruppe_filter'] ) ) : '';
		$bg_terms = get_terms( array( 'taxonomy' => self::TAX_BAUGRUPPE, 'hide_empty' => false ) );
		echo '<select name="m24_baugruppe_filter"><option value="">Alle Baugruppen</option>';
		if ( ! is_wp_error( $bg_terms ) ) {
			foreach ( $bg_terms as $t ) {
				printf( '<option value="%s"%s>%s (%d)</option>', esc_attr( $t->slug ), selected( $cur_bg, $t->slug, false ), esc_html( $t->name ), (int) $t->count );
			}
		}
		echo '</select>';

		// Typ (existierend, bleibt)
		$typ = isset( $_GET['m24_typ'] ) ? sanitize_text_field( wp_unslash( $_GET['m24_typ'] ) ) : '';
		echo '<select name="m24_typ"><option value="">Alle Typen</option>';
		foreach ( array( 'neu' => 'Neuteile', 'gebraucht' => 'Gebrauchtteile' ) as $k => $v ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $typ, $k, false ), esc_html( $v ) );
		}
		echo '</select>';

		// Status
		$status = isset( $_GET['m24_status'] ) ? sanitize_text_field( wp_unslash( $_GET['m24_status'] ) ) : '';
		echo '<select name="m24_status"><option value="">Alle Status</option>';
		foreach ( array( 'aktiv' => 'Aktiv', 'ausgeblendet' => 'Ausgeblendet', 'verkauft' => 'Verkauft' ) as $k => $v ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $status, $k, false ), esc_html( $v ) );
		}
		echo '</select>';
	}

	public static function apply_sort_and_filter( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || self::PT !== $q->get( 'post_type' ) ) { return; }

		// Meta-Filter
		$mq = array();
		if ( ! empty( $_GET['m24_typ'] ) ) {
			$mq[] = array( 'key' => '_m24_typ', 'value' => sanitize_text_field( wp_unslash( $_GET['m24_typ'] ) ) );
		}
		if ( ! empty( $_GET['m24_status'] ) ) {
			$mq[] = array( 'key' => '_m24_status', 'value' => sanitize_text_field( wp_unslash( $_GET['m24_status'] ) ) );
		}
		if ( $mq ) { $q->set( 'meta_query', $mq ); }

		// Taxonomy-Filter
		$tq = array();
		$modell_filter = isset( $_GET['m24_modell_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['m24_modell_filter'] ) ) : '';
		if ( '__none__' === $modell_filter ) {
			$tq[] = array(
				'taxonomy' => self::TAX_MODELL,
				'operator' => 'NOT EXISTS',
			);
		} elseif ( '' !== $modell_filter ) {
			$tq[] = array(
				'taxonomy' => self::TAX_MODELL,
				'field'    => 'slug',
				'terms'    => $modell_filter,
			);
		}
		if ( ! empty( $_GET['m24_baugruppe_filter'] ) ) {
			$tq[] = array(
				'taxonomy' => self::TAX_BAUGRUPPE,
				'field'    => 'slug',
				'terms'    => sanitize_text_field( wp_unslash( $_GET['m24_baugruppe_filter'] ) ),
			);
		}
		if ( $tq ) { $q->set( 'tax_query', $tq ); }

		// Sortierung
		$orderby = $q->get( 'orderby' );
		if ( 'm24_artnr' === $orderby ) {
			$q->set( 'meta_key', '_m24_artikelnummer' );
			$q->set( 'orderby', 'meta_value' );
		} elseif ( 'm24_preis' === $orderby ) {
			$q->set( 'meta_key', '_m24_preis_netto' );
			$q->set( 'orderby', 'meta_value_num' );
		} elseif ( 'm24_status' === $orderby ) {
			$q->set( 'meta_key', '_m24_status' );
			$q->set( 'orderby', 'meta_value' );
		}
	}

	// ─── SUCHE (Artnr + BMW-Teilenr) ────────────────────────────

	public static function search_join( $join, $q ) {
		global $wpdb;
		if ( is_admin() && $q->is_main_query() && $q->is_search() && self::PT === $q->get( 'post_type' ) ) {
			$join .= " LEFT JOIN {$wpdb->postmeta} m24sm ON ({$wpdb->posts}.ID = m24sm.post_id) ";
		}
		return $join;
	}

	public static function search_meta( $search, $q ) {
		global $wpdb;
		if ( '' === $search || ! is_admin() || ! $q->is_main_query() || ! $q->is_search() || self::PT !== $q->get( 'post_type' ) ) {
			return $search;
		}
		$term = '%' . $wpdb->esc_like( $q->get( 's' ) ) . '%';
		$meta = $wpdb->prepare(
			" OR (m24sm.meta_key IN ('_m24_artikelnummer','_m24_bmw_teilenummer') AND m24sm.meta_value LIKE %s)",
			$term
		);
		$search = preg_replace( '/\)\)\s*$/', $meta . '))', $search, 1 );
		$q->set( 'distinct', true );
		return $search;
	}

	// ─── ROW-ACTIONS + QUICK-VIEW ───────────────────────────────

	public static function row_actions( $actions, $post ) {
		if ( self::PT !== $post->post_type ) { return $actions; }

		$new = array();
		// Bearbeiten (default behalten)
		if ( isset( $actions['edit'] ) ) { $new['edit'] = $actions['edit']; }

		// Beschreibung Quick-View
		$new['m24_qv'] = sprintf(
			'<a href="#" class="m24-qv-toggle" data-post="%d">Beschreibung ansehen</a>',
			(int) $post->ID
		);

		// Status-Toggle + Duplizieren + Loeschen
		$status = get_post_meta( $post->ID, '_m24_status', true ) ?: 'aktiv';
		$nonce  = wp_create_nonce( 'm24_teil_action_' . $post->ID );
		$base   = admin_url( 'admin.php?action=m24_teil_action&post=' . $post->ID . '&_wpnonce=' . $nonce );

		if ( 'ausgeblendet' === $status ) {
			$new['m24_vis'] = sprintf( '<a href="%s">Einblenden</a>', esc_url( $base . '&do=aktiv' ) );
		} else {
			$new['m24_vis'] = sprintf( '<a href="%s">Ausblenden</a>', esc_url( $base . '&do=ausgeblendet' ) );
		}
		// Verkauft-Toggle (Schnellaktion): markiert/entmarkiert ohne Editor.
		if ( 'verkauft' === $status ) {
			$new['m24_sold'] = sprintf( '<a href="%s">Wieder aktiv</a>', esc_url( $base . '&do=aktiv' ) );
		} else {
			$new['m24_sold'] = sprintf( '<a href="%s" style="color:#9e2b2b">Als verkauft</a>', esc_url( $base . '&do=verkauft' ) );
		}
		$new['m24_dup'] = sprintf( '<a href="%s">Duplizieren</a>', esc_url( $base . '&do=dup' ) );
		if ( isset( $actions['trash'] ) )   { $new['trash']   = $actions['trash']; }
		if ( isset( $actions['delete'] ) )  { $new['delete']  = $actions['delete']; }

		// Inline-Quick-View-Panel mit Beschreibung (versteckt) ans Ende der ersten Spalte haengen.
		// WP rendert post_row_actions in der Titel-Spalte → wir koennen hier kein Block-HTML
		// einfuegen. Stattdessen: JS injiziert das Panel beim Toggle-Klick (fetch via AJAX? Nein,
		// hier rendern wir den Content direkt als data-Attribut).
		$desc = get_post_meta( $post->ID, '_m24_beschreibung_de', true );
		if ( '' === trim( (string) $desc ) ) {
			$desc = wp_strip_all_tags( (string) $post->post_content );
		}
		$desc_short = wp_html_excerpt( (string) $desc, 600, '…' );
		$new['m24_qv_data'] = sprintf(
			'<span class="m24-qv-data" data-post="%d" data-desc="%s" style="display:none"></span>',
			(int) $post->ID, esc_attr( $desc_short )
		);

		return $new;
	}

	// ─── QUICK-EDIT ─────────────────────────────────────────────

	public static function quick_edit_box( $col, $post_type ) {
		if ( self::PT !== $post_type ) { return; }
		// Nur EINMAL pro Quick-Edit-Form rendern (an "title"-Spalte gehaengt)
		if ( 'm24_preis' !== $col ) { return; }
		$modell_terms = get_terms( array( 'taxonomy' => self::TAX_MODELL, 'hide_empty' => false ) );
		$bg_terms     = get_terms( array( 'taxonomy' => self::TAX_BAUGRUPPE, 'hide_empty' => false ) );
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<h4>M24 — Teile-Daten</h4>
				<label>
					<span class="title">Preis (Brutto)</span>
					<span class="input-text-wrap"><input type="text" name="m24_qe_brutto" value="" style="width:100px"></span>
				</label>
				<label style="display:block">
					<span class="title">Modelle (mehrere möglich)</span>
					<span class="input-text-wrap" style="display:block">
						<select name="m24_qe_modell[]" multiple size="5" style="width:100%;max-width:280px">
							<?php if ( ! is_wp_error( $modell_terms ) ) : foreach ( $modell_terms as $t ) : ?>
								<option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; endif; ?>
						</select>
					</span>
				</label>
				<label>
					<input type="checkbox" name="m24_qe_modell_replace" value="1">
					<span class="checkbox-title">Bestehende Modell-Zuordnungen ersetzen (Standard: hinzufügen)</span>
				</label>
				<label>
					<input type="checkbox" name="m24_qe_modell_clear" value="1">
					<span class="checkbox-title">Alle Modell-Zuordnungen entfernen</span>
				</label>
				<label style="display:block">
					<span class="title">Baugruppen (mehrere möglich)</span>
					<span class="input-text-wrap" style="display:block">
						<select name="m24_qe_baugruppe[]" multiple size="5" style="width:100%;max-width:280px">
							<?php if ( ! is_wp_error( $bg_terms ) ) : foreach ( $bg_terms as $t ) : ?>
								<option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; endif; ?>
						</select>
					</span>
				</label>
				<label>
					<input type="checkbox" name="m24_qe_baugruppe_replace" value="1">
					<span class="checkbox-title">Bestehende Baugruppen-Zuordnungen ersetzen</span>
				</label>
				<label>
					<input type="checkbox" name="m24_qe_baugruppe_clear" value="1">
					<span class="checkbox-title">Alle Baugruppen-Zuordnungen entfernen</span>
				</label>
				<label>
					<span class="title">Rennsport-Hinweis</span>
					<select name="m24_qe_rennsport_hinweis">
						<option value="">— unverändert —</option>
						<option value="1">Aktiv</option>
						<option value="0">Aus</option>
					</select>
				</label>
				<?php wp_nonce_field( 'm24_quick_edit', 'm24_qe_nonce' ); ?>
			</div>
		</fieldset>
		<?php
	}

	public static function quick_edit_save( $post_id, $post ) {
		if ( ! isset( $_POST['m24_qe_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['m24_qe_nonce'] ), 'm24_quick_edit' ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }

		// Preis
		if ( isset( $_POST['m24_qe_brutto'] ) ) {
			$raw = wp_unslash( (string) $_POST['m24_qe_brutto'] );
			$raw = trim( str_replace( array( '€', ' ' ), '', $raw ) );
			$raw = str_replace( '.', '', $raw );  // dt. Tausenderpunkt raus
			$raw = str_replace( ',', '.', $raw );
			if ( '' !== $raw && is_numeric( $raw ) ) {
				self::update_price( $post_id, (float) $raw );
			}
		}

		// Modell-Terms (Multi)
		$modell_clear   = ! empty( $_POST['m24_qe_modell_clear'] );
		$modell_replace = ! empty( $_POST['m24_qe_modell_replace'] );
		$modell_ids = array();
		if ( isset( $_POST['m24_qe_modell'] ) && is_array( $_POST['m24_qe_modell'] ) ) {
			$modell_ids = array_filter( array_map( 'absint', wp_unslash( $_POST['m24_qe_modell'] ) ) );
		}
		if ( $modell_clear ) {
			wp_set_object_terms( $post_id, array(), self::TAX_MODELL, false );
		} elseif ( ! empty( $modell_ids ) ) {
			// Append=false bei Replace, true bei Append (Standard)
			wp_set_object_terms( $post_id, $modell_ids, self::TAX_MODELL, ! $modell_replace );
		}

		// Baugruppe-Terms (Multi)
		$bg_clear   = ! empty( $_POST['m24_qe_baugruppe_clear'] );
		$bg_replace = ! empty( $_POST['m24_qe_baugruppe_replace'] );
		$bg_ids = array();
		if ( isset( $_POST['m24_qe_baugruppe'] ) && is_array( $_POST['m24_qe_baugruppe'] ) ) {
			$bg_ids = array_filter( array_map( 'absint', wp_unslash( $_POST['m24_qe_baugruppe'] ) ) );
		}
		if ( $bg_clear ) {
			wp_set_object_terms( $post_id, array(), self::TAX_BAUGRUPPE, false );
		} elseif ( ! empty( $bg_ids ) ) {
			wp_set_object_terms( $post_id, $bg_ids, self::TAX_BAUGRUPPE, ! $bg_replace );
		}

		// Rennsport-Hinweis (3-state: '', '0', '1')
		$rh = isset( $_POST['m24_qe_rennsport_hinweis'] ) ? sanitize_text_field( wp_unslash( $_POST['m24_qe_rennsport_hinweis'] ) ) : '';
		if ( '1' === $rh ) {
			update_post_meta( $post_id, '_m24_rennsport_hinweis', 1 );
		} elseif ( '0' === $rh ) {
			update_post_meta( $post_id, '_m24_rennsport_hinweis', 0 );
		}
		// '' = unveraendert, nichts tun.
	}

	// ─── BULK-ACTIONS ───────────────────────────────────────────

	public static function bulk_actions( $actions ) {
		$actions['m24_assign_modell']    = 'Modell zuweisen';
		$actions['m24_assign_baugruppe'] = 'Baugruppe zuweisen';
		$actions['m24_rennsport_on']     = 'Rennsport-Hinweis: an';
		$actions['m24_rennsport_off']    = 'Rennsport-Hinweis: aus';
		$actions['m24_hide']             = 'Ausblenden';
		return $actions;
	}

	public static function handle_bulk_actions( $redirect, $action, $post_ids ) {
		if ( empty( $post_ids ) ) { return $redirect; }
		check_admin_referer( 'bulk-posts' );  // WP-Bulk-Nonce

		$count = 0;
		if ( 'm24_hide' === $action ) {
			foreach ( $post_ids as $pid ) {
				if ( current_user_can( 'edit_post', $pid ) ) {
					update_post_meta( $pid, '_m24_status', 'ausgeblendet' );
					$count++;
				}
			}
			return add_query_arg( 'm24_bulk_done', 'hide-' . $count, $redirect );
		}

		if ( 'm24_rennsport_on' === $action || 'm24_rennsport_off' === $action ) {
			$val = ( 'm24_rennsport_on' === $action ) ? 1 : 0;
			foreach ( $post_ids as $pid ) {
				if ( current_user_can( 'edit_post', $pid ) ) {
					update_post_meta( $pid, '_m24_rennsport_hinweis', $val );
					$count++;
				}
			}
			return add_query_arg( 'm24_bulk_done', 'rennsport-' . ( $val ? 'on-' : 'off-' ) . $count, $redirect );
		}

		if ( 'm24_assign_modell' === $action ) {
			$term_ids = isset( $_REQUEST['m24_bulk_modell_terms'] ) && is_array( $_REQUEST['m24_bulk_modell_terms'] )
				? array_filter( array_map( 'absint', wp_unslash( $_REQUEST['m24_bulk_modell_terms'] ) ) )
				: array();
			$replace = ! empty( $_REQUEST['m24_bulk_replace'] );
			if ( ! empty( $term_ids ) ) {
				foreach ( $post_ids as $pid ) {
					if ( current_user_can( 'edit_post', $pid ) ) {
						// Append=false bei Replace, true bei Append (Standard)
						wp_set_object_terms( $pid, $term_ids, self::TAX_MODELL, ! $replace );
						$count++;
					}
				}
			}
			return add_query_arg( 'm24_bulk_done', 'modell-' . $count, $redirect );
		}

		if ( 'm24_assign_baugruppe' === $action ) {
			$term_ids = isset( $_REQUEST['m24_bulk_baugruppe_terms'] ) && is_array( $_REQUEST['m24_bulk_baugruppe_terms'] )
				? array_filter( array_map( 'absint', wp_unslash( $_REQUEST['m24_bulk_baugruppe_terms'] ) ) )
				: array();
			$replace = ! empty( $_REQUEST['m24_bulk_replace'] );
			if ( ! empty( $term_ids ) ) {
				foreach ( $post_ids as $pid ) {
					if ( current_user_can( 'edit_post', $pid ) ) {
						wp_set_object_terms( $pid, $term_ids, self::TAX_BAUGRUPPE, ! $replace );
						$count++;
					}
				}
			}
			return add_query_arg( 'm24_bulk_done', 'baugruppe-' . $count, $redirect );
		}

		return $redirect;
	}

	public static function bulk_notices() {
		if ( empty( $_GET['m24_bulk_done'] ) ) { return; }
		$raw = sanitize_text_field( wp_unslash( $_GET['m24_bulk_done'] ) );
		list( $kind, $num ) = array_pad( explode( '-', $raw, 2 ), 2, '' );
		$num = (int) $num;
		$msg = '';
		if ( 'hide' === $kind )       { $msg = $num . ' Teile ausgeblendet.'; }
		if ( 'modell' === $kind )     { $msg = 'Modell auf ' . $num . ' Teile angewendet.'; }
		if ( 'baugruppe' === $kind )  { $msg = 'Baugruppe auf ' . $num . ' Teile angewendet.'; }
		if ( 'rennsport' === $kind ) {
			// Format: rennsport-on-N / rennsport-off-N
			$parts = explode( '-', $raw );
			$state = isset( $parts[1] ) ? $parts[1] : '';
			$count = isset( $parts[2] ) ? (int) $parts[2] : 0;
			$msg = 'Rennsport-Hinweis auf ' . $count . ' Teile ' . ( 'on' === $state ? 'aktiviert' : 'deaktiviert' ) . '.';
		}
		if ( '' !== $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	// ─── AJAX INLINE-PRICE ──────────────────────────────────────

	public static function ajax_inline_price() {
		check_ajax_referer( self::NONCE_PRICE, 'nonce' );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'msg' => 'Keine Berechtigung' ), 403 );
		}
		$raw = wp_unslash( (string) ( $_POST['price'] ?? '' ) );
		$raw = trim( str_replace( array( '€', ' ' ), '', $raw ) );
		$raw = str_replace( '.', '', $raw );
		$raw = str_replace( ',', '.', $raw );
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			wp_send_json_error( array( 'msg' => 'Ungültiger Preis' ), 400 );
		}
		$brutto = round( (float) $raw, 2 );
		self::update_price( $post_id, $brutto );
		wp_send_json_success( array(
			'brutto_fmt' => number_format( $brutto, 2, ',', '.' ),
		) );
	}

	/**
	 * Schreibt den Brutto-Preis ins erste _m24_preisoptionen-Element + sync _m24_preis_netto.
	 * Bei §25a: brutto = basis, netto bleibt null. Bei regel: brutto eingegeben, netto wird abgeleitet.
	 */
	private static function update_price( $post_id, $brutto ) {
		$modus = get_post_meta( $post_id, '_m24_mwst_modus', true ) ?: 'regel';
		$opts_raw = (string) get_post_meta( $post_id, '_m24_preisoptionen', true );
		$opts = ( '' !== $opts_raw ) ? json_decode( $opts_raw, true ) : array();
		if ( ! is_array( $opts ) || empty( $opts ) ) {
			$opts = array( array(
				'label'  => '',
				'art_nr' => (string) get_post_meta( $post_id, '_m24_artikelnummer', true ),
				'netto'  => null,
				'brutto' => $brutto,
			) );
		}
		// Erstes Element updaten
		$opts[0]['brutto'] = $brutto;
		if ( 'paragraf25a' === $modus ) {
			$opts[0]['netto'] = null;
			$legacy_basis = $brutto;
		} else {
			$netto = round( $brutto / 1.19, 2 );
			$opts[0]['netto'] = $netto;
			$legacy_basis = $netto;
		}
		update_post_meta( $post_id, '_m24_preisoptionen', wp_json_encode( $opts ) );
		update_post_meta( $post_id, '_m24_preis_netto', (float) $legacy_basis );
	}

	// ─── HANDLE ACTION (Row-Action-Links) ───────────────────────

	public static function handle_action() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$do      = isset( $_GET['do'] ) ? sanitize_text_field( wp_unslash( $_GET['do'] ) ) : '';
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) { wp_die( 'Keine Berechtigung.' ); }
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'm24_teil_action_' . $post_id ) ) {
			wp_die( 'Ungültiger Sicherheits-Token.' );
		}
		if ( 'dup' === $do ) {
			self::duplicate( $post_id );
		} elseif ( in_array( $do, array( 'aktiv', 'ausgeblendet', 'verkauft' ), true ) ) {
			update_post_meta( $post_id, '_m24_status', $do );
		}
		$ref = wp_get_referer();
		wp_safe_redirect( $ref ?: admin_url( 'edit.php?post_type=' . self::PT ) );
		exit;
	}

	/**
	 * Duplizieren als Entwurf — inkl. aller Meta, Thumbnail, Galerie + Taxonomie-Terms.
	 * NICHT kopiert: _m24_sw_id (sonst Upsert-Kollision im Importer).
	 */
	private static function duplicate( $post_id ) {
		$src = get_post( $post_id );
		if ( ! $src ) { return 0; }
		$new_id = wp_insert_post( array(
			'post_type'    => self::PT,
			'post_status'  => 'draft',
			'post_title'   => $src->post_title . ' (Kopie)',
			'post_content' => $src->post_content,
			'post_excerpt' => $src->post_excerpt,
		) );
		if ( is_wp_error( $new_id ) || ! $new_id ) { return 0; }

		// Meta kopieren (alle _m24_* + Thumbnail + Galerie), AUSSER _m24_sw_id
		foreach ( get_post_meta( $post_id ) as $key => $vals ) {
			if ( '_m24_sw_id' === $key ) { continue; }
			if ( 0 === strpos( $key, '_m24_' ) || '_thumbnail_id' === $key ) {
				update_post_meta( $new_id, $key, maybe_unserialize( $vals[0] ) );
			}
		}
		// Artikelnummer leeren (Eindeutigkeit)
		update_post_meta( $new_id, '_m24_artikelnummer', '' );
		update_post_meta( $new_id, '_m24_status', 'ausgeblendet' );

		// Taxonomie-Terms kopieren
		foreach ( array( self::TAX_MODELL, self::TAX_BAUGRUPPE, M24_Catalog_CPT::TAXONOMY ) as $tax ) {
			$term_ids = wp_get_post_terms( $post_id, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) ) {
				wp_set_post_terms( $new_id, $term_ids, $tax, false );
			}
		}
		return $new_id;
	}

	// ─── MULTI-TERM MODELL-ZELLE + AJAX-TOGGLE ──────────────────

	/**
	 * Rendert die Modell-Zelle als Chip-Liste + Add-Toggle. Wird auch vom AJAX-Handler
	 * zur Re-Render genutzt (Server-Authority — JS spiegelt nur das HTML).
	 */
	public static function render_modell_cell( $post_id ) {
		$terms = get_the_terms( $post_id, self::TAX_MODELL );
		$chips = '';
		$ids   = array();
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$ids[] = (int) $t->term_id;
				$chips .= sprintf(
					'<span class="m24-ms-chip" data-term-id="%d">%s<a href="#" class="m24-ms-remove" title="Entfernen">×</a></span>',
					(int) $t->term_id,
					esc_html( $t->name )
				);
			}
		}
		if ( '' === $chips ) {
			$chips = '<span class="m24-ms-empty">— ohne Modell —</span>';
		}
		return sprintf(
			'<div class="m24-multi-modell" data-post="%d" data-current="%s"><span class="m24-ms-chips">%s</span> <a href="#" class="m24-ms-toggle">+ Modell</a><div class="m24-ms-dropdown" hidden></div></div>',
			(int) $post_id,
			esc_attr( implode( ',', $ids ) ),
			$chips
		);
	}

	/**
	 * AJAX: Term hinzufuegen oder entfernen. Re-rendert die Zelle Server-seitig
	 * und schickt das HTML zurueck — JS swappt den Container-Inhalt.
	 */
	public static function ajax_modell_toggle() {
		check_ajax_referer( self::NONCE_MODELL_TOGGLE, 'nonce' );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$term_id = absint( $_POST['term_id'] ?? 0 );
		$op      = sanitize_text_field( wp_unslash( $_POST['op'] ?? '' ) );

		if ( ! $post_id || ! $term_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'msg' => 'Keine Berechtigung' ), 403 );
		}
		// Term existiert + gehoert zur Modell-Taxonomie?
		$term = get_term( $term_id, self::TAX_MODELL );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( array( 'msg' => 'Unbekannter Term' ), 400 );
		}

		$existing = wp_get_post_terms( $post_id, self::TAX_MODELL, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $existing ) ) { $existing = array(); }
		$existing = array_map( 'intval', $existing );

		if ( 'add' === $op ) {
			$new = array_unique( array_merge( $existing, array( $term_id ) ) );
		} elseif ( 'remove' === $op ) {
			$new = array_values( array_diff( $existing, array( $term_id ) ) );
		} else {
			wp_send_json_error( array( 'msg' => 'Ungueltige Op' ), 400 );
		}

		wp_set_object_terms( $post_id, array_values( $new ), self::TAX_MODELL, false );

		$ids = array_map( 'intval', wp_get_post_terms( $post_id, self::TAX_MODELL, array( 'fields' => 'ids' ) ) );
		wp_send_json_success( array(
			'html' => self::render_modell_cell( $post_id ),
			'ids'  => $ids,
		) );
	}

	// ─── AJAX INLINE-TITLE (Rename + SEO-Sync) ──────────────────

	/**
	 * Aenderung des post_title via Inline-Edit in der Liste.
	 *  - Nonce + edit_post-Cap
	 *  - wp_update_post() triggert save_post_m24_teil → catalog-seo::fill_if_empty
	 *    regeneriert wpSEO-Title/Desc nur wenn Autofill-Marker gueltig (Hash matcht).
	 *  - Slug bleibt unangetastet (kein 'post_name' im Update).
	 */
	public static function ajax_inline_title() {
		check_ajax_referer( self::NONCE_TITLE, 'nonce' );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$title   = sanitize_text_field( wp_unslash( (string) ( $_POST['title'] ?? '' ) ) );
		$title   = trim( $title );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'msg' => 'Keine Berechtigung' ), 403 );
		}
		if ( '' === $title ) {
			wp_send_json_error( array( 'msg' => 'Titel darf nicht leer sein' ), 400 );
		}
		$r = wp_update_post( array(
			'ID'         => $post_id,
			'post_title' => $title,
		), true );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'msg' => $r->get_error_message() ), 500);
		}
		// T6-Fix: SEO ist render-seitig (catalog-seo::force_detail_title + filter_desc).
		// Kein Meta-Schreib mehr noetig — Title folgt dem Post-Titel automatisch.
		// T8: post_name wird via save_post-Hook (catalog-fields::auto_slug_from_title)
		// regeneriert wenn _m24_url_slug_manual nicht gesetzt ist.
		wp_send_json_success( array(
			'title' => get_the_title( $post_id ),
			'slug'  => get_post_field( 'post_name', $post_id ),
		) );
	}

	// ─── ASSETS ─────────────────────────────────────────────────

	public static function enqueue_assets( $hook ) {
		if ( 'edit.php' !== $hook ) { return; }
		$screen = get_current_screen();
		if ( ! $screen || self::PT !== $screen->post_type ) { return; }

		$base    = plugin_dir_url( M24_PLATTFORM_FILE );
		// Cache-Busting per filemtime: ein Redeploy bustet Cloudflare/WP-Rocket/Browser-Cache
		// zuverlaessig (statt statischer ?ver=0.1.0, die alte JS auf Live ausliefern kann).
		$js_path = M24_PLATTFORM_DIR . 'assets/js/catalog-admin-list.js';
		$version = file_exists( $js_path ) ? (string) filemtime( $js_path )
			: ( defined( 'M24_PLATTFORM_VERSION' ) ? M24_PLATTFORM_VERSION : '0.1.0' );

		wp_enqueue_script( 'm24-admin-list', $base . 'assets/js/catalog-admin-list.js', array( 'jquery', 'inline-edit-post' ), $version, true );
		wp_localize_script( 'm24-admin-list', 'M24AdminList', array(
			'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
			'noncePrice'         => wp_create_nonce( self::NONCE_PRICE ),
			'nonceModellToggle'  => wp_create_nonce( self::NONCE_MODELL_TOGGLE ),
			'nonceTitle'         => wp_create_nonce( self::NONCE_TITLE ),
			'modellTerms'        => self::dropdown_data( self::TAX_MODELL ),
			'baugruppeTerms'     => self::dropdown_data( self::TAX_BAUGRUPPE ),
		) );

		// Inline-CSS: kompakte Zeilen, Thumbnail-Cover, Title-Ellipsis, Preis inline, Row-Actions hover-only.
		$b = 'body.post-type-m24_teil';
		$css = '.m24-qv-panel{background:#f6f7f7;border-left:3px solid #2271b1;padding:10px 14px;margin:6px 0;font-size:13px;color:#1d2327;max-width:900px}'
			// Kompakte Zellen + vertikal mittig (8px wegen Thumbnail-66x45)
			. $b . ' .wp-list-table.posts td,' . $b . ' .wp-list-table.posts th{padding:8px 10px!important;vertical-align:middle!important}'
			// Bild-Spalte: fix 66x45 (3:2 proportional, +50%) + cover, kein padding-right
			. $b . ' .wp-list-table .column-m24_bild{width:76px!important;padding-right:0!important}'
			. $b . ' .wp-list-table img.m24-thumb{width:66px!important;height:45px!important;object-fit:cover!important;display:block!important;border-radius:2px}'
			. $b . ' .wp-list-table .m24-thumb-empty{display:inline-block;width:66px;height:45px;background:#f0f0f1;color:#bbb;text-align:center;line-height:45px;border-radius:2px;font-size:16px}'
			// Title-Spalte: voll ausgeschrieben (kein ellipsis, kein max-width)
			. $b . ' .wp-list-table .column-title{white-space:nowrap}'
			. $b . ' .wp-list-table .column-title strong{display:inline}'
			. $b . ' .wp-list-table .column-title strong a{display:inline;white-space:nowrap}'
			// Horizontal-Scroll NUR innerhalb des Wrappers — darf NICHT das Admin-Layout
			// (Sidebar/Toolbar) nach links schieben. Die Flex-/Grid-Vorfahren (WP 7.0) bekommen
			// min-width:0, damit die max-content-Tabelle die Inhaltsspalte nicht aufblaeht;
			// der Wrapper selbst wird auf die Inhaltsbreite begrenzt.
			. $b . ' #wpcontent,' . $b . ' #wpbody,' . $b . ' #wpbody-content,' . $b . ' #wpbody-content .wrap,' . $b . ' #posts-filter{min-width:0}'
			. $b . ' #wpbody-content .wrap,' . $b . ' #posts-filter{max-width:100%}'
			. $b . ' .m24-table-scroll{overflow-x:auto;overflow-y:visible;width:100%;max-width:100%;min-width:0;background:#fff}'
			. $b . ' .m24-table-scroll .wp-list-table{width:max-content;min-width:100%}'
			// Row-Actions: nur bei Hover (WP-Standard explizit erzwingen)
			. $b . ' .wp-list-table .row-actions{visibility:hidden;position:absolute;left:10px}'
			. $b . ' .wp-list-table tr:hover .row-actions,' . $b . ' .wp-list-table tr:focus-within .row-actions{visibility:visible}'
			// Preis-Spalte: inline, kein Umbruch
			. $b . ' .wp-list-table .column-m24_preis{white-space:nowrap}'
			. $b . ' .wp-list-table .m24-price-cell{display:inline-flex;align-items:center;gap:4px;font-size:13px}'
			. $b . ' .wp-list-table input.m24-inline-price{width:74px;text-align:right;padding:2px 5px;font-family:var(--mono,Consolas,monospace);font-size:13px;line-height:1.4;height:26px;margin:0}'
			. $b . ' .wp-list-table .m24-price-status{font-size:11px;color:#777}'
			// Inline-Price-Feedback (Background-Flash)
			. '.m24-inline-price{transition:background .15s ease}'
			. '.m24-inline-price.saving{background:#fff8e1}'
			. '.m24-inline-price.saved{background:#d4edda}'
			. '.m24-inline-price.error{background:#f8d7da}'
			// Inline-Title-Edit (Edit-Icon + Input)
			. $b . ' .m24-title-edit{margin-left:6px;color:#646970;opacity:.45;text-decoration:none;font-size:14px;cursor:pointer;display:inline-block}'
			. $b . ' .m24-title-edit:hover{opacity:1;color:#2271b1}'
			. $b . ' input.m24-title-input{font-size:14px;font-weight:600;width:90%;min-width:300px;padding:3px 6px;border:1px solid #2271b1;border-radius:3px;transition:background .15s ease}'
			. $b . ' input.m24-title-input.saving{background:#fff8e1}'
			. $b . ' input.m24-title-input.saved{background:#d4edda}'
			. $b . ' input.m24-title-input.error{background:#f8d7da}'
			// Multi-Term-Chips fuer Modell-Spalte
			. $b . ' .m24-multi-modell{display:flex;flex-wrap:wrap;align-items:center;gap:4px;position:relative}'
			. $b . ' .m24-ms-chips{display:inline-flex;flex-wrap:wrap;gap:3px}'
			. $b . ' .m24-ms-chip{display:inline-flex;align-items:center;gap:3px;background:#e7f3ff;color:#0a4b78;padding:1px 4px 1px 6px;border-radius:10px;font-size:11px;line-height:1.4;border:1px solid #b6dcfe}'
			. $b . ' .m24-ms-chip a.m24-ms-remove{color:#0a4b78;text-decoration:none;font-weight:700;font-size:14px;line-height:1;padding:0 2px;opacity:.55}'
			. $b . ' .m24-ms-chip a.m24-ms-remove:hover{opacity:1;color:#c8102e}'
			. $b . ' .m24-ms-empty{color:#c8102e;font-size:12px;font-style:italic}'
			. $b . ' .m24-ms-toggle{font-size:11px;color:#2271b1;text-decoration:none;background:#f6f7f7;border:1px solid #dcdcde;border-radius:10px;padding:1px 6px;cursor:pointer}'
			. $b . ' .m24-ms-toggle:hover{background:#fff;border-color:#2271b1}'
			. $b . ' .m24-ms-dropdown{position:absolute;top:100%;left:0;margin-top:2px;background:#fff;border:1px solid #ccd0d4;box-shadow:0 4px 12px rgba(0,0,0,.12);border-radius:4px;padding:6px;z-index:100;max-height:280px;overflow-y:auto;min-width:220px}'
			. $b . ' .m24-ms-dropdown label{display:block;padding:3px 6px;font-size:12px;cursor:pointer;border-radius:2px}'
			. $b . ' .m24-ms-dropdown label:hover{background:#f0f6fc}'
			. $b . ' .m24-ms-dropdown label input{margin-right:6px;vertical-align:-1px}'
			// Bulk-Modal
			. '.m24-bulk-modal{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:99999;display:flex;align-items:center;justify-content:center}'
			. '.m24-bulk-modal-content{background:#fff;border-radius:6px;padding:20px;max-width:480px;width:90%;box-shadow:0 8px 24px rgba(0,0,0,.2)}'
			. '.m24-bulk-modal-content h3{margin:0 0 8px;font-size:16px}'
			. '.m24-bulk-modal-content p{margin:4px 0 8px;color:#646970;font-size:13px}'
			. '.m24-bulk-modal-content select{width:100%;height:200px;padding:6px;font-size:13px;margin-bottom:8px}'
			. '.m24-bulk-modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}';
		wp_add_inline_style( 'wp-admin', $css );
	}

	private static function dropdown_data( $taxonomy ) {
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		$out = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[] = array( 'id' => (int) $t->term_id, 'name' => $t->name );
			}
		}
		return $out;
	}
}

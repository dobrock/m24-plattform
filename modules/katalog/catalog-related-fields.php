<?php
/**
 * M24 Plattform — Katalog: Meta-Box „Verknuepfte Teile"
 * Modul: modules/katalog/catalog-related-fields.php
 *
 * Manuelle Zuordnung fuer den „Weitere Teile"-Block (v. a. Neuteile/Rennsport-Bundles):
 *  - Autocomplete-Suchfeld (Titel/Artikelnummer/BMW-Nummer) → gezieltes Pinnen.
 *  - Geordnete Pin-Liste (↑/↓ zum Sortieren, × zum Entfernen). Pins zuerst, dann
 *    Auto-Auffuellung bis 5 (siehe M24_Catalog_Related).
 *  - Toggle „nur manuell" → keine Auto-Auffuellung, nur die Pins (kuratierte Bundles).
 * Speichert `_m24_related_pins` (JSON, geordnet) + `_m24_related_manual_only` (0/1).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Related_Fields {

	const NONCE = 'm24_related_nonce';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . M24_Catalog_CPT::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function add_box() {
		add_meta_box(
			'm24_teil_related',
			'Verknüpfte Teile („Weitere Teile")',
			array( __CLASS__, 'render' ),
			M24_Catalog_CPT::POST_TYPE,
			'normal',
			'low'
		);
	}

	public static function render( $post ) {
		wp_nonce_field( 'm24_related_' . $post->ID, self::NONCE );
		$raw         = get_post_meta( $post->ID, M24_Catalog_Related::META_PINS, true );
		$ids         = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		$ids         = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		$manual_only = (bool) (int) get_post_meta( $post->ID, M24_Catalog_Related::META_MANUAL_ONLY, true );
		?>
		<style>
			.m24rel{font-size:13px;margin-top:4px}
			.m24rel .m24rel-only{display:flex;align-items:center;gap:7px;font-weight:600;margin:2px 0 12px;cursor:pointer}
			.m24rel-list{display:flex;flex-direction:column;gap:7px;margin-bottom:12px}
			.m24rel-empty{color:#777;font-style:italic;padding:6px 0}
			.m24rel-pin{display:grid;grid-template-columns:40px 1fr auto auto;gap:10px;align-items:center;border:1px solid #d6d8dd;border-radius:6px;padding:6px 9px;background:#fafbfc}
			.m24rel-pin .m24rel-thumb{width:40px;height:34px;border-radius:4px;background:#ededea;overflow:hidden}
			.m24rel-pin .m24rel-thumb img{width:100%;height:100%;object-fit:cover;display:block}
			.m24rel-pin .m24rel-meta{min-width:0}
			.m24rel-pin .m24rel-meta strong{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
			.m24rel-pin .m24rel-meta small{color:#777}
			.m24rel-pin .m24rel-bad{color:#b32d2e;font-weight:600}
			.m24rel-ord{display:inline-flex;gap:2px}
			.m24rel-ord button,.m24rel-del{background:#fff;border:1px solid #ccd0d4;border-radius:4px;cursor:pointer;width:26px;height:26px;line-height:1;padding:0;font-size:14px}
			.m24rel-del{color:#b32d2e;border-color:#e5b3b3}
			.m24rel-search{position:relative;max-width:520px}
			.m24rel-search input{width:100%}
			.m24rel-sugg{position:absolute;left:0;right:0;top:100%;z-index:50;background:#fff;border:1px solid #ccd0d4;border-top:none;border-radius:0 0 6px 6px;box-shadow:0 6px 18px rgba(0,0,0,.12);max-height:320px;overflow:auto;display:none}
			.m24rel-sugg.open{display:block}
			.m24rel-sugg button{display:grid;grid-template-columns:36px 1fr;gap:9px;align-items:center;width:100%;text-align:left;background:#fff;border:none;border-bottom:1px solid #f0f0f0;padding:6px 9px;cursor:pointer}
			.m24rel-sugg button:hover{background:#f0f6fc}
			.m24rel-sugg .s-thumb{width:36px;height:30px;border-radius:4px;background:#ededea;overflow:hidden}
			.m24rel-sugg .s-thumb img{width:100%;height:100%;object-fit:cover;display:block}
			.m24rel-sugg .s-meta strong{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
			.m24rel-sugg .s-meta small{color:#777}
			.m24rel-sugg .s-note{padding:8px 10px;color:#777;font-style:italic}
			.m24rel-hint{color:#666;font-size:11.5px;margin:8px 0 0}
		</style>
		<div class="m24rel" data-rest="<?php echo esc_url( rest_url( M24_Catalog_Related::NS . '/teile-suche' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-self="<?php echo (int) $post->ID; ?>">
			<label class="m24rel-only">
				<input type="checkbox" name="m24_related_manual_only" value="1" <?php checked( $manual_only, true ); ?>>
				<span>Nur manuell — keine Auto-Auffüllung (kuratiertes Bundle)</span>
			</label>

			<div class="m24rel-list" id="m24rel-list">
				<?php
				$rendered = 0;
				foreach ( $ids as $pid ) {
					$p = get_post( $pid );
					if ( ! $p || M24_Catalog_CPT::POST_TYPE !== $p->post_type ) { continue; }
					$it  = M24_Catalog_Related::item( $pid );
					$bad = ! M24_Catalog_Related::is_available( $pid );
					self::pin_row( $it, $bad );
					$rendered++;
				}
				if ( 0 === $rendered ) {
					echo '<div class="m24rel-empty" data-empty="1">Keine manuellen Pins — Auto-Auswahl greift (gleiches Modell, dann Baugruppe).</div>';
				}
				?>
			</div>

			<div class="m24rel-search">
				<input type="text" id="m24rel-q" autocomplete="off" placeholder="Teil suchen (Titel, Artikelnummer, BMW-Nummer)…">
				<div class="m24rel-sugg" id="m24rel-sugg"></div>
			</div>
			<p class="m24rel-hint">Pins erscheinen zuerst (in dieser Reihenfolge), danach füllt die Auto-Auswahl bis 5 auf. „Nur manuell" schaltet die Auffüllung ab.</p>

			<template id="m24rel-tpl"><?php self::pin_row( array( 'id' => 0, 'title' => '', 'artnr' => '', 'thumb' => '' ), false, true ); ?></template>
		</div>

		<script>
		(function(){
			var root = document.querySelector('.m24rel'); if(!root) return;
			var list = root.querySelector('#m24rel-list');
			var qIn  = root.querySelector('#m24rel-q');
			var sugg = root.querySelector('#m24rel-sugg');
			var tpl  = root.querySelector('#m24rel-tpl');
			var rest = root.dataset.rest, nonce = root.dataset.nonce, self = root.dataset.self;
			var timer = null;

			function pinnedIds(){
				return Array.prototype.map.call(list.querySelectorAll('.m24rel-pin'), function(r){ return r.dataset.id; });
			}
			function syncEmpty(){
				var e = list.querySelector('[data-empty]');
				var has = list.querySelector('.m24rel-pin');
				if(has && e){ e.remove(); }
			}
			function wireRow(row){
				row.querySelector('.m24rel-del').addEventListener('click', function(){ row.remove(); });
				var ord = row.querySelectorAll('.m24rel-ord button');
				ord[0].addEventListener('click', function(){ var p=row.previousElementSibling; if(p&&p.classList.contains('m24rel-pin')) list.insertBefore(row,p); });
				ord[1].addEventListener('click', function(){ var n=row.nextElementSibling; if(n&&n.classList.contains('m24rel-pin')) list.insertBefore(n,row); });
			}
			Array.prototype.forEach.call(list.querySelectorAll('.m24rel-pin'), wireRow);

			function addPin(it){
				if(pinnedIds().indexOf(String(it.id)) !== -1) return;
				var wrap = document.createElement('div'); wrap.innerHTML = tpl.innerHTML.trim();
				var row = wrap.firstChild;
				row.dataset.id = it.id;
				row.querySelector('input[type=hidden]').value = it.id;
				row.querySelector('.m24rel-meta strong').textContent = it.title || ('#'+it.id);
				row.querySelector('.m24rel-meta small').textContent = it.artnr || '';
				var th = row.querySelector('.m24rel-thumb');
				if(it.thumb){ th.innerHTML = '<img src="'+it.thumb+'" alt="">'; }
				list.appendChild(row); wireRow(row); syncEmpty();
			}

			function closeSugg(){ sugg.classList.remove('open'); sugg.innerHTML=''; }

			function search(q){
				var ex = [self].concat(pinnedIds()).join(',');
				fetch(rest + '?q=' + encodeURIComponent(q) + '&exclude=' + encodeURIComponent(ex), { headers: { 'X-WP-Nonce': nonce } })
					.then(function(r){ return r.json(); })
					.then(function(items){
						sugg.innerHTML = '';
						if(!items || !items.length){ sugg.innerHTML = '<div class="s-note">Keine Treffer.</div>'; sugg.classList.add('open'); return; }
						items.forEach(function(it){
							var b = document.createElement('button'); b.type='button';
							b.innerHTML = '<span class="s-thumb">'+(it.thumb?'<img src="'+it.thumb+'" alt="">':'')+'</span><span class="s-meta"><strong></strong><small></small></span>';
							b.querySelector('strong').textContent = it.title || ('#'+it.id);
							b.querySelector('small').textContent = it.artnr || '';
							b.addEventListener('click', function(){ addPin(it); qIn.value=''; closeSugg(); qIn.focus(); });
							sugg.appendChild(b);
						});
						sugg.classList.add('open');
					})
					.catch(function(){ closeSugg(); });
			}

			qIn.addEventListener('input', function(){
				var q = qIn.value.trim();
				clearTimeout(timer);
				if(q.length < 2){ closeSugg(); return; }
				timer = setTimeout(function(){ search(q); }, 220);
			});
			document.addEventListener('click', function(e){ if(!root.contains(e.target)) closeSugg(); });
		})();
		</script>
		<?php
	}

	/** Eine Pin-Zeile rendern. $tpl=true → leeres Template-Markup. */
	private static function pin_row( $it, $bad = false, $tpl = false ) {
		$id = (int) $it['id'];
		?>
		<div class="m24rel-pin" data-id="<?php echo $tpl ? '' : esc_attr( (string) $id ); ?>">
			<span class="m24rel-thumb"><?php if ( ! $tpl && '' !== $it['thumb'] ) : ?><img src="<?php echo esc_url( $it['thumb'] ); ?>" alt=""><?php endif; ?></span>
			<span class="m24rel-meta">
				<strong><?php echo $tpl ? '' : esc_html( '' !== $it['title'] ? $it['title'] : ( '#' . $id ) ); ?></strong>
				<small><?php echo $tpl ? '' : esc_html( $it['artnr'] ); ?><?php if ( ! $tpl && $bad ) : ?> <span class="m24rel-bad">· derzeit nicht verfügbar</span><?php endif; ?></small>
			</span>
			<span class="m24rel-ord"><button type="button" title="nach oben">↑</button><button type="button" title="nach unten">↓</button></span>
			<button type="button" class="m24rel-del" title="entfernen">×</button>
			<input type="hidden" name="m24_related_pins[]" value="<?php echo $tpl ? '' : esc_attr( (string) $id ); ?>">
		</div>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE ] ), 'm24_related_' . $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		$pins  = isset( $_POST['m24_related_pins'] ) && is_array( $_POST['m24_related_pins'] ) ? wp_unslash( $_POST['m24_related_pins'] ) : array();
		$clean = array();
		$seen  = array();
		foreach ( $pins as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 || $pid === (int) $post_id || isset( $seen[ $pid ] ) ) { continue; }
			if ( M24_Catalog_CPT::POST_TYPE !== get_post_type( $pid ) ) { continue; }
			$seen[ $pid ] = true;
			$clean[]      = $pid;
		}
		update_post_meta( $post_id, M24_Catalog_Related::META_PINS, wp_json_encode( $clean ) );
		update_post_meta( $post_id, M24_Catalog_Related::META_MANUAL_ONLY, isset( $_POST['m24_related_manual_only'] ) ? 1 : 0 );
	}
}

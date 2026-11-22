<?php
/**
 * M24 Katalog — Themenseiten je Modell-Hub (Reiter mit eigener Adresse) — 22.11.2026
 *
 * AUFTRAG DANIEL (22.11.2026): „Als ein Projekt sollten wir auch stärker auf die Modelle optimieren" – Start mit dem
 * BMW Z4 GT3, gewählt ist Vorschlag B: Reiter unter dem Kopf der Modellseite, JEDER Reiter eine eigene Seite mit
 * eigener Adresse, eigenem Titel, eigener Beschreibung und eigenem Text. Grund: In der Search Console stehen getrennte
 * Suchanfragen („z4 gt3 body kit", „bmw z4 gt3 for sale", „bmw z4 gt3") – eine einzige Teileliste kann nicht für alle
 * die passende Antwort sein.
 *
 * ADRESSEN:  /teile/<hub>/            Übersicht (wie bisher, unverändert)
 *            /teile/<hub>/<thema>/    Themenseite, z. B. /teile/z4-gt3/bodykit/
 *
 * DREI ARTEN von Themen:
 *   teile      – Teile des Hubs, die zum Thema gehören (Baugruppe ODER Stichwort im Titel; nur ein Teil der Teile
 *                trägt eine Baugruppe, deshalb beides). Alle Treffer auf einer Seite, teuerste zuerst, verkaufte am Ende.
 *   fahrzeuge  – Fahrzeuge (m24_fahrzeug + alte Beiträge), deren Titel zum Modell passt; verfügbare zuerst.
 *   seite      – reine Textseite mit Kontaktknopf (Aufbau & Revision).
 *
 * EINBAU ohne Umbau der Hub-Klasse: eigene Rewrite-Regel + Abfragevariable; die Hub-Ansicht (catalog-hub-view.php)
 * fragt an vier Stellen nach (Filter m24_hub_view_h1 / m24_hub_view_cfg / m24_hub_view_eigene_teile und Aktionen
 * m24_hub_nach_telem / m24_hub_view_teile). Suche, Filter und Sortierung der Übersicht gibt es auf Themenseiten
 * bewusst nicht – sie würden ?kat=/?sort= anhängen (noindex) und das Thema verlassen.
 *
 * SEO: eigener Titel/Beschreibung/Canonical je Thema; index nur, wenn der Hub selbst freigegeben ist UND das Thema
 * Inhalt hat (leere Themen: noindex und kein Reiter). Sitemap: M24_Catalog_Hub_Sitemap nimmt die Themen mit auf.
 *
 * NEUES MODELL: in alle() einen Eintrag ergänzen – sonst nichts. Texte bewusst knapp und ohne erfundene Angaben;
 * Markenrecht: Teile immer „passend für" (Bestimmungsangabe).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Hub_Themen {

	const QV           = 'm24_thema';
	const REWRITE_FLAG = 'm24_hub_themen_rewrite_v1';

	/** hub => [ thema => Angaben ]. Reihenfolge = Reihenfolge der Reiter. */
	public static function alle() {
		static $a = null;
		if ( null !== $a ) { return $a; }
		$a = array(
			'z4-gt3' => array(
				'fahrzeuge' => array(
					'art'   => 'fahrzeuge',
					'label' => 'Fahrzeuge',
					're'    => '/z4\s*gt3/i',
					'h1'    => 'BMW Z4 GT3 kaufen – Fahrzeuge im Angebot',
					'title' => 'BMW Z4 GT3 kaufen – Rennfahrzeuge im Angebot | MOTORSPORT24',
					'desc'  => 'BMW Z4 GT3 zu verkaufen: aktuelle und bereits vermittelte Rennfahrzeuge bei MOTORSPORT24 – mit Historie, Teilepaketen und weltweitem Versand. Seit 2006.',
					'h2'    => 'BMW Z4 GT3 zu verkaufen',
					'text'  => '<p>Hier stehen die BMW Z4 GT3, die wir aktuell anbieten, und darunter Fahrzeuge, die wir bereits vermittelt haben. Zu jedem Fahrzeug gehören die bekannte Historie und der Stand der Technik zum Zeitpunkt des Angebots.</p><p>Kein passendes Fahrzeug dabei? Schreiben Sie uns, welchen Z4 GT3 Sie suchen – nicht jedes Fahrzeug steht öffentlich im Angebot.</p>',
				),
				'bodykit' => array(
					'art'       => 'teile',
					'label'     => 'Bodykit & Karosserie',
					'baugruppe' => 'karosserie',
					're'        => '/bodykit|sto(?:ß|ss)f(?:ä|ae)nger|motorhaube|heckklappe|\bdach\b|diffusor|t(?:ü|ue)ren?\b|splitter|canard|schweller|radlauf|kotfl(?:ü|ue)gel|heckfl(?:ü|ue)gel|rear wing|spiegel|mirror|lexan|armaturenbrett/iu',
					'h1'        => 'Bodykit & Karosserieteile passend für BMW Z4 GT3',
					'title'     => 'BMW Z4 GT3 Bodykit & Karosserieteile kaufen | MOTORSPORT24',
					'desc'      => 'Bodykit und Karosserieteile passend für BMW Z4 GT3: Komplett-Paket, Stoßfänger, Kotflügel, Motorhaube, Türen, Dach, Frontsplitter, Diffusor und Heckflügel. Weltweiter Versand.',
					'h2'        => 'Bodykit und Karosserieteile für den Z4 GT3',
					'text'      => '<p>Vom Komplett-Paket bis zum einzelnen Teil: Stoßfänger, Kotflügel, Motorhaube, Türen, Dach, Schweller, Frontsplitter, Diffusor und Heckflügel passend für den BMW Z4 GT3.</p><p>Gut zu wissen: Das Bodykit selbst umfasst Stoßfänger, Aufsätze und Kotflügel. Für die komplette Außenoptik eines Z4 GT3 kommen mindestens Frontsplitter und Heckdiffusor dazu, in der Regel auch die Motorhaube. Fragen Sie uns nach dem passenden Umfang für Ihr Fahrzeug.</p>',
					'unten' => '<h2>Was zum Bodykit des Z4 GT3 gehört – und was nicht</h2><p>Das Bodykit passend für den BMW Z4 GT3 besteht aus den Stoßfängern vorn und hinten, den Aufsätzen und den Kotflügeln. Damit steht die Grundform des Rennwagens. Für die komplette Außenoptik kommen mindestens der Frontsplitter und der Heckdiffusor dazu, in der Regel auch die Motorhaube. Diese Teile führen wir einzeln – ebenso Türen, Dach, Schweller, Heckklappe und Heckflügel.</p><h3>Einzelteil oder Paket</h3><p>Nach einem Schaden reicht oft ein einzelnes Teil. Bei einem Neuaufbau ist das Paket die bessere Wahl, weil alle Teile zueinander passen. Schreiben Sie uns, was an Ihrem Fahrzeug vorhanden ist und was fehlt – wir stellen den passenden Umfang zusammen und nennen Ihnen einen Gesamtpreis.</p><h3>Verfügbarkeit und Versand</h3><p>Karosserieteile für den Z4 GT3 sind am Markt selten geworden. Wir führen einen eigenen Bestand, von dem nicht jedes Teil online steht. Große Karosserieteile versenden wir transportsicher verpackt und versichert per Spedition, weltweit.</p><h3>Häufige Fragen</h3><p><strong>Ist die Motorhaube im Bodykit enthalten?</strong><br>Nein. Motorhaube, Frontsplitter und Heckdiffusor sind eigene Teile und werden zusätzlich angeboten.</p><p><strong>Kann ich einzelne Teile aus dem Kit kaufen?</strong><br>Ja. Stoßfänger, Kotflügel und Aufsätze gibt es auch einzeln.</p><p><strong>Ich finde ein Teil nicht in der Liste – gibt es das trotzdem?</strong><br>Fragen Sie an. Nicht alles aus unserem Bestand ist online gelistet.</p>',
				),
				'motor-getriebe' => array(
					'art'   => 'teile',
					'label' => 'Motor & Getriebe',
					're'    => '/\bmotor\b|\bp65\b|zylinder|cylinder|pleuel|connecting rod|kolben|piston|nockenwelle|camshaft|(?:ö|oe)lpumpe|oil pump|(?:ö|oe)lwanne|oil pan|hubraum|kr(?:ü|ue)mmer|manifold|k(?:ü|ue)hler|radiator|luftfilter|air filter|getriebe|gearbox|kupplung|clutch|generator|alternator|fahrpedal|gasket|dichtung/iu',
					'h1'    => 'Motor P65 & Getriebe passend für BMW Z4 GT3',
					'title' => 'BMW Z4 GT3 Motor P65 & Getriebe – Teile und Aggregate | MOTORSPORT24',
					'desc'  => 'Motor- und Getriebeteile passend für BMW Z4 GT3: Motor P65, Hubraum-Kit 4,4 l, Kolben, Pleuel, Nockenwellen, Ölpumpe, Krümmer, Xtrac-Getriebe und Kupplung. Weltweiter Versand.',
					'h2'    => 'Antrieb des Z4 GT3: Motor P65 und Getriebe',
					'text'  => '<p>Der BMW Z4 GT3 fährt mit dem V8 P65, der Motorsport-Variante des S65 aus dem M3 (E9x). Hier finden Sie komplette Aggregate und Einzelteile: Motor, Hubraum-Kit, Kolben, Pleuel, Nockenwellen, Ölpumpe, Ölwanne, Abgaskrümmer, Kühler sowie Getriebe und Kupplung.</p><p>Sie suchen ein bestimmtes Teil oder eine Revision von Motor oder Getriebe? Fragen Sie gezielt an – nicht alles aus unserem Bestand steht online.</p>',
					'unten' => '<h2>Motor P65 und Getriebe: Teile, Aggregate, Revision</h2><p>Im BMW Z4 GT3 arbeitet der V8 P65 mit 4,4 Litern Hubraum, die Motorsport-Variante des S65 aus dem M3 (E9x). Auf dieser Seite finden Sie alles rund um den Antrieb: komplette Motoren, das Hubraum-Kit, Kolben, Pleuel, Nockenwellen, Ölpumpe und Ölwanne, Abgaskrümmer und Kühler, dazu Getriebe und Kupplung.</p><h3>Teil, Aggregat oder Revision</h3><p>Sie haben drei Wege: das einzelne Teil für die eigene Werkstatt, ein komplettes Aggregat zum Einbau oder die Revision Ihres vorhandenen Motors oder Getriebes. Welcher Weg sinnvoll ist, hängt vom Zustand ab. Mehr dazu unter <a href="%AUFBAU%">Aufbau &amp; Revision</a>.</p><h3>Was wir für Ihre Anfrage brauchen</h3><p>Je genauer die Angaben, desto schneller die Antwort: Teilenummer, falls vorhanden, ein Foto des gesuchten oder defekten Teils und ein kurzer Hinweis, ob es um Ersatz, Vorrat oder einen Aufbau geht.</p><h3>Häufige Fragen</h3><p><strong>Liefern Sie komplette Motoren und Getriebe?</strong><br>Ja. Neben Einzelteilen bieten wir komplette Aggregate an.</p><p><strong>Sind die Teile geprüft?</strong><br>Ja. Viele Teile stammen aus eigenen Rennsport-Umbauten und werden vor dem Verkauf kontrolliert. Zustand und Teilenummer stehen beim jeweiligen Teil.</p><p><strong>Versenden Sie ins Ausland?</strong><br>Ja, weltweit – Motoren und Getriebe per Spedition, versichert.</p>',
				),
				'fahrwerk-bremse' => array(
					'art'   => 'teile',
					'label' => 'Fahrwerk & Bremse',
					're'    => '/brems|brake|l(?:ä|ae)ngstr(?:ä|ae)ger|trailing arm|radtr(?:ä|ae)ger|wheel carrier|radnabe|wheel hub|felge|\bbbs\b|lenkhilfe|power steering|drehratensensor|sto(?:ß|ss)d(?:ä|ae)mpfer|querlenker|stabilisator/iu',
					'h1'    => 'Fahrwerk, Bremse & Räder passend für BMW Z4 GT3',
					'title' => 'BMW Z4 GT3 Fahrwerk, Bremse & Felgen – Teile | MOTORSPORT24',
					'desc'  => 'Fahrwerks- und Bremsenteile passend für BMW Z4 GT3: Radträger, Längsträger, Radnaben, AP-Racing-Bremsscheiben, Bremssättel, Lenkhilfepumpe und Felgen. Weltweiter Versand.',
					'h2'    => 'Fahrwerk, Bremse und Räder für den Z4 GT3',
					'text'  => '<p>Teile rund um Achsen, Bremse und Räder passend für den BMW Z4 GT3: Radträger, Längsträger, Radnaben mit Lager, Bremsscheiben und Bremssättel, Lenkhilfepumpe sowie Felgen.</p><p>Viele dieser Teile stammen aus eigenen Rennsport-Umbauten und sind vor dem Verkauf geprüft. Zustand und Teilenummer stehen beim jeweiligen Teil.</p>',
					'unten' => '<h2>Fahrwerk, Bremse und Räder des Z4 GT3</h2><p>Achsen, Bremse und Räder sind am Rennwagen Verschleiß- und Unfallteile zugleich. Entsprechend oft werden sie gesucht: Radträger, Längsträger, Radnaben mit Lager, Bremsscheiben und Bremssättel, die Lenkhilfepumpe und Felgen passend für den BMW Z4 GT3.</p><h3>Herkunft und Zustand</h3><p>Viele dieser Teile stammen aus eigenen Rennsport-Umbauten. Vor dem Verkauf werden sie geprüft; Zustand und Teilenummer stehen beim jeweiligen Teil. Wenn Sie Fragen zu einem bestimmten Teil haben, schicken wir Ihnen auf Wunsch weitere Fotos.</p><h3>Mehrere Teile auf einmal</h3><p>Wer eine Achse überholt oder ein Fahrzeug aufbaut, braucht selten nur ein Teil. Schicken Sie uns Ihre Liste – wir prüfen den Bestand und machen ein Angebot für den gesamten Umfang, auch für Teile, die nicht online stehen.</p><h3>Häufige Fragen</h3><p><strong>Sind die Bremsenteile für die Straße zugelassen?</strong><br>Nein. Die Teile sind für den Rennsport bestimmt.</p><p><strong>Gibt es Teile paarweise oder satzweise?</strong><br>Das hängt vom Bestand ab. Stückzahl und Ausführung stehen beim jeweiligen Teil; fragen Sie bei Bedarf nach einem Satz.</p><p><strong>Versenden Sie ins Ausland?</strong><br>Ja, weltweit und versichert.</p>',
				),
				'aufbau-revision' => array(
					'art'   => 'seite',
					'label' => 'Aufbau & Revision',
					'h1'    => 'BMW Z4 GT3 – Aufbau, Motor- und Getrieberevision',
					'title' => 'BMW Z4 GT3 Aufbau, Motor- & Getrieberevision | MOTORSPORT24',
					'desc'  => 'BMW Z4 GT3 aus einer Hand: Komplettaufbau von Fahrzeugen, Revision von Motor P65 und Getriebe, komplette Aggregate und alle Karosserieteile. Anfrage an MOTORSPORT24.',
					'h2'    => 'Z4 GT3 aus einer Hand',
					'text'  => '<p>Der BMW Z4 GT3 ist einer unserer Schwerpunkte. Neben dem großen Teilebestand bieten wir für dieses Fahrzeug:</p><ul><li><strong>Komplettaufbau</strong> von Fahrzeugen</li><li><strong>Motorrevision</strong> des V8 P65 sowie komplette Motoren</li><li><strong>Getrieberevision</strong> sowie komplette Getriebe</li><li><strong>Karosserie</strong>: alle Karosserieteile, einzeln oder als Paket</li></ul><p>Alles kommt aus einer Hand: Die Teile für Antrieb, Fahrwerk, Bremse und Karosserie führen wir im eigenen Bestand, viele davon aus eigenen Rennsport-Umbauten. Das verkürzt Wege, weil Teilebeschaffung und Arbeit nicht bei verschiedenen Stellen liegen.</p><p><strong>So läuft es ab:</strong> Sie schildern uns Fahrzeug, Zustand und Ziel – am besten mit Fotos. Wir prüfen, was nötig und was sinnvoll ist, und melden uns mit einem konkreten Vorschlag zu Umfang und Preis. Erst danach entscheiden Sie.</p><p>Umfang, Zeitrahmen und Preis hängen vom Fahrzeug und vom Zustand der Aggregate ab.</p>',
					'unten' => '<h2>Häufige Fragen zu Aufbau und Revision</h2><p><strong>Kann ich nur den Motor oder nur das Getriebe revidieren lassen?</strong><br>Ja. Motor und Getriebe werden einzeln revidiert; ein Komplettaufbau ist dafür nicht nötig.</p><p><strong>Liefern Sie statt einer Revision auch ein komplettes Aggregat?</strong><br>Ja. Wir bieten komplette Motoren und komplette Getriebe an. Was wirtschaftlicher ist, hängt vom Zustand Ihres Aggregats ab.</p><p><strong>Was kostet eine Revision?</strong><br>Das lässt sich erst nach Sichtung sagen, weil Umfang und Teilebedarf vom Zustand abhängen. Sie erhalten vorab einen konkreten Vorschlag mit Umfang und Preis.</p><p><strong>Bauen Sie komplette Fahrzeuge auf?</strong><br>Ja. Der Komplettaufbau gehört zu unseren Leistungen rund um den Z4 GT3 – einschließlich aller Karosserieteile.</p><p><strong>Bekomme ich die Teile für einen Aufbau aus einer Hand?</strong><br>Ja. Bodykit und Karosserieteile, Antrieb, Fahrwerk und Bremse führen wir im eigenen Bestand; nicht alles davon steht online.</p>',
				),
			),
		);
		$a = (array) apply_filters( 'm24_hub_themen', $a );
		return $a;
	}

	public static function fuer( $hub ) { $a = self::alle(); return isset( $a[ $hub ] ) ? $a[ $hub ] : array(); }

	/** Aktives Thema (validiert gegen den aktuellen Hub) oder ''. */
	public static function aktiv() {
		if ( ! class_exists( 'M24_Catalog_Hub' ) ) { return ''; }
		$t = get_query_var( self::QV );
		$h = M24_Catalog_Hub::current();
		return ( is_string( $t ) && '' !== $t && '' !== $h && isset( self::fuer( $h )[ $t ] ) ) ? $t : '';
	}

	public static function url( $hub, $thema = '' ) {
		return M24_Catalog_Hub::url( $hub ) . ( '' !== $thema ? $thema . '/' : '' );
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ), 21 );
		add_filter( 'rewrite_rules_array', array( __CLASS__, 'prepend_rule' ), 20 ); // nach der Hub-Regel einsortiert ⇒ steht davor
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_flush' ) );
		// Ansicht
		add_filter( 'm24_hub_view_h1', array( __CLASS__, 'view_h1' ), 10, 2 );
		add_filter( 'm24_hub_view_cfg', array( __CLASS__, 'view_cfg' ), 10, 2 );
		add_filter( 'm24_hub_view_eigene_teile', array( __CLASS__, 'view_eigen' ), 10, 2 );
		add_action( 'm24_hub_nach_telem', array( __CLASS__, 'reiter' ) );
		add_action( 'm24_hub_view_teile', array( __CLASS__, 'inhalt' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'formular_assets' ), 30 );
		// SEO – nach der Hub-Klasse (99)
		add_filter( 'wpseo_set_title',     array( __CLASS__, 'seo_title' ), 100 );
		add_filter( 'wpseo_set_desc',      array( __CLASS__, 'seo_desc' ), 100 );
		add_filter( 'wpseo_set_robots',    array( __CLASS__, 'seo_robots' ), 100 );
		add_filter( 'wpseo_set_canonical', array( __CLASS__, 'seo_canonical' ), 100 );
		add_filter( 'document_title_parts', array( __CLASS__, 'doc_title' ), 20 );
	}

	private static function regel() {
		$r = array();
		foreach ( self::alle() as $hub => $themen ) {
			if ( ! $themen ) { continue; }
			$r[ '^' . M24_Catalog_Hub::BASE . '/(' . preg_quote( $hub, '#' ) . ')/(' . implode( '|', array_map( 'preg_quote', array_keys( $themen ) ) ) . ')/?$' ]
				= 'index.php?' . M24_Catalog_Hub::QV . '=$matches[1]&' . self::QV . '=$matches[2]';
		}
		return $r;
	}

	public static function add_rewrite() {
		foreach ( self::regel() as $re => $ziel ) { add_rewrite_rule( $re, $ziel, 'top' ); }
	}

	public static function prepend_rule( $rules ) { return self::regel() + (array) $rules; }

	public static function query_vars( $vars ) { $vars[] = self::QV; return $vars; }

	public static function maybe_flush() {
		$soll = self::REWRITE_FLAG . '_' . md5( wp_json_encode( array_keys( self::regel() ) ) );
		if ( get_option( self::REWRITE_FLAG ) === $soll ) { return; }
		self::add_rewrite();
		flush_rewrite_rules( false );
		update_option( self::REWRITE_FLAG, $soll );
	}

	// ── Daten ──────────────────────────────────────────────────────────

	/** Teile-IDs eines Themas: aktive zuerst (teuerste zuerst, wie die Übersicht), dann wenige verkaufte. */
	public static function teile_ids( $hub, $thema ) {
		static $cache = array();
		$k = $hub . '|' . $thema;
		if ( isset( $cache[ $k ] ) ) { return $cache[ $k ]; }
		$t = self::fuer( $hub )[ $thema ] ?? array();
		if ( empty( $t ) || 'teile' !== $t['art'] ) { return $cache[ $k ] = array(); }
		$alle = M24_Catalog_Hub::ordered_ids( $hub, 'preis-ab', '', 'alle' );
		$aus  = array();
		foreach ( $alle as $id ) {
			$ok = ! empty( $t['re'] ) && preg_match( $t['re'], html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) );
			if ( ! $ok && ! empty( $t['baugruppe'] ) && has_term( $t['baugruppe'], 'm24_baugruppe', $id ) ) { $ok = true; }
			if ( $ok ) { $aus[] = (int) $id; }
		}
		return $cache[ $k ] = $aus;
	}

	/** Fahrzeuge eines Themas: [ [id, status] ], verfügbare zuerst. */
	public static function fahrzeuge( $hub, $thema ) {
		static $cache = array();
		$k = $hub . '|' . $thema;
		if ( isset( $cache[ $k ] ) ) { return $cache[ $k ]; }
		$t = self::fuer( $hub )[ $thema ] ?? array();
		if ( empty( $t ) || 'fahrzeuge' !== $t['art'] || empty( $t['re'] ) ) { return $cache[ $k ] = array(); }
		$typen = array_values( array_filter( array( 'm24_fahrzeug' ), 'post_type_exists' ) );
		if ( ! $typen ) { return $cache[ $k ] = array(); }
		$ids = get_posts( array( 'post_type' => $typen, 'post_status' => 'publish', 'posts_per_page' => 300, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => true ) );
		$da = array(); $weg = array();
		foreach ( $ids as $id ) {
			if ( ! preg_match( $t['re'], html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) ) || ! has_post_thumbnail( $id ) ) { continue; }
			$st = class_exists( 'M24FZ_CPT' ) ? (string) M24FZ_CPT::status( $id ) : 'gelistet';
			if ( 'verkauft' === $st ) { $weg[] = array( (int) $id, $st ); } else { $da[] = array( (int) $id, $st ); }
		}
		return $cache[ $k ] = array_merge( $da, $weg );
	}

	/** Hat das Thema Inhalt? (Reiter und Index nur dann.) */
	public static function hat_inhalt( $hub, $thema ) {
		$t = self::fuer( $hub )[ $thema ] ?? array();
		if ( empty( $t ) ) { return false; }
		if ( 'teile' === $t['art'] ) { return (bool) self::teile_ids( $hub, $thema ); }
		// Fahrzeuge-Reiter nur, wenn aktuell ein Fahrzeug zum Verkauf steht (Daniel 22.11.2026) – sonst kein Reiter, noindex, nicht in der Sitemap.
		if ( 'fahrzeuge' === $t['art'] ) { return (bool) array_filter( self::fahrzeuge( $hub, $thema ), function ( $f ) { return 'verkauft' !== $f[1]; } ); }
		return true;
	}

	// ── Ansicht ────────────────────────────────────────────────────────

	public static function view_h1( $h1, $hub ) {
		$t = self::aktiv();
		return '' !== $t ? self::fuer( $hub )[ $t ]['h1'] : $h1;
	}

	/** Einleitung der Themenseite statt der Hub-Einleitung; der lange SEO-Text des Hubs bleibt der Übersicht vorbehalten. */
	public static function view_cfg( $cfg, $hub ) {
		$t = self::aktiv();
		if ( '' === $t ) { return $cfg; }
		$d = self::fuer( $hub )[ $t ];
		$cfg['intro_h2']      = $d['h2'];
		$cfg['intro_html']    = $d['text'];
		$cfg['seo_text_html'] = isset( $d['unten'] ) ? str_replace( '%AUFBAU%', esc_url( self::url( $hub, 'aufbau-revision' ) ), (string) $d['unten'] ) : '';
		$cfg['cross_links']   = array(); // sonst hängt die Ansicht den allgemeinen „laufend wechselnder Bestand"-Text unter die Themenseite (Befund live 22.11.2026)
		return $cfg;
	}

	public static function view_eigen( $eigen, $hub ) { return '' !== self::aktiv() ? true : $eigen; }

	/** Reiterleiste unter der Datenzeile – auf der Übersicht und auf jeder Themenseite. */
	/** Themenseite mit Anfrageformular: Stil/Skript des Kontaktformulars früh einbinden (kein ungestylter Aufbau). */
	public static function formular_assets() {
		$t = self::aktiv();
		if ( '' === $t ) { return; }
		$d = self::fuer( M24_Catalog_Hub::current() )[ $t ] ?? array();
		if ( empty( $d ) || 'seite' !== $d['art'] ) { return; }
		foreach ( array( 'm24-inquiry-fields', 'm24-kontakt' ) as $h ) {
			if ( wp_style_is( $h, 'registered' ) ) { wp_enqueue_style( $h ); }
			if ( wp_script_is( $h, 'registered' ) ) { wp_enqueue_script( $h ); }
		}
	}

	public static function reiter( $hub ) {
		$themen = self::fuer( $hub );
		if ( ! $themen ) { return; }
		$akt   = self::aktiv();
		$punkte = array( array( '', 'Übersicht' ) );
		foreach ( $themen as $slug => $d ) { if ( self::hat_inhalt( $hub, $slug ) ) { $punkte[] = array( $slug, $d['label'] ); } }
		if ( count( $punkte ) < 2 ) { return; }
		echo '<style>.m24hub-reiter{border-bottom:2px solid #15181d;margin:26px 0 0}.m24hub-reiter .m24hub-wrap{display:flex;gap:0;overflow-x:auto;scrollbar-width:none}.m24hub-reiter a{padding:14px 20px;font-weight:700;font-size:14px;line-height:1;color:#15181d;text-decoration:none;white-space:nowrap;border-radius:6px 6px 0 0}.m24hub-reiter a:hover{background:#eceef1}.m24hub-reiter a.an{background:#15181d;color:#fff}'
			. '.m24hub-fz{display:grid;grid-template-columns:repeat(4,1fr);gap:22px}.m24hub-fz a{display:block;background:#fff;border:1px solid #e3e6ea;border-radius:8px;overflow:hidden;text-decoration:none;color:#15181d}.m24hub-fz a:hover{border-color:#b8892d}.m24hub-fz .b{display:block;aspect-ratio:3/2;background:#e9ecef center/cover no-repeat;position:relative}.m24hub-fz .s{position:absolute;left:10px;top:10px;background:#15181d;color:#fff;font-size:10.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:6px 8px;border-radius:3px}.m24hub-fz .s.da{background:#b8892d}.m24hub-fz .t{display:block;padding:14px 14px 16px;font-weight:700;font-size:15px;line-height:1.3}.m24hub-fz-h{font-size:13px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#7a828c;margin:34px 0 14px}.m24hub-fz-h:first-child{margin-top:0}'
			. '.m24hub-cta{background:#15181d;color:#fff;border-radius:8px;padding:26px 28px;display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap}.m24hub-cta b{font-size:19px}.m24hub-cta span{display:block;color:#aeb6bf;font-size:14px;margin-top:4px}.m24hub-cta a{background:#b8892d;color:#fff;font-weight:700;padding:13px 20px;border-radius:5px;text-decoration:none;white-space:nowrap}'
			. '.m24hub-aq{margin-top:40px;background:#15181d;border-radius:10px;padding:44px 44px 44px 48px;display:grid;grid-template-columns:5fr 6fr;gap:48px;align-items:start;color:#fff;scroll-margin-top:110px}.m24hub-aq>*{min-width:0}.m24hub-aq small{display:block;font-size:12px;font-weight:700;letter-spacing:.14em;color:#b8892d;text-transform:uppercase}.m24hub-aq h2{color:#fff;font-size:30px;line-height:1.15;margin:12px 0 14px}.m24hub-aq p{color:#c3cad2;font-size:16px;line-height:1.6;margin:0 0 20px}.m24hub-aq ul{list-style:none!important;margin:0 0 24px!important;padding:0!important}.m24hub-aq li{position:relative;margin:0!important;padding:9px 0 9px 30px;border-top:1px solid #2a2f37;font-size:15px;color:#fff;list-style:none!important}.m24hub-aq li:before{content:"\\2713";position:absolute;left:0;color:#b8892d;font-weight:700}.m24hub-aq .ml{font-size:14px;color:#aeb6bf}.m24hub-aq .ml a{color:#fff;font-weight:700}.m24hub-aq .fk{background:#fff;border-radius:8px;padding:28px 28px 22px;color:#15181d}.m24hub-aq .fk .m24kf{margin:0;max-width:none}'
			. '.m24hub .m24hub-seo h3{font-size:16.5px;font-weight:700;margin:22px 0 6px}'
			. '@media(max-width:900px){.m24hub-aq{grid-template-columns:1fr;gap:28px;padding:30px 22px}.m24hub-aq h2{font-size:25px}.m24hub-aq .fk{padding:20px 16px}}'
			. '@media(max-width:900px){.m24hub-fz{grid-template-columns:repeat(2,1fr)}}@media(max-width:560px){.m24hub-fz{grid-template-columns:1fr}.m24hub-reiter a{padding:13px 14px}}</style>';
		echo '<nav class="m24hub-reiter" aria-label="Themen"><div class="m24hub-wrap">';
		foreach ( $punkte as $p ) {
			printf( '<a href="%s"%s>%s</a>', esc_url( self::url( $hub, $p[0] ) ), $p[0] === $akt ? ' class="an" aria-current="page"' : '', esc_html( $p[1] ) );
		}
		echo '</div></nav>';
	}

	/** Inhalt der Themenseite an Stelle der Teileliste mit Suche/Filter. */
	public static function inhalt( $hub ) {
		$thema = self::aktiv();
		if ( '' === $thema ) { return; }
		$d = self::fuer( $hub )[ $thema ];
		echo '<section class="m24hub-parts"><div class="m24hub-wrap">';
		if ( 'teile' === $d['art'] ) {
			$ids = self::teile_ids( $hub, $thema );
			echo '<div class="head"><h2>' . esc_html( $d['label'] ) . '</h2><span class="count">' . esc_html( sprintf( _n( '%s Teil', '%s Teile', count( $ids ), 'm24-plattform' ), number_format_i18n( count( $ids ) ) ) ) . '</span></div>';
			if ( $ids ) {
				$q = new WP_Query( array( 'post_type' => 'm24_teil', 'post_status' => 'publish', 'post__in' => $ids, 'orderby' => 'post__in', 'posts_per_page' => count( $ids ), 'no_found_rows' => true ) );
				echo '<div class="m24hub-grid view-4">' . M24_Catalog_Hub::cards_html( array( 'query' => $q, 'hub' => $hub, 'q' => '' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '<p style="margin:26px 0 0"><a href="' . esc_url( self::url( $hub ) ) . '">Alle Teile passend für dieses Modell ansehen →</a></p>';
		} elseif ( 'fahrzeuge' === $d['art'] ) {
			$fz = self::fahrzeuge( $hub, $thema );
			$gruppe = function ( $titel, $liste ) {
				if ( ! $liste ) { return; }
				echo '<div class="m24hub-fz-h">' . esc_html( $titel ) . '</div><div class="m24hub-fz">';
				foreach ( $liste as $f ) {
					$bild = get_the_post_thumbnail_url( $f[0], 'medium_large' );
					$da   = 'verkauft' !== $f[1];
					printf( '<a href="%s"><span class="b" style="background-image:url(%s)"><span class="s%s">%s</span></span><span class="t">%s</span></a>',
						esc_url( get_permalink( $f[0] ) ), esc_url( (string) $bild ), $da ? ' da' : '', esc_html( 'reserviert' === $f[1] ? 'Reserviert' : ( $da ? 'Verfügbar' : 'Verkauft' ) ), esc_html( get_the_title( $f[0] ) ) );
				}
				echo '</div>';
			};
			$gruppe( 'Aktuell im Angebot', array_values( array_filter( $fz, function ( $f ) { return 'verkauft' !== $f[1]; } ) ) );
			$gruppe( 'Bereits vermittelt', array_values( array_filter( $fz, function ( $f ) { return 'verkauft' === $f[1]; } ) ) );
		}
		if ( 'seite' === $d['art'] && class_exists( 'M24_Kontakt_Form' ) ) {
			// Anfrage-Block mit Formular direkt auf der Seite (Daniel 22.11.2026, Vorschlag A).
			echo '<div class="m24hub-aq" id="anfrage"><div><small>Anfrage</small><h2>Revision oder Teile anfragen</h2><p>Sagen Sie uns, was Sie vorhaben – Motor- oder Getrieberevision, Komplettaufbau oder ein bestimmtes Teil. Wir antworten persönlich.</p>'
				. '<ul><li>Motorrevision P65 und komplette Motoren</li><li>Getrieberevision und komplette Getriebe</li><li>Komplettaufbau von Fahrzeugen</li><li>Teile aus unserem Bestand, auch nicht online gelistete</li></ul>'
				. '<div class="ml">Lieber per E-Mail? <a href="mailto:service@motorsport24.de">service@motorsport24.de</a></div></div>'
				. '<div class="fk">' . M24_Kontakt_Form::shortcode( array( 'themen' => 'revision,aufbau,teile,sonstiges' ) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( 'teile' !== $d['art'] ) {
			$kontakt = home_url( '/kontakt/' );
			echo '<div class="m24hub-cta" style="margin-top:34px"><div><b>' . esc_html( 'seite' === $d['art'] ? 'Projekt besprechen' : 'Sie suchen einen bestimmten Z4 GT3?' ) . '</b><span>Schreiben Sie uns – wir antworten persönlich.</span></div><a href="' . esc_url( $kontakt ) . '">Anfrage senden</a></div>';
		}
		echo '</div></section>';
	}

	// ── SEO ────────────────────────────────────────────────────────────

	private static function d() {
		$t = self::aktiv();
		return '' !== $t ? self::fuer( M24_Catalog_Hub::current() )[ $t ] : null;
	}
	public static function seo_title( $v )  { $d = self::d(); return $d ? $d['title'] : $v; }
	public static function seo_desc( $v )   { $d = self::d(); return $d ? $d['desc'] : $v; }
	public static function doc_title( $p )  { $d = self::d(); if ( $d ) { $p['title'] = $d['h1']; } return $p; }
	public static function seo_canonical( $v ) { $t = self::aktiv(); return '' !== $t ? self::url( M24_Catalog_Hub::current(), $t ) : $v; }
	public static function seo_robots( $v ) {
		$t = self::aktiv();
		if ( '' === $t ) { return $v; }
		$hub = M24_Catalog_Hub::current();
		// Parameter an der Themen-Adresse (?kat= usw.) oder leeres Thema: nicht in den Index.
		if ( ! empty( $_GET ) || ! self::hat_inhalt( $hub, $t ) ) { return 'noindex, follow'; } // phpcs:ignore WordPress.Security.NonceVerification
		$allow = (array) apply_filters( 'm24_indexable_hub_slugs', array( 'e36', 'z4-gt3' ) );
		return in_array( $hub, $allow, true ) ? 'index, follow' : 'noindex, follow';
	}

	/** Für die Sitemap: Adressen aller Themen mit Inhalt eines Hubs. */
	public static function sitemap_urls( $hub ) {
		$aus = array();
		foreach ( array_keys( self::fuer( $hub ) ) as $t ) { if ( self::hat_inhalt( $hub, $t ) ) { $aus[] = self::url( $hub, $t ); } }
		return $aus;
	}
}
M24_Hub_Themen::init();

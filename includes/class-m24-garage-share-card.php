<?php
/**
 * M24 Plattform — Vorschaubild der geteilten Garage (og:image für WhatsApp, E-Mail, iMessage …).
 *
 * AUFTRAG (Daniel, 20.10.2026): „an das Vorschaubild denken, welches per WhatsApp, E-Mail usw. dargestellt wird —
 * etwas zusammen mit dem MOTORSPORT24-Logo und dem Herobild aus dem ersten in der Garage befindlichen Artikel im
 * Querformat." Zwei Stile zur Auswahl (Option m24_garage_card_stil, Standard „a"):
 *   a  Foto vollflächig, unten dunkles Band mit Logo, Titel und Positionszahl
 *   b  Foto links (58 %), rechts dunkle Tafel mit Logo, Titel, Positionszahl und Garagen-Nr.
 *
 * Endpunkt: /?m24garage_card=TOKEN(&stil=a|b)(&neu=1) → JPEG 1200×630. Datei liegt unter uploads/m24-garage-cards/
 * und wird neu gebaut, sobald der Snapshot jünger ist. GD, Liberation Sans (assets/fonts).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Garage_Share_Card {

	const QUERY  = 'm24garage_card';
	const OPTION = 'm24_garage_card_stil';
	const W = 1200;
	const H = 630;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 1 );
		add_filter( 'm24_garage_og_image', array( __CLASS__, 'og_image' ), 10, 2 );
	}

	public static function stil(): string { $s = (string) get_option( self::OPTION, 'a' ); return in_array( $s, array( 'a', 'b' ), true ) ? $s : 'a'; }

	/** og:image der Share-Ansicht → unsere Karte (mit Zeitstempel des Snapshots als Cache-Brecher). */
	public static function og_image( $img, $token ) {
		if ( '' === (string) $token || ! function_exists( 'imagecreatetruecolor' ) ) { return $img; }
		$snap = M24_Garage_Cart::read_snapshot( (string) $token );
		if ( ! $snap || ( empty( $snap['items'] ) && empty( $snap['vehicles'] ) ) ) { return $img; }
		$v = ! empty( $snap['created_at'] ) ? (string) strtotime( (string) $snap['created_at'] ) : '0';
		return add_query_arg( array( self::QUERY => rawurlencode( (string) $token ), 'stil' => self::stil(), 'v' => $v ), home_url( '/' ) );
	}

	public static function maybe_serve() {
		if ( is_admin() || empty( $_GET[ self::QUERY ] ) ) { return; } // phpcs:ignore
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ); // phpcs:ignore
		$stil  = isset( $_GET['stil'] ) && 'b' === $_GET['stil'] ? 'b' : ( isset( $_GET['stil'] ) ? 'a' : self::stil() ); // phpcs:ignore
		$neu   = ! empty( $_GET['neu'] ) && current_user_can( 'manage_options' ); // phpcs:ignore
		$file  = self::build( $token, $stil, $neu );
		if ( '' === $file ) { status_header( 404 ); exit; }
		while ( ob_get_level() > 0 ) { ob_end_clean(); } // Whitespace anderer Dateien vor den JPEG-Bytes verwerfen
		nocache_headers();
		header( 'Content-Type: image/jpeg' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		readfile( $file ); // phpcs:ignore
		exit;
	}

	/** Karte bauen (oder aus dem Cache) → Dateipfad, '' bei Fehler. */
	public static function build( string $token, string $stil, bool $force = false ): string {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! class_exists( 'M24_Garage_Cart' ) ) { return ''; }
		$snap = M24_Garage_Cart::read_snapshot( $token );
		if ( ! $snap ) { return ''; }
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'm24-garage-cards/';
		if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
		$ts   = ! empty( $snap['created_at'] ) ? (int) strtotime( (string) $snap['created_at'] ) : 0;
		$file = $dir . substr( md5( $token ), 0, 16 ) . '-' . $stil . '-' . $ts . '.jpg';
		if ( ! $force && is_file( $file ) ) { return $file; }
		foreach ( glob( $dir . substr( md5( $token ), 0, 16 ) . '-*.jpg' ) ?: array() as $alt ) { @unlink( $alt ); } // phpcs:ignore

		$items = array_values( (array) ( $snap['items'] ?? array() ) );
		$veh   = array_values( (array) ( $snap['vehicles'] ?? array() ) );
		$first = $items ? (array) $items[0] : ( $veh ? (array) $veh[0] : array() );
		$n     = count( $items ) + count( $veh );
		$opts  = class_exists( 'M24_Garage_Share_Opts' ) ? M24_Garage_Share_Opts::from_snapshot( $snap ) : array( 'lang' => 'de', 'tax' => 'brutto' );
		$en    = 'en' === $opts['lang'];
		$acc   = M24_Garage_Cart::resolve_share_token( $token );
		$gno   = $acc > 0 ? M24_Garage_Cart::garage_no( $acc, false ) : '';

		$hero = self::hero_image( $first );
		$img  = imagecreatetruecolor( self::W, self::H );
		$navy = imagecolorallocate( $img, 14, 36, 64 );
		$blue = imagecolorallocate( $img, 31, 116, 196 );
		$white = imagecolorallocate( $img, 255, 255, 255 );
		$grey  = imagecolorallocate( $img, 190, 200, 212 );
		imagefill( $img, 0, 0, $navy );
		// Hero-Schrift der Seite (Daniel, 20.10.2026): Saira (OFL), statische Schnitte aus der Google-Fonts-Variable.
		$bold = M24_PLATTFORM_DIR . 'assets/fonts/Saira-Bold.ttf';
		$reg  = M24_PLATTFORM_DIR . 'assets/fonts/Saira-Regular.ttf';
		if ( ! is_readable( $bold ) ) { $bold = M24_PLATTFORM_DIR . 'assets/fonts/LiberationSans-Bold.ttf'; }
		if ( ! is_readable( $reg ) )  { $reg  = M24_PLATTFORM_DIR . 'assets/fonts/LiberationSans-Regular.ttf'; }
		$title = $en ? 'Your racing parts selection' : 'Deine Rennsport-Teile Auswahl'; // Daniel, 20.10.2026 (Schrift: fette Grotesk, Liberation Sans Bold = Helvetica-Metrik)
		$sub   = $en ? ( $n . ( 1 === $n ? ' item' : ' items' ) . ' · prepared for you' ) : ( $n . ( 1 === $n ? ' Position' : ' Positionen' ) . ' · zusammengestellt für Dich' );
		$first_title = (string) ( $first['title'] ?? '' );

		if ( 'b' === $stil ) {
			// ── B: Foto links, Tafel rechts ──
			$pw = 696;
			if ( $hero ) { self::cover( $img, $hero, 0, 0, $pw, self::H ); }
			// weicher Übergang zur Tafel
			for ( $x = $pw - 60; $x < $pw; $x++ ) { $a = (int) round( 127 * ( 1 - ( $pw - $x ) / 60 ) ); imageline( $img, $x, 0, $x, self::H, imagecolorallocatealpha( $img, 14, 36, 64, 127 - $a ) ); }
			imagefilledrectangle( $img, $pw, 0, self::W, self::H, $navy );
			imagefilledrectangle( $img, $pw, 0, $pw + 8, self::H, $blue ); // blauer Steg
			$x = $pw + 48;
			self::logo( $img, $x, 56, 380 );
			$y = self::text( $img, $bold, 34, $white, $x, 290, $title, 440, 42 );
			$y = self::text( $img, $reg, 20, $grey, $x, $y + 44, $sub, 440, 27 );
			if ( '' !== $first_title ) { self::text( $img, $reg, 19, $white, $x, $y + 50, self::shorten( $first_title, 70 ), 440, 26 ); }
			if ( '' !== $gno ) { self::text( $img, $bold, 20, $blue, $x, 572, ( $en ? 'Garage no. ' : 'Garagen-Nr. ' ) . $gno, 440 ); }
		} else {
			// ── A (Daniel, 20.10.2026, wie auf der Facebook-Seite): Foto vollflächig; untere 20 % schwarz mit ~50 % Deckkraft,
			//    darüber bis 45 % von unten ausfaden; Schrift über dem dunklen Teil; Logo oben links. Kein blaues Band.
			if ( $hero ) { self::cover( $img, $hero, 0, 0, self::W, self::H ); }
			imagealphablending( $img, true );
			$solid_from = (int) round( self::H * 0.80 ); // ab 80 % Höhe (= untere 20 %) volle 50 %
			$fade_from  = (int) round( self::H * 0.55 ); // bei 45 % von unten beginnt der Verlauf (0 %)
			for ( $y = $fade_from; $y < self::H; $y++ ) {
				$t = $y >= $solid_from ? 1.0 : ( $y - $fade_from ) / max( 1, $solid_from - $fade_from );
				$alpha = (int) round( 127 - 64 * $t ); // 127 = unsichtbar … 63 = ~50 % Schwarz
				imageline( $img, 0, $y, self::W, $y, imagecolorallocatealpha( $img, 0, 0, 0, max( 0, min( 127, $alpha ) ) ) );
			}
			// leichter Schatten oben links fürs Logo
			for ( $y = 0; $y < 130; $y++ ) { $a = (int) round( 127 - 45 * ( 1 - $y / 130 ) ); imageline( $img, 0, $y, self::W, $y, imagecolorallocatealpha( $img, 0, 0, 0, max( 0, min( 127, $a ) ) ) ); }
			// Logo dezenter (Daniel, 20.10.2026): kleiner, Abstand oben = Abstand des Texts unten (38 px).
			self::logo( $img, 66, 25, 240 ); // 25 px oben, 60 px links = bündig mit dem Link-Titel in der Mail-Vorschau (Daniel, 20.10.2026)
			self::text( $img, $bold, 44, $white, 66, 548, $title, 1100 );
			self::text( $img, $reg, 21, $grey, 66, 592, $sub, 1100 ); // Garagen-Nr. steht im Link-Titel (og:title), nicht doppelt im Bild
		}
		if ( $hero ) { imagedestroy( $hero ); }
		imagejpeg( $img, $file, 88 );
		imagedestroy( $img );
		return is_file( $file ) ? $file : '';
	}

	/** Herobild des ersten Artikels als GD-Bild (Beitragsbild groß, sonst eingefrorene Bild-URL). */
	private static function hero_image( array $first ) {
		$aid = (int) ( $first['article_id'] ?? 0 );
		$path = '';
		if ( $aid > 0 ) {
			$tid = (int) get_post_thumbnail_id( $aid );
			if ( $tid ) { $path = (string) get_attached_file( $tid ); if ( '' !== $path && ! is_readable( $path ) ) { $path = ''; } }
		}
		if ( '' === $path ) {
			$url = (string) ( $first['image_url'] ?? '' );
			if ( '' !== $url ) {
				$tmp = download_url( $url, 20 );
				if ( ! is_wp_error( $tmp ) ) { $path = $tmp; }
			}
		}
		if ( '' === $path ) { return null; }
		$im = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore
		if ( strpos( $path, get_temp_dir() ) === 0 ) { @unlink( $path ); } // phpcs:ignore
		return $im ?: null;
	}

	/** Bild formatfüllend (cover) in ein Rechteck. */
	private static function cover( $dst, $src, int $x, int $y, int $w, int $h ): void {
		$sw = imagesx( $src ); $sh = imagesy( $src );
		$scale = max( $w / $sw, $h / $sh );
		$cw = (int) round( $w / $scale ); $ch = (int) round( $h / $scale );
		$sx = (int) max( 0, ( $sw - $cw ) / 2 ); $sy = (int) max( 0, ( $sh - $ch ) / 2 );
		imagecopyresampled( $dst, $src, $x, $y, $sx, $sy, $w, $h, $cw, $ch );
	}

	/** Weißes Logo (assets/img/m24-logo-weiss.png, von Daniel 20.10.2026) in gewünschter Breite. */
	private static function logo( $dst, int $x, int $y, int $w ): void {
		$p = M24_PLATTFORM_DIR . 'assets/img/m24-logo-weiss.png';
		if ( ! is_readable( $p ) ) { return; }
		$l = imagecreatefrompng( $p );
		if ( ! $l ) { return; }
		imagealphablending( $l, false ); imagesavealpha( $l, true );
		$lw = imagesx( $l ); $lh = imagesy( $l );
		// Leerraum der PNG abschneiden (die Datei hat oben/unten transparente Ränder) → $y ist der echte Abstand zum Rand.
		$top = 0; $bot = $lh - 1;
		while ( $top < $lh && self::row_empty( $l, $top, $lw ) ) { $top++; }
		while ( $bot > $top && self::row_empty( $l, $bot, $lw ) ) { $bot--; }
		$ch = max( 1, $bot - $top + 1 );
		$h  = (int) round( $ch * $w / $lw );
		imagealphablending( $dst, true );
		imagecopyresampled( $dst, $l, $x, $y, 0, $top, $w, $h, $lw, $ch );
		imagedestroy( $l );
	}

	private static function row_empty( $im, int $y, int $w ): bool {
		for ( $x = 0; $x < $w; $x += 2 ) { if ( ( ( imagecolorat( $im, $x, $y ) >> 24 ) & 0x7F ) < 120 ) { return false; } }
		return true;
	}

	/** Text mit Umbruch in eine Breite; $lh = Zeilenhöhe. */
	/** Liefert die Grundlinie der letzten gezeichneten Zeile. */
	private static function text( $img, string $font, int $size, int $col, int $x, int $y, string $txt, int $maxw, int $lh = 0 ): int {
		if ( ! is_readable( $font ) ) { imagestring( $img, 5, $x, $y - 14, $txt, $col ); return $y; }
		$lh = $lh ?: (int) round( $size * 1.3 );
		$words = preg_split( '/\s+/u', trim( $txt ) ); $line = '';
		foreach ( $words as $w ) {
			$try = '' === $line ? $w : $line . ' ' . $w;
			$box = imagettfbbox( $size, 0, $font, $try );
			if ( ( $box[2] - $box[0] ) > $maxw && '' !== $line ) { imagettftext( $img, $size, 0, $x, $y, $col, $font, $line ); $y += $lh; $line = $w; }
			else { $line = $try; }
		}
		if ( '' !== $line ) { imagettftext( $img, $size, 0, $x, $y, $col, $font, $line ); }
		return $y;
	}

	private static function shorten( string $s, int $n ): string { return mb_strlen( $s ) > $n ? rtrim( mb_substr( $s, 0, $n - 1 ) ) . '…' : $s; }
}

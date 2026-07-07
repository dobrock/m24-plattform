/**
 * M24 Country Flags (Ansatz A: ISO2 + Regional-Indicator-Codepoints) — window.M24Country.
 * Verändert die Eingabe NIE; leitet nur ISO2 + Flagge ab. Spiegelt M24_Country_Flags (PHP).
 */
(function () {
	'use strict';
	var ALIAS = {
		'USA': 'US', 'U.S.A.': 'US', 'UNITED STATES': 'US', 'VEREINIGTE STAATEN': 'US', 'AMERIKA': 'US',
		'UK': 'GB', 'U.K.': 'GB', 'ENGLAND': 'GB', 'GROSSBRITANNIEN': 'GB', 'GREAT BRITAIN': 'GB',
		'UNITED KINGDOM': 'GB', 'VEREINIGTES KÖNIGREICH': 'GB', 'VEREINIGTES KOENIGREICH': 'GB',
		'DEUTSCHLAND': 'DE', 'GERMANY': 'DE', 'BRD': 'DE', 'ÖSTERREICH': 'AT', 'OESTERREICH': 'AT', 'AUSTRIA': 'AT',
		'SCHWEIZ': 'CH', 'SWITZERLAND': 'CH', 'SUISSE': 'CH', 'FRANKREICH': 'FR', 'FRANCE': 'FR',
		'ITALIEN': 'IT', 'ITALY': 'IT', 'SPANIEN': 'ES', 'SPAIN': 'ES', 'NIEDERLANDE': 'NL', 'NETHERLANDS': 'NL',
		'HOLLAND': 'NL', 'BELGIEN': 'BE', 'BELGIUM': 'BE', 'LUXEMBURG': 'LU', 'POLEN': 'PL', 'POLAND': 'PL',
		'TSCHECHIEN': 'CZ', 'CZECHIA': 'CZ', 'DÄNEMARK': 'DK', 'DAENEMARK': 'DK', 'DENMARK': 'DK',
		'SCHWEDEN': 'SE', 'SWEDEN': 'SE', 'NORWEGEN': 'NO', 'NORWAY': 'NO', 'FINNLAND': 'FI', 'FINLAND': 'FI',
		'PORTUGAL': 'PT', 'GRIECHENLAND': 'GR', 'GREECE': 'GR', 'IRLAND': 'IE', 'IRELAND': 'IE',
		'KANADA': 'CA', 'CANADA': 'CA', 'AUSTRALIEN': 'AU', 'AUSTRALIA': 'AU', 'JAPAN': 'JP', 'CHINA': 'CN',
		'RUSSLAND': 'RU', 'RUSSIA': 'RU', 'TÜRKEI': 'TR', 'TUERKEI': 'TR', 'TURKEY': 'TR',
		'VAE': 'AE', 'UAE': 'AE', 'UNITED ARAB EMIRATES': 'AE', 'VEREINIGTE ARABISCHE EMIRATE': 'AE'
	};
	// Fallback-Namen (falls die Seite keine eigene Länder-Map mitgibt).
	var NAMES = window.M24CountryNames || {};

	function countryToIso2(land) {
		var s = String(land == null ? '' : land).trim();
		if (!s) { return ''; }
		var u = s.toUpperCase();
		if (/^[A-Z]{2}$/.test(u)) { return u; }
		if (ALIAS[u]) { return ALIAS[u]; }
		for (var iso in NAMES) { if (NAMES.hasOwnProperty(iso) && String(NAMES[iso]).toUpperCase() === u) { return iso; } }
		return '';
	}
	function flag(iso2) {
		iso2 = String(iso2 || '').toUpperCase();
		if (!/^[A-Z]{2}$/.test(iso2)) { return ''; }
		var base = 0x1F1E6, out = '';
		for (var i = 0; i < 2; i++) { out += String.fromCodePoint(base + (iso2.charCodeAt(i) - 65)); }
		return out;
	}
	function getFlagAndCountry(land) {
		var raw = String(land == null ? '' : land).trim();
		if (!raw) { return ''; }
		var f = flag(countryToIso2(raw));
		return (f ? f + ' ' : '') + raw;
	}
	window.M24Country = { countryToIso2: countryToIso2, flag: flag, getFlagAndCountry: getFlagAndCountry };
})();

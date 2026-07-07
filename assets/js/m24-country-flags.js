/* ============================================================================
 * country-flags.js — Land → Flagge + Label (framework-neutral, keine Imports)
 * Extrahiert aus MOTORSPORT24 M24 Desk (src/js/utils.js + src/js/state.js).
 *
 * Zwei Ansätze in einer Datei:
 *   A) ISO2 + Regional-Indicator-Codepoints  (empfohlen, wartbar)
 *   B) 1:1 Literal-Map                        (Desk-treu, kein Codepoint-Rechnen)
 *
 * Verhalten 1:1 wie Desk:
 *   - Normalisierung: input.toLowerCase().trim()  (Ansatz A strippt zusätzlich
 *     optional ein führendes Flaggen-Emoji — im Desk passiert das beim Aufrufer)
 *   - Fallback-Heuristik: 5-stellige Zahl -> DE, Wort "bei" -> DE, sonst ''
 *   - Label = Flagge + ' ' + roher Eingabetext (KEINE Kanonisierung von "USA")
 *   - leere Eingabe: getFlag('')='' , getFlagAndCountry('')='—'
 * ========================================================================== */

/* ------------------------------------------------------------------ *
 * ANSATZ A — ISO2 + Codepoints
 * ------------------------------------------------------------------ */

/* Alias (lowercase, DE+EN+ISO2+Stadt) -> ISO2  ('GB-SCT' = Sonderfall Schottland) */
const ALIAS_TO_ISO2 = {
  'deutschland':'DE','germany':'DE','de':'DE',
  'österreich':'AT','oesterreich':'AT','austria':'AT',
  'schweiz':'CH','switzerland':'CH',
  'liechtenstein':'LI',
  'frankreich':'FR','france':'FR',
  'luxemburg':'LU','luxembourg':'LU',
  'belgien':'BE','belgium':'BE',
  'niederlande':'NL','netherlands':'NL','holland':'NL',
  'dänemark':'DK','daenemark':'DK','denmark':'DK',
  'tschechien':'CZ','czech':'CZ','tschechische republik':'CZ','czech republic':'CZ','czechia':'CZ',
  'polen':'PL','poland':'PL',
  'vereinigtes königreich':'GB','vereinigtes koenigreich':'GB','großbritannien':'GB','grossbritannien':'GB',
  'uk':'GB','gb':'GB','england':'GB','great britain':'GB','britain':'GB','united kingdom':'GB',
  'wales':'GB','nordirland':'GB','northern ireland':'GB',            /* Ergänzung ggü. Desk-Lücke */
  'schottland':'GB-SCT','scotland':'GB-SCT',
  'irland':'IE','ireland':'IE',
  'schweden':'SE','sweden':'SE',
  'norwegen':'NO','norway':'NO',
  'finnland':'FI','finland':'FI',
  'island':'IS','iceland':'IS',
  'italien':'IT','italy':'IT',
  'spanien':'ES','spain':'ES',
  'portugal':'PT',
  'griechenland':'GR','greece':'GR',
  'kroatien':'HR','croatia':'HR',
  'ungarn':'HU','hungary':'HU',
  'rumänien':'RO','rumaenien':'RO','romania':'RO',
  'slowakei':'SK','slovakia':'SK',
  'slowenien':'SI','slovenia':'SI',
  'serbien':'RS','serbia':'RS',
  'bulgarien':'BG','bulgaria':'BG','bg':'BG',
  'usa':'US','united states':'US','us':'US','vereinigte staaten':'US','vereinigten staaten':'US',
  'kanada':'CA','canada':'CA',
  'dubai':'AE','vae':'AE','uae':'AE','vereinigte arabische emirate':'AE','emirates':'AE','abu dhabi':'AE',
  'qatar':'QA','katar':'QA',
  'kuwait':'KW',
  'saudi-arabien':'SA','saudi arabia':'SA','saudi':'SA',
  'bahrain':'BH',
  'oman':'OM',
  'japan':'JP',
  'china':'CN',
  'südkorea':'KR','suedkorea':'KR','south korea':'KR','korea':'KR',
  'singapur':'SG','singapore':'SG',
  'hongkong':'HK','hong kong':'HK',
  'taiwan':'TW',
  'indien':'IN','india':'IN',
  'australien':'AU','australia':'AU',
  'neuseeland':'NZ','new zealand':'NZ',
  'namibia':'NA',
  'südafrika':'ZA','suedafrika':'ZA','south africa':'ZA',
  'türkei':'TR','tuerkei':'TR','turkey':'TR',
  'mexiko':'MX','mexico':'MX',
  'brasilien':'BR','brazil':'BR',
  'argentinien':'AR','argentina':'AR',
  'thailand':'TH',
  'malaysia':'MY',
  'indonesien':'ID','indonesia':'ID',
  'philippinen':'PH','philippines':'PH',
  'vietnam':'VN',
  'ukraine':'UA',
  'estland':'EE','estonia':'EE',
  'lettland':'LV','latvia':'LV',
  'litauen':'LT','lithuania':'LT',
  'malta':'MT',
  /* optional: EU-Sammelfall (im Desk NICHT vorhanden) */
  'eu':'EU','europäische union':'EU','europaeische union':'EU','european union':'EU',
};

/* genutzte ISO2-Liste (Ziel-Codes, ohne den Sonderfall GB-SCT) */
const ISO2_LIST = [
  'DE','AT','CH','LI','FR','LU','BE','NL','DK','CZ','PL','GB','IE','SE','NO','FI','IS',
  'IT','ES','PT','GR','HR','HU','RO','SK','SI','RS','BG','US','CA','AE','QA','KW','SA',
  'BH','OM','JP','CN','KR','SG','HK','TW','IN','AU','NZ','NA','ZA','TR','MX','BR','AR',
  'TH','MY','ID','PH','VN','UA','EE','LV','LT','MT','EU'
];

/* Schottland-Flagge (Tag-Sequenz) aus Codepoints — encoding-sicher */
const FLAG_SCOTLAND = String.fromCodePoint(
  0x1F3F4, 0xE0067, 0xE0062, 0xE0073, 0xE0063, 0xE0074, 0xE007F
); /* 🏴 + gbsct + cancel-tag */

/* führendes Flaggen-Emoji entfernen (RI-Paar, Tag-Flag, einzelne Wehflagge) */
function stripLeadingFlag(s) {
  return s.replace(
    /^(?:[\u{1F1E6}-\u{1F1FF}]{2}|\u{1F3F4}[\u{E0000}-\u{E007F}]+|\u{1F3F4})\s*/u,
    ''
  ).trimStart();
}

/* ISO2 -> Emoji via Regional-Indicator-Codepoints (U+1F1E6 = 'A') */
function isoToFlag(iso2) {
  if (!iso2) return '';
  const cc = String(iso2).toUpperCase();
  if (cc === 'GB-SCT' || cc === 'SCT') return FLAG_SCOTLAND;
  if (!/^[A-Z]{2}$/.test(cc)) return '';
  const BASE = 0x1F1E6;
  return String.fromCodePoint(BASE + cc.charCodeAt(0) - 65, BASE + cc.charCodeAt(1) - 65);
}

/* Freitext/ISO/Alias -> ISO2 | null  (inkl. Fallback-Heuristiken) */
function countryToIso2(input) {
  if (!input) return null;
  const raw = String(input);
  const key = stripLeadingFlag(raw).toLowerCase().trim();
  if (ALIAS_TO_ISO2[key]) return ALIAS_TO_ISO2[key];
  if (/^[a-z]{2}$/.test(key) && ISO2_LIST.includes(key.toUpperCase())) return key.toUpperCase();
  // Heuristik wie im Desk (auf Rohtext): dt. PLZ (5 Ziffern) oder "bei" -> DE
  if (/\b\d{5}\b/.test(raw)) return 'DE';
  if (/\bbei\b/i.test(raw)) return 'DE';
  return null;
}

/* Land -> Flagge (''=unbekannt) */
function getFlag(input) {
  return isoToFlag(countryToIso2(input));
}

/* Land -> "Flagge Label" (Label = roher Eingabetext); leer -> '—' */
function getFlagAndCountry(input) {
  if (!input) return '—';
  const flag = getFlag(input);
  return flag ? flag + ' ' + input : String(input);
}

/* strukturiert, falls das Plugin Flagge/Label/ISO2 getrennt braucht */
function resolveCountry(input) {
  const iso2 = countryToIso2(input);
  const flag = isoToFlag(iso2);
  return { input: input == null ? '' : String(input), iso2, flag,
           label: flag ? flag + ' ' + input : (input == null ? '' : String(input)) };
}


/* ------------------------------------------------------------------ *
 * ANSATZ B — 1:1 Literal-Map (exakt wie M24 Desk, kein Codepoint-Rechnen)
 * ------------------------------------------------------------------ */

const COUNTRY_FLAGS = {
  'deutschland':'🇩🇪','germany':'🇩🇪','de':'🇩🇪',
  'österreich':'🇦🇹','austria':'🇦🇹',
  'schweiz':'🇨🇭','switzerland':'🇨🇭','liechtenstein':'🇱🇮',
  'frankreich':'🇫🇷','france':'🇫🇷',
  'luxemburg':'🇱🇺','luxembourg':'🇱🇺',
  'belgien':'🇧🇪','belgium':'🇧🇪',
  'niederlande':'🇳🇱','netherlands':'🇳🇱','holland':'🇳🇱',
  'dänemark':'🇩🇰','denmark':'🇩🇰',
  'tschechien':'🇨🇿','czech':'🇨🇿','tschechische republik':'🇨🇿',
  'polen':'🇵🇱','poland':'🇵🇱',
  'vereinigtes königreich':'🇬🇧','großbritannien':'🇬🇧','uk':'🇬🇧','gb':'🇬🇧','england':'🇬🇧','great britain':'🇬🇧','britain':'🇬🇧','united kingdom':'🇬🇧',
  'schottland':FLAG_SCOTLAND,'scotland':FLAG_SCOTLAND,
  'irland':'🇮🇪','ireland':'🇮🇪',
  'schweden':'🇸🇪','sweden':'🇸🇪',
  'norwegen':'🇳🇴','norway':'🇳🇴',
  'finnland':'🇫🇮','finland':'🇫🇮',
  'island':'🇮🇸','iceland':'🇮🇸',
  'italien':'🇮🇹','italy':'🇮🇹',
  'spanien':'🇪🇸','spain':'🇪🇸',
  'portugal':'🇵🇹',
  'griechenland':'🇬🇷','greece':'🇬🇷',
  'kroatien':'🇭🇷','croatia':'🇭🇷',
  'ungarn':'🇭🇺','hungary':'🇭🇺',
  'rumänien':'🇷🇴','romania':'🇷🇴',
  'slowakei':'🇸🇰','slovakia':'🇸🇰',
  'slowenien':'🇸🇮','slovenia':'🇸🇮',
  'serbien':'🇷🇸','serbia':'🇷🇸',
  'bulgarien':'🇧🇬','bulgaria':'🇧🇬','bg':'🇧🇬',
  'usa':'🇺🇸','united states':'🇺🇸','us':'🇺🇸','vereinigte staaten':'🇺🇸','vereinigten staaten':'🇺🇸',
  'kanada':'🇨🇦','canada':'🇨🇦',
  'dubai':'🇦🇪','vae':'🇦🇪','uae':'🇦🇪','vereinigte arabische emirate':'🇦🇪','emirates':'🇦🇪','abu dhabi':'🇦🇪',
  'qatar':'🇶🇦','katar':'🇶🇦',
  'kuwait':'🇰🇼',
  'saudi-arabien':'🇸🇦','saudi arabia':'🇸🇦','saudi':'🇸🇦',
  'bahrain':'🇧🇭',
  'oman':'🇴🇲',
  'japan':'🇯🇵',
  'china':'🇨🇳',
  'südkorea':'🇰🇷','south korea':'🇰🇷','korea':'🇰🇷',
  'singapur':'🇸🇬','singapore':'🇸🇬',
  'hongkong':'🇭🇰','hong kong':'🇭🇰',
  'taiwan':'🇹🇼',
  'indien':'🇮🇳','india':'🇮🇳',
  'australien':'🇦🇺','australia':'🇦🇺',
  'neuseeland':'🇳🇿','new zealand':'🇳🇿',
  'namibia':'🇳🇦',
  'südafrika':'🇿🇦','south africa':'🇿🇦',
  'türkei':'🇹🇷','turkey':'🇹🇷',
  'mexiko':'🇲🇽','mexico':'🇲🇽',
  'brasilien':'🇧🇷','brazil':'🇧🇷',
  'argentinien':'🇦🇷','argentina':'🇦🇷',
  'thailand':'🇹🇭',
  'malaysia':'🇲🇾',
  'indonesien':'🇮🇩','indonesia':'🇮🇩',
  'philippinen':'🇵🇭','philippines':'🇵🇭',
  'vietnam':'🇻🇳',
  'ukraine':'🇺🇦',
  'estland':'🇪🇪','estonia':'🇪🇪',
  'lettland':'🇱🇻','latvia':'🇱🇻',
  'litauen':'🇱🇹','lithuania':'🇱🇹',
  'malta':'🇲🇹',
};

/* Desk-treue Variante von getFlag (Literal-Map + gleiche Heuristik) */
function getFlagLiteral(land) {
  if (!land) return '';
  const key = String(land).toLowerCase().trim();
  if (COUNTRY_FLAGS[key]) return COUNTRY_FLAGS[key];
  if (/\b\d{5}\b/.test(land)) return '🇩🇪';
  if (/\bbei\b/i.test(land)) return '🇩🇪';
  return '';
}


/* ------------------------------------------------------------------ *
 * Export (ESM + CommonJS + global) — je nach Umgebung
 * ------------------------------------------------------------------ */
const _api = {
  getFlag, getFlagAndCountry, resolveCountry, countryToIso2, isoToFlag,
  getFlagLiteral, ALIAS_TO_ISO2, ISO2_LIST, COUNTRY_FLAGS, FLAG_SCOTLAND
};
/* Klassisches <script> (WordPress enqueue) -> global window.M24Country.
   Node/CommonJS -> module.exports.
   ESM gewünscht? Datei als .mjs laden und diese Zeile ergänzen:
   // export { getFlag, getFlagAndCountry, resolveCountry, countryToIso2, isoToFlag,
   //          getFlagLiteral, ALIAS_TO_ISO2, ISO2_LIST, COUNTRY_FLAGS, FLAG_SCOTLAND }; */
if (typeof module !== 'undefined' && module.exports) module.exports = _api;
if (typeof window !== 'undefined') window.M24Country = _api;

/* --- Beispiele ---
   getFlag('USA')              -> '🇺🇸'
   getFlagAndCountry('USA')    -> '🇺🇸 USA'
   getFlagAndCountry('England')-> '🇬🇧 England'
   getFlag('Schottland')       -> '🏴󠁧󠁢󠁳󠁣󠁴󠁿'
   getFlag('12345 Berlin')     -> '🇩🇪'   (PLZ-Heuristik)
   getFlag('Neuland')          -> ''       (unbekannt)
   getFlagAndCountry('')       -> '—'
*/

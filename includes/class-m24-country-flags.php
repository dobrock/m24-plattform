<?php
declare(strict_types=1);

/**
 * CountryFlags — Land -> Flagge + Label (framework-neutral, PHP 8.4)
 * Extrahiert aus MOTORSPORT24 M24 Desk (src/js/utils.js + src/js/state.js).
 * Benötigt nur die mbstring-Extension (in WordPress/PHP 8.4 Standard).
 *
 * Zwei Ansätze:
 *   A) ISO2 + Regional-Indicator-Codepoints  (empfohlen, wartbar, keine Emoji-Literale)
 *   B) 1:1 Literal-Map                        (Desk-treu)
 *
 * Verhalten 1:1 wie Desk:
 *   - Normalisierung: mb_strtolower(trim(input))   (Ansatz A strippt zusätzlich ein
 *     optional führendes Flaggen-Emoji — im Desk macht das der Aufrufer)
 *   - Fallback: 5-stellige Zahl -> DE, Wort "bei" -> DE, sonst ''
 *   - Label = Flagge + ' ' + Eingabetext OHNE führende Flagge (KEINE Kanonisierung von "USA";
 *     ein bereits vorhandenes Flaggen-Präfix wird entfernt → nie doppelte Flagge, z. B. Desk „🇨🇭 Schweiz")
 *   - leere Eingabe: getFlag('')='' , getFlagAndCountry('')='—'
 */
final class CountryFlags
{
    /** Alias (lowercase, DE+EN+ISO2+Stadt) -> ISO2 ('GB-SCT' = Sonderfall Schottland) */
    public const ALIAS_TO_ISO2 = [
        'deutschland'=>'DE','germany'=>'DE','de'=>'DE',
        'österreich'=>'AT','oesterreich'=>'AT','austria'=>'AT',
        'schweiz'=>'CH','switzerland'=>'CH',
        'liechtenstein'=>'LI',
        'frankreich'=>'FR','france'=>'FR',
        'luxemburg'=>'LU','luxembourg'=>'LU',
        'belgien'=>'BE','belgium'=>'BE',
        'niederlande'=>'NL','netherlands'=>'NL','holland'=>'NL',
        'dänemark'=>'DK','daenemark'=>'DK','denmark'=>'DK',
        'tschechien'=>'CZ','czech'=>'CZ','tschechische republik'=>'CZ','czech republic'=>'CZ','czechia'=>'CZ',
        'polen'=>'PL','poland'=>'PL',
        'vereinigtes königreich'=>'GB','vereinigtes koenigreich'=>'GB','großbritannien'=>'GB','grossbritannien'=>'GB',
        'uk'=>'GB','gb'=>'GB','england'=>'GB','great britain'=>'GB','britain'=>'GB','united kingdom'=>'GB',
        'wales'=>'GB','nordirland'=>'GB','northern ireland'=>'GB',        // Ergänzung ggü. Desk-Lücke
        'schottland'=>'GB-SCT','scotland'=>'GB-SCT',
        'irland'=>'IE','ireland'=>'IE',
        'schweden'=>'SE','sweden'=>'SE',
        'norwegen'=>'NO','norway'=>'NO',
        'finnland'=>'FI','finland'=>'FI',
        'island'=>'IS','iceland'=>'IS',
        'italien'=>'IT','italy'=>'IT',
        'spanien'=>'ES','spain'=>'ES',
        'portugal'=>'PT',
        'griechenland'=>'GR','greece'=>'GR',
        'kroatien'=>'HR','croatia'=>'HR',
        'ungarn'=>'HU','hungary'=>'HU',
        'rumänien'=>'RO','rumaenien'=>'RO','romania'=>'RO',
        'slowakei'=>'SK','slovakia'=>'SK',
        'slowenien'=>'SI','slovenia'=>'SI',
        'serbien'=>'RS','serbia'=>'RS',
        'bulgarien'=>'BG','bulgaria'=>'BG','bg'=>'BG',
        'usa'=>'US','united states'=>'US','us'=>'US','vereinigte staaten'=>'US','vereinigten staaten'=>'US',
        'kanada'=>'CA','canada'=>'CA',
        'dubai'=>'AE','vae'=>'AE','uae'=>'AE','vereinigte arabische emirate'=>'AE','emirates'=>'AE','abu dhabi'=>'AE',
        'qatar'=>'QA','katar'=>'QA',
        'kuwait'=>'KW',
        'saudi-arabien'=>'SA','saudi arabia'=>'SA','saudi'=>'SA',
        'bahrain'=>'BH',
        'oman'=>'OM',
        'japan'=>'JP',
        'china'=>'CN',
        'südkorea'=>'KR','suedkorea'=>'KR','south korea'=>'KR','korea'=>'KR',
        'singapur'=>'SG','singapore'=>'SG',
        'hongkong'=>'HK','hong kong'=>'HK',
        'taiwan'=>'TW',
        'indien'=>'IN','india'=>'IN',
        'australien'=>'AU','australia'=>'AU',
        'neuseeland'=>'NZ','new zealand'=>'NZ',
        'namibia'=>'NA',
        'südafrika'=>'ZA','suedafrika'=>'ZA','south africa'=>'ZA',
        'türkei'=>'TR','tuerkei'=>'TR','turkey'=>'TR',
        'mexiko'=>'MX','mexico'=>'MX',
        'brasilien'=>'BR','brazil'=>'BR',
        'argentinien'=>'AR','argentina'=>'AR',
        'thailand'=>'TH',
        'malaysia'=>'MY',
        'indonesien'=>'ID','indonesia'=>'ID',
        'philippinen'=>'PH','philippines'=>'PH',
        'vietnam'=>'VN',
        'ukraine'=>'UA',
        'estland'=>'EE','estonia'=>'EE',
        'lettland'=>'LV','latvia'=>'LV',
        'litauen'=>'LT','lithuania'=>'LT',
        'malta'=>'MT',
        // optional: EU-Sammelfall (im Desk NICHT vorhanden)
        'eu'=>'EU','europäische union'=>'EU','europaeische union'=>'EU','european union'=>'EU',
    ];

    /** genutzte ISO2-Liste (ohne Sonderfall GB-SCT) */
    public const ISO2_LIST = [
        'DE','AT','CH','LI','FR','LU','BE','NL','DK','CZ','PL','GB','IE','SE','NO','FI','IS',
        'IT','ES','PT','GR','HR','HU','RO','SK','SI','RS','BG','US','CA','AE','QA','KW','SA',
        'BH','OM','JP','CN','KR','SG','HK','TW','IN','AU','NZ','NA','ZA','TR','MX','BR','AR',
        'TH','MY','ID','PH','VN','UA','EE','LV','LT','MT','EU',
    ];

    /** Schottland-Flagge (Tag-Sequenz) — via Codepoints, encoding-sicher */
    public static function flagScotland(): string
    {
        return "\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}";
    }

    /** führendes Flaggen-Emoji entfernen (RI-Paar, Tag-Flag, einzelne Wehflagge) */
    public static function stripLeadingFlag(string $s): string
    {
        $s = preg_replace(
            '/^(?:[\x{1F1E6}-\x{1F1FF}]{2}|\x{1F3F4}[\x{E0000}-\x{E007F}]+|\x{1F3F4})\s*/u',
            '', $s
        ) ?? $s;
        return ltrim($s);
    }

    /** ISO2 -> Emoji via Regional-Indicator-Codepoints (U+1F1E6 = 'A') */
    public static function isoToFlag(?string $iso2): string
    {
        if ($iso2 === null || $iso2 === '') return '';
        $cc = strtoupper($iso2);
        if ($cc === 'GB-SCT' || $cc === 'SCT') return self::flagScotland();
        if (!preg_match('/^[A-Z]{2}$/', $cc)) return '';
        $base = 0x1F1E6;
        return mb_chr($base + ord($cc[0]) - 65, 'UTF-8')
             . mb_chr($base + ord($cc[1]) - 65, 'UTF-8');
    }

    /** Freitext/ISO/Alias -> ISO2 | null (inkl. Fallback-Heuristiken) */
    public static function countryToIso2(?string $input): ?string
    {
        if ($input === null || $input === '') return null;
        $raw = $input;
        $key = mb_strtolower(trim(self::stripLeadingFlag($raw)), 'UTF-8');
        if (isset(self::ALIAS_TO_ISO2[$key])) return self::ALIAS_TO_ISO2[$key];
        if (preg_match('/^[a-z]{2}$/', $key) && in_array(strtoupper($key), self::ISO2_LIST, true)) {
            return strtoupper($key);
        }
        if (preg_match('/\b\d{5}\b/', $raw)) return 'DE';   // dt. PLZ-Heuristik
        if (preg_match('/\bbei\b/i', $raw))  return 'DE';   // "bei" im Ortsnamen
        return null;
    }

    /** Land -> Flagge (''=unbekannt) */
    public static function getFlag(?string $input): string
    {
        return self::isoToFlag(self::countryToIso2($input));
    }

    /**
     * Land -> "Flagge Label"; leer -> '—'.
     * Label = Eingabetext OHNE ein evtl. bereits vorhandenes führendes Flaggen-Emoji — sonst entsteht eine
     * Dopplung, wenn der Wert schon mit Flagge kommt (Desk-Sync liefert orders.country als „🇨🇭 Schweiz").
     * Genau EINE Flagge wird frisch aus dem (intern ohnehin flaggenbereinigten) Wert abgeleitet.
     */
    public static function getFlagAndCountry(?string $input): string
    {
        if ($input === null || $input === '') return '—';
        $label = trim(self::stripLeadingFlag($input));
        $flag  = self::getFlag($input);
        if ($label === '') return $flag !== '' ? $flag : '—'; // Eingabe war nur eine Flagge
        return $flag !== '' ? $flag . ' ' . $label : $label;
    }

    /** strukturiert: flag / label / iso2 getrennt */
    public static function resolve(?string $input): array
    {
        $iso2  = self::countryToIso2($input);
        $flag  = self::isoToFlag($iso2);
        $in    = $input ?? '';
        $label = trim(self::stripLeadingFlag($in)); // führende Flagge aus dem Label entfernen (keine Dopplung)
        return [
            'input' => $in,
            'iso2'  => $iso2,
            'flag'  => $flag,
            'label' => ($flag !== '' && $label !== '') ? $flag . ' ' . $label : ($label !== '' ? $label : $flag),
        ];
    }

    /* ------------------------------------------------------------------ *
     * ANSATZ B — 1:1 Literal-Map (exakt wie M24 Desk)
     * ------------------------------------------------------------------ */

    /** @return array<string,string> lowercase-Alias -> Emoji */
    public static function countryFlagsMap(): array
    {
        return [
            'deutschland'=>'🇩🇪','germany'=>'🇩🇪','de'=>'🇩🇪',
            'österreich'=>'🇦🇹','austria'=>'🇦🇹',
            'schweiz'=>'🇨🇭','switzerland'=>'🇨🇭','liechtenstein'=>'🇱🇮',
            'frankreich'=>'🇫🇷','france'=>'🇫🇷',
            'luxemburg'=>'🇱🇺','luxembourg'=>'🇱🇺',
            'belgien'=>'🇧🇪','belgium'=>'🇧🇪',
            'niederlande'=>'🇳🇱','netherlands'=>'🇳🇱','holland'=>'🇳🇱',
            'dänemark'=>'🇩🇰','denmark'=>'🇩🇰',
            'tschechien'=>'🇨🇿','czech'=>'🇨🇿','tschechische republik'=>'🇨🇿',
            'polen'=>'🇵🇱','poland'=>'🇵🇱',
            'vereinigtes königreich'=>'🇬🇧','großbritannien'=>'🇬🇧','uk'=>'🇬🇧','gb'=>'🇬🇧','england'=>'🇬🇧','great britain'=>'🇬🇧','britain'=>'🇬🇧','united kingdom'=>'🇬🇧',
            'schottland'=>self::flagScotland(),'scotland'=>self::flagScotland(),
            'irland'=>'🇮🇪','ireland'=>'🇮🇪',
            'schweden'=>'🇸🇪','sweden'=>'🇸🇪',
            'norwegen'=>'🇳🇴','norway'=>'🇳🇴',
            'finnland'=>'🇫🇮','finland'=>'🇫🇮',
            'island'=>'🇮🇸','iceland'=>'🇮🇸',
            'italien'=>'🇮🇹','italy'=>'🇮🇹',
            'spanien'=>'🇪🇸','spain'=>'🇪🇸',
            'portugal'=>'🇵🇹',
            'griechenland'=>'🇬🇷','greece'=>'🇬🇷',
            'kroatien'=>'🇭🇷','croatia'=>'🇭🇷',
            'ungarn'=>'🇭🇺','hungary'=>'🇭🇺',
            'rumänien'=>'🇷🇴','romania'=>'🇷🇴',
            'slowakei'=>'🇸🇰','slovakia'=>'🇸🇰',
            'slowenien'=>'🇸🇮','slovenia'=>'🇸🇮',
            'serbien'=>'🇷🇸','serbia'=>'🇷🇸',
            'bulgarien'=>'🇧🇬','bulgaria'=>'🇧🇬','bg'=>'🇧🇬',
            'usa'=>'🇺🇸','united states'=>'🇺🇸','us'=>'🇺🇸','vereinigte staaten'=>'🇺🇸','vereinigten staaten'=>'🇺🇸',
            'kanada'=>'🇨🇦','canada'=>'🇨🇦',
            'dubai'=>'🇦🇪','vae'=>'🇦🇪','uae'=>'🇦🇪','vereinigte arabische emirate'=>'🇦🇪','emirates'=>'🇦🇪','abu dhabi'=>'🇦🇪',
            'qatar'=>'🇶🇦','katar'=>'🇶🇦',
            'kuwait'=>'🇰🇼',
            'saudi-arabien'=>'🇸🇦','saudi arabia'=>'🇸🇦','saudi'=>'🇸🇦',
            'bahrain'=>'🇧🇭',
            'oman'=>'🇴🇲',
            'japan'=>'🇯🇵',
            'china'=>'🇨🇳',
            'südkorea'=>'🇰🇷','south korea'=>'🇰🇷','korea'=>'🇰🇷',
            'singapur'=>'🇸🇬','singapore'=>'🇸🇬',
            'hongkong'=>'🇭🇰','hong kong'=>'🇭🇰',
            'taiwan'=>'🇹🇼',
            'indien'=>'🇮🇳','india'=>'🇮🇳',
            'australien'=>'🇦🇺','australia'=>'🇦🇺',
            'neuseeland'=>'🇳🇿','new zealand'=>'🇳🇿',
            'namibia'=>'🇳🇦',
            'südafrika'=>'🇿🇦','south africa'=>'🇿🇦',
            'türkei'=>'🇹🇷','turkey'=>'🇹🇷',
            'mexiko'=>'🇲🇽','mexico'=>'🇲🇽',
            'brasilien'=>'🇧🇷','brazil'=>'🇧🇷',
            'argentinien'=>'🇦🇷','argentina'=>'🇦🇷',
            'thailand'=>'🇹🇭',
            'malaysia'=>'🇲🇾',
            'indonesien'=>'🇮🇩','indonesia'=>'🇮🇩',
            'philippinen'=>'🇵🇭','philippines'=>'🇵🇭',
            'vietnam'=>'🇻🇳',
            'ukraine'=>'🇺🇦',
            'estland'=>'🇪🇪','estonia'=>'🇪🇪',
            'lettland'=>'🇱🇻','latvia'=>'🇱🇻',
            'litauen'=>'🇱🇹','lithuania'=>'🇱🇹',
            'malta'=>'🇲🇹',
        ];
    }

    /** Desk-treu: Literal-Map + gleiche Heuristik */
    public static function getFlagLiteral(?string $land): string
    {
        if ($land === null || $land === '') return '';
        $key = mb_strtolower(trim($land), 'UTF-8');
        $map = self::countryFlagsMap();
        if (isset($map[$key])) return $map[$key];
        if (preg_match('/\b\d{5}\b/', $land)) return '🇩🇪';
        if (preg_match('/\bbei\b/i', $land))  return '🇩🇪';
        return '';
    }
}

/* --- Beispiele ---
   CountryFlags::getFlag('USA')               -> '🇺🇸'
   CountryFlags::getFlagAndCountry('USA')     -> '🇺🇸 USA'
   CountryFlags::getFlagAndCountry('England') -> '🇬🇧 England'
   CountryFlags::getFlag('Schottland')        -> '🏴󠁧󠁢󠁳󠁣󠁴󠁿'
   CountryFlags::getFlag('12345 Berlin')      -> '🇩🇪'
   CountryFlags::getFlag('Neuland')           -> ''
   CountryFlags::resolve('England')  -> ['input'=>'England','iso2'=>'GB','flag'=>'🇬🇧','label'=>'🇬🇧 England']
*/


// M24-Alias: bestehende Aufrufer nutzen M24_Country_Flags (identische API wie CountryFlags).
if ( ! class_exists( 'M24_Country_Flags' ) ) { class_alias( 'CountryFlags', 'M24_Country_Flags' ); }

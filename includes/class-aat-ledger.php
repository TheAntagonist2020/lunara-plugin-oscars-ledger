<?php
/**
 * Oscars Ledger: the ledger classes loaded on every request (plan-v5 §2, §4.4.8).
 *
 * U01 adds AAT_Ledger_Text, the one fold of the ledger code, because the source codec's
 * fold-coverage rule (§4.2 rule 13) needs its tables. The AAT_Ledger state machine and
 * its revision constants follow in later units (U03, U04) in this same file.
 *
 * This file defines classes only. It calls no WordPress function at load time, so the
 * pure codec (includes/class-aat-ledger-source.php) and the offline bundle builder can
 * require it outside WordPress.
 */

/**
 * Self-contained, locale-independent text folding (fold_policy `lunara-fold/1`).
 *
 * fold() never calls remove_accents, mb_strtolower, mb_convert_case, iconv,
 * Normalizer or setlocale, so PHP 8.2 (CI), PHP 8.4 (the local builder) and
 * production give byte-identical output.
 */
final class AAT_Ledger_Text
{
    const FOLD_POLICY = 'lunara-fold/1';

    /**
     * Every letter in U+00C0-U+017F (upper and lower case to lower-case ASCII), plus
     * the curly quotes and the en and em dashes.
     *
     * The letter table is vendored from WordPress's remove_accents()
     * (wp-includes/formatting.php, "Decompositions for Latin-1 Supplement" and
     * "Latin Extended-A"; WordPress is GPLv2 or later), lower-cased, with one change
     * the plan names: U+00DF (sharp s) folds to "ss". U+00D7 and U+00F7 are not
     * letters and are not keys.
     */
    const FOLD_MAP = array(
        "\u{00C0}" => 'a',
        "\u{00C1}" => 'a',
        "\u{00C2}" => 'a',
        "\u{00C3}" => 'a',
        "\u{00C4}" => 'a',
        "\u{00C5}" => 'a',
        "\u{00C6}" => 'ae',
        "\u{00C7}" => 'c',
        "\u{00C8}" => 'e',
        "\u{00C9}" => 'e',
        "\u{00CA}" => 'e',
        "\u{00CB}" => 'e',
        "\u{00CC}" => 'i',
        "\u{00CD}" => 'i',
        "\u{00CE}" => 'i',
        "\u{00CF}" => 'i',
        "\u{00D0}" => 'd',
        "\u{00D1}" => 'n',
        "\u{00D2}" => 'o',
        "\u{00D3}" => 'o',
        "\u{00D4}" => 'o',
        "\u{00D5}" => 'o',
        "\u{00D6}" => 'o',
        "\u{00D8}" => 'o',
        "\u{00D9}" => 'u',
        "\u{00DA}" => 'u',
        "\u{00DB}" => 'u',
        "\u{00DC}" => 'u',
        "\u{00DD}" => 'y',
        "\u{00DE}" => 'th',
        "\u{00DF}" => 'ss',
        "\u{00E0}" => 'a',
        "\u{00E1}" => 'a',
        "\u{00E2}" => 'a',
        "\u{00E3}" => 'a',
        "\u{00E4}" => 'a',
        "\u{00E5}" => 'a',
        "\u{00E6}" => 'ae',
        "\u{00E7}" => 'c',
        "\u{00E8}" => 'e',
        "\u{00E9}" => 'e',
        "\u{00EA}" => 'e',
        "\u{00EB}" => 'e',
        "\u{00EC}" => 'i',
        "\u{00ED}" => 'i',
        "\u{00EE}" => 'i',
        "\u{00EF}" => 'i',
        "\u{00F0}" => 'd',
        "\u{00F1}" => 'n',
        "\u{00F2}" => 'o',
        "\u{00F3}" => 'o',
        "\u{00F4}" => 'o',
        "\u{00F5}" => 'o',
        "\u{00F6}" => 'o',
        "\u{00F8}" => 'o',
        "\u{00F9}" => 'u',
        "\u{00FA}" => 'u',
        "\u{00FB}" => 'u',
        "\u{00FC}" => 'u',
        "\u{00FD}" => 'y',
        "\u{00FE}" => 'th',
        "\u{00FF}" => 'y',
        "\u{0100}" => 'a',
        "\u{0101}" => 'a',
        "\u{0102}" => 'a',
        "\u{0103}" => 'a',
        "\u{0104}" => 'a',
        "\u{0105}" => 'a',
        "\u{0106}" => 'c',
        "\u{0107}" => 'c',
        "\u{0108}" => 'c',
        "\u{0109}" => 'c',
        "\u{010A}" => 'c',
        "\u{010B}" => 'c',
        "\u{010C}" => 'c',
        "\u{010D}" => 'c',
        "\u{010E}" => 'd',
        "\u{010F}" => 'd',
        "\u{0110}" => 'd',
        "\u{0111}" => 'd',
        "\u{0112}" => 'e',
        "\u{0113}" => 'e',
        "\u{0114}" => 'e',
        "\u{0115}" => 'e',
        "\u{0116}" => 'e',
        "\u{0117}" => 'e',
        "\u{0118}" => 'e',
        "\u{0119}" => 'e',
        "\u{011A}" => 'e',
        "\u{011B}" => 'e',
        "\u{011C}" => 'g',
        "\u{011D}" => 'g',
        "\u{011E}" => 'g',
        "\u{011F}" => 'g',
        "\u{0120}" => 'g',
        "\u{0121}" => 'g',
        "\u{0122}" => 'g',
        "\u{0123}" => 'g',
        "\u{0124}" => 'h',
        "\u{0125}" => 'h',
        "\u{0126}" => 'h',
        "\u{0127}" => 'h',
        "\u{0128}" => 'i',
        "\u{0129}" => 'i',
        "\u{012A}" => 'i',
        "\u{012B}" => 'i',
        "\u{012C}" => 'i',
        "\u{012D}" => 'i',
        "\u{012E}" => 'i',
        "\u{012F}" => 'i',
        "\u{0130}" => 'i',
        "\u{0131}" => 'i',
        "\u{0132}" => 'ij',
        "\u{0133}" => 'ij',
        "\u{0134}" => 'j',
        "\u{0135}" => 'j',
        "\u{0136}" => 'k',
        "\u{0137}" => 'k',
        "\u{0138}" => 'k',
        "\u{0139}" => 'l',
        "\u{013A}" => 'l',
        "\u{013B}" => 'l',
        "\u{013C}" => 'l',
        "\u{013D}" => 'l',
        "\u{013E}" => 'l',
        "\u{013F}" => 'l',
        "\u{0140}" => 'l',
        "\u{0141}" => 'l',
        "\u{0142}" => 'l',
        "\u{0143}" => 'n',
        "\u{0144}" => 'n',
        "\u{0145}" => 'n',
        "\u{0146}" => 'n',
        "\u{0147}" => 'n',
        "\u{0148}" => 'n',
        "\u{0149}" => 'n',
        "\u{014A}" => 'n',
        "\u{014B}" => 'n',
        "\u{014C}" => 'o',
        "\u{014D}" => 'o',
        "\u{014E}" => 'o',
        "\u{014F}" => 'o',
        "\u{0150}" => 'o',
        "\u{0151}" => 'o',
        "\u{0152}" => 'oe',
        "\u{0153}" => 'oe',
        "\u{0154}" => 'r',
        "\u{0155}" => 'r',
        "\u{0156}" => 'r',
        "\u{0157}" => 'r',
        "\u{0158}" => 'r',
        "\u{0159}" => 'r',
        "\u{015A}" => 's',
        "\u{015B}" => 's',
        "\u{015C}" => 's',
        "\u{015D}" => 's',
        "\u{015E}" => 's',
        "\u{015F}" => 's',
        "\u{0160}" => 's',
        "\u{0161}" => 's',
        "\u{0162}" => 't',
        "\u{0163}" => 't',
        "\u{0164}" => 't',
        "\u{0165}" => 't',
        "\u{0166}" => 't',
        "\u{0167}" => 't',
        "\u{0168}" => 'u',
        "\u{0169}" => 'u',
        "\u{016A}" => 'u',
        "\u{016B}" => 'u',
        "\u{016C}" => 'u',
        "\u{016D}" => 'u',
        "\u{016E}" => 'u',
        "\u{016F}" => 'u',
        "\u{0170}" => 'u',
        "\u{0171}" => 'u',
        "\u{0172}" => 'u',
        "\u{0173}" => 'u',
        "\u{0174}" => 'w',
        "\u{0175}" => 'w',
        "\u{0176}" => 'y',
        "\u{0177}" => 'y',
        "\u{0178}" => 'y',
        "\u{0179}" => 'z',
        "\u{017A}" => 'z',
        "\u{017B}" => 'z',
        "\u{017C}" => 'z',
        "\u{017D}" => 'z',
        "\u{017E}" => 'z',
        "\u{017F}" => 's',
        "\u{2013}" => '-',
        "\u{2014}" => '-',
        "\u{2018}" => "'",
        "\u{2019}" => "'",
        "\u{201C}" => '"',
        "\u{201D}" => '"',
    );

    /**
     * Non-ASCII characters the dataset uses that fold() deliberately keeps. The
     * codec refuses a dataset character that is in neither this list nor FOLD_MAP
     * (fold_unmapped_char), so a new character forces a reviewed map change.
     */
    const FOLD_PASSTHROUGH = array(
        "\u{00AE}",
    );

    /**
     * strtr through FOLD_MAP, ASCII strtolower, whitespace runs collapsed, trimmed.
     */
    public static function fold(string $text): string
    {
        $text = strtolower(strtr($text, self::FOLD_MAP));
        $text = preg_replace('~[ \t\r\n\f\v]+~', ' ', $text);
        return trim((string) $text, ' ');
    }
}

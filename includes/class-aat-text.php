<?php
/**
 * Text repair for encoding damage (2.8.17).
 *
 * The site's database connection is latin1 (DB_CHARSET), so UTF-8 that passed
 * through it, or text pasted from Windows tools, can arrive in two broken
 * shapes:
 *
 * 1. Raw Windows-1252 bytes inside otherwise valid UTF-8, e.g. "Baker\x92s".
 *    Only the invalid bytes are converted; valid characters are never touched.
 * 2. Double-encoded UTF-8 ("mojibake"), e.g. "Bakerâ€™s" for "Baker’s",
 *    "cafÃ©" for "café", "ðŸŽ¬" for "🎬". Each damaged character is a lead
 *    character (Â..ô) followed by 1-3 continuation characters, which map back
 *    to the original UTF-8 bytes through Windows-1252 as MySQL's "latin1"
 *    defines it (the five bytes Windows-1252 leaves undefined become C1
 *    controls). A run is replaced only when those bytes form one valid UTF-8
 *    character, so real accented text, real emoji and real "Ã" are left alone,
 *    and mixed strings are repaired piece by piece.
 *
 * Lost characters (U+FFFD, "?") cannot be recovered from the bytes and are not
 * guessed here.
 *
 * @package AcademyAwardsTable
 */

// Only defines a class, so it is safe to load outside WordPress (tests, validators).

if (!class_exists('AAT_Text')) {
    class AAT_Text {
        /** Windows-1252 (MySQL latin1) bytes 0x80-0x9F as Unicode code points. */
        private static $cp1252_high = array(
            0x80 => 0x20AC, 0x81 => 0x0081, 0x82 => 0x201A, 0x83 => 0x0192,
            0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021,
            0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039,
            0x8C => 0x0152, 0x8D => 0x008D, 0x8E => 0x017D, 0x8F => 0x008F,
            0x90 => 0x0090, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C,
            0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
            0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
            0x9C => 0x0153, 0x9D => 0x009D, 0x9E => 0x017E, 0x9F => 0x0178,
        );

        private static $reverse = null;
        private static $pattern = null;

        /**
         * Repair both kinds of damage. Safe on clean text (returns it unchanged).
         *
         * @param mixed $text Text.
         * @return string Valid UTF-8.
         */
        public static function repair($text) {
            $text = (string) $text;
            if ($text === '') {
                return '';
            }
            $text = self::fix_invalid_bytes($text);
            // Text double-encoded twice comes back after two passes.
            for ($pass = 0; $pass < 2; $pass++) {
                $fixed = self::undo_double_utf8($text);
                if ($fixed === $text) {
                    break;
                }
                $text = $fixed;
            }
            return $text;
        }

        /**
         * Convert only bytes that are not part of a valid UTF-8 sequence, reading
         * each as Windows-1252.
         */
        public static function fix_invalid_bytes($text) {
            $text = (string) $text;
            if ($text === '' || preg_match('//u', $text) === 1) {
                return $text;
            }
            $fixed = preg_replace_callback(
                '/[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|([\x80-\xFF])/',
                function ($m) {
                    if (!isset($m[1])) {
                        return $m[0];
                    }
                    $byte = ord($m[1]);
                    $cp = $byte < 0xA0 ? self::$cp1252_high[$byte] : $byte;
                    return self::chr_utf8($cp);
                },
                $text
            );
            return is_string($fixed) ? $fixed : $text;
        }

        /**
         * Replace each double-encoded character with the character it stood for.
         */
        public static function undo_double_utf8($text) {
            $text = (string) $text;
            if ($text === '' || preg_match('//u', $text) !== 1) {
                return $text;
            }
            self::prepare();
            $fixed = preg_replace_callback(self::$pattern, function ($m) {
                $bytes = '';
                foreach (preg_split('//u', $m[0], -1, PREG_SPLIT_NO_EMPTY) as $char) {
                    $cp = self::ord_utf8($char);
                    if (!isset(self::$reverse[$cp])) {
                        return $m[0];
                    }
                    $bytes .= chr(self::$reverse[$cp]);
                }
                return (preg_match('//u', $bytes) === 1 && strlen($bytes) > 1) ? $bytes : $m[0];
            }, $text);
            return is_string($fixed) ? $fixed : $text;
        }

        /**
         * True when text still shows the usual signs of mojibake.
         */
        public static function looks_damaged($text) {
            return (bool) preg_match('/\x{FFFD}|Ã[\x{0080}-\x{00BF}\x{2018}-\x{203A}\x{20AC}\x{2122}\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}\x{02C6}\x{02DC}]|â€|Â[\x{00A0}-\x{00BF}]|ð\x{0178}/u', (string) $text);
        }

        private static function prepare() {
            if (self::$pattern !== null) {
                return;
            }
            self::$reverse = array();
            for ($byte = 0x80; $byte <= 0xFF; $byte++) {
                $cp = $byte < 0xA0 ? self::$cp1252_high[$byte] : $byte;
                self::$reverse[$cp] = $byte;
            }
            // Continuation characters: what bytes 0x80-0xBF look like after the round trip.
            $cont = '';
            for ($byte = 0x80; $byte <= 0xBF; $byte++) {
                $cp = $byte < 0xA0 ? self::$cp1252_high[$byte] : $byte;
                $cont .= sprintf('\x{%04X}', $cp);
            }
            $c = '[' . $cont . ']';
            self::$pattern = '/[\x{00C2}-\x{00DF}]' . $c . '|[\x{00E0}-\x{00EF}]' . $c . '{2}|[\x{00F0}-\x{00F4}]' . $c . '{3}/u';
        }

        private static function chr_utf8($cp) {
            if ($cp < 0x80) {
                return chr($cp);
            }
            if ($cp < 0x800) {
                return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
            }
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }

        private static function ord_utf8($char) {
            $b = array_values(unpack('C*', $char));
            $n = count($b);
            if ($n === 1) {
                return $b[0];
            }
            if ($n === 2) {
                return (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F);
            }
            if ($n === 3) {
                return (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F);
            }
            return (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F);
        }
    }
}

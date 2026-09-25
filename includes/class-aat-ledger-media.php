<?php
/**
 * Posters and portraits for the Oscar Ledger Explorer and its read API.
 *
 * Reads only artwork the site already holds: the poster mapped to a title (a
 * review's featured image or the poster table), a person's portrait in the
 * media library, and TMDB artwork the importers have already cached. Like
 * every public page, it never calls TMDB while a visitor waits, and it goes
 * through the same plugin lookups as the profile pages, so a film or a person
 * shows the same image in the Explorer as on its own page.
 *
 * A title or person with no artwork gets a monogram plate instead, so every
 * row keeps the same media box.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AAT_Ledger_Media {

    const CACHE_GROUP = 'aat_ledger_media';
    const CACHE_TTL = 21600; // 6 hours, so a new upload shows up the same day.
    const VERSION = 1;

    /** @var array Request-local answers, keyed by entity ID. */
    private static $memo = array();

    /**
     * Artwork for an IMDb title or person ID.
     *
     * @return array array('attachment' => int, 'url' => string, 'kind' => 'poster'|'portrait'), or array() when there is none.
     */
    public static function for_id($id) {
        $id = strtolower(trim((string) $id));
        if (!preg_match('/^(tt|nm)\d{7,10}$/', $id)) {
            return array();
        }
        if (array_key_exists($id, self::$memo)) {
            return self::$memo[$id];
        }
        $key = 'v' . self::VERSION . ':' . $id;
        $hit = wp_cache_get($key, self::CACHE_GROUP);
        if (is_array($hit)) {
            return self::$memo[$id] = $hit;
        }
        $media = strpos($id, 'tt') === 0 ? self::film($id) : self::person($id);
        wp_cache_set($key, $media, self::CACHE_GROUP, self::CACHE_TTL);
        return self::$memo[$id] = $media;
    }

    /**
     * The first of $ids that has artwork, in the order given.
     */
    public static function first($ids) {
        foreach ((array) $ids as $id) {
            $media = self::for_id($id);
            if (!empty($media)) {
                return $media;
            }
        }
        return array();
    }

    /**
     * An <img> for $media, decorative (alt=""): the name it shows is always
     * printed beside it. '' when $media is empty.
     */
    public static function img($media, $class, $sizes, $width = 200, $height = 300) {
        if (!empty($media['attachment'])) {
            $html = wp_get_attachment_image((int) $media['attachment'], 'medium', false, array(
                'class' => $class,
                'alt' => '',
                'loading' => 'lazy',
                'decoding' => 'async',
                'sizes' => $sizes,
            ));
            if (is_string($html) && $html !== '') {
                return $html;
            }
        }
        if (!empty($media['url'])) {
            return '<img class="' . esc_attr($class) . '" src="' . esc_url($media['url']) . '" alt="" width="' . (int) $width . '" height="' . (int) $height . '" loading="lazy" decoding="async">';
        }
        return '';
    }

    /**
     * A plain image URL for $media (the API's search suggestions), or ''.
     */
    public static function url($media) {
        if (!empty($media['attachment'])) {
            $url = wp_get_attachment_image_url((int) $media['attachment'], 'medium');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        return !empty($media['url']) ? (string) $media['url'] : '';
    }

    /**
     * Up to two initials for a monogram plate: "Industrial Light & Magic"
     * gives "IM", "The Godfather" gives "G". Leading articles are skipped. A
     * ceremony number ("98") is kept whole.
     */
    public static function initials($label) {
        $label = trim(wp_strip_all_tags((string) $label));
        if (preg_match('/^\d{1,3}$/', $label)) {
            return $label;
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', $label, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || empty($words)) {
            return '';
        }
        if (count($words) > 1 && in_array(strtolower($words[0]), array('the', 'a', 'an'), true)) {
            array_shift($words);
        }
        $first = self::letter($words[0]);
        $last = count($words) > 1 ? self::letter($words[count($words) - 1]) : '';
        return $first . $last;
    }

    private static function letter($word) {
        return function_exists('mb_substr') ? mb_strtoupper(mb_substr($word, 0, 1)) : strtoupper(substr($word, 0, 1));
    }

    private static function film($tt) {
        $plugin = Academy_Awards_Table::get_instance();
        $attachment = method_exists($plugin, 'get_poster_attachment_id_for_title') ? (int) $plugin->get_poster_attachment_id_for_title($tt) : 0;
        if ($attachment > 0 && wp_attachment_is_image($attachment)) {
            return array('attachment' => $attachment, 'url' => '', 'kind' => 'poster');
        }
        $tmdb = method_exists($plugin, 'get_tmdb_data_for_imdb_id') ? (array) $plugin->get_tmdb_data_for_imdb_id($tt, false) : array();
        $url = self::tmdb_size((string) ($tmdb['poster_full'] ?? ''), 'w342');
        return $url !== '' ? array('attachment' => 0, 'url' => $url, 'kind' => 'poster') : array();
    }

    private static function person($nm) {
        $plugin = Academy_Awards_Table::get_instance();
        $package = method_exists($plugin, 'get_person_visual_package') ? (array) $plugin->get_person_visual_package($nm, 'medium', false) : array();
        $attachment = (int) ($package['portrait_attachment_id'] ?? 0);
        if ($attachment > 0 && ($package['visual_source'] ?? '') === 'local-media-library' && wp_attachment_is_image($attachment)) {
            return array('attachment' => $attachment, 'url' => '', 'kind' => 'portrait');
        }
        $url = (string) ($package['portrait_url'] ?? '');
        if ($url !== '' && ($package['visual_source'] ?? '') === 'tmdb-person-profile') {
            $url = self::tmdb_size($url, 'w185');
        }
        return $url !== '' ? array('attachment' => 0, 'url' => $url, 'kind' => 'portrait') : array();
    }

    /**
     * A TMDB image URL at a thumbnail size. Anything that is not an https
     * image.tmdb.org URL comes back as ''.
     */
    private static function tmdb_size($url, $size) {
        $url = trim($url);
        if (strpos($url, 'https://image.tmdb.org/t/p/') !== 0) {
            return '';
        }
        return (string) preg_replace('#^https://image\.tmdb\.org/t/p/[^/]+/#', 'https://image.tmdb.org/t/p/' . $size . '/', $url);
    }
}

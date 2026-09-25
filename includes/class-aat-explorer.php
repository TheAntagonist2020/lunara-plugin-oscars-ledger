<?php
/**
 * Oscar Ledger Explorer at /{base}/explore/.
 *
 * One server-rendered page over the read API (class-aat-read-api.php), which
 * it calls in-process with rest_do_request(). Nothing reaches SQL from here.
 * The page works without JavaScript. With it, filtering is instant:
 * assets/js/ledger-explorer.js fetches the same URL with fragment=1 and swaps
 * in the re-rendered regions, so every region has exactly one renderer (this
 * file).
 *
 * URL state: q, ceremony, decade, class, category, winner, entity (up to four
 * IDs), by (film, person, company, category or ceremony; empty = nominations),
 * sort (newest|oldest) and pg. Only the bare page is indexable; every filtered
 * or paged state is noindex,follow, and its links carry rel=nofollow.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class AAT_Explorer {

    const QUERY_VAR = 'aat_explore';
    const PER_PAGE = 25;
    const VIEWS = array('' => 'Nominations', 'film' => 'By film', 'person' => 'By person', 'company' => 'By company', 'category' => 'By category', 'ceremony' => 'By ceremony');

    private static $state = null;
    private static $page = null;

    public static function init() {
        add_action('init', array(__CLASS__, 'register_rewrite'), 9);
        add_action('init', array(__CLASS__, 'ensure_rewrite'), 20);
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('template_redirect', array(__CLASS__, 'template_redirect'), 1);
        add_filter('template_include', array(__CLASS__, 'template_include'), 20);
        add_filter('pre_get_document_title', array(__CLASS__, 'document_title'), 30);
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('wp_robots', array(__CLASS__, 'robots'));
        add_action('wp_head', array(__CLASS__, 'canonical'), 2);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }

    /* ------------------------------------------------------------------
     * Route
     * ------------------------------------------------------------------ */

    public static function register_rewrite() {
        $base = self::plugin()->get_entity_base_slug();
        add_rewrite_rule('^' . preg_quote($base, '/') . '/explore/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
    }

    /**
     * The plugin flushes permalinks once per version at init 10, after this
     * rule registers at 9. If the saved rules still lack it (for example after
     * a flush from elsewhere), flush once, at most hourly.
     */
    public static function ensure_rewrite() {
        $rules = get_option('rewrite_rules');
        if (!is_array($rules) || empty($rules)) {
            return;
        }
        $key = '^' . preg_quote(self::plugin()->get_entity_base_slug(), '/') . '/explore/?$';
        if (!isset($rules[$key]) && get_transient('aat_explore_rewrite_flush') === false) {
            set_transient('aat_explore_rewrite_flush', 1, HOUR_IN_SECONDS);
            flush_rewrite_rules(false);
        }
    }

    public static function query_vars($vars) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function is_request() {
        return (bool) get_query_var(self::QUERY_VAR);
    }

    public static function base_url() {
        return self::plugin()->get_entity_base_url() . 'explore/';
    }

    public static function template_redirect() {
        if (!self::is_request()) {
            return;
        }
        global $wp_query;
        $wp_query->is_404 = false;
        $wp_query->is_page = true;
        status_header(200);

        if (isset($_GET['fragment']) && $_GET['fragment'] === '1') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $page = self::page();
            header('Content-Type: application/json; charset=' . get_option('blog_charset'));
            header('Cache-Control: public, max-age=300');
            header('X-Robots-Tag: noindex');
            echo wp_json_encode(array(
                'url' => $page['url'],
                'title' => self::document_title(''),
                'regions' => $page['regions'],
            ));
            exit;
        }
    }

    public static function template_include($template) {
        if (!self::is_request()) {
            return $template;
        }
        $file = AAT_PLUGIN_DIR . 'templates/explorer-page.php';
        return file_exists($file) ? $file : $template;
    }

    public static function document_title($title) {
        if (!self::is_request()) {
            return $title;
        }
        $page = self::page();
        $parts = array_filter(array($page['heading'], 'Oscar Ledger Explorer', get_bloginfo('name')));
        return implode(' - ', array_unique($parts));
    }

    public static function body_class($classes) {
        if (self::is_request()) {
            $classes[] = 'aat-shell-page';
            $classes[] = 'aat-shell-explorer';
        }
        return $classes;
    }

    public static function robots($robots) {
        if (self::is_request() && self::has_state()) {
            $robots['noindex'] = true;
            $robots['follow'] = true;
            unset($robots['index']);
        }
        return $robots;
    }

    public static function canonical() {
        if (self::is_request()) {
            echo '<link rel="canonical" href="' . esc_url(self::base_url()) . '" />' . "\n";
        }
    }

    public static function enqueue() {
        if (!self::is_request()) {
            return;
        }
        $css = 'assets/css/ledger-explorer.css';
        $js = 'assets/js/ledger-explorer.js';
        wp_enqueue_style('aat-ledger-explorer', AAT_PLUGIN_URL . $css, array(), self::asset_version($css));
        wp_enqueue_script('aat-ledger-explorer', AAT_PLUGIN_URL . $js, array(), self::asset_version($js), true);
    }

    private static function asset_version($relative) {
        $file = AAT_PLUGIN_DIR . $relative;
        return file_exists($file) ? AAT_VERSION . '.' . filemtime($file) : AAT_VERSION;
    }

    /* ------------------------------------------------------------------
     * State
     * ------------------------------------------------------------------ */

    /** The URL state, normalized. Invalid values are dropped, never passed on. */
    public static function state() {
        if (self::$state !== null) {
            return self::$state;
        }
        $get = function ($key) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return (isset($_GET[$key]) && is_scalar($_GET[$key])) ? trim(sanitize_text_field(wp_unslash((string) $_GET[$key]))) : '';
        };

        $state = array('q' => '', 'ceremony' => 0, 'decade' => 0, 'class' => '', 'category' => '', 'winner' => false, 'entity' => array(), 'by' => '', 'sort' => 'newest', 'pg' => 1);

        $q = $get('q');
        $state['q'] = function_exists('mb_substr') ? mb_substr($q, 0, 80) : substr($q, 0, 80);

        $ceremony = $get('ceremony');
        if (ctype_digit($ceremony) && (int) $ceremony >= 1 && (int) $ceremony <= 200) {
            $state['ceremony'] = (int) $ceremony;
        }
        $decade = $get('decade');
        if ($state['ceremony'] === 0 && ctype_digit($decade) && (int) $decade % 10 === 0 && (int) $decade >= 1920 && (int) $decade <= 2090) {
            $state['decade'] = (int) $decade;
        }

        $categories = self::categories();
        $category = sanitize_title($get('category'));
        if ($category !== '' && isset($categories[$category])) {
            $state['category'] = $category;
            $state['class'] = $categories[$category]['class'];
        } else {
            $class = $get('class');
            if ($class !== '' && in_array($class, wp_list_pluck($categories, 'class'), true)) {
                $state['class'] = $class;
            }
        }

        $state['winner'] = $get('winner') === '1';

        $ids = array_filter(array_map('trim', explode(',', strtolower($get('entity')))), function ($id) {
            return (bool) preg_match(AAT_Read_API::ID_PATTERN, $id);
        });
        $ids = array_slice(array_values(array_unique($ids)), 0, AAT_Read_API::MAX_ENTITIES);
        sort($ids, SORT_STRING);
        $state['entity'] = $ids;

        $by = $get('by');
        $state['by'] = array_key_exists($by, self::VIEWS) ? $by : '';
        $state['sort'] = $get('sort') === 'oldest' ? 'oldest' : 'newest';
        $pg = (int) $get('pg');
        $state['pg'] = max(1, min(1000, $pg ?: 1));

        self::$state = $state;
        return $state;
    }

    private static function has_state() {
        $state = self::state();
        return $state['q'] !== '' || $state['ceremony'] || $state['decade'] || $state['class'] !== '' || $state['category'] !== ''
            || $state['winner'] || !empty($state['entity']) || $state['by'] !== '' || $state['sort'] !== 'newest' || $state['pg'] > 1;
    }

    /**
     * An explorer URL for $state with $changes applied. A filter change resets
     * the page; ceremony and decade exclude each other; a category implies its class.
     */
    public static function url($state, $changes = array()) {
        $next = array_merge($state, $changes);
        if (!array_key_exists('pg', $changes)) {
            $next['pg'] = 1;
        }
        if (!empty($changes['ceremony'])) {
            $next['decade'] = 0;
        }
        if (!empty($changes['decade'])) {
            $next['ceremony'] = 0;
        }
        if (array_key_exists('class', $changes) && !array_key_exists('category', $changes)) {
            $next['category'] = '';
        }
        $args = array();
        foreach (array('ceremony', 'decade', 'class', 'category') as $key) {
            if (!empty($next[$key])) {
                $args[$key] = $next[$key];
            }
        }
        if (!empty($next['category'])) {
            unset($args['class']);
        }
        if (!empty($next['winner'])) {
            $args['winner'] = 1;
        }
        if (!empty($next['entity'])) {
            $args['entity'] = implode(',', (array) $next['entity']);
        }
        if (!empty($next['by'])) {
            $args['by'] = $next['by'];
        }
        if (($next['sort'] ?? 'newest') === 'oldest') {
            $args['sort'] = 'oldest';
        }
        if (($next['pg'] ?? 1) > 1) {
            $args['pg'] = (int) $next['pg'];
        }
        return empty($args) ? self::base_url() : add_query_arg(array_map('rawurlencode', array_map('strval', $args)), self::base_url());
    }

    /* ------------------------------------------------------------------
     * Page model: every region rendered once, for the page and for fragments
     * ------------------------------------------------------------------ */

    public static function page() {
        if (self::$page !== null) {
            return self::$page;
        }
        $state = self::state();
        $notices = array();

        // Free text from the no-JS form: take the best match, as the typeahead would.
        if ($state['q'] !== '') {
            $found = self::api('/search', array('q' => $state['q'], 'limit' => 1));
            $best = (is_array($found) && !empty($found['results'][0])) ? $found['results'][0] : null;
            if ($best) {
                $explore = (array) ($best['explore'] ?? array());
                $state = array_merge($state, array('ceremony' => 0, 'decade' => 0, 'class' => '', 'category' => '', 'entity' => array(), 'by' => '', 'pg' => 1), self::explore_to_state($explore));
                $notices[] = sprintf('Showing %s for “%s”.', esc_html($best['name']), esc_html($state['q']));
            } else {
                $notices[] = sprintf('No person, film, company, category or year matches “%s”.', esc_html($state['q']));
            }
            $state['q'] = '';
            self::$state = $state;
        }

        $filters = self::api_filters($state);
        $status = self::api('/status');
        $facets = self::api('/facets', $filters);
        if (is_wp_error($facets)) {
            $notices[] = 'Those filters could not be combined, so they were cleared.';
            $state = array_merge($state, array('ceremony' => 0, 'decade' => 0, 'class' => '', 'category' => '', 'winner' => false, 'entity' => array(), 'pg' => 1));
            self::$state = $state;
            $filters = self::api_filters($state);
            $facets = self::api('/facets', $filters);
        }

        $entities = array();
        foreach ($state['entity'] as $id) {
            $entity = self::api('/entities/' . $id);
            $entities[$id] = is_array($entity) ? $entity : array('id' => $id, 'name' => strtoupper($id), 'missing' => true);
        }

        if ($state['by'] !== '') {
            $list = self::api('/groups', $filters + array('by' => $state['by'], 'page' => $state['pg'], 'per_page' => self::PER_PAGE));
        } else {
            $list = self::api('/nominations', $filters + array('sort' => $state['sort'], 'page' => $state['pg'], 'per_page' => self::PER_PAGE));
        }
        if (is_wp_error($list)) {
            $list = array('total' => 0, 'items' => array(), 'page' => 1, 'pages' => 1);
            $notices[] = 'The ledger could not be read just now. Please try again.';
        }

        $heading = self::heading($state, $entities);
        self::$page = array(
            'state' => $state,
            'url' => self::url($state, array('pg' => $state['pg'])),
            'heading' => $heading,
            'status' => is_array($status) ? $status : array(),
            'regions' => array(
                'filters' => self::render_filters($state, is_array($facets) ? $facets : array()),
                'chips' => self::render_chips($state, $entities, $notices),
                'debrief' => count($entities) === 1 ? self::render_debrief(reset($entities), $state) : '',
                'status' => self::render_status($state, $list),
                'results' => $state['by'] !== '' ? self::render_groups($state, $list) : self::render_nominations($state, $list),
                'pager' => self::render_pager($state, $list),
            ),
        );
        return self::$page;
    }

    /** The whole page body; the template wraps it in the theme's header and footer. */
    public static function render_page() {
        $page = self::page();
        $state = $page['state'];
        $dataset = (array) ($page['status']['dataset'] ?? array());
        $search_url = rest_url(AAT_Read_API::NS . '/search');

        ob_start();
        ?>
<div id="lle" class="lle" data-lle-root data-lle-base="<?php echo esc_url(self::base_url()); ?>" data-lle-search="<?php echo esc_url($search_url); ?>">
    <section class="lle-hero" aria-labelledby="lle-title">
        <div class="lle-hero__inner">
            <p class="lle-kicker">Lunara · The Oscar Ledger</p>
            <h1 id="lle-title" class="lle-title">Oscar Ledger Explorer</h1>
            <p class="lle-dek">Every nomination and every win, from the 1st Academy Awards to the <?php echo esc_html(self::plugin()->ordinal((int) ($dataset['ceremonies'] ?? 98))); ?>, checked against the Academy&rsquo;s own records.</p>
            <form class="lle-search" role="search" action="<?php echo esc_url(self::base_url()); ?>" method="get" data-lle-search-form>
                <label class="lle-sr" for="lle-q">Search people, films, companies, categories or a year</label>
                <div class="lle-combobox">
                    <svg class="lle-search__icon" aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M10 3a7 7 0 0 1 5.6 11.2l4.6 4.6-1.4 1.4-4.6-4.6A7 7 0 1 1 10 3zm0 2a5 5 0 1 0 0 10 5 5 0 0 0 0-10z"/></svg>
                    <input id="lle-q" class="lle-search__input" name="q" type="search" autocomplete="off" spellcheck="false" enterkeyhint="search"
                        placeholder="Search a person, film, category or year" role="combobox" aria-expanded="false" aria-controls="lle-suggest" aria-autocomplete="list">
                    <button class="lle-search__go" type="submit">Search</button>
                    <ul id="lle-suggest" class="lle-suggest" role="listbox" aria-label="Suggestions" hidden></ul>
                </div>
            </form>
            <?php if (!empty($dataset['nominations'])) : ?>
            <p class="lle-stats"><span><?php echo esc_html(number_format_i18n((int) $dataset['nominations'])); ?> nominations</span><span><?php echo esc_html(number_format_i18n((int) $dataset['wins'])); ?> wins</span><span><?php echo esc_html(number_format_i18n((int) $dataset['ceremonies'])); ?> ceremonies</span></p>
            <?php endif; ?>
            <p class="lle-try">Try
                <a rel="nofollow" data-lle-nav href="<?php echo esc_url(self::url(self::blank(), array('category' => 'best-picture', 'winner' => true))); ?>">Every Best Picture</a>
                <a rel="nofollow" data-lle-nav href="<?php echo esc_url(self::url(self::blank(), array('entity' => array('nm0000658')))); ?>">Meryl Streep</a>
                <a rel="nofollow" data-lle-nav href="<?php echo esc_url(self::url(self::blank(), array('ceremony' => 69))); ?>">Films of 1996</a>
                <a rel="nofollow" data-lle-nav href="<?php echo esc_url(self::url(self::blank(), array('by' => 'person', 'winner' => true))); ?>">Most-awarded people</a>
            </p>
        </div>
    </section>

    <div class="lle-bar" data-lle-region="filters"><?php echo $page['regions']['filters']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered and escaped below ?></div>

    <div class="lle-body">
        <div data-lle-region="chips"><?php echo $page['regions']['chips']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <div data-lle-region="debrief"><?php echo $page['regions']['debrief']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <div data-lle-region="status"><?php echo $page['regions']['status']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <div class="lle-results" data-lle-region="results" aria-busy="false"><?php echo $page['regions']['results']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <div data-lle-region="pager"><?php echo $page['regions']['pager']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    </div>

    <footer class="lle-attrib">
        <p>Data: <a href="https://github.com/DLu/oscar_data" rel="noopener">DLu/oscar_data</a> (BSD 2-Clause), corrected and reconciled by Lunara against the Academy Awards Database. Every correction is logged with its evidence. Developers: <a href="<?php echo esc_url(rest_url(AAT_Read_API::NS . '/status')); ?>">the Oscar Ledger API</a>.</p>
    </footer>
</div>
        <?php
        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * Regions
     * ------------------------------------------------------------------ */

    private static function render_filters($state, $facets) {
        $select = function ($name, $label, $options, $selected, $all_label) {
            $html = '<label class="lle-field"><span class="lle-field__label">' . esc_html($label) . '</span><select name="' . esc_attr($name) . '" class="lle-select">';
            $html .= '<option value="">' . esc_html($all_label) . '</option>';
            foreach ($options as $value => $text) {
                $html .= '<option value="' . esc_attr((string) $value) . '"' . selected((string) $selected, (string) $value, false) . '>' . esc_html($text) . '</option>';
            }
            return $html . '</select></label>';
        };
        $count = function ($row) {
            return ' (' . number_format_i18n((int) ($row['nominations'] ?? 0)) . ')';
        };

        $views = self::VIEWS;
        unset($views['']);

        $decades = array();
        foreach ((array) ($facets['decade'] ?? array()) as $row) {
            $decades[(int) $row['value']] = $row['label'] . $count($row);
        }
        $ceremonies = array();
        foreach ((array) ($facets['ceremony'] ?? array()) as $row) {
            $year = (int) substr((string) $row['year_label'], 0, 4);
            if ($state['decade'] && ($year < $state['decade'] || $year > $state['decade'] + 9)) {
                continue;
            }
            $ceremonies[(int) $row['value']] = $row['label'] . ' · ' . $row['year_label'] . $count($row);
        }
        $classes = array();
        foreach ((array) ($facets['class'] ?? array()) as $row) {
            $classes[$row['value']] = $row['label'] . $count($row);
        }
        $categories = array();
        foreach ((array) ($facets['category'] ?? array()) as $row) {
            $categories[$row['value']] = $row['label'] . $count($row);
        }

        $html = '<form class="lle-filters" action="' . esc_url(self::base_url()) . '" method="get" data-lle-filters>';
        $html .= '<div class="lle-filters__row">';
        $html .= $select('by', 'View', $views, $state['by'], 'Nominations');
        $html .= $select('decade', 'Decade', $decades, $state['decade'] ?: '', 'All decades');
        $html .= $select('ceremony', 'Ceremony', $ceremonies, $state['ceremony'] ?: '', 'All ceremonies');
        $html .= $select('class', 'Class', $classes, $state['class'], 'All classes');
        $html .= $select('category', 'Category', $categories, $state['category'], 'All categories');
        if ($state['by'] === '') {
            $html .= $select('sort', 'Order', array('oldest' => 'Oldest first'), $state['sort'] === 'oldest' ? 'oldest' : '', 'Newest first');
        }
        $wins = isset($facets['wins']) ? ' (' . number_format_i18n((int) $facets['wins']) . ')' : '';
        $html .= '<label class="lle-toggle"><input type="checkbox" name="winner" value="1"' . checked($state['winner'], true, false) . '><span>Winners only' . esc_html($wins) . '</span></label>';
        $html .= '</div>';
        if (!empty($state['entity'])) {
            $html .= '<input type="hidden" name="entity" value="' . esc_attr(implode(',', $state['entity'])) . '">';
        }
        $html .= '<noscript><button class="lle-apply" type="submit">Apply</button></noscript>';
        $html .= '</form>';
        return $html;
    }

    private static function render_chips($state, $entities, $notices) {
        $html = '';
        foreach ($notices as $notice) {
            $html .= '<p class="lle-notice">' . $notice . '</p>'; // Notices are built from escaped parts.
        }
        $chips = '';
        foreach ($entities as $id => $entity) {
            $rest = array_values(array_diff($state['entity'], array($id)));
            $chips .= '<span class="lle-chip"><span class="lle-chip__kind">' . esc_html(self::kind_label($entity['kind'] ?? '')) . '</span> ' . esc_html((string) ($entity['name'] ?? $id))
                . ' <a class="lle-chip__x" rel="nofollow" data-lle-nav href="' . esc_url(self::url($state, array('entity' => $rest))) . '" aria-label="' . esc_attr('Remove ' . ($entity['name'] ?? $id)) . '">×</a></span>';
        }
        if (self::has_state()) {
            $chips .= '<a class="lle-clear" rel="nofollow" data-lle-nav href="' . esc_url(self::base_url()) . '">Clear all</a>';
        }
        return $html . ($chips !== '' ? '<div class="lle-chips">' . $chips . '</div>' : '');
    }

    private static function render_debrief($entity, $state) {
        if (!empty($entity['missing'])) {
            return '';
        }
        $plugin = self::plugin();
        $id = (string) $entity['id'];
        $poster = '';
        if (($entity['kind'] ?? '') === 'film' && method_exists($plugin, 'get_poster_img_html_for_title')) {
            $poster = (string) $plugin->get_poster_img_html_for_title($id, 'medium', array('class' => 'lle-debrief__poster', 'width' => 120, 'height' => 180));
        }
        $span = '';
        if (!empty($entity['first_ceremony']) && !empty($entity['last_ceremony'])) {
            $first = $entity['first_ceremony'];
            $last = $entity['last_ceremony'];
            $span = $first['ceremony'] === $last['ceremony']
                ? sprintf('%s Academy Awards (%s)', $first['label'], $first['year_label'])
                : sprintf('%s (%s) to %s (%s)', $first['label'], $first['year_label'], $last['label'], $last['year_label']);
        }
        $html = '<aside class="lle-debrief" aria-label="' . esc_attr('About ' . $entity['name']) . '">';
        if ($poster !== '') {
            $html .= '<div class="lle-debrief__media">' . $poster . '</div>';
        }
        $html .= '<div class="lle-debrief__body">';
        $html .= '<p class="lle-kicker">' . esc_html(self::kind_label($entity['kind'] ?? '')) . ' · Debrief</p>';
        $html .= '<h2 class="lle-debrief__name">' . esc_html($entity['name']) . '</h2>';
        $html .= '<p class="lle-debrief__stats"><strong>' . esc_html(number_format_i18n((int) $entity['nominations'])) . '</strong> ' . esc_html(_n('nomination', 'nominations', (int) $entity['nominations'])) . ' · <strong>'
            . esc_html(number_format_i18n((int) $entity['wins'])) . '</strong> ' . esc_html(_n('win', 'wins', (int) $entity['wins'])) . ' · <strong>'
            . esc_html(number_format_i18n((int) $entity['ceremonies'])) . '</strong> ' . esc_html(_n('ceremony', 'ceremonies', (int) $entity['ceremonies'])) . '</p>';
        if ($span !== '') {
            $html .= '<p class="lle-debrief__span">' . esc_html($span) . '</p>';
        }
        if (!empty($entity['categories'])) {
            $html .= '<ul class="lle-debrief__cats">';
            foreach (array_slice($entity['categories'], 0, 8) as $category) {
                $html .= '<li><a rel="nofollow" data-lle-nav href="' . esc_url(self::url($state, array('category' => $category['slug']))) . '">' . esc_html($category['name'])
                    . ' <span>' . esc_html($category['nominations'] . ' · ' . $category['wins'] . ' ' . _n('win', 'wins', (int) $category['wins'])) . '</span></a></li>';
            }
            $html .= '</ul>';
        }
        $html .= '<p class="lle-debrief__links"><a href="' . esc_url($entity['url']) . '">Full Oscar profile</a> <a href="' . esc_url($entity['imdb_url']) . '" rel="noopener">IMDb</a></p>';
        $html .= '</div></aside>';
        return $html;
    }

    private static function render_status($state, $list) {
        $total = (int) ($list['total'] ?? 0);
        $page = (int) ($list['page'] ?? 1);
        $first = $total ? ($page - 1) * self::PER_PAGE + 1 : 0;
        $last = min($total, $page * self::PER_PAGE);
        if ($state['by'] !== '') {
            $noun = array('film' => array('film', 'films'), 'person' => array('person', 'people'), 'company' => array('company', 'companies'), 'category' => array('category', 'categories'), 'ceremony' => array('ceremony', 'ceremonies'));
            $text = $total ? sprintf('%s–%s of %s %s', number_format_i18n($first), number_format_i18n($last), number_format_i18n($total), $total === 1 ? $noun[$state['by']][0] : $noun[$state['by']][1]) : 'Nothing matches these filters.';
        } else {
            $wins = (int) ($list['wins'] ?? 0);
            $text = $total
                ? sprintf('%s–%s of %s %s · %s %s', number_format_i18n($first), number_format_i18n($last), number_format_i18n($total), _n('nomination', 'nominations', $total), number_format_i18n($wins), _n('win', 'wins', $wins))
                : 'No nominations match these filters.';
        }
        return '<p id="lle-status" class="lle-status" role="status" aria-live="polite">' . esc_html($text) . '</p>';
    }

    private static function render_nominations($state, $list) {
        $items = (array) ($list['items'] ?? array());
        if (empty($items)) {
            return '<div class="lle-empty"><p>No records match these filters.</p><p><a rel="nofollow" data-lle-nav href="' . esc_url(self::base_url()) . '">Start over</a></p></div>';
        }
        $html = '';
        $current_ceremony = null;
        $current_category = null;
        foreach ($items as $record) {
            if ($record['ceremony'] !== $current_ceremony) {
                if ($current_ceremony !== null) {
                    $html .= '</section>';
                }
                $current_ceremony = $record['ceremony'];
                $current_category = null;
                $html .= '<section class="lle-ceremony"><h2 class="lle-ceremony__title"><a href="' . esc_url($record['ceremony_url']) . '">' . esc_html($record['ceremony_label'] . ' Academy Awards') . '</a> <span>' . esc_html(self::year_phrase($record['year_label'])) . '</span></h2>';
            }
            if ($record['category']['slug'] !== $current_category) {
                $current_category = $record['category']['slug'];
                $html .= '<h3 class="lle-category"><a href="' . esc_url($record['category']['url']) . '">' . esc_html($record['category']['name']) . '</a> <span>' . esc_html($record['category']['class_label']) . '</span></h3>';
            }
            $html .= self::render_record($record);
        }
        return $html . '</section>';
    }

    private static function render_record($record) {
        $films = self::render_slots($record['films'], true);
        $people = self::render_slots($record['nominees'], false);
        $roles = array_values(array_filter((array) $record['detail'], 'strlen'));
        $film_led = $record['category']['class'] === 'Title' || $people === '';

        $lead = $film_led ? $films : $people;
        $sub = array();
        if ($film_led && $people !== '') {
            $sub[] = $people;
        }
        if (!$film_led && $films !== '') {
            $sub[] = $films;
        }
        if ($record['category']['class'] === 'Acting' && !empty($roles)) {
            $sub[] = '<span class="lle-role">as ' . self::join_names($roles) . '</span>';
        }
        if ($lead === '') {
            $lead = esc_html($record['citation'] !== '' ? $record['citation'] : $record['credit']);
        }

        $html = '<article class="lle-row' . ($record['winner'] ? ' is-won' : '') . '">';
        $html .= '<p class="lle-row__result">' . ($record['winner'] ? '<span aria-hidden="true">★</span> Won' : 'Nominated') . '</p>';
        $html .= '<div class="lle-row__main"><p class="lle-row__lead">' . $lead . '</p>';
        if (!empty($sub)) {
            $html .= '<p class="lle-row__sub">' . implode(' <span class="lle-dot" aria-hidden="true">·</span> ', $sub) . '</p>';
        }

        $facts = array();
        if ($record['category']['as_given'] !== '' && strcasecmp($record['category']['as_given'], $record['category']['name']) !== 0) {
            $facts['Category as given'] = esc_html(AAT_Read_API::category_name($record['category']['as_given']));
        }
        if ($record['credit'] !== '') {
            $facts['Credited'] = esc_html($record['credit']);
        }
        if (!empty($roles) && $record['category']['class'] !== 'Acting') {
            $facts['Detail'] = self::join_names($roles);
        }
        if ($record['note'] !== '') {
            $facts['Note'] = esc_html($record['note']);
        }
        if ($record['citation'] !== '' && $lead !== esc_html($record['citation'])) {
            $facts['Citation'] = esc_html($record['citation']);
        }
        $facts['Record'] = esc_html('#' . $record['id'] . ' · ' . $record['ceremony_label'] . ' ceremony · films of ' . $record['year_label']);
        $html .= '<details class="lle-record"><summary>Record</summary><dl>';
        foreach ($facts as $term => $value) {
            $html .= '<dt>' . esc_html($term) . '</dt><dd>' . $value . '</dd>';
        }
        $html .= '</dl></details></div></article>';
        return $html;
    }

    /**
     * Linked names for one field. One ID: the canonical name, with "credited
     * as" when the credit differs. Several IDs (a joint credit): the credit,
     * then everyone it names. No ID: the credit as plain text.
     */
    private static function render_slots($slots, $is_film) {
        $out = array();
        foreach ((array) $slots as $slot) {
            $name = (string) $slot['name'];
            $links = (array) ($slot['links'] ?? array());
            if (count($links) === 1) {
                $link = $links[0];
                $text = '<a href="' . esc_url($link['url']) . '">' . esc_html($link['name']) . '</a>';
                if (self::fold($link['name']) !== self::fold($name)) {
                    $text .= ' <span class="lle-alias">credited as ' . esc_html($name) . '</span>';
                }
            } elseif (count($links) > 1) {
                $names = array();
                foreach ($links as $link) {
                    $names[] = '<a href="' . esc_url($link['url']) . '">' . esc_html($link['name']) . '</a>';
                }
                $text = esc_html($name) . ' <span class="lle-alias">(' . self::join_names($names, false) . ')</span>';
            } else {
                $text = esc_html($name);
            }
            $out[] = $is_film ? '<cite class="lle-film">' . $text . '</cite>' : '<span class="lle-name">' . $text . '</span>';
        }
        return self::join_names($out, false);
    }

    private static function render_groups($state, $list) {
        $items = (array) ($list['items'] ?? array());
        if (empty($items)) {
            return '<div class="lle-empty"><p>Nothing matches these filters.</p><p><a rel="nofollow" data-lle-nav href="' . esc_url(self::base_url()) . '">Start over</a></p></div>';
        }
        $plugin = self::plugin();
        $rank = ((int) ($list['page'] ?? 1) - 1) * self::PER_PAGE;
        $html = '<ol class="lle-groups lle-groups--' . esc_attr($state['by']) . '" start="' . ($rank + 1) . '">';
        foreach ($items as $item) {
            if (in_array($state['by'], array('film', 'person', 'company'), true)) {
                $explore = self::url($state, array('entity' => array($item['value']), 'by' => ''));
            } elseif ($state['by'] === 'category') {
                $explore = self::url($state, array('category' => $item['value'], 'by' => ''));
            } else {
                $explore = self::url($state, array('ceremony' => $item['value'], 'by' => ''));
            }
            $poster = '';
            if ($state['by'] === 'film' && method_exists($plugin, 'get_poster_img_html_for_title')) {
                $poster = (string) $plugin->get_poster_img_html_for_title($item['value'], 'thumbnail', array('class' => 'lle-group__poster', 'width' => 64, 'height' => 96));
            }
            $label = $item['label'] !== '' ? $item['label'] : strtoupper($item['value']);
            if (!empty($item['year_label'])) {
                $label .= ' · ' . $item['year_label'];
            }
            $rank++;
            $html .= '<li class="lle-group"><span class="lle-group__rank">' . esc_html((string) $rank) . '</span>' . ($poster !== '' ? '<span class="lle-group__media">' . $poster . '</span>' : '')
                . '<span class="lle-group__body"><a class="lle-group__name" href="' . esc_url($item['url']) . '">' . esc_html($label) . '</a>'
                . '<span class="lle-group__counts">' . esc_html(number_format_i18n((int) $item['nominations']) . ' ' . _n('nomination', 'nominations', (int) $item['nominations']) . ' · ' . number_format_i18n((int) $item['wins']) . ' ' . _n('win', 'wins', (int) $item['wins'])) . '</span></span>'
                . '<a class="lle-group__explore" rel="nofollow" data-lle-nav href="' . esc_url($explore) . '">Explore</a></li>';
        }
        return $html . '</ol>';
    }

    private static function render_pager($state, $list) {
        $page = (int) ($list['page'] ?? 1);
        $pages = (int) ($list['pages'] ?? 1);
        if ($pages <= 1) {
            return '';
        }
        $html = '<nav class="lle-pager" aria-label="Pages">';
        $html .= $page > 1 ? '<a rel="nofollow prev" data-lle-nav href="' . esc_url(self::url($state, array('pg' => $page - 1))) . '">← Previous</a>' : '<span aria-hidden="true"></span>';
        $html .= '<span class="lle-pager__at">Page ' . esc_html(number_format_i18n($page)) . ' of ' . esc_html(number_format_i18n($pages)) . '</span>';
        $html .= $page < $pages ? '<a rel="nofollow next" data-lle-nav href="' . esc_url(self::url($state, array('pg' => $page + 1))) . '">Next →</a>' : '<span aria-hidden="true"></span>';
        return $html . '</nav>';
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function api($route, $params = array()) {
        $request = new WP_REST_Request('GET', '/' . AAT_Read_API::NS . $route);
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null && $value !== false && $value !== 0) {
                $request->set_param($key, $value);
            }
        }
        $response = rest_do_request($request);
        if ($response->is_error()) {
            return $response->as_error();
        }
        return $response->get_data();
    }

    private static function api_filters($state) {
        return array(
            'ceremony' => $state['ceremony'] ?: '',
            'decade' => $state['decade'] ?: '',
            'class' => $state['category'] === '' ? $state['class'] : '',
            'category' => $state['category'],
            'winner' => $state['winner'] ? '1' : '',
            'entity' => implode(',', $state['entity']),
        );
    }

    private static function explore_to_state($explore) {
        $out = array();
        if (!empty($explore['entity'])) {
            $out['entity'] = array(strtolower((string) $explore['entity']));
        }
        if (!empty($explore['category'])) {
            $out['category'] = sanitize_title((string) $explore['category']);
            $categories = self::categories();
            $out['class'] = $categories[$out['category']]['class'] ?? '';
        }
        if (!empty($explore['ceremony'])) {
            $out['ceremony'] = (int) $explore['ceremony'];
        }
        return $out;
    }

    private static function blank() {
        return array('q' => '', 'ceremony' => 0, 'decade' => 0, 'class' => '', 'category' => '', 'winner' => false, 'entity' => array(), 'by' => '', 'sort' => 'newest', 'pg' => 1);
    }

    /** slug => class, from the API's category list. */
    private static function categories() {
        static $map = null;
        if ($map === null) {
            $map = array();
            $data = self::api('/categories');
            foreach ((array) (is_array($data) ? ($data['items'] ?? array()) : array()) as $item) {
                $map[$item['slug']] = array('class' => $item['class'], 'name' => $item['name']);
            }
        }
        return $map;
    }

    private static function heading($state, $entities) {
        $parts = array();
        foreach ($entities as $entity) {
            $parts[] = (string) ($entity['name'] ?? '');
        }
        if ($state['category'] !== '') {
            $categories = self::categories();
            $parts[] = $categories[$state['category']]['name'] ?? '';
        } elseif ($state['class'] !== '') {
            $parts[] = AAT_Read_API::CLASS_LABELS[$state['class']] ?? $state['class'];
        }
        if ($state['ceremony']) {
            $parts[] = self::plugin()->ordinal($state['ceremony']) . ' Academy Awards';
        } elseif ($state['decade']) {
            $parts[] = $state['decade'] . 's';
        }
        if ($state['winner']) {
            $parts[] = 'Winners';
        }
        return implode(' · ', array_filter($parts));
    }

    private static function year_phrase($year_label) {
        return 'Films of ' . $year_label;
    }

    private static function kind_label($kind) {
        $labels = array('film' => 'Film', 'person' => 'Person', 'company' => 'Company');
        return $labels[$kind] ?? '';
    }

    /** "A", "A and B", "A, B and C". Escapes plain text unless told the parts are HTML. */
    private static function join_names($names, $escape = true) {
        $names = array_values(array_filter(array_map('strval', (array) $names), 'strlen'));
        if ($escape) {
            $names = array_map('esc_html', $names);
        }
        if (count($names) <= 1) {
            return implode('', $names);
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    private static function fold($value) {
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        return preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }

    private static function plugin() {
        return Academy_Awards_Table::get_instance();
    }
}

<?php
/**
 * Gutenberg blocks for Academy Awards database surfaces.
 *
 * @package Academy_Awards_Table
 */

if (!defined('ABSPATH')) {
    exit;
}

class Academy_Awards_Table_Blocks {

    public static function init() {
        add_filter('block_categories_all', array(__CLASS__, 'register_category'));
        add_action('init', array(__CLASS__, 'register_blocks'));

        // The shortcode→block migration page is retired along with the
        // inserter entries: the site standardized on shortcodes, so a tool
        // that converts shortcodes INTO blocks now points the wrong way.
        // Restore with: add_filter('aat_enable_block_migration_page', '__return_true');
        if (apply_filters('aat_enable_block_migration_page', false)) {
            add_action('admin_menu', array(__CLASS__, 'register_migration_page'));
        }
    }

    public static function register_category($categories) {
        foreach ($categories as $category) {
            if (isset($category['slug']) && $category['slug'] === 'lunara') {
                return $categories;
            }
        }

        array_unshift($categories, array(
            'slug'  => 'lunara',
            'title' => __('Lunara', 'academy-awards-table'),
            'icon'  => 'awards',
        ));

        return $categories;
    }

    public static function register_blocks() {
        wp_register_script(
            'aat-blocks',
            AAT_PLUGIN_URL . 'assets/js/aat-blocks.js',
            array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render'),
            AAT_VERSION,
            true
        );

        $common = array(
            'category'      => 'lunara',
            'editor_script' => 'aat-blocks',
            'supports'      => array(
                'align'    => array('wide', 'full'),
                'anchor'   => true,
                'html'     => false,
                // Kept registered so any stored block markup still renders,
                // but hidden from the inserter: the 2026-07 content census
                // found ZERO posts/pages using these blocks — every live
                // Oscars surface renders via shortcode/template — and the
                // editorial workflow is standardizing on shortcodes.
                'inserter' => false,
            ),
        );

        register_block_type('academy-awards/database', array_merge($common, array(
            'title'           => __('Academy Awards Database', 'academy-awards-table'),
            'description'     => __('The searchable Oscars database as a real block.', 'academy-awards-table'),
            'attributes'      => array(
                'category'    => array('type' => 'string', 'default' => ''),
                'awardClass'  => array('type' => 'string', 'default' => ''),
                'year'        => array('type' => 'string', 'default' => ''),
                'ceremony'    => array('type' => 'string', 'default' => ''),
                'winnersOnly' => array('type' => 'boolean', 'default' => false),
                'layout'      => array('type' => 'string', 'default' => 'full'),
                'autoload'    => array('type' => 'boolean', 'default' => false),
                'limit'       => array('type' => 'number', 'default' => 0),
            ),
            'render_callback' => array(__CLASS__, 'render_database'),
        )));

        register_block_type('academy-awards/tracker', array_merge($common, array(
            'title'           => __('Lunara Awards Tracker', 'academy-awards-table'),
            'attributes'      => array(
                'ceremony'    => array('type' => 'string', 'default' => 'latest'),
                'year'        => array('type' => 'string', 'default' => ''),
                'category'    => array('type' => 'string', 'default' => ''),
                'awardClass'  => array('type' => 'string', 'default' => ''),
                'winnersOnly' => array('type' => 'boolean', 'default' => false),
                'layout'      => array('type' => 'string', 'default' => 'embedded'),
            ),
            'render_callback' => array(__CLASS__, 'render_tracker'),
        )));

        register_block_type('academy-awards/tracker-v2', array_merge($common, array(
            'title'           => __('Lunara Awards Tracker V2', 'academy-awards-table'),
            'attributes'      => array(
                'ceremony'        => array('type' => 'string', 'default' => 'latest'),
                'showSelector'    => array('type' => 'boolean', 'default' => true),
                'showPosters'     => array('type' => 'boolean', 'default' => true),
                'showImdb'        => array('type' => 'boolean', 'default' => true),
                'showReviewLinks' => array('type' => 'boolean', 'default' => true),
            ),
            'render_callback' => array(__CLASS__, 'render_tracker_v2'),
        )));

        register_block_type('academy-awards/ballot', array_merge($common, array(
            'title'           => __('Oscar Ballot', 'academy-awards-table'),
            'attributes'      => array(
                'ceremony'     => array('type' => 'string', 'default' => 'latest'),
                'headline'     => array('type' => 'string', 'default' => ''),
                'intro'        => array('type' => 'string', 'default' => ''),
                'showSelector' => array('type' => 'boolean', 'default' => true),
                'categories'   => array('type' => 'string', 'default' => ''),
            ),
            'render_callback' => array(__CLASS__, 'render_ballot'),
        )));
    }

    public static function render_database($attributes) {
        $plugin = Academy_Awards_Table::get_instance();
        return $plugin->render_shortcode(array(
            'category'     => self::string_attr($attributes, 'category'),
            'class'        => self::string_attr($attributes, 'awardClass'),
            'year'         => self::string_attr($attributes, 'year'),
            'ceremony'     => self::string_attr($attributes, 'ceremony'),
            'winners_only' => self::bool_attr($attributes, 'winnersOnly') ? 'true' : 'false',
            'layout'       => self::string_attr($attributes, 'layout', 'full'),
            'autoload'     => self::bool_attr($attributes, 'autoload') ? 'true' : 'false',
            'limit'        => isset($attributes['limit']) ? (int) $attributes['limit'] : 0,
        ));
    }

    public static function render_tracker($attributes) {
        $plugin = Academy_Awards_Table::get_instance();
        return $plugin->render_tracker_shortcode(array(
            'ceremony'     => self::string_attr($attributes, 'ceremony', 'latest'),
            'year'         => self::string_attr($attributes, 'year'),
            'category'     => self::string_attr($attributes, 'category'),
            'class'        => self::string_attr($attributes, 'awardClass'),
            'winners_only' => self::bool_attr($attributes, 'winnersOnly') ? 'true' : 'false',
            'layout'       => self::string_attr($attributes, 'layout', 'embedded'),
        ));
    }

    public static function render_tracker_v2($attributes) {
        $plugin = Academy_Awards_Table::get_instance();
        return $plugin->render_tracker_v2_shortcode(array(
            'ceremony'          => self::string_attr($attributes, 'ceremony', 'latest'),
            'show_selector'     => self::bool_attr($attributes, 'showSelector', true) ? 'true' : 'false',
            'show_posters'      => self::bool_attr($attributes, 'showPosters', true) ? 'true' : 'false',
            'show_imdb'         => self::bool_attr($attributes, 'showImdb', true) ? 'true' : 'false',
            'show_review_links' => self::bool_attr($attributes, 'showReviewLinks', true) ? 'true' : 'false',
        ));
    }

    public static function render_ballot($attributes) {
        $plugin = Academy_Awards_Table::get_instance();
        return $plugin->render_ballot_shortcode(array(
            'ceremony'      => self::string_attr($attributes, 'ceremony', 'latest'),
            'headline'      => self::string_attr($attributes, 'headline'),
            'intro'         => self::string_attr($attributes, 'intro'),
            'show_selector' => self::bool_attr($attributes, 'showSelector', true) ? 'true' : 'false',
            'categories'    => self::string_attr($attributes, 'categories'),
        ));
    }

    private static function string_attr($attributes, $key, $default = '') {
        return isset($attributes[$key]) ? sanitize_text_field((string) $attributes[$key]) : $default;
    }

    private static function bool_attr($attributes, $key, $default = false) {
        if (!array_key_exists($key, (array) $attributes)) {
            return (bool) $default;
        }

        return filter_var($attributes[$key], FILTER_VALIDATE_BOOLEAN);
    }

    public static function register_migration_page() {
        add_management_page(
            __('Academy Awards Block Migration', 'academy-awards-table'),
            __('Academy Awards Block Migration', 'academy-awards-table'),
            'edit_pages',
            'aat-block-migration',
            array(__CLASS__, 'render_migration_page')
        );
    }

    public static function render_migration_page() {
        if (!current_user_can('edit_pages')) {
            wp_die(esc_html__('You do not have permission to migrate content.', 'academy-awards-table'));
        }

        $result = null;
        if (isset($_POST['aat_block_migration_action'])) {
            check_admin_referer('aat_block_migration');
            if (sanitize_key(wp_unslash($_POST['aat_block_migration_action'])) === 'convert') {
                $result = self::convert_candidate_posts();
            }
        }

        $candidates = self::find_candidates();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Academy Awards Block Migration', 'academy-awards-table'); ?></h1>
            <p><?php esc_html_e('Convert Academy Awards shortcodes into real Gutenberg blocks. The old shortcodes remain available as a fallback.', 'academy-awards-table'); ?></p>

            <?php if (is_array($result)) : ?>
                <div class="notice notice-success"><p><?php printf(esc_html__('Converted %1$d item(s). Skipped %2$d item(s).', 'academy-awards-table'), (int) $result['converted'], (int) $result['skipped']); ?></p></div>
            <?php endif; ?>

            <?php if (empty($candidates)) : ?>
                <p><?php esc_html_e('No supported Academy Awards shortcodes were found.', 'academy-awards-table'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Title', 'academy-awards-table'); ?></th><th><?php esc_html_e('Type', 'academy-awards-table'); ?></th><th><?php esc_html_e('Shortcodes', 'academy-awards-table'); ?></th><th><?php esc_html_e('Edit', 'academy-awards-table'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($candidates as $post) : ?>
                        <tr>
                            <td><?php echo esc_html(get_the_title($post->ID)); ?></td>
                            <td><?php echo esc_html($post->post_type); ?></td>
                            <td><code><?php echo esc_html(implode(', ', self::detect_shortcodes($post->post_content))); ?></code></td>
                            <td><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php esc_html_e('Open editor', 'academy-awards-table'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="post" style="margin-top:18px;">
                    <?php wp_nonce_field('aat_block_migration'); ?>
                    <input type="hidden" name="aat_block_migration_action" value="convert">
                    <?php submit_button(__('Convert Academy Shortcodes to Blocks', 'academy-awards-table')); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function supported_shortcodes() {
        return array('academy_awards', 'lunara_awards_tracker', 'lunara_oscar_ballot', 'academy_awards_ballot', 'lunara_awards_tracker_v2', 'academy_awards_tracker_v2');
    }

    private static function find_candidates() {
        $query = new WP_Query(array(
            'post_type'      => array('page', 'post', 'review', 'journal'),
            'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => 500,
            'no_found_rows'  => true,
        ));

        $posts = array();
        foreach ($query->posts as $post) {
            if (self::detect_shortcodes($post->post_content)) {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    private static function detect_shortcodes($content) {
        $found = array();
        foreach (self::supported_shortcodes() as $tag) {
            if (has_shortcode($content, $tag)) {
                $found[] = $tag;
            }
        }
        return $found;
    }

    private static function convert_candidate_posts() {
        $converted = 0;
        $skipped = 0;

        foreach (self::find_candidates() as $post) {
            $content = self::convert_content($post->post_content);
            if ($content === $post->post_content) {
                $skipped++;
                continue;
            }

            wp_update_post(array(
                'ID'           => $post->ID,
                'post_content' => $content,
            ));
            $converted++;
        }

        return array('converted' => $converted, 'skipped' => $skipped);
    }

    private static function convert_content($content) {
        $regex = get_shortcode_regex(self::supported_shortcodes());
        return preg_replace_callback('/' . $regex . '/s', function($matches) {
            $tag = $matches[2];
            $atts = shortcode_parse_atts($matches[3]);
            $atts = is_array($atts) ? $atts : array();
            return self::shortcode_to_block($tag, $atts);
        }, $content);
    }

    private static function shortcode_to_block($tag, $atts) {
        $block = '';
        $attrs = array();

        switch ($tag) {
            case 'academy_awards':
                $block = 'academy-awards/database';
                $attrs = array(
                    'category'    => isset($atts['category']) ? sanitize_text_field($atts['category']) : '',
                    'awardClass'  => isset($atts['class']) ? sanitize_text_field($atts['class']) : '',
                    'year'        => isset($atts['year']) ? sanitize_text_field($atts['year']) : '',
                    'ceremony'    => isset($atts['ceremony']) ? sanitize_text_field($atts['ceremony']) : '',
                    'winnersOnly' => self::truthy($atts['winners_only'] ?? false),
                    'layout'      => isset($atts['layout']) ? sanitize_key($atts['layout']) : 'full',
                    'autoload'    => self::truthy($atts['autoload'] ?? false),
                    'limit'       => isset($atts['limit']) ? (int) $atts['limit'] : 0,
                );
                break;
            case 'lunara_awards_tracker':
                $block = 'academy-awards/tracker';
                $attrs = array(
                    'ceremony'    => isset($atts['ceremony']) ? sanitize_text_field($atts['ceremony']) : 'latest',
                    'year'        => isset($atts['year']) ? sanitize_text_field($atts['year']) : '',
                    'category'    => isset($atts['category']) ? sanitize_text_field($atts['category']) : '',
                    'awardClass'  => isset($atts['class']) ? sanitize_text_field($atts['class']) : '',
                    'winnersOnly' => self::truthy($atts['winners_only'] ?? false),
                    'layout'      => isset($atts['layout']) ? sanitize_key($atts['layout']) : 'embedded',
                );
                break;
            case 'lunara_awards_tracker_v2':
            case 'academy_awards_tracker_v2':
                $block = 'academy-awards/tracker-v2';
                $attrs = array(
                    'ceremony'        => isset($atts['ceremony']) ? sanitize_text_field($atts['ceremony']) : 'latest',
                    'showSelector'    => self::truthy($atts['show_selector'] ?? true),
                    'showPosters'     => self::truthy($atts['show_posters'] ?? true),
                    'showImdb'        => self::truthy($atts['show_imdb'] ?? true),
                    'showReviewLinks' => self::truthy($atts['show_review_links'] ?? true),
                );
                break;
            case 'lunara_oscar_ballot':
            case 'academy_awards_ballot':
                $block = 'academy-awards/ballot';
                $attrs = array(
                    'ceremony'     => isset($atts['ceremony']) ? sanitize_text_field($atts['ceremony']) : 'latest',
                    'headline'     => isset($atts['headline']) ? sanitize_text_field($atts['headline']) : '',
                    'intro'        => isset($atts['intro']) ? sanitize_text_field($atts['intro']) : '',
                    'showSelector' => self::truthy($atts['show_selector'] ?? true),
                    'categories'   => isset($atts['categories']) ? sanitize_text_field($atts['categories']) : '',
                );
                break;
        }

        if ($block === '') {
            return '';
        }

        $attrs = array_filter($attrs, function($value) {
            return !($value === '' || $value === null || $value === 0);
        });

        return '<!-- wp:' . $block . (empty($attrs) ? '' : ' ' . wp_json_encode($attrs)) . ' /-->';
    }

    private static function truthy($value) {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

Academy_Awards_Table_Blocks::init();

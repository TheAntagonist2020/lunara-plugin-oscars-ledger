<?php
/**
 * Interactive Oscar ballot template.
 *
 * Variables are prepared by Academy_Awards_Table::render_ballot_shortcode().
 *
 * @package Academy_Awards_Table
 */

if (!defined('ABSPATH')) {
    exit;
}

$groups = isset($ballot_groups['categories']) && is_array($ballot_groups['categories']) ? $ballot_groups['categories'] : array();
$review_map = isset($ballot_groups['review_map']) && is_array($ballot_groups['review_map']) ? $ballot_groups['review_map'] : array();
?>
<section class="aat-ballot" data-state-key="<?php echo esc_attr($state_key); ?>">
    <header class="aat-ballot__header">
        <p class="aat-ballot__kicker"><?php echo esc_html($season_label); ?><?php if (!empty($year_label)) : ?> &middot; <?php echo esc_html($year_label); ?><?php endif; ?></p>
        <h2><?php echo esc_html($headline); ?></h2>
        <?php if (!empty($intro)) : ?>
            <p><?php echo esc_html($intro); ?></p>
        <?php endif; ?>

        <?php if (!empty($show_selector) && !empty($ceremonies)) : ?>
            <label class="aat-ballot__selector">
                <span><?php esc_html_e('Ceremony', 'academy-awards-table'); ?></span>
                <select data-aat-ballot-ceremony>
                    <?php foreach ($ceremonies as $option_ceremony) : ?>
                        <option value="<?php echo esc_attr((string) (int) $option_ceremony); ?>" <?php selected((int) $ceremony, (int) $option_ceremony); ?>>
                            <?php echo esc_html($this->ordinal((int) $option_ceremony)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
    </header>

    <?php if (empty($groups)) : ?>
        <p class="aat-ballot__empty"><?php esc_html_e('No ballot categories found for this ceremony.', 'academy-awards-table'); ?></p>
    <?php else : ?>
        <div class="aat-ballot__categories">
            <?php foreach ($groups as $group) : ?>
                <?php
                $category = isset($group['category']) ? (string) $group['category'] : '';
                $rows = isset($group['rows']) && is_array($group['rows']) ? $group['rows'] : array();
                if ($category === '' || empty($rows)) {
                    continue;
                }
                ?>
                <article class="aat-ballot__category" data-category="<?php echo esc_attr($category); ?>">
                    <h3><?php echo esc_html($this->format_category_display($category)); ?></h3>
                    <div class="aat-ballot__choices" role="list">
                        <?php foreach ($rows as $row) : ?>
                            <?php
                            $row_id = isset($row['id']) ? (int) $row['id'] : 0;
                            $film = trim((string) ($row['film'] ?? ''));
                            $name = trim((string) ($row['name'] ?? ''));
                            $nominees = trim((string) ($row['nominees'] ?? ''));
                            $film_id = trim((string) ($row['film_id'] ?? ''));
                            $is_winner = !empty($row['winner']);
                            $label = $film !== '' ? $film : ($name !== '' ? $name : $nominees);
                            $detail = '';
                            if ($film !== '' && $name !== '' && $name !== $film) {
                                $detail = $name;
                            } elseif ($nominees !== '' && $nominees !== $label) {
                                $detail = $nominees;
                            }
                            $review_url = '';
                            if ($film_id !== '') {
                                $ids = preg_split('/\s*\|\s*/', $film_id);
                                foreach ((array) $ids as $id) {
                                    $id = strtolower(trim((string) $id));
                                    if ($id !== '' && isset($review_map[$id])) {
                                        $review_url = (string) $review_map[$id];
                                        break;
                                    }
                                }
                            }
                            $choice_key = sanitize_title($category) . '-' . $row_id;
                            ?>
                            <div class="aat-ballot__choice" role="listitem">
                                <div class="aat-ballot__choice-copy">
                                    <strong><?php echo esc_html($label); ?></strong>
                                    <?php if ($detail !== '') : ?><span><?php echo esc_html($detail); ?></span><?php endif; ?>
                                    <?php if ($is_winner) : ?><em><?php esc_html_e('Winner', 'academy-awards-table'); ?></em><?php endif; ?>
                                </div>
                                <div class="aat-ballot__choice-actions">
                                    <label><input type="radio" name="aat-will-<?php echo esc_attr(sanitize_title($category)); ?>" value="<?php echo esc_attr($choice_key); ?>" data-ballot-kind="will" data-ballot-category="<?php echo esc_attr($category); ?>" data-ballot-label="<?php echo esc_attr($label); ?>"> <?php esc_html_e('Will', 'academy-awards-table'); ?></label>
                                    <label><input type="radio" name="aat-should-<?php echo esc_attr(sanitize_title($category)); ?>" value="<?php echo esc_attr($choice_key); ?>" data-ballot-kind="should" data-ballot-category="<?php echo esc_attr($category); ?>" data-ballot-label="<?php echo esc_attr($label); ?>"> <?php esc_html_e('Should', 'academy-awards-table'); ?></label>
                                    <?php if ($review_url !== '') : ?><a href="<?php echo esc_url($review_url); ?>"><?php esc_html_e('Review', 'academy-awards-table'); ?></a><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="aat-ballot__footer">
            <button type="button" class="aat-ballot__button" data-aat-ballot-copy><?php esc_html_e('Copy Picks', 'academy-awards-table'); ?></button>
            <button type="button" class="aat-ballot__button aat-ballot__button--ghost" data-aat-ballot-reset><?php esc_html_e('Reset', 'academy-awards-table'); ?></button>
            <span class="aat-ballot__status" aria-live="polite"></span>
        </div>
    <?php endif; ?>
</section>

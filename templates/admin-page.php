<?php
/**
 * Academy Awards Table - Admin Page Template
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap aat-admin-wrap">
    
    <!-- Header -->
    <div class="aat-admin-header">
        <span class="dashicons dashicons-awards"></span>
        <h1><?php esc_html_e('Academy Awards Table', 'academy-awards-table'); ?></h1>
    </div>

    <!-- Message Area -->
    <div class="aat-message-area"></div>

    <!-- Stats Cards -->
    <div class="aat-admin-stats">
        <div class="aat-admin-stat-card">
            <h3><?php esc_html_e('Total Records', 'academy-awards-table'); ?></h3>
            <div class="stat-value"><?php echo esc_html(number_format($total_records)); ?></div>
        </div>
        <div class="aat-admin-stat-card">
            <h3><?php esc_html_e('Winners', 'academy-awards-table'); ?></h3>
            <div class="stat-value"><?php echo esc_html(number_format($total_winners)); ?></div>
        </div>
        <div class="aat-admin-stat-card">
            <h3><?php esc_html_e('Categories', 'academy-awards-table'); ?></h3>
            <div class="stat-value"><?php echo esc_html($categories); ?></div>
        </div>
        <div class="aat-admin-stat-card">
            <h3><?php esc_html_e('Years Covered', 'academy-awards-table'); ?></h3>
            <div class="stat-value"><?php echo esc_html($years); ?></div>
        </div>
    </div>

    <!-- Import Section -->
    <div class="aat-admin-section">
        <h2><?php esc_html_e('Import Your Oscar Data', 'academy-awards-table'); ?></h2>
        
        <div class="aat-import-area">
            <div class="aat-import-icon">📁</div>
            <h3><?php esc_html_e('Import the Oscars Dataset', 'academy-awards-table'); ?></h3>
            <p><?php esc_html_e('Recommended: import the bundled oscars.csv (included with this plugin). Or upload a newer CSV/JSON if you have one.', 'academy-awards-table'); ?></p>

            <div class="aat-import-buttons" style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
                <button type="button" id="aat-import-bundled" class="aat-import-btn">
                    <?php esc_html_e('Import Bundled oscars.csv', 'academy-awards-table'); ?>
                </button>
                <button type="button" class="aat-import-btn aat-import-btn-upload">
                    <?php esc_html_e('Upload CSV/JSON', 'academy-awards-table'); ?>
                </button>
            </div>

            <input type="file" id="aat-import-file" accept=".csv,.json">
            
            <div class="aat-progress">
                <div class="aat-progress-bar">
                    <div class="aat-progress-fill" style="width: 0%"></div>
                </div>
                <div class="aat-progress-text">0%</div>
            </div>
        </div>

        <!-- Quick Ceremony Update (Delta Import) -->
        <div class="aat-format-info" style="margin-top: 25px;">
            <h4><?php esc_html_e('Quick Ceremony Update (Delta Import)', 'academy-awards-table'); ?></h4>
            <p><?php esc_html_e('Use this when new nominations/winners arrive: upload a TSV/CSV containing ONLY one ceremony. The plugin will replace that ceremony in the database (fast) without re-importing the full history.', 'academy-awards-table'); ?></p>

            <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <input type="file" id="aat-delta-file" accept=".csv,.tsv">
                <button type="button" id="aat-delta-import" class="aat-import-btn">
                    <?php esc_html_e('Replace Ceremony from File', 'academy-awards-table'); ?>
                </button>
                <button type="button" id="aat-repair-schema" class="button">
                    <?php esc_html_e('Repair Tables / Rewrite Rules', 'academy-awards-table'); ?>
                </button>
            </div>

            <p style="margin-top:10px; color:#666;">
                <?php esc_html_e('Tip: Your file should use the same columns as oscars.csv (including Ceremony, Year, CanonicalCategory, Category, Film, FilmId, Name, Winner, etc.).', 'academy-awards-table'); ?>
            </p>
        </div>


        <!-- Format Information -->
        <div class="aat-format-info">
            <h4><?php esc_html_e('Your Data Format (Tab-Separated CSV)', 'academy-awards-table'); ?></h4>
            <p><?php esc_html_e('Your oscars.csv file should have these columns:', 'academy-awards-table'); ?></p>
            
            <div class="aat-sample-data">
                <table>
                    <thead>
                        <tr>
                            <th>Ceremony</th>
                            <th>Year</th>
                            <th>Class</th>
                            <th>CanonicalCategory</th>
                            <th>Category</th>
                            <th>Film</th>
                            <th>Name</th>
                            <th>Winner</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>97</td>
                            <td>2024</td>
                            <td>Acting</td>
                            <td>ACTOR IN A LEADING ROLE</td>
                            <td>ACTOR</td>
                            <td>The Brutalist</td>
                            <td>Adrien Brody</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td>97</td>
                            <td>2024</td>
                            <td>Title</td>
                            <td>BEST PICTURE</td>
                            <td>BEST PICTURE</td>
                            <td>Anora</td>
                            <td>Sean Baker, Producer</td>
                            <td>True</td>
                        </tr>
                        <tr>
                            <td>97</td>
                            <td>2024</td>
                            <td>Directing</td>
                            <td>DIRECTING</td>
                            <td>DIRECTING</td>
                            <td>Anora</td>
                            <td>Sean Baker</td>
                            <td>True</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h4 style="margin-top: 20px;"><?php esc_html_e('Column Descriptions:', 'academy-awards-table'); ?></h4>
            <ul style="color: #666; margin-left: 20px;">
                <li><strong>Ceremony</strong> - Ceremony number (1-97)</li>
                <li><strong>Year</strong> - Year designation (e.g., "2024", "1927/28")</li>
                <li><strong>Class</strong> - Award class (Acting, Directing, Production, Title, Writing, Music, Special, SciTech)</li>
                <li><strong>CanonicalCategory</strong> - Standardized category name</li>
                <li><strong>Category</strong> - Original category name as presented</li>
                <li><strong>Film</strong> - Film title(s), separated by | for multiple films</li>
                <li><strong>Name</strong> - Nominee name as displayed</li>
                <li><strong>Winner</strong> - "True" for winners, empty for nominees</li>
            </ul>
        </div>
    </div>

    <!-- Shortcode Section -->
    <div class="aat-admin-section">
        <h2><?php esc_html_e('How to Display the Table', 'academy-awards-table'); ?></h2>
        
        <p><?php esc_html_e('Use the following shortcode to display the Academy Awards table on any page or post:', 'academy-awards-table'); ?></p>

        <div class="aat-shortcode-examples">
            <div class="aat-shortcode-example">
                <code>[academy_awards]</code>
                <span><?php echo esc_html(sprintf(__('Display the full interactive table with all %s nominations currently in your database', 'academy-awards-table'), number_format($total_records))); ?></span>
            </div>
            
            <div class="aat-shortcode-example">
                <code>[academy_awards category="BEST PICTURE"]</code>
                <span><?php esc_html_e('Show only Best Picture nominations', 'academy-awards-table'); ?></span>
            </div>
            
            <div class="aat-shortcode-example">
                <code>[academy_awards year="2024"]</code>
                <span><?php esc_html_e('Show nominations from the 97th Academy Awards', 'academy-awards-table'); ?></span>
            </div>
            
            <div class="aat-shortcode-example">
                <code>[academy_awards winners_only="true"]</code>
                <span><?php esc_html_e('Display only the winners throughout history', 'academy-awards-table'); ?></span>
            </div>
            
            <div class="aat-shortcode-example">
                <code>[academy_awards category="DIRECTING" winners_only="true"]</code>
                <span><?php esc_html_e('Show all Best Director winners across the full dataset (1927/28–2024)', 'academy-awards-table'); ?></span>
            </div>
        </div>
    </div>

    <!-- Danger Zone -->
    <div class="aat-admin-section">
        <h2><?php esc_html_e('Data Management', 'academy-awards-table'); ?></h2>
        
        <div class="aat-danger-zone">
            <h3>⚠️ <?php esc_html_e('Danger Zone', 'academy-awards-table'); ?></h3>
            <p><?php esc_html_e('This action will permanently delete all Academy Awards data from the database. You will need to re-import your oscars.csv file.', 'academy-awards-table'); ?></p>
            <button type="button" id="aat-clear-data" class="aat-danger-btn">
                <?php esc_html_e('Delete All Data', 'academy-awards-table'); ?>
            </button>
        </div>
    </div>

</div>

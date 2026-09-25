<?php
/**
 * Oscar Ledger Explorer: /{base}/explore/.
 *
 * The body is rendered and escaped by AAT_Explorer::render_page(), the same
 * renderer that answers the page's instant-filter fragment requests.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
echo AAT_Explorer::render_page(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in AAT_Explorer.
get_footer();

<?php
/**
 * Plugin Name: Animerco Embed Scraper
 * Description: Admin tool to find/embed links (attempts to scrape tv.animerco.org). May miss JS-injected players; consider a headless-browser approach for complete results.
 * Version: 1.0
 * Author: ChatGPT (example)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Animerco_Embed_Scraper {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_post_animerco_scrape', array( $this, 'handle_scrape_request' ) );
    }

    public function register_admin_menu() {
        add_menu_page(
            'Animerco Scraper',
            'Animerco Scraper',
            'manage_options',
            'animerco-embed-scraper',
            array( $this, 'admin_page' ),
            'dashicons-search',
            80
        );
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Animerco Embed Scraper</h1>
            <p>Choose type, enter a name (anime title or movie title). The plugin will try to find season/episode or movie pages on <code>tv.animerco.org</code> and extract embed links it can find in the static HTML.</p>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field( 'animerco_scrape_action', 'animerco_scrape_nonce' ); ?>
                <input type="hidden" name="action" value="animerco_scrape">
                <table class="form-table">
                    <tr>
                        <th><label for="type">Type</label></th>
                        <td>
                            <select name="type" id="type">
                                <option value="anime">Anime (TV show)</option>
                                <option value="movie">Movie</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="name">Name</label></th>
                        <td><input name="name" id="name" type="text" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="limit">Max results</label></th>
                        <td><input name="limit" id="limit" type="number" value="20" min="1" max="200"></td>
                    </tr>
                </table>
                <?php submit_button('Scrape'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handler for the form submission
     */
    public function handle_scrape_request() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die('Unauthorized');
        if ( ! isset($_POST['animerco_scrape_nonce']) || ! wp_verify_nonce( $_POST['animerco_scrape_nonce'], 'animerco_scrape_action' ) ) {
            wp_die('Invalid nonce');
        }

        $type  = sanitize_text_field( $_POST['type'] ?? 'anime' );
        $name  = sanitize_text_field( $_POST['name'] ?? '' );
        $limit = intval( $_POST['limit'] ?? 20 );

        if ( empty( $name ) ) {
            wp_redirect( admin_url( 'admin.php?page=animerco-embed-scraper&msg=empty' ) );
            exit;
        }

        // Build a simple site-search URL on the target site:
        $site_search_url = 'https://tv.animerco.org/?s=' . rawurlencode( $name );

        $search_html = $this->fetch_url( $site_search_url );
        $links = $this->extract_links_from_search( $search_html, $limit );

        // For each candidate link, fetch and extract embed URLs
        $results = array();

        foreach ( $links as $l ) {
            $html = $this->fetch_url( $l );
            $embeds = $this->extract_embed_links_from_html( $html );
            $results[] = array(
                'page' => $l,
                'embeds' => $embeds,
            );
        }

        // Store results in transient for display (short lived)
        $key = 'animerco_scrape_' . get_current_user_id();
        set_transient( $key, $results, 60 * 5 );

        wp_redirect( admin_url( 'admin.php?page=animerco-embed-scraper&show_results=1&key=' . $key ) );
        exit;
    }

    /**
     * Helper: safe HTTP GET using WP HTTP API
     */
    private function fetch_url( $url ) {
        // Make sure we respect remote request timeouts and user agent
        $args = array(
            'timeout' => 20,
            'headers' => array(
                'User-Agent' => 'AnimercoEmbedScraper/1.0 (+https://yourdomain.example)'
            ),
            // follow redirects
            'redirection' => 5,
        );

        $resp = wp_remote_get( $url, $args );
        if ( is_wp_error( $resp ) ) {
            return '';
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code != 200 ) return '';

        return wp_remote_retrieve_body( $resp );
    }

    /**
     * Extract candidate season/episode/movie links from a search results page
     */
    private function extract_links_from_search( $html, $limit = 20 ) {
        $urls = array();

        if ( empty( $html ) ) return $urls;

        // quick regex to find hrefs
        if ( preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $m) ) {
            foreach ( $m[1] as $href ) {
                // keep only tv.animerco.org links to /seasons/ /episodes/ or /movies/
                if ( strpos( $href, 'tv.animerco.org' ) !== false ) {
                    if ( preg_match('#/(seasons|episodes|movies)/#', $href) ) {
                        $urls[] = $this->normalize_url( $href );
                        if ( count($urls) >= $limit ) break;
                    }
                } elseif ( strpos( $href, '/seasons/' ) !== false || strpos( $href, '/episodes/' ) !== false || strpos( $href, '/movies/' ) !== false ) {
                    // relative link
                    $urls[] = $this->normalize_url( $href );
                    if ( count($urls) >= $limit ) break;
                }
            }
        }

        // unique and limit
        $urls = array_values( array_unique( $urls ) );
        return array_slice( $urls, 0, $limit );
    }

    private function normalize_url( $href ) {
        if ( strpos( $href, 'http' ) === 0 ) return $href;
        // make absolute
        if ( strpos( $href, '//' ) === 0 ) return 'https:' . $href;
        return 'https://tv.animerco.org' . (strpos($href, '/')===0 ? $href : '/' . $href);
    }

    /**
     * Extract embed links from episode/movie HTML:
     * - Parse iframe src attributes
     * - Fallback: regex find http(s) urls in scripts and HTML and filter by known providers
     */
    private function extract_embed_links_from_html( $html ) {
        $found = array();

        if ( empty( $html ) ) return $found;

        // 1) DOM parse for iframe tags
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        if ( $doc->loadHTML( $html ) ) {
            $iframes = $doc->getElementsByTagName('iframe');
            foreach ( $iframes as $iframe ) {
                $src = trim( $iframe->getAttribute('src') );
                if ( $src ) {
                    $found[] = array( 'type' => 'iframe', 'url' => $this->absolute_iframe_url($src) );
                }
            }
        }
        libxml_clear_errors();

        // 2) regex fallback: find URLs in HTML/inline scripts
        if ( preg_match_all('#https?://[^\s\'"<>]+#i', $html, $m) ) {
            $candidates = array_unique( $m[0] );
            foreach ( $candidates as $c ) {
                // filter to common embed/provider tokens
                $provider_tokens = array('player', 'embed', 'vk.com', 'ok.ru', 'mega', 'mp4upload', 'yourupload', 'vidmoly', 'videas', 'sibnet', 'zuvioeb', 'hqq', 'mega', 'stream', 'cloud');
                $low = strtolower($c);
                foreach ( $provider_tokens as $tok ) {
                    if ( strpos($low, $tok) !== false ) {
                        $found[] = array( 'type' => 'regex', 'url' => $c );
                        break;
                    }
                }
            }
        }

        // dedupe preserving order
        $unique = array();
        $out = array();
        foreach ( $found as $f ) {
            if ( empty( $f['url'] ) ) continue;
            $k = $f['url'];
            if ( isset( $unique[$k] ) ) continue;
            $unique[$k] = true;
            $out[] = $f;
        }

        return $out;
    }

    private function absolute_iframe_url($src) {
        if ( strpos($src, '//') === 0 ) return 'https:' . $src;
        if ( strpos($src, 'http') === 0 ) return $src;
        // relative
        return 'https://tv.animerco.org' . (strpos($src, '/')===0 ? $src : '/' . $src);
    }
}

new Animerco_Embed_Scraper();

/**
 * Display results when redirected back to admin page
 */
add_action( 'admin_notices', function() {
    if ( ! current_user_can('manage_options') ) return;
    if ( ! isset( $_GET['show_results'] ) || $_GET['show_results'] != '1' ) return;
    $key = sanitize_text_field( $_GET['key'] ?? '' );
    if ( empty( $key ) ) return;
    $results = get_transient( $key );
    if ( $results === false ) {
        echo '<div class="notice notice-warning"><p>Results expired or not found.</p></div>';
        return;
    }

    echo '<div class="wrap"><h2>Scrape Results</h2>';
    foreach ( $results as $r ) {
        echo '<h3>Page: <a href="' . esc_url($r['page']) . '" target="_blank">' . esc_html($r['page']) . '</a></h3>';
        if ( empty($r['embeds']) ) {
            echo '<p><em>No embed links found via static HTML or inline scripts. This page likely loads players via JavaScript/AJAX — try a headless-browser approach or inspect network calls on the site.</em></p>';
        } else {
            echo '<ol>';
            foreach ( $r['embeds'] as $e ) {
                echo '<li>' . esc_html( $e['type'] ) . ': <a href="' . esc_url( $e['url'] ) . '" target="_blank">' . esc_html( $e['url'] ) . '</a></li>';
            }
            echo '</ol>';
        }
    }
    echo '</div>';
});

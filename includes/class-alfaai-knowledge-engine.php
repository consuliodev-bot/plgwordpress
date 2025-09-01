<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple knowledge engine that loads JSON datasets and provides
 * helper methods to search through them or the WordPress database.
 */
class AlfaAI_Knowledge_Engine {

    /**
     * Loaded knowledge datasets.
     *
     * @var array
     */
    private static array $datasets = array();

    /**
     * Initialise the engine by loading available datasets. The datasets
     * are loaded immediately and also on WordPress `init` for safety.
     *
     * @return void
     */
    public static function init() : void {
        if (function_exists('add_action')) {
            add_action('init', array(__CLASS__, 'load_datasets'));
        }
        // Load datasets immediately for CLI or tests.
        self::load_datasets();
    }

    /**
     * Load all JSON knowledge datasets from the assets/data directory.
     *
     * @return void
     */
    public static function load_datasets() : void {
        $base_dir = defined('ALFAAI_PRO_PLUGIN_DIR')
            ? ALFAAI_PRO_PLUGIN_DIR
            : dirname(__DIR__) . '/';

        $data_dir = $base_dir . 'assets/data/';

        if (!is_dir($data_dir)) {
            return;
        }

        $files = glob($data_dir . '*.json');

        foreach ($files as $file) {
            $json = json_decode(file_get_contents($file), true);
            if (is_array($json)) {
                self::$datasets[basename($file)] = $json;
            }
        }
    }

    /**
     * Search across loaded datasets and the WordPress database.
     *
     * @param string $query Search term.
     * @param int    $limit Optional limit for database results.
     *
     * @return array Results from JSON datasets and database queries.
     */
    public static function find(string $query, int $limit = 5) : array {
        $results = array(
            'json'     => self::query_json($query),
            'database' => self::query_database($query, $limit),
        );

        return $results;
    }

    /**
     * Search within the loaded JSON datasets.
     *
     * @param string $query Search term.
     *
     * @return array Matching records from the datasets.
     */
    public static function query_json(string $query) : array {
        $matches = array();
        $query   = strtolower($query);

        foreach (self::$datasets as $name => $data) {
            $found = self::search_array($data, $query);
            foreach ($found as $item) {
                $item['dataset'] = $name;
                $matches[]       = $item;
            }
        }

        return $matches;
    }

    /**
     * Perform a basic search on the WordPress posts table.
     *
     * @param string $query Search term.
     * @param int    $limit Maximum number of results.
     *
     * @return array Array of database rows or empty array if WP is not loaded.
     */
    public static function query_database(string $query, int $limit = 5) : array {
        if (!function_exists('get_option')) {
            // WordPress not loaded; skip database query.
            return array();
        }

        global $wpdb;
        if (!isset($wpdb)) {
            return array();
        }

        $like = '%' . $wpdb->esc_like($query) . '%';
        $sql  = $wpdb->prepare(
            "SELECT ID, post_title, post_excerpt, post_content FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND (post_title LIKE %s OR post_content LIKE %s)
             LIMIT %d",
            $like,
            $like,
            $limit
        );

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Recursively search an array for a query string.
     *
     * @param array  $data  Array to search.
     * @param string $query Lowercase query string.
     * @param string $path  Current path within the array.
     *
     * @return array List of matches containing path and value.
     */
    private static function search_array($data, string $query, string $path = '') : array {
        $results = array();
        if (!is_array($data)) {
            return $results;
        }

        foreach ($data as $key => $value) {
            $current_path = $path === '' ? (string) $key : $path . '.' . $key;

            if (is_array($value)) {
                $results = array_merge($results, self::search_array($value, $query, $current_path));
            } else {
                $text = strtolower((string) $value);
                if (strpos($text, $query) !== false || strpos(strtolower((string) $key), $query) !== false) {
                    $results[] = array(
                        'path'  => $current_path,
                        'value' => $value,
                    );
                }
            }
        }

        return $results;
    }
}


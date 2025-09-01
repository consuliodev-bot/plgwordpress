<?php
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class AlfaAI_Core {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public static function init() {
        $instance = self::get_instance();
        $instance->load_dependencies();
        $instance->setup_hooks();
    }
    
    private function load_dependencies() {
        require_once ALFAAI_PRO_PLUGIN_DIR . 'includes/class-alfaai-admin.php';
        require_once ALFAAI_PRO_PLUGIN_DIR . 'includes/class-alfaai-frontend.php';
        require_once ALFAAI_PRO_PLUGIN_DIR . 'includes/class-alfaai-api.php';
        require_once ALFAAI_PRO_PLUGIN_DIR . 'includes/class-alfaai-database.php';
        require_once ALFAAI_PRO_PLUGIN_DIR . 'includes/class-alfaai-knowledge.php';
    }
    
    private function setup_hooks() {
        add_action('init', array($this, 'init_plugin'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        if (is_admin()) {
            AlfaAI_Admin::init();
        } else {
            AlfaAI_Frontend::init();
        }
        
        AlfaAI_API::init();
        
        // Add AJAX actions
        add_action('wp_ajax_alfaai_transcribe_audio', array('AlfaAI_API', 'transcribe_audio'));
        add_action('wp_ajax_nopriv_alfaai_transcribe_audio', array('AlfaAI_API', 'transcribe_audio'));
    }
    
    public function init_plugin() {
        add_rewrite_rule('^alfa-ai/?$', 'index.php?alfaai_page=1', 'top');
        add_rewrite_tag('%alfaai_page%', '([0-9]+)');
        
        add_action('template_redirect', array($this, 'handle_frontend_page'));
    }
    
    public function handle_frontend_page() {
        if (get_query_var('alfaai_page')) {
            AlfaAI_Frontend::render_standalone_page();
            exit;
        }
    }
    
    public function enqueue_frontend_assets() {
        if (get_query_var('alfaai_page')) {
            wp_enqueue_style('alfaai-frontend', ALFAAI_PRO_PLUGIN_URL . 'assets/css/frontend.css', array(), ALFAAI_PRO_VERSION);
            wp_enqueue_script('alfaai-frontend', ALFAAI_PRO_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), ALFAAI_PRO_VERSION, true);
            
            wp_localize_script('alfaai-frontend', 'alfaai_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => rest_url('alfaai/v1/'),
                'nonce' => wp_create_nonce('alfaai_frontend_nonce'),
                'user_id' => get_current_user_id(),
                'is_logged_in' => is_user_logged_in()
            ));
        }
    }
    
    public function enqueue_admin_assets($hook) {
        // Core-level enqueue not needed; handled in AlfaAI_Admin::enqueue_admin_assets
        return;
    }
    
    
    public static function activate() {
        self::create_database_tables();
        flush_rewrite_rules();
    }
    
    public static function deactivate() {
        flush_rewrite_rules();
    }
    
    private static function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $conversations_table = $wpdb->prefix . 'alfaai_conversations';
        $messages_table = $wpdb->prefix . 'alfaai_messages';
        $external_dbs_table = $wpdb->prefix . 'alfaai_external_dbs';
        
        $sql_conversations = "CREATE TABLE $conversations_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            title varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        $sql_messages = "CREATE TABLE $messages_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) NOT NULL,
            role enum('user','assistant') NOT NULL,
            content longtext NOT NULL,
            provider varchar(50) DEFAULT NULL,
            model varchar(100) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id)
        ) $charset_collate;";
        
        $sql_external_dbs = "CREATE TABLE $external_dbs_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            url varchar(500) NOT NULL,
            description text,
            api_key varchar(255) DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_conversations);
        dbDelta($sql_messages);
        dbDelta($sql_external_dbs);
    }
}


<?php
/**
 * Plugin Name: Alfassa AI Professional
 * Plugin URI: https://alfassa.org/alfaai
 * Description: Suite AI completa con routing modelli, Web (Brave), RAG su DB Alfassa, immagini, video (job), OCR parser, export chat, light/dark - Enterprise Edition
 * Version: 11.10.43
 * Author: IT Team Alfassa
 * Author URI: https://alfassa.org
 * License: GPL v2 or later
 * Text Domain: alfaai-professional
 * Domain Path: /languages
 * Requires at least: 6.6
 * Tested up to: 6.6
 * Requires PHP: 8.0
 * Network: false
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// Disabilita errori in produzione
if (!defined('WP_DEBUG') || !WP_DEBUG) {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

define('ALFAAI_PRO_VERSION', '11.10.43');
define('ALFAAI_PRO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ALFAAI_PRO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ALFAAI_PRO_PLUGIN_FILE', __FILE__);
define('ALFAAI_PRO_PLUGIN_BASENAME', plugin_basename(__FILE__));

define('ALFAAI_PRO_TABLE_CONVERSATIONS', 'alfaai_pro_conversations');
define('ALFAAI_PRO_TABLE_MESSAGES', 'alfaai_pro_messages');
define('ALFAAI_PRO_TABLE_EXTERNAL_DBS', 'alfaai_pro_external_databases');

// Caricamento classi
$required_classes = [
    'class-alfaai-core.php',
    'class-alfaai-admin.php',
    'class-alfaai-frontend.php',
    'class-alfaai-api.php',
    'class-alfaai-database.php',
    'class-alfaai-knowledge.php',
    'class-alfaai-admin-sites.php',
    'class-alfaai-ajax-extended.php',
    'class-alfaai-google-cloud.php',
    'class-alfaai-response-builder.php',
    'class-alfaai-model-router.php',
    'class-alfaai-cache.php',
    'class-alfaai-rate.php',
    'class-alfaai-admin-enterprise.php',
    'class-alfaai-knowledge-engine.php',
    'class-alfaai-security.php',
    'class-alfaai-google-services.php' // Nuova classe per servizi Google
];

foreach ($required_classes as $class_file) {
    $file_path = ALFAAI_PRO_PLUGIN_DIR . 'includes/' . $class_file;
    if (file_exists($file_path)) {
        require_once $file_path;
    }
}

// Hook di attivazione/disattivazione
register_activation_hook(__FILE__, 'alfaai_pro_activate');
register_deactivation_hook(__FILE__, 'alfaai_pro_deactivate');

function alfaai_pro_activate() {
    if (class_exists('AlfaAI_Database')) {
        AlfaAI_Database::create_tables();
    }
    
    // Opzioni predefinite
    add_option('alfaai_pro_brand_name', 'AlfaAI Professional');
    add_option('alfaai_pro_brand_color', '#6366f1');
    add_option('alfaai_pro_font_family', 'Inter, sans-serif');
    add_option('alfaai_pro_external_dbs', array());
    add_option('alfaai_pro_model_mode', 'auto');
    add_option('alfaai_pro_enable_web', '1');
    add_option('alfaai_pro_theme', 'auto');
    
    // Impostazioni Google Cloud
    add_option('alfaai_pro_google_vision_key', '');
    add_option('alfaai_pro_google_speech_key', '');
    add_option('alfaai_pro_google_tts_key', '');
    
    flush_rewrite_rules();
    error_log('AlfaAI Professional v' . ALFAAI_PRO_VERSION . ' activated - IT Team Alfassa');
}

function alfaai_pro_deactivate() {
    flush_rewrite_rules();
    error_log('AlfaAI Professional deactivated');
}

// Inizializzazione plugin
function alfaai_pro_init() {
    $classes = [
        'AlfaAI_Core',
        'AlfaAI_Admin',
        'AlfaAI_Frontend',
        'AlfaAI_API',
        'AlfaAI_Database',
        'AlfaAI_Admin_Sites',
        'AlfaAI_Ajax_Extended',
        'AlfaAI_Google_Services'
    ];
    
    foreach ($classes as $class) {
        if (class_exists($class)) {
            call_user_func([$class, 'init']);
        }
    }
}
add_action('plugins_loaded', 'alfaai_pro_init');

// Link azioni plugin
function alfaai_pro_action_links($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=alfaai-professional') . '">Impostazioni</a>';
    $frontend_link = '<a href="' . home_url('/alfa-ai') . '" target="_blank">Apri AI</a>';
    array_unshift($links, $settings_link, $frontend_link);
    return $links;
}
add_filter('plugin_action_links_' . ALFAAI_PRO_PLUGIN_BASENAME, 'alfaai_pro_action_links');

// Notifiche admin per chiavi API
function alfaai_pro_admin_notices() {
    $openai_key = get_option('alfaai_pro_openai_key', '');
    $gemini_key = get_option('alfaai_pro_gemini_key', '');
    $deepseek_key = get_option('alfaai_pro_deepseek_key', '');
    
    if (empty($openai_key) && empty($gemini_key) && empty($deepseek_key)) {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>AlfaAI Professional:</strong> Configura le tue API keys in <a href="' . admin_url('options-general.php?page=alfaai-professional') . '">Impostazioni</a> per utilizzare le funzionalità AI.</p>';
        echo '</div>';
    }
}
add_action('admin_notices', 'alfaai_pro_admin_notices');

error_log('AlfaAI Professional v' . ALFAAI_PRO_VERSION . ' loaded - IT Team Alfassa');

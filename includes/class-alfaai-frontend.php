<?php
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class AlfaAI_Frontend {
    
    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        // Removed duplicate template_redirect hook; handled by AlfaAI_Core
        // add_action('template_redirect', array(__CLASS__, 'handle_frontend_page'));
        add_shortcode('alfaai', array(__CLASS__, 'render_shortcode'));
        
        // AJAX actions for frontend
        add_action('wp_ajax_alfaai_get_conversations', array(__CLASS__, 'get_conversations'));
        add_action('wp_ajax_nopriv_alfaai_get_conversations', array(__CLASS__, 'get_conversations'));
        add_action('wp_ajax_alfaai_export_conversation', array(__CLASS__, 'export_conversation'));
        add_action('wp_ajax_nopriv_alfaai_export_conversation', array(__CLASS__, 'export_conversation'));
    }
    
    public static function enqueue_assets() {
        if (get_query_var('alfaai_page') || is_page('alfa-ai')) {
            wp_enqueue_style('alfaai-frontend', ALFAAI_PRO_PLUGIN_URL . 'assets/css/frontend.css', array(), ALFAAI_PRO_VERSION);
            wp_enqueue_script('alfaai-frontend', ALFAAI_PRO_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), ALFAAI_PRO_VERSION, true);
            
            // Enqueue highlight.js for code syntax highlighting
            wp_enqueue_style('highlight-js', 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css', array(), '11.9.0');
            wp_enqueue_script('highlight-js', 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js', array(), '11.9.0', true);
            
            wp_localize_script('alfaai-frontend', 'alfaai_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => rest_url('alfaai/v1/'),
                'nonce' => wp_create_nonce('alfaai_frontend_nonce'),
                'user_id' => get_current_user_id(),
                'is_logged_in' => is_user_logged_in(),
                'brand_name' => get_option('alfaai_pro_brand_name', 'AlfaAI Professional'),
                'brand_color' => get_option('alfaai_pro_brand_color', '#6366f1'),
                'theme' => get_option('alfaai_pro_theme', 'auto')
            ));
        }
    }
    
    public static function handle_frontend_page() {
        if (get_query_var('alfaai_page')) {
            self::render_standalone_page();
            exit;
        }
    }
    
    public static function render_standalone_page() {
        $brand_name = get_option('alfaai_pro_brand_name', 'AlfaAI Professional');
        $brand_color = get_option('alfaai_pro_brand_color', '#6366f1');
        $theme = get_option('alfaai_pro_theme', 'auto');
        
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($brand_name); ?> - AI Assistant</title>
            <link rel="icon" href="<?php echo esc_url(ALFAAI_PRO_PLUGIN_URL . 'assets/images/favicon.ico'); ?>" />
            <?php wp_head(); ?>
        </head>
        <body class="alfaai-standalone <?php echo esc_attr($theme); ?>-theme" data-theme="<?php echo esc_attr($theme); ?>">
            <div class="alfaai-app" id="alfaai-app">
                <!-- Header -->
                <header class="alfaai-header">
                    <div class="alfaai-header-content">
                        <div class="alfaai-brand">
                            <div class="alfaai-logo">
                                <svg width="32" height="32" viewBox="0 0 40 40" fill="none">
                                    <rect width="40" height="40" rx="8" fill="<?php echo esc_attr($brand_color); ?>"/>
                                    <path d="M12 28L20 12L28 28H24L22 24H18L16 28H12Z" fill="white"/>
                                    <path d="M18.5 20H21.5L20 17L18.5 20Z" fill="white"/>
                                </svg>
                            </div>
                            <h1 class="alfaai-brand-name"><?php echo esc_html($brand_name); ?></h1>
                        </div>
                        
                        <div class="alfaai-header-controls">
                            <button class="alfaai-theme-toggle" id="theme-toggle" title="Cambia tema">
                                <svg class="theme-icon theme-light" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd"/>
                                </svg>
                                <svg class="theme-icon theme-dark" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"/>
                                </svg>
                            </button>
                            
                            <button class="alfaai-sidebar-toggle" id="sidebar-toggle" title="Toggle sidebar">
                                <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </header>
                
                <!-- Main Content -->
                <main class="alfaai-main">
                    <!-- Sidebar -->
                    <aside class="alfaai-sidebar" id="alfaai-sidebar">
                        <div class="alfaai-sidebar-header">
                            <h2>Conversazioni</h2>
                            <button class="alfaai-btn alfaai-btn-primary alfaai-btn-sm" id="new-conversation">
                                <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/>
                                </svg>
                                Nuova Chat
                            </button>
                        </div>
                        
                        <div class="alfaai-sidebar-search">
                            <input type="text" placeholder="Cerca conversazioni..." id="conversation-search" class="alfaai-input alfaai-input-sm">
                        </div>
                        
                        <div class="alfaai-conversations-list" id="conversations-list">
                            <div class="alfaai-loading">Caricamento conversazioni...</div>
                        </div>
                    </aside>
                    
                    <!-- Chat Area -->
                    <div class="alfaai-chat-container">
                        <!-- Toolbar -->
                        <div class="alfaai-toolbar">
                            <div class="alfaai-toolbar-modes">
                                <button class="alfaai-mode-btn active" data-mode="chat">
                                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"/>
                                    </svg>
                                    Chat
                                </button>
                                <button class="alfaai-mode-btn" data-mode="web">
                                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M4.083 9h1.946c.089-1.546.383-2.97.837-4.118A6.004 6.004 0 004.083 9zM10 2a8 8 0 100 16 8 8 0 000-16zm0 2c-.076 0-.232.032-.465.262-.238.234-.497.623-.737 1.182-.389.907-.673 2.142-.766 3.556h3.936c-.093-1.414-.377-2.649-.766-3.556-.24-.56-.5-.948-.737-1.182C10.232 4.032 10.076 4 10 4zm3.971 5c-.089-1.546-.383-2.97-.837-4.118A6.004 6.004 0 0115.917 9h-1.946zm-2.003 2H8.032c.093 1.414.377 2.649.766 3.556.24.56.5.948.737 1.182.233.23.389.262.465.262.076 0 .232-.032.465-.262.238-.234.498-.623.737-1.182.389-.907.673-2.142.766-3.556zm1.166 4.118c.454-1.147.748-2.572.837-4.118h1.946a6.004 6.004 0 01-2.783 4.118zm-6.268 0C6.412 13.97 6.118 12.546 6.03 11H4.083a6.004 6.004 0 002.783 4.118z" clip-rule="evenodd"/>
                                    </svg>
                                    Web
                                </button>
                                <button class="alfaai-mode-btn" data-mode="image">
                                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/>
                                    </svg>
                                    Immagine
                                </button>
                                
                            </div>
                            
                            <div class="alfaai-toolbar-actions">
                                <button class="alfaai-btn alfaai-btn-secondary alfaai-btn-sm" id="export-chat">
                                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                    </svg>
                                    Export
                                </button>
                            </div>
                        </div>
                        
                        <!-- Messages Area -->
                        <div class="alfaai-messages" id="messages-container">
                            <div class="alfaai-welcome-message">
                                <div class="alfaai-welcome-content">
                                    <h2>Benvenuto in <?php echo esc_html($brand_name); ?></h2>
                                    <p>La tua AI professionale con accesso ai database Alfassa, ricerca web e generazione multimediale.</p>
                                    <div class="alfaai-welcome-features">
                                        <div class="alfaai-feature">
                                            <svg width="24" height="24" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/>
                                            </svg>
                                            <span>Database Alfassa integrati</span>
                                        </div>
                                        <div class="alfaai-feature">
                                            <svg width="24" height="24" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M4.083 9h1.946c.089-1.546.383-2.97.837-4.118A6.004 6.004 0 004.083 9zM10 2a8 8 0 100 16 8 8 0 000-16zm0 2c-.076 0-.232.032-.465.262-.238.234-.497.623-.737 1.182-.389.907-.673 2.142-.766 3.556h3.936c-.093-1.414-.377-2.649-.766-3.556-.24-.56-.5-.948-.737-1.182C10.232 4.032 10.076 4 10 4zm3.971 5c-.089-1.546-.383-2.97-.837-4.118A6.004 6.004 0 0115.917 9h-1.946zm-2.003 2H8.032c.093 1.414.377 2.649.766 3.556.24.56.5.948.737 1.182.233.23.389.262.465.262.076 0 .232-.032.465-.262.238-.234.498-.623.737-1.182.389-.907.673-2.142.766-3.556zm1.166 4.118c.454-1.147.748-2.572.837-4.118h1.946a6.004 6.004 0 01-2.783 4.118zm-6.268 0C6.412 13.97 6.118 12.546 6.03 11H4.083a6.004 6.004 0 002.783 4.118z" clip-rule="evenodd"/>
                                            </svg>
                                            <span>Ricerca web in tempo reale</span>
                                        </div>
                                        <div class="alfaai-feature">
                                            <svg width="24" height="24" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/>
                                            </svg>
                                            <span>Generazione immagini e video</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Input Area -->
                        <div class="alfaai-input-area">
                            <div class="alfaai-thinking-indicator" id="thinking-indicator" style="display: none;">
                                <div class="alfaai-thinking-content">
                                    <div class="alfaai-spinner"></div>
                                    <span class="alfaai-thinking-text">Pensando...</span>
                                </div>
                            </div>
                            
                            <form class="alfaai-input-form" id="message-form">
                                <div class="alfaai-input-wrapper">
                                    <textarea 
                                        id="message-input" 
                                        placeholder="Scrivi il tuo messaggio..." 
                                        class="alfaai-textarea"
                                        rows="1"
                                        maxlength="4000"></textarea>
                                    <div class="alfaai-input-actions">
                                        <button type="button" class="alfaai-btn alfaai-btn-icon" id="attach-file" title="Allega file">
                                            <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                        <button type="button" class="alfaai-btn alfaai-btn-icon" id="voice-input" title="Registra messaggio vocale">
                                            <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M7 4a3 3 0 016 0v4a3 3 0 11-6 0V4zm4 10.93A7.001 7.001 0 0017 8a1 1 0 10-2 0A5 5 0 015 8a1 1 0 00-2 0 7.001 7.001 0 006 6.93V17H6a1 1 0 100 2h8a1 1 0 100-2h-3v-2.07z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                        <button type="submit" class="alfaai-btn alfaai-btn-primary alfaai-btn-icon" id="send-message" title="Invia messaggio">
                                            <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                <input type="file" id="file-input" style="display: none;" accept=".pdf,.docx,.jpg,.jpeg,.png,.gif">
                            </form>
                        </div>
                    </div>
                </main>
            </div>
            
            <!-- Modals -->
            <div id="image-modal" class="alfaai-modal" style="display: none;">
                <div class="alfaai-modal-content alfaai-modal-large">
                    <div class="alfaai-modal-header">
                        <h3>Genera Immagine</h3>
                        <button type="button" class="alfaai-modal-close">&times;</button>
                    </div>
                    <div class="alfaai-modal-body">
                        <form id="image-form">
                            <div class="alfaai-form-group">
                                <label>Descrizione immagine:</label>
                                <textarea name="prompt" placeholder="Descrivi l'immagine che vuoi generare..." class="alfaai-textarea" rows="3" required></textarea>
                            </div>
                        </form>
                        <div id="image-result" style="display: none;">
                            <img id="generated-image" src="" alt="Generated image" style="max-width: 100%; height: auto;">
                        </div>
                    </div>
                    <div class="alfaai-modal-footer">
                        <button type="button" class="alfaai-btn alfaai-btn-secondary" id="cancel-image">Annulla</button>
                        <button type="button" class="alfaai-btn alfaai-btn-primary" id="generate-image">Genera</button>
                    </div>
                </div>
            </div>
            
            <div id="video-modal" class="alfaai-modal" style="display: none;">
                <div class="alfaai-modal-content alfaai-modal-large">
                    <div class="alfaai-modal-header">
                        <h3>Genera Video</h3>
                        <button type="button" class="alfaai-modal-close">&times;</button>
                    </div>
                    <div class="alfaai-modal-body">
                        <form id="video-form">
                            <div class="alfaai-form-group">
                                <label>Descrizione video:</label>
                                <textarea name="prompt" placeholder="Descrivi il video che vuoi generare..." class="alfaai-textarea" rows="3" required></textarea>
                            </div>
                            <div class="alfaai-form-row">
                                <div class="alfaai-form-group">
                                    <label>Durata (secondi):</label>
                                    <select name="duration" class="alfaai-select">
                                        <option value="5">5 secondi</option>
                                        <option value="10">10 secondi</option>
                                        <option value="15">15 secondi</option>
                                    </select>
                                </div>
                                <div class="alfaai-form-group">
                                    <label>Risoluzione:</label>
                                    <select name="resolution" class="alfaai-select">
                                        <option value="720p">720p</option>
                                        <option value="1080p">1080p</option>
                                    </select>
                                </div>
                            </div>
                        </form>
                        <div id="video-result" style="display: none;">
                            <div class="alfaai-video-status">
                                <div class="alfaai-spinner"></div>
                                <span id="video-status-text">Generazione in corso...</span>
                            </div>
                            <video id="generated-video" controls style="max-width: 100%; height: auto; display: none;">
                                <source src="" type="video/mp4">
                            </video>
                        </div>
                    </div>
                    <div class="alfaai-modal-footer">
                        <button type="button" class="alfaai-btn alfaai-btn-secondary" id="cancel-video">Annulla</button>
                        <button type="button" class="alfaai-btn alfaai-btn-primary" id="generate-video">Genera</button>
                    </div>
                </div>
            </div>
            <div id="image-modal-container" class="alfaai-image-modal">
    <div class="alfaai-image-modal-content">
        <button class="alfaai-image-modal-close">&times;</button>
        <img src="" id="modal-image-content" alt="Immagine ingrandita">
    </div>
</div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
    
    public static function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'height' => '600px',
            'theme' => 'auto'
        ), $atts);
        
        $brand_name = get_option('alfaai_pro_brand_name', 'AlfaAI Professional');
        
        ob_start();
        ?>
        <div class="alfaai-shortcode-container" style="height: <?php echo esc_attr($atts['height']); ?>;" data-theme="<?php echo esc_attr($atts['theme']); ?>">
            <iframe src="<?php echo home_url('/alfa-ai/'); ?>" 
                    style="width: 100%; height: 100%; border: none; border-radius: 8px;"
                    title="<?php echo esc_attr($brand_name); ?>">
            </iframe>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public static function get_conversations() {
        if (!wp_verify_nonce($_POST['nonce'], 'alfaai_frontend_nonce')) {
            wp_send_json_error(array('message' => 'Nonce verification failed'));
            return;
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            $user_id = 0; // Anonymous user
        }
        
        $conversations = AlfaAI_Database::get_conversations($user_id);
        
        wp_send_json_success($conversations);
    }
    
    public static function export_conversation() {
        if (!wp_verify_nonce($_POST['nonce'], 'alfaai_frontend_nonce')) {
            wp_send_json_error(array('message' => 'Nonce verification failed'));
            return;
        }
        
        $conversation_id = absint($_POST['conversation_id']);
        $format = sanitize_text_field($_POST['format']); // json or markdown
        
        $messages = AlfaAI_Database::get_messages($conversation_id);
        
        if ($format === 'json') {
            $export_data = array(
                'conversation_id' => $conversation_id,
                'exported_at' => current_time('mysql'),
                'messages' => $messages
            );
            
            wp_send_json_success(array(
                'data' => json_encode($export_data, JSON_PRETTY_PRINT),
                'filename' => 'conversation_' . $conversation_id . '.json',
                'mime_type' => 'application/json'
            ));
        } else {
            $markdown = "# Conversazione " . $conversation_id . "\n\n";
            $markdown .= "Esportata il: " . current_time('Y-m-d H:i:s') . "\n\n";
            
            foreach ($messages as $message) {
                $role = $message->role === 'user' ? 'Utente' : 'Assistente';
                $markdown .= "## " . $role . "\n\n";
                $markdown .= $message->content . "\n\n";
                $markdown .= "---\n\n";
            }
            
            wp_send_json_success(array(
                'data' => $markdown,
                'filename' => 'conversation_' . $conversation_id . '.md',
                'mime_type' => 'text/markdown'
            ));
        }
    }
}


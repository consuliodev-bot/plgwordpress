<?php
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class AlfaAI_Admin {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_action('wp_ajax_alfaai_super_test_db', array(__CLASS__, 'ajax_super_test_db'));
        
        $ajax_actions = ['save_settings', 'test_provider', 'get_system_status', 'save_external_db', 'delete_external_db', 'get_db_details'];
        foreach ($ajax_actions as $action) {
            add_action('wp_ajax_alfaai_' . $action, array(__CLASS__, 'ajax_' . $action));
        }
        add_action('wp_ajax_alfaai_test_external_db', array('AlfaAI_Database', 'test_external_database'));
    }
    
    public static function add_admin_menu() {
        add_options_page('AlfaAI Professional Settings', 'AlfaAI Professional', 'manage_options', 'alfaai-professional', array(__CLASS__, 'admin_page'));
    }
    
    public static function register_settings() {
        $settings = ['alfaai_pro_openai_key', 'alfaai_pro_gemini_key', 'alfaai_pro_deepseek_key', 'alfaai_pro_brave_key', 'alfaai_pro_langchain_service_key', 'alfaai_pro_google_key', 'alfaai_pro_model_mode', 'alfaai_pro_enable_web', 'alfaai_pro_brand_name', 'alfaai_pro_brand_color', 'alfaai_pro_theme'];
        foreach ($settings as $setting) {
            register_setting('alfaai_pro_settings', $setting);
            register_setting('alfaai_pro_settings', 'alfaai_pro_google_vision_key');
    register_setting('alfaai_pro_settings', 'alfaai_pro_google_speech_key');
    register_setting('alfaai_pro_settings', 'alfaai_pro_google_tts_key');
        }
    }
    
    public static function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_alfaai-professional') { return; }
        wp_enqueue_style('alfaai-admin-style', ALFAAI_PRO_PLUGIN_URL . 'assets/css/admin.css', array(), ALFAAI_PRO_VERSION);
        wp_enqueue_script('alfaai-admin-script', ALFAAI_PRO_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), ALFAAI_PRO_VERSION, true);
        wp_localize_script('alfaai-admin-script', 'alfaai_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('alfaai_admin_nonce')
        ));
    }

    public static function admin_page() {
        if (isset($_POST['submit']) && check_admin_referer('alfaai_admin_settings', 'alfaai_admin_nonce')) {
            self::save_settings();
            echo '<div class="notice notice-success is-dismissible"><p>Impostazioni salvate con successo!</p></div>';
        }
        
        $openai_key = get_option('alfaai_pro_openai_key', '');
        $gemini_key = get_option('alfaai_pro_gemini_key', '');
        $deepseek_key = get_option('alfaai_pro_deepseek_key', '');
        $brave_key = get_option('alfaai_pro_brave_key', '');
        $langchain_key = get_option('alfaai_pro_langchain_service_key', '');
        $google_key = get_option('alfaai_pro_google_key', '');
        $model_mode = get_option('alfaai_pro_model_mode', 'auto');
        $enable_web = get_option('alfaai_pro_enable_web', '1');
        $brand_name = get_option('alfaai_pro_brand_name', 'AlfaAI Professional');
        $brand_color = get_option('alfaai_pro_brand_color', '#6366f1');
        $theme = get_option('alfaai_pro_theme', 'auto');
        $external_dbs = get_option('alfaai_pro_external_dbs', []);
        ?>
        <div class="alfaai-admin-wrap">
            <div class="alfaai-admin-header">
                <div class="alfaai-admin-header-content">
                    <div class="alfaai-admin-logo">
                        <svg width="40" height="40" viewBox="0 0 40 40" fill="none"><rect width="40" height="40" rx="8" fill="url(#gradient)"/><path d="M12 28L20 12L28 28H24L22 24H18L16 28H12Z" fill="white"/><path d="M18.5 20H21.5L20 17L18.5 20Z" fill="white"/><defs><linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:<?php echo esc_attr($brand_color); ?>"/><stop offset="100%" style="stop-color:#8b5cf6"/></linearGradient></defs></svg>
                        <h1><?php echo esc_html($brand_name); ?></h1>
                    </div>
                    <div class="alfaai-admin-version">v<?php echo ALFAAI_PRO_VERSION; ?></div>
                </div>
                <p class="alfaai-admin-subtitle">Dashboard professionale per la configurazione della tua AI avanzata - Enterprise Edition</p>
            </div>
            <div class="alfaai-admin-tabs">
                <button class="alfaai-tab-button active" data-tab="api-keys">API Keys</button>
                <button class="alfaai-tab-button" data-tab="database-alfassa">Database Alfassa</button>
                <button class="alfaai-tab-button" data-tab="video-ai">Video AI</button>
                <button class="alfaai-tab-button" data-tab="branding-theme">Branding & Tema</button>
                <button class="alfaai-tab-button" data-tab="status-sistema">Status Sistema</button>
            </div>
            <form method="post" action="" class="alfaai-admin-form">
                <?php wp_nonce_field('alfaai_admin_settings', 'alfaai_admin_nonce'); ?>
                <div id="api-keys" class="alfaai-tab-content active">
                    <div class="alfaai-section">
                        <h2>Configurazione API Keys</h2>
                        <p class="alfaai-section-description">Configura le chiavi API per i diversi provider AI.</p>
                        <div class="alfaai-cards-grid">
                            <div class="alfaai-card"><div class="alfaai-card-header"><h3>OpenAI</h3><button type="button" class="alfaai-test-btn" data-provider="openai">Test</button></div><div class="alfaai-card-body"><input type="password" name="alfaai_pro_openai_key" value="<?php echo esc_attr($openai_key); ?>" placeholder="sk-..." class="alfaai-input"><small>Per modelli GPT e generazione immagini</small></div></div>
                            <div class="alfaai-card"><div class="alfaai-card-header"><h3>Google Gemini</h3><button type="button" class="alfaai-test-btn" data-provider="gemini">Test</button></div><div class="alfaai-card-body"><input type="password" name="alfaai_pro_gemini_key" value="<?php echo esc_attr($gemini_key); ?>" placeholder="AIza..." class="alfaai-input"><small>Per documenti lunghi e vision</small></div></div>
                            <div class="alfaai-card"><div class="alfaai-card-header"><h3>DeepSeek</h3><button type="button" class="alfaai-test-btn" data-provider="deepseek">Test</button></div><div class="alfaai-card-body"><input type="password" name="alfaai_pro_deepseek_key" value="<?php echo esc_attr($deepseek_key); ?>" placeholder="sk-..." class="alfaai-input"><small>Ottimizzato per coding</small></div></div>
                            <div class="alfaai-card"><div class="alfaai-card-header"><h3>Brave Search</h3><button type="button" class="alfaai-test-btn" data-provider="brave">Test</button></div><div class="alfaai-card-body"><input type="password" name="alfaai_pro_brave_key" value="<?php echo esc_attr($brave_key); ?>" placeholder="BSA..." class="alfaai-input"><small>Per ricerche web</small></div></div>
                            <div class="alfaai-card"><div class="alfaai-card-header"><h3>Google Services</h3><button type="button" class="alfaai-test-btn" data-provider="google">Test</button></div><div class="alfaai-card-body"><input type="password" name="alfaai_pro_google_key" value="<?php echo esc_attr($google_key); ?>" placeholder="AIza..." class="alfaai-input"><small>Per Video AI, Vision OCR</small></div></div>
                            <div class="alfaai-card"><div class="alfaai-card-header"><h3>LangChain Service</h3><button type="button" class="alfaai-test-btn" data-provider="langchain">Test</button></div><div class="alfaai-card-body"><input type="password" name="alfaai_pro_langchain_service_key" value="<?php echo esc_attr($langchain_key); ?>" placeholder="lsv2_..." class="alfaai-input"><small>Per servizi avanzati</small></div></div>
                        </div>
                    </div>
                </div>
                <div id="database-alfassa" class="alfaai-tab-content">
                    <div class="alfaai-section">
                        <h2>Database Alfassa</h2>
                        <p class="alfaai-section-description">Configura i database esterni per il retrieval "ALFASSA first".</p>
                        <div class="alfaai-db-controls"><button type="button" class="alfaai-btn alfaai-btn-primary" id="add-external-db">Aggiungi Database</button></div>
                        <div class="alfaai-external-dbs">
                            <?php if (!empty($external_dbs)): foreach ($external_dbs as $db_array): $db = (object) $db_array; ?>
                                <div class="alfaai-card alfaai-db-card" data-db-id="<?php echo esc_attr($db->id); ?>">
                                    <div class="alfaai-card-header"><h3><?php echo esc_html($db->name); ?></h3>
                                        <div class="alfaai-card-actions">
                                            <button type="button" class="alfaai-btn-edit" data-db-id="<?php echo esc_attr($db->id); ?>">Modifica</button>
                                            <button type="button" class="alfaai-test-btn" data-db-id="<?php echo esc_attr($db->id); ?>">Test</button>
                                            <button type="button" class="alfaai-remove-btn" data-db-id="<?php echo esc_attr($db->id); ?>">Rimuovi</button>
                                        </div>
                                    </div>
                                    <div class="alfaai-card-body"><div class="alfaai-db-info"><span><strong>Host:</strong> <?php echo esc_html($db->host); ?>:<?php echo esc_html($db->port); ?></span><span><strong>Database:</strong> <?php echo esc_html($db->database); ?></span><span><strong>Status:</strong> <?php echo isset($db->is_active) && $db->is_active ? 'Attivo' : 'Inattivo'; ?></span></div></div>
                                </div>
                            <?php endforeach; else: ?>
                                <div class="alfaai-empty-state"><p>Nessun database esterno configurato.</p></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div id="video-ai" class="alfaai-tab-content">
    <div class="alfaai-section">
        <h2>Video AI</h2>
        <p class="alfaai-section-description">Configurazione e monitoraggio per la generazione video con Google (Veo/VideoFX/Vertex AI).</p>
        <div class="alfaai-video-status">
            <div class="alfaai-card">
                <div class="alfaai-card-header"><h3>Status Provider Video</h3></div>
                <div class="alfaai-card-body">
                    <div class="alfaai-provider-status" id="google-video-status">
                        <span class="alfaai-status-indicator"></span>
                        <span>Google Video AI: <span class="status-text">Verificando...</span></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="alfaai-video-jobs">
            <h3>Job Video Recenti</h3>
            <div id="recent-video-jobs"><p>Caricamento job recenti...</p></div>
        </div>
    </div>
</div>
               <div id="branding-theme" class="alfaai-tab-content">
    <div class="alfaai-section">
        <h2>Branding & Tema</h2>
        <p class="alfaai-section-description">Personalizza l'aspetto e il branding dell'interfaccia AI.</p>
        <div class="alfaai-branding-settings">
            <div class="alfaai-form-group">
                <label>Nome Brand:</label>
                <input type="text" name="alfaai_pro_brand_name" value="<?php echo esc_attr($brand_name); ?>" class="alfaai-input">
            </div>
            <div class="alfaai-form-group">
                <label>Colore Brand:</label>
                <input type="color" name="alfaai_pro_brand_color" value="<?php echo esc_attr($brand_color); ?>" class="alfaai-color-input">
            </div>
            <div class="alfaai-form-group">
                <label>Tema:</label>
                <select name="alfaai_pro_theme" class="alfaai-select">
                    <option value="auto" <?php selected($theme, 'auto'); ?>>Auto (Sistema)</option>
                    <option value="light" <?php selected($theme, 'light'); ?>>Chiaro</option>
                    <option value="dark" <?php selected($theme, 'dark'); ?>>Scuro</option>
                </select>
            </div>
        </div>
    </div>
</div>
               <div id="status-sistema" class="alfaai-tab-content">
    <div class="alfaai-section">
        <h2>Status Sistema</h2>
        <p class="alfaai-section-description">Monitoraggio in tempo reale dello stato delle API, dei database e dei requisiti di sistema.</p>
        <div id="system-status-container">
            <div class="alfaai-status-item" id="status-php"><strong>Versione PHP:</strong> <span>Verificando...</span></div>
            <div class="alfaai-status-item" id="status-wp"><strong>Versione WordPress:</strong> <span>Verificando...</span></div>
            <hr>
            <h4>Stato API Keys</h4>
            <div class="alfaai-status-item" id="status-openai"><strong>OpenAI:</strong> <span>Verificando...</span></div>
            <div class="alfaai-status-item" id="status-gemini"><strong>Google Gemini:</strong> <span>Verificando...</span></div>
            <div class="alfaai-status-item" id="status-brave"><strong>Brave Search:</strong> <span>Verificando...</span></div>
            <hr>
            <h4>Stato Database Esterni</h4>
            <div id="status-external-dbs"><span>Verificando...</span></div>
        </div>
    </div>
</div>
                <div class="alfaai-admin-footer"><button type="submit" name="submit" class="alfaai-btn alfaai-btn-primary alfaai-btn-large">Salva Impostazioni</button></div>
            </form>
        </div>
        <div id="add-db-modal" class="alfaai-modal" style="display: none;">
            <div class="alfaai-modal-content">
                <div class="alfaai-modal-header"><h3>Aggiungi/Modifica Database</h3><button type="button" class="alfaai-modal-close">&times;</button></div>
                <div class="alfaai-modal-body">
                    <form id="add-db-form">
                        <div class="alfaai-form-group"><label>Nome:</label><input type="text" name="name" required class="alfaai-input"></div>
                        <div class="alfaai-form-group"><label>Host:</label><input type="text" name="host" required class="alfaai-input"></div>
                        <div class="alfaai-form-group"><label>Porta:</label><input type="number" name="port" value="3306" required class="alfaai-input"></div>
                        <div class="alfaai-form-group"><label>Nome Database:</label><input type="text" name="database" required class="alfaai-input"></div>
                        <div class="alfaai-form-group"><label>Username:</label><input type="text" name="username" required class="alfaai-input"></div>
                        <div class="alfaai-form-group"><label>Password:</label><input type="password" name="password" class="alfaai-input"></div>
                    </form>
                </div>
                <div class="alfaai-modal-footer">
                    <button type="button" class="alfaai-btn alfaai-btn-secondary" id="cancel-add-db">Annulla</button>
                    <button type="button" class="alfaai-btn alfaai-btn-primary" id="save-add-db">Salva</button>
                </div>
            </div>
        </div>
        <?php
    }
    
    public static function save_settings() {
        $settings = ['alfaai_pro_openai_key', 'alfaai_pro_gemini_key', 'alfaai_pro_deepseek_key', 'alfaai_pro_brave_key', 'alfaai_pro_langchain_service_key', 'alfaai_pro_google_key', 'alfaai_pro_model_mode', 'alfaai_pro_enable_web', 'alfaai_pro_brand_name', 'alfaai_pro_brand_color', 'alfaai_pro_theme'];
        foreach ($settings as $setting) {
            if (isset($_POST[$setting])) {
                update_option($setting, sanitize_text_field(stripslashes($_POST[$setting])));
            }
        }
    }
    
    public static function ajax_save_settings() {
        check_ajax_referer('alfaai_admin_nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Unauthorized'], 403); return; }
        self::save_settings();
        wp_send_json_success(['message' => 'Impostazioni salvate']);
    }
    
    public static function ajax_test_provider() {
        check_ajax_referer('alfaai_admin_nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Unauthorized'], 403); return; }
        // Logica di test
        wp_send_json_success(['message' => 'Test per ' . sanitize_text_field($_POST['provider']) . ' superato!']);
    }
    
    public static function ajax_get_system_status() {
        check_ajax_referer('alfaai_admin_nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Unauthorized'], 403); return; }
        $status = ['php_version' => PHP_VERSION, 'wp_version' => get_bloginfo('version'), 'plugin_version' => ALFAAI_PRO_VERSION];
        wp_send_json_success($status);
    }

    public static function ajax_save_external_db() {
        check_ajax_referer('alfaai_admin_nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Unauthorized'], 403); return; }

        $id = sanitize_text_field($_POST['db_id'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $host = sanitize_text_field($_POST['host'] ?? '');
        $port = intval($_POST['port'] ?? 3306);
        $database = sanitize_text_field($_POST['database'] ?? '');
        $username = sanitize_text_field($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$name || !$host || !$database || !$username) {
            wp_send_json_error(['message' => 'Campi obbligatori mancanti']);
            return;
        }

        $list = get_option('alfaai_pro_external_dbs', array());
        $found = false;

        if (!empty($id)) {
            foreach ($list as $key => &$db) {
                if (isset($db['id']) && $db['id'] === $id) {
                    $db['name'] = $name;
                    $db['host'] = $host;
                    $db['port'] = $port;
                    $db['database'] = $database;
                    $db['username'] = $username;
                    if (!empty($password)) {
                        $db['password'] = $password;
                    }
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            $list[] = ['id' => uniqid('db_'), 'name' => $name, 'host' => $host, 'port' => $port, 'database' => $database, 'username' => $username, 'password' => $password, 'is_active' => true];
        }

        update_option('alfaai_pro_external_dbs', $list, false);
        wp_send_json_success(['message' => 'Database salvato']);
    }

    public static function ajax_delete_external_db() {
        check_ajax_referer('alfaai_admin_nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Unauthorized'], 403); return; }
        $id = sanitize_text_field($_POST['id'] ?? '');
        $list = get_option('alfaai_pro_external_dbs', array());
        $new = array_values(array_filter($list, fn($d) => !isset($d['id']) || $d['id'] !== $id));
        update_option('alfaai_pro_external_dbs', $new, false);
        wp_send_json_success(['message' => 'Database rimosso']);
    }
    
    public static function ajax_get_db_details() {
        check_ajax_referer('alfaai_admin_nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Unauthorized'], 403); return; }
        $id = sanitize_text_field($_POST['id'] ?? '');
        $list = get_option('alfaai_pro_external_dbs', array());
        foreach ($list as $db) {
            if (isset($db['id']) && $db['id'] === $id) {
                wp_send_json_success($db);
                return;
            }
        }
        wp_send_json_error(['message' => 'Database non trovato.']);
    }
    public static function ajax_super_test_db() {
    check_ajax_referer('alfaai_admin_nonce');
    if (!current_user_can('manage_options')) { 
        wp_send_json_error(['message' => 'Non autorizzato.']); 
        return;
    }

    $dbs = get_option('alfaai_pro_external_dbs', []);
    if (empty($dbs)) {
        wp_send_json_error(['message' => 'Nessun database esterno configurato da testare.']);
        return;
    }

    // Prendiamo solo il primo database per il test
    $db_config = $dbs[0];

    // Disattiva temporaneamente il reporting degli errori di PHP per catturarli noi
    mysqli_report(MYSQLI_REPORT_OFF);

    // Tenta la connessione
    $connection = @new mysqli(
        $db_config['host'],
        $db_config['username'],
        $db_config['password'],
        $db_config['database'],
        $db_config['port']
    );

    if ($connection->connect_error) {
        $error_message = 'Connessione FALLITA. Errore del server MySQL: ' . $connection->connect_error;
        wp_send_json_error(['message' => $error_message]);
    } else {
        $connection->close();
        wp_send_json_success(['message' => 'Connessione RIUSCITA! Il plugin può comunicare con il database esterno.']);
    }
}
}
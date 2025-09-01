<?php

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class AlfaAI_Database {
    
    public static function init() {
        add_action('init', array(__CLASS__, 'create_tables'));
        add_action('wp_ajax_alfaai_test_external_db', array(__CLASS__, 'test_external_database'));
        add_action('wp_ajax_alfaai_search_external_db', array(__CLASS__, 'search_external_database'));
    }
    
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Conversations table
        $conversations_table = $wpdb->prefix . 'alfaai_conversations';
        $conversations_sql = "CREATE TABLE $conversations_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            title varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY updated_at (updated_at)
        ) $charset_collate;";
        
        // Messages table
        $messages_table = $wpdb->prefix . 'alfaai_messages';
        $messages_sql = "CREATE TABLE $messages_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) NOT NULL,
            role enum('user','assistant','system') NOT NULL,
            content longtext NOT NULL,
            files longtext,
            provider varchar(50),
            model varchar(100),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Files table
        $files_table = $wpdb->prefix . 'alfaai_files';
        $files_sql = "CREATE TABLE $files_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            filename varchar(255) NOT NULL,
            original_name varchar(255) NOT NULL,
            file_path varchar(500) NOT NULL,
            file_url varchar(500) NOT NULL,
            file_size bigint(20) NOT NULL,
            file_type varchar(100) NOT NULL,
            user_id bigint(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Video generations table
        $videos_table = $wpdb->prefix . 'alfaai_videos';
        $videos_sql = "CREATE TABLE $videos_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            prompt text NOT NULL,
            provider varchar(50) NOT NULL,
            status enum('pending','processing','completed','failed') DEFAULT 'pending',
            video_url varchar(500),
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Search history table
        $searches_table = $wpdb->prefix . 'alfaai_searches';
        $searches_sql = "CREATE TABLE $searches_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            query varchar(500) NOT NULL,
            results longtext,
            source varchar(50) NOT NULL DEFAULT 'web',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY source (source)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($conversations_sql);
        dbDelta($messages_sql);
        dbDelta($files_sql);
        dbDelta($videos_sql);
        dbDelta($searches_sql);
        
        // Update database version
        update_option('alfaai_pro_db_version', '1.0.0');
    }
    
    public static function test_external_database() {
    check_ajax_referer('alfaai_admin_nonce', 'nonce');

    $db_id = sanitize_text_field($_POST['db_id']);
    $dbs = get_option('alfaai_pro_external_dbs', []);
    $db_config = null;

    // Trova la configurazione del DB corretto usando l'ID
    foreach ($dbs as $db) {
        if (isset($db['id']) && $db['id'] === $db_id) {
            $db_config = $db;
            break;
        }
    }

    if (!$db_config) {
        wp_send_json_error(array('message' => 'Configurazione DB non trovata.'));
        return;
    }
    
    try {
        // Usa la funzione di connessione esistente con i dati corretti
        $connection = self::connect_external_database(
            $db_config['host'],
            $db_config['port'],
            $db_config['database'],
            $db_config['username'],
            $db_config['password']
        );
        
        if ($connection) {
            mysqli_close($connection);
            wp_send_json_success(array('message' => 'Connessione al database riuscita!'));
        } else {
            // Questo caso non dovrebbe verificarsi a causa dell'eccezione
            wp_send_json_error(array('message' => 'Impossibile connettersi al database.'));
        }
        
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Errore di connessione: ' . $e->getMessage()));
    }
}
    
    public static function search_external_database() {
        check_ajax_referer('alfaai_frontend_nonce', 'nonce');
        
        $query = sanitize_text_field($_POST['query']);
        $database_id = intval($_POST['database_id']);
        
        if (empty($query)) {
            wp_send_json_error('Query di ricerca richiesta');
        }
        
        try {
            $external_dbs = get_option('alfaai_pro_external_dbs', array());
            
            if (!isset($external_dbs[$database_id])) {
                wp_send_json_error('Database non trovato');
            }
            
            $db_config = $external_dbs[$database_id];
            $results = self::perform_external_search($db_config, $query);
            
            // Save search history
            self::save_search_history($query, $results, 'external_db');
            
            wp_send_json_success(array(
                'results' => $results,
                'database' => $db_config['name'],
                'query' => $query
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Errore durante la ricerca: ' . $e->getMessage());
        }
    }
    
    private static function connect_external_database($host, $port, $database, $username, $password) {
        $connection = @mysqli_connect($host, $username, $password, $database, $port);
        
        if (!$connection) {
            throw new Exception(mysqli_connect_error());
        }
        
        mysqli_set_charset($connection, 'utf8mb4');
        
        return $connection;
    }
    
    private static function perform_external_search($db_config, $query) {
        $connection = self::connect_external_database(
            $db_config['host'],
            $db_config['port'],
            $db_config['database'],
            $db_config['username'],
            $db_config['password']
        );
        
        // Get table structure
        $tables_query = "SHOW TABLES";
        $tables_result = mysqli_query($connection, $tables_query);
        
        if (!$tables_result) {
            mysqli_close($connection);
            throw new Exception('Errore nel recupero delle tabelle');
        }
        
        $search_results = array();
        
        // Search in each table
        while ($table_row = mysqli_fetch_array($tables_result)) {
            $table_name = $table_row[0];
            
            // Get table columns
            $columns_query = "SHOW COLUMNS FROM `$table_name`";
            $columns_result = mysqli_query($connection, $columns_query);
            
            if (!$columns_result) {
                continue;
            }
            
            $text_columns = array();
            while ($column_row = mysqli_fetch_array($columns_result)) {
                $column_type = strtolower($column_row['Type']);
                if (strpos($column_type, 'varchar') !== false || 
                    strpos($column_type, 'text') !== false ||
                    strpos($column_type, 'char') !== false) {
                    $text_columns[] = $column_row['Field'];
                }
            }
            
            if (empty($text_columns)) {
                continue;
            }
            
            // Build search query
            $where_conditions = array();
            foreach ($text_columns as $column) {
                $where_conditions[] = "`$column` LIKE '%" . mysqli_real_escape_string($connection, $query) . "%'";
            }
            
            $search_query = "SELECT * FROM `$table_name` WHERE " . implode(' OR ', $where_conditions) . " LIMIT 10";
            $search_result = mysqli_query($connection, $search_query);
            
            if ($search_result && mysqli_num_rows($search_result) > 0) {
                $table_results = array();
                while ($row = mysqli_fetch_assoc($search_result)) {
                    $table_results[] = $row;
                }
                
                $search_results[] = array(
                    'table' => $table_name,
                    'results' => $table_results,
                    'count' => count($table_results)
                );
            }
        }
        
        mysqli_close($connection);
        
        return $search_results;
    }
    
    private static function save_search_history($query, $results, $source) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'alfaai_searches',
            array(
                'user_id' => get_current_user_id(),
                'query' => $query,
                'results' => json_encode($results),
                'source' => $source,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );
    }
    
    public static function save_file($filename, $original_name, $file_path, $file_url, $file_size, $file_type) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'alfaai_files',
            array(
                'filename' => $filename,
                'original_name' => $original_name,
                'file_path' => $file_path,
                'file_url' => $file_url,
                'file_size' => $file_size,
                'file_type' => $file_type,
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    public static function save_video_generation($prompt, $provider, $status = 'pending') {
        global $wpdb;
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'alfaai_videos',
            array(
                'user_id' => get_current_user_id(),
                'prompt' => $prompt,
                'provider' => $provider,
                'status' => $status,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    public static function update_video_generation($id, $status, $video_url = null, $error_message = null) {
        global $wpdb;
        
        $data = array(
            'status' => $status,
            'completed_at' => current_time('mysql')
        );
        
        $format = array('%s', '%s');
        
        if ($video_url) {
            $data['video_url'] = $video_url;
            $format[] = '%s';
        }
        
        if ($error_message) {
            $data['error_message'] = $error_message;
            $format[] = '%s';
        }
        
        return $wpdb->update(
            $wpdb->prefix . 'alfaai_videos',
            $data,
            array('id' => $id),
            $format,
            array('%d')
        );
    }
    
    public static function get_user_conversations($user_id, $limit = 50) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}alfaai_conversations 
             WHERE user_id = %d 
             ORDER BY updated_at DESC 
             LIMIT %d",
            $user_id,
            $limit
        ));
    }
    
    public static function get_conversation_messages($conversation_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}alfaai_messages 
             WHERE conversation_id = %d 
             ORDER BY created_at ASC",
            $conversation_id
        ));
    }
    
    public static function get_user_files($user_id, $limit = 100) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}alfaai_files 
             WHERE user_id = %d 
             ORDER BY created_at DESC 
             LIMIT %d",
            $user_id,
            $limit
        ));
    }
    
    public static function get_user_videos($user_id, $limit = 50) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}alfaai_videos 
             WHERE user_id = %d 
             ORDER BY created_at DESC 
             LIMIT %d",
            $user_id,
            $limit
        ));
    }
    
    public static function get_search_history($user_id, $limit = 100) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}alfaai_searches 
             WHERE user_id = %d 
             ORDER BY created_at DESC 
             LIMIT %d",
            $user_id,
            $limit
        ));
    }
    
    public static function cleanup_old_data($days = 30) {
        global $wpdb;
        
        $date_threshold = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        // Clean old search history
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}alfaai_searches WHERE created_at < %s",
            $date_threshold
        ));
        
        // Clean old completed videos
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}alfaai_videos 
             WHERE status = 'completed' AND completed_at < %s",
            $date_threshold
        ));
        
        // Clean orphaned messages (conversations that no longer exist)
        $wpdb->query(
            "DELETE m FROM {$wpdb->prefix}alfaai_messages m 
             LEFT JOIN {$wpdb->prefix}alfaai_conversations c ON m.conversation_id = c.id 
             WHERE c.id IS NULL"
        );
    }
    
    public static function get_database_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Conversations count
        $stats['conversations'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}alfaai_conversations"
        );
        
        // Messages count
        $stats['messages'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}alfaai_messages"
        );
        
        // Files count
        $stats['files'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}alfaai_files"
        );
        
        // Videos count
        $stats['videos'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}alfaai_videos"
        );
        
        // Searches count
        $stats['searches'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}alfaai_searches"
        );
        
        // Database size
        $stats['database_size'] = $wpdb->get_var($wpdb->prepare(
            "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
             FROM information_schema.tables 
             WHERE table_schema = %s 
             AND table_name LIKE %s",
            DB_NAME,
            $wpdb->prefix . 'alfaai_%'
        ));
        
        return $stats;
    }
    
    public static function export_user_data($user_id) {
        $data = array(
            'conversations' => self::get_user_conversations($user_id),
            'files' => self::get_user_files($user_id),
            'videos' => self::get_user_videos($user_id),
            'searches' => self::get_search_history($user_id)
        );
        
        // Add messages for each conversation
        foreach ($data['conversations'] as &$conversation) {
            $conversation->messages = self::get_conversation_messages($conversation->id);
        }
        
        return $data;
    }
    
    public static function delete_user_data($user_id) {
        global $wpdb;
        
        // Delete in correct order to respect foreign key constraints
        $wpdb->delete($wpdb->prefix . 'alfaai_searches', array('user_id' => $user_id), array('%d'));
        $wpdb->delete($wpdb->prefix . 'alfaai_videos', array('user_id' => $user_id), array('%d'));
        $wpdb->delete($wpdb->prefix . 'alfaai_files', array('user_id' => $user_id), array('%d'));
        
        // Delete messages for user's conversations
        $wpdb->query($wpdb->prepare(
            "DELETE m FROM {$wpdb->prefix}alfaai_messages m 
             INNER JOIN {$wpdb->prefix}alfaai_conversations c ON m.conversation_id = c.id 
             WHERE c.user_id = %d",
            $user_id
        ));
        
        // Delete conversations
        $wpdb->delete($wpdb->prefix . 'alfaai_conversations', array('user_id' => $user_id), array('%d'));
        
        return true;
    }
     public static function get_conversations($user_id, $limit = 50) {
        // Questa funzione è un alias per get_user_conversations per compatibilità
        return self::get_user_conversations($user_id, $limit);
    }

    public static function get_messages($conversation_id) {
        // Questa funzione è un alias per get_conversation_messages per compatibilità
        return self::get_conversation_messages($conversation_id);
    }
    
    public static function save_conversation($user_id, $title) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'alfaai_conversations';

        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'title' => $title,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s')
        );

        return $result ? $wpdb->insert_id : false;
    }

    public static function save_message($conversation_id, $role, $content, $files_json = '[]', $provider = null, $model = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'alfaai_messages';

    // Se il modello è 'none', non provare a salvarlo nel database.
    if ($model === 'none') {
        $model = null;
    }

    $wpdb->insert(
        $table_name,
        array(
            'conversation_id' => $conversation_id,
            'role' => $role,
            'content' => $content,
            'files' => $files_json,
            'provider' => $provider,
            'model' => $model, // Ora sarà null se era 'none'
            'created_at' => current_time('mysql'),
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
    );
}
    public static function get_external_databases() {
        $dbs = get_option('alfaai_pro_external_dbs', []);
        // Converte gli array in oggetti per compatibilità con il resto del codice
        return array_map(function($d) {
            return (object) $d;
        }, $dbs);
    }
}


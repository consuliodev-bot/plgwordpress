<?php
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class AlfaAI_API {
    
    public static function init() {
    add_action('rest_api_init', array(__CLASS__, 'register_routes'));

    add_action('wp_ajax_alfaai_send_message', array(__CLASS__, 'handle_send_message'));
    add_action('wp_ajax_nopriv_alfaai_send_message', array(__CLASS__, 'handle_send_message'));

    add_action('wp_ajax_alfaai_generate_image', array(__CLASS__, 'handle_generate_image'));
    add_action('wp_ajax_nopriv_alfaai_generate_image', array(__CLASS__, 'handle_generate_image'));

    add_action('wp_ajax_alfaai_generate_video', array(__CLASS__, 'handle_generate_video'));
    add_action('wp_ajax_nopriv_alfaai_generate_video', array(__CLASS__, 'handle_generate_video'));

    add_action('wp_ajax_alfaai_video_status', array(__CLASS__, 'handle_video_status'));
    add_action('wp_ajax_nopriv_alfaai_video_status', array(__CLASS__, 'handle_video_status'));

    // >>> CRON (giusto qui, dentro init) <<<
    add_action('init', [__CLASS__, 'schedule_daily_sync']);
    add_action('alfaai_daily_sync', [__CLASS__, 'export_articles_to_json']);
}

    
    public static function register_routes() {
        register_rest_route('alfaai/v1', '/chat', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_send_message'),
            'permission_callback' => '__return_true',
            'args' => array(
                'message' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field'
                ),
                'conversation_id' => array(
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                ),
                'provider' => array(
                    'type' => 'string',
                    'default' => 'auto',
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
        
        register_rest_route('alfaai/v1', '/generate-image', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_generate_image'),
            'permission_callback' => '__return_true',
            'args' => array(
                'prompt' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field'
                )
            )
        ));
        
        register_rest_route('alfaai/v1', '/generate-video', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_generate_video'),
            'permission_callback' => '__return_true',
            'args' => array(
                'prompt' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field'
                )
            )
        ));
        
        register_rest_route('alfaai/v1', '/video-status/(?P<job_id>[a-zA-Z0-9\-_]+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'rest_video_status'),
            'permission_callback' => '__return_true'
        ));
    }
    
    // Main message handling with ALFASSA first logic
 public static function handle_send_message() {
    // 1) Nonce
    if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'alfaai_frontend_nonce')) {
        self::send_final_error('Nonce verification failed');
        return;
    }

    // 2) SSE headers
    $is_sse = !empty($_GET['stream']);
    if ($is_sse && !headers_sent()) {
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        nocache_headers();
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) { @ob_end_flush(); }
        ob_implicit_flush(true);
    }

    // 3) Messaggio utente
    $message = sanitize_textarea_field($_REQUEST['message'] ?? '');

    // 4) Saluti immediati
    if (preg_match('/^(ciao|buongiorno|buonasera|hey|salve)\b/i', $message)) {
        $greetings = ['Ciao! Come posso aiutarti?', 'Ciao 👋 Dimmi pure!', 'Ciao! In cosa posso esserti utile oggi?'];
        $response = [
            'content'     => $greetings[array_rand($greetings)],
            'provider'    => 'assistant',
            'model'       => 'dialog',
            'attachments' => []
        ];
        self::send_and_save_response($message, $response, $is_sse);
        return;
    }

    // 5) Parametri dal frontend
    $mode     = isset($_REQUEST['mode']) ? sanitize_text_field($_REQUEST['mode']) : 'chat';
    $provider = isset($_REQUEST['provider']) ? sanitize_text_field($_REQUEST['provider']) : 'auto';

    // 6) Smalltalk breve → risposta veloce del modello
    if (self::is_smalltalk($message)) {
        self::call_openai($message, []);
        exit;
    }

    // 7) Contesto interno (DB+knowledge)
    $final_message  = $message;
    $attachments    = ['web_sources' => [], 'images' => []];
    $internal_notes = '';

    if ($is_sse) self::send_stream_event('step', ['message' => 'Analizzo dati interni...']);

    $db_results = self::search_alfassa_databases($message);
    if (!empty($db_results))  $internal_notes .= self::build_db_context($db_results);

    $knowledge_hits = self::search_alfassa_knowledge($message, 6);
    if (!empty($knowledge_hits)) $internal_notes .= self::build_knowledge_context($knowledge_hits);

    if (!empty($internal_notes)) {
        $final_message .= "\n\n[CONTESTO INTERNO (NON RIVELARE):]\n{$internal_notes}\n";
        $final_message .= "Istruzioni: usa queste note interne per migliorare la risposta, ma NON menzionare database interni, tabelle o 'whitepaper/knowledge'. Rispondi in prosa naturale, strutturata, tono professionale.\n";
    }

    // 8) Richieste di articoli/link dai siti Alfassa (risposta editoriale + attachments)
    if (self::is_article_request($message)) {
        if ($is_sse) self::send_stream_event('step', ['message' => 'Cerco articoli Alfassa...']);
        $articles = self::search_alfassa_articles($message, 8);
        $response = self::generate_response_from_articles($message, $articles);
        self::send_and_save_response($message, $response, $is_sse);
        return;
    }

    // 9) Web search se serve
    $web_results = [];
    if ($mode === 'web' || self::needs_web_search($message)) {
        if ($is_sse) self::send_stream_event('step', ['message' => 'Cerco sul web...']);
        $web_results = self::perform_web_search($message);

        $is_person = self::is_person_query($message);
        if ($is_person) {
            if ($is_sse) self::send_stream_event('step', ['message' => 'Cerco profilo LinkedIn...']);
            $linkedin = self::perform_linkedin_lookup($message);
            if ($linkedin && !empty($linkedin['url'])) {
                $already = false;
                foreach ($web_results as $r) {
                    if (!empty($r['url']) && $r['url'] === $linkedin['url']) { $already = true; break; }
                }
                if (!$already) {
                    array_unshift($web_results, [
                        'title' => $linkedin['title'],
                        'url'   => $linkedin['url'],
                        'description' => 'Profilo LinkedIn (fonte prioritaria)',
                    ]);
                }
            }
        }

        if (!empty($web_results)) {
            $final_message .= "\n\nUsa le seguenti FONTI WEB per rispondere. Cita in testo come [1], [2].\nFONTI:\n";
            foreach ($web_results as $idx => $r) {
                $n     = $idx + 1;
                $title = isset($r['title']) ? $r['title'] : '';
                $url   = isset($r['url']) ? $r['url'] : '';
                $desc  = isset($r['description']) ? $r['description'] : '';
                $final_message .= "[$n] {$title} — {$url}\n{$desc}\n";

                // >>> QUI cambiamo l’etichetta mostrata nel riquadro "Fonti web"
                $label = self::build_source_label($title, $url); // "Titolo — dominio"
                $attachments['web_sources'][] = [
                    'title'  => $label,
                    'url'    => $url,
                    'domain' => $label, // il frontend usa 'domain' come testo → lo forziamo uguale all'etichetta
                ];
            }

            if ($is_sse) self::send_stream_event('step', ['message' => 'Cerco immagini pertinenti...']);
            $images = self::perform_image_search($message, $web_results);
            if (!empty($images)) $attachments['images'] = array_slice($images, 0, 3);
        } else {
            if ($is_sse) self::send_stream_event('step', ['message' => 'Nessun risultato web; procedo senza fonti.']);
        }
    }

    // 10) Invoca il modello con stream
    self::call_openai($final_message, $attachments);
    exit;
}



// Schedula la sync una volta al giorno (attiva su init del plugin)
public static function schedule_daily_sync() {
    if (!wp_next_scheduled('alfaai_daily_sync')) {
        wp_schedule_event(time() + 600, 'daily', 'alfaai_daily_sync');
    }
}


// Esporta articoli con URL in un JSON cache
public static function export_articles_to_json() {
    $hits = self::search_alfassa_databases(''); // prendi un sottoinsieme sensato nella tua versione
    $site_map = self::get_site_url_map();
    $out = [];

    foreach ($hits as $r) {
        $row   = (array)$r['data'];
        $url   = self::build_article_url($row, $r['table'], $r['source'], $site_map);
        if (!$url) continue;

        $title = '';
        foreach (['post_title','title','name','headline'] as $k) {
            if (!empty($row[$k])) { $title = self::clean_text($row[$k]); break; }
        }
        if ($title === '') $title = ucfirst(str_replace('_',' ',$r['table']));

        $snippet = '';
        foreach (['excerpt','description','content','bio','post_excerpt'] as $k) {
            if (!empty($row[$k])) { $snippet = self::str_snippet($row[$k], 200); break; }
        }

        $out[] = [
            'title'   => $title,
            'url'     => $url,
            'source'  => $r['source'],
            'table'   => $r['table'],
            'snippet' => $snippet,
        ];
    }

    $base = plugin_dir_path(dirname(__FILE__));
    if (!file_exists($base.'assets/cache')) {
        @wp_mkdir_p($base.'assets/cache');
    }
    file_put_contents($base.'assets/cache/alfassa-articles.json', wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}


private static function is_article_request(string $q): bool {
    $ql = mb_strtolower($q, 'UTF-8');
    $needles = [
        'articolo','articoli','post','blog','news','notizia','articolo su','link','collegamento',
        'mandami il link','dammi il link','dove posso leggere','mostrami l\'articolo','scheda progetto'
    ];
    foreach ($needles as $n) {
        if (mb_strpos($ql, $n) !== false) return true;
    }
    // anche pattern tipo "articolo: <titolo>"
    if (preg_match('/articol[oi]\s*:/u', $ql)) return true;
    return false;
}

private static function search_alfassa_articles(string $query, int $limit = 8): array {
    $external_dbs = AlfaAI_Database::get_external_databases();
    if (empty($external_dbs)) return [];

    // parole-chiave pulite dalla query
    $tokens = self::extract_keywords($query);
    // se dopo il filtro non resta nulla, buttiamoci comunque su "alfassa"
    if (empty($tokens)) $tokens = ['alfassa'];

    // mappa key -> site_url (impostata in "AlfaAI – External Sites")
    $site_map = get_option('alfaai_pro_external_site_urls', []);

    $out = [];

    foreach ($external_dbs as $cfg) {
        $db = (object)$cfg;

        // chiave per la mappa site_url: prima name, altrimenti host|database
        $key = '';
        if (!empty($db->name)) {
            $key = trim((string)$db->name);
        } else {
            $host     = trim((string)($db->host ?? ''));
            $database = trim((string)($db->database ?? ''));
            $key      = strtolower($host . '|' . $database);
        }

        // connessione
        $mysqli = @new \mysqli($db->host, $db->username, $db->password, $db->database, (int)($db->port ?? 3306));
        if ($mysqli->connect_error) { continue; }
        $mysqli->set_charset('utf8mb4');

        // trova tabella posts
        $posts_tbl = '';
        $res = $mysqli->query("SHOW TABLES LIKE '%\\_posts'");
        if ($res && $row = $res->fetch_array()) { $posts_tbl = $row[0]; }
        if ($res) $res->free();
        if ($posts_tbl === '') { $mysqli->close(); continue; }

        // site_url: 1) mapping UI  2) proprietà site_url nel config  3) wp_options
        $site_url = '';
        if (!empty($site_map[$key])) {
            $site_url = trim($site_map[$key]);
        } elseif (!empty($db->site_url)) {
            $site_url = trim((string)$db->site_url);
        }
        if ($site_url === '') {
            $options_tbl = preg_replace('/_posts$/', '_options', $posts_tbl);
            if ($options_tbl && $options_tbl !== $posts_tbl) {
                $o = $mysqli->query("SELECT option_value FROM `$options_tbl` WHERE option_name IN ('siteurl','home') ORDER BY option_name='siteurl' DESC LIMIT 1");
                if ($o && $opt = $o->fetch_assoc()) { $site_url = trim((string)$opt['option_value']); }
                if ($o) $o->free();
            }
        }
        $site_url = rtrim($site_url, '/');

        // costruisci score dinamico (titolo pesa più del contenuto)
        $score_sql_parts = [];
        $where_or_parts  = [];
        foreach ($tokens as $tok) {
            $tok_esc = $mysqli->real_escape_string($tok);
            $score_sql_parts[] = "(CASE WHEN post_title LIKE '%$tok_esc%' THEN 3 ELSE 0 END) + (CASE WHEN post_content LIKE '%$tok_esc%' THEN 1 ELSE 0 END)";
            $where_or_parts[]  = "post_title LIKE '%$tok_esc%' OR post_content LIKE '%$tok_esc%'";
        }
        $score_sql = implode(' + ', $score_sql_parts);
        $where_or  = '(' . implode(' OR ', $where_or_parts) . ')';

        // query: priorità post/pagina/news/article, pubblicati, ordinati per score e data
        $sql = "
            SELECT 
                ID, post_title, post_date, post_content, post_name, post_type, post_status,
                ($score_sql) AS score
            FROM `$posts_tbl`
            WHERE post_status='publish'
              AND post_type IN ('post','page','news','article')
              AND $where_or
            ORDER BY score DESC, post_date DESC
            LIMIT " . (int)max($limit * 2, 12);

        $rs = $mysqli->query($sql);
        if ($rs) {
            while ($r = $rs->fetch_assoc()) {
                if (empty($r['post_title']) || (int)$r['score'] <= 0) continue;

                // URL sicuro (sempre funzionante): ?p=ID
                $url = '#';
                if ($site_url !== '') {
                    $url = $site_url . '/?p=' . (int)$r['ID'];
                }

                $excerpt = self::str_snippet(wp_strip_all_tags($r['post_content'] ?? ''), 320);

                $out[] = [
                    'title'   => trim((string)$r['post_title']),
                    'url'     => esc_url_raw($url),
                    'excerpt' => $excerpt,
                    'date'    => substr((string)$r['post_date'], 0, 10),
                    'source'  => $site_url ?: ($db->name ?? 'alfassa-db'),
                    'score'   => (int)$r['score'],
                ];
            }
            $rs->free();
        }

        $mysqli->close();
    }

    // ordina per score e data, taglia al limite
    if (!empty($out)) {
        usort($out, function($a, $b){
            $s = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            if ($s !== 0) return $s;
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });
        $out = array_slice($out, 0, $limit);
    }

    return $out;
}


// === Helper: mappa chiave DB -> site_url salvata in "AlfaAI – External Sites" ===
private static function get_site_url_map(): array {
    $rows = get_option('alfaai_external_sites', []); // array di righe salvate dalla mini-UI
    $map  = [];

    if (is_array($rows)) {
        foreach ($rows as $r) {
            $key = isset($r['key']) ? strtolower(trim($r['key'])) : '';
            $url = isset($r['site_url']) ? trim($r['site_url']) : '';
            if ($key !== '' && $url !== '') {
                $map[$key] = rtrim($url, '/');
            }
        }
    }

    // fallback “ovvio” per i domini ufficiali
    if (!isset($map['alfassa.org'])) $map['alfassa.org'] = 'https://www.alfassa.org';
    if (!isset($map['alfassa.net'])) $map['alfassa.net'] = 'https://www.alfassa.net';

    return $map;
}
private static function safe_host($url): string {
    if (!$url) return 'alfassa';
    $host = parse_url((string)$url, PHP_URL_HOST);
    if (!$host) {
        // se arriva senza schema (es. //site o /path), prova ad aggiungere https
        $host = parse_url('https://' . ltrim((string)$url, '/'), PHP_URL_HOST);
    }
    if (!$host) return 'alfassa';
    return preg_replace('/^www\./i', '', $host);
}
// Etichetta leggibile per il riquadro "Fonti web": "Titolo — dominio" (troncata)
private static function build_source_label($title, $url): string {
    $t = self::clean_text($title ?: '');
    if ($t === '') $t = self::safe_host($url);
    $t = self::str_snippet($t, 72);
    $host = self::safe_host($url);
    if ($host && mb_stripos($t, $host) === false) {
        $t .= ' — ' . $host;
    }
    return $t;
}

// Blocco articolo leggibile (niente markdown): numerazione + icone + righe separate
private static function format_article_block(int $index, string $title, string $url, string $date = '', string $excerpt = ''): string {
    $title   = self::clean_text($title);
    $url     = trim($url);
    $host    = self::safe_host($url);
    $excerpt = self::str_snippet($excerpt, 240);

    $out  = $index . "️⃣  📄 " . $title . "\n";
    $out .= "   🌐 " . $host;
    if (!empty($date)) { $out .= "   •   🗓️ " . $date; }
    $out .= "\n";
    if (!empty($excerpt)) { $out .= "   📝 " . $excerpt . "\n"; }
    $out .= "   🔗 " . $url . "\n\n";
    return $out;
}


// Deduplica e normalizza web_sources (per URL), mantiene la prima occorrenza
private static function dedupe_web_sources(array $sources): array {
    $seen = [];
    $out  = [];
    foreach ($sources as $it) {
        $u = isset($it['url']) ? (string)$it['url'] : '';
        if ($u === '' || isset($seen[$u])) { continue; }
        $seen[$u] = true;

        $title = isset($it['title']) && $it['title'] !== '' ? (string)$it['title'] : $u;
        $out[] = [
            'title'  => self::short_title($title, 96),
            'url'    => $u,
            'domain' => self::safe_host($u),
        ];
    }
    return $out;
}

// Accorcia un titolo troppo lungo preservando il senso
private static function short_title(string $str, int $max = 96): string {
    $t = trim(preg_replace('/\s+/u', ' ', $str));
    if (mb_strlen($t, 'UTF-8') <= $max) return $t;
    $cut = mb_substr($t, 0, $max - 1, 'UTF-8');
    // non troncare in mezzo alla parola
    $cut = preg_replace('/[^\p{L}\p{N}]+$/u', '', $cut);
    return rtrim($cut).'…';
}


// === Helper: pulizia breve stringhe per titoli/snippet
private static function clean_text($v): string {
    if (is_array($v) || is_object($v)) {
        $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
    }
    $v = wp_strip_all_tags((string)$v);
    $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $v = preg_replace('/\s+/u', ' ', $v);
    return trim($v);
}

// === Helper: trova una URL (anche annidata) in array/json
private static function find_first_url_recursive($data): ?string {
    if (is_string($data)) {
        if (preg_match('#https?://[^\s"<>\)]+#i', $data, $m)) return $m[0];
        return null;
    }
    if (is_array($data)) {
        foreach ($data as $v) {
            $u = self::find_first_url_recursive($v);
            if ($u) return $u;
        }
    } elseif (is_object($data)) {
        foreach (get_object_vars($data) as $v) {
            $u = self::find_first_url_recursive($v);
            if ($u) return $u;
        }
    }
    return null;
}

// === Helper: costruisce l’URL dell’articolo dal record + tabella + sorgente DB ===
private static function build_article_url(array $row, string $table, string $source_key, array $site_map): ?string {
    // 1) Colonne “classiche”
    foreach (['guid','permalink','url','link','href'] as $k) {
        if (!empty($row[$k]) && filter_var($row[$k], FILTER_VALIDATE_URL)) {
            return esc_url_raw($row[$k]);
        }
    }

    // 2) Se ci sono payload JSON, cerca url dentro
    foreach ($row as $v) {
        // già in search_alfassa_databases() potremmo aver decodificato json → qui lo sfruttiamo
        $u = self::find_first_url_recursive($v);
        if ($u && filter_var($u, FILTER_VALIDATE_URL)) return esc_url_raw($u);
    }

    // 3) WordPress: wp_posts (o simili) → usa site_url + slug
    $site = '';
    $key  = strtolower(trim($source_key));
    if (isset($site_map[$key])) $site = $site_map[$key];

    // Heuristics WP
    if ($site !== '') {
        // slug/post_name
        if (!empty($row['post_name'])) {
            return esc_url_raw($site . '/' . ltrim($row['post_name'], '/'));
        }
        if (!empty($row['slug'])) {
            return esc_url_raw($site . '/' . ltrim($row['slug'], '/'));
        }
        // id numerico (permalink di base): https://site/?p=ID
        if (!empty($row['ID']) && is_numeric($row['ID'])) {
            return esc_url_raw($site . '/?p=' . intval($row['ID']));
        }
    }

    return null; // non siamo riusciti a costruirla
}


private static function generate_response_from_articles(string $query, array $articles): array {
    // Se non ci sono risultati
    if (empty($articles)) {
        return [
            'content'     => "## Articoli ALFASSA\n\nNon ho trovato articoli pertinenti alla richiesta: **" . self::clean_text($query) . "**.\nProva a usare un titolo o una parola chiave più specifica.",
            'provider'    => 'alfassa_db',
            'model'       => 'retrieval',
            'attachments' => ['web_sources' => [], 'images' => []],
            'format'      => 'markdown',
        ];
    }

    $lines        = [];
    $attachments  = ['web_sources' => [], 'images' => []];
    $i            = 1;

    foreach ($articles as $a) {
        $title   = self::clean_text($a['title']   ?? '');
        $url     = esc_url_raw($a['url']         ?? '#');
        $snippet = self::str_snippet($a['excerpt'] ?? ($a['snippet'] ?? ''), 260);
        $date    = !empty($a['date']) ? $a['date'] : '';
        $host    = self::safe_host($url);

        $line  = $i . ". **{$title}** — _{$host}_";
        if ($date !== '') $line .= " ({$date})";
        $line .= "\n";
        if ($snippet !== '') $line .= "   > {$snippet}\n";
        $line .= "   [Apri l’articolo →]({$url})";

        $lines[] = $line;

        // Etichetta per il riquadro "Fonti web"
        $label = self::build_source_label($title, $url);
        $attachments['web_sources'][] = [
            'title'  => $label,
            'url'    => $url,
            'domain' => $label,
        ];
        $i++;
    }

    $intro = "## Articoli ALFASSA pertinenti\n\n";
    $intro .= "Ho trovato **" . count($articles) . "** contenuti relativi a “" . self::clean_text($query) . "”. Ecco i migliori:\n\n";

    $content = $intro . implode("\n\n", $lines);

    return [
        'content'     => $content,
        'provider'    => 'alfassa_db',
        'model'       => 'retrieval',
        'attachments' => $attachments,
        'format'      => 'markdown',
    ];
}

// Estrae parole-chiave significative da una frase IT/EN
private static function extract_keywords(string $q): array {
    $q = mb_strtolower($q, 'UTF-8');
    // rimuovi punteggiatura/apostrofi tipografici
    $q = str_replace(['’','“','”','«','»'], ["'","\"","\"","\"","\""], $q);
    $q = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q);

    $raw = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
    $stop = [
        'un','una','uno','il','lo','la','i','gli','le','di','dei','delle','della','del','degli',
        'e','ed','o','oppure','che','chi','come','cosa','cos','cos\'','su','sul','sulla','per',
        'con','da','nel','nella','nei','nelle','al','allo','alla','agli','alle','ai',
        'articolo','articoli','post','blog','news','notizia','link','collegamento','dammi','mandami',
        'parla','parlare','riguarda','riguardante','su',
        'about','the','and','or','of','to','in','on','for','an','a'
    ];
    $out = [];
    foreach ($raw as $t) {
        $t = trim($t, "'");
        if ($t === '' || in_array($t, $stop, true)) continue;
        if (mb_strlen($t, 'UTF-8') < 3) continue;
        $out[] = $t;
    }
    // unici
    $out = array_values(array_unique($out));
    // limitiamo a 8 per non esplodere lo SQL
    return array_slice($out, 0, 8);
}

// Host “sicuro” da una URL (per attachments)



// Small talk / saluti in IT/EN → evita ricerche e rispondi naturale
private static function is_smalltalk(string $q): bool {
    $ql = mb_strtolower(trim($q), 'UTF-8');
    // se la frase è cortissima, alta probabilità di smalltalk
    if (mb_strlen($ql, 'UTF-8') <= 24) {
        $patterns = [
            'ciao','buongiorno','buona sera','buonasera','hey','hei','hola','salve',
            'come va','come stai','come posso','aiuto','help','thanks','grazie',
            'buon pomeriggio','buonpomeriggio','buonanotte','notte','we','yo'
        ];
        foreach ($patterns as $p) {
            if (mb_strpos($ql, $p) !== false) return true;
        }
    }
    // domande tipo "sei lì?", "ci sei?"
    if (preg_match('/\b(ci sei|sei li|stai li|mi senti)\b/u', $ql)) return true;
    return false;
}

// Trasforma risultati del DB in CONTESTO TESTUALE nascosto e filtra tabelle rumorose
private static function build_db_context(array $results): string {
    if (empty($results)) return '';
    $buf = "— NOTE INTERNE (DB):\n";
    foreach ($results as $r) {
        $tbl = strtolower($r['table'] ?? '');
        // Filtri hard per non portare log/notifiche/opzioni
        if ($tbl === '' ||
            strpos($tbl, 'log') !== false ||
            strpos($tbl, 'notif') !== false ||
            strpos($tbl, 'notification') !== false ||
            strpos($tbl, 'wpnotif') !== false ||
            strpos($tbl, 'option') !== false ||
            strpos($tbl, 'session') !== false ||
            strpos($tbl, 'cache') !== false ||
            strpos($tbl, 'queue') !== false ||
            strpos($tbl, 'audit') !== false ||
            strpos($tbl, 'debug') !== false ||
            strpos($tbl, 'error') !== false ||
            // in generale evita quasi tutto il prefisso wp_
            preg_match('/^wp_/i', $tbl)
        ) {
            continue;
        }

        $row = (array)($r['data'] ?? []);
        // prova campi comuni per un riassunto umano
        $title = '';
        foreach (['title','name','nome','oggetto','subject'] as $k) {
            if (!empty($row[$k]) && is_string($row[$k])) { $title = $row[$k]; break; }
        }
        $desc  = '';
        foreach (['description','descrizione','bio','content','testo','text','summary','excerpt'] as $k) {
            if (!empty($row[$k])) { $desc = is_string($row[$k]) ? $row[$k] : wp_json_encode($row[$k], JSON_UNESCAPED_UNICODE); break; }
        }
        $role  = '';
        foreach (['role','ruolo','position','posizione','title_role'] as $k) {
            if (!empty($row[$k]) && is_string($row[$k])) { $role = $row[$k]; break; }
        }

        $line = '';
        if ($title !== '') $line .= $title;
        if ($role  !== '') $line .= ($line ? ' — ' : '') . $role;
        if ($desc  !== '') $line .= ($line ? ' — ' : '') . self::str_snippet($desc, 260);

        if ($line === '') {
            // fallback: compatto tutta la riga
            $line = self::str_snippet(wp_json_encode($row, JSON_UNESCAPED_UNICODE), 260);
        }
        $buf .= "• " . $line . "\n";
    }
    if ($buf === "— NOTE INTERNE (DB):\n") return ''; // tutto filtrato
    return $buf . "\n";
}

// Trasforma gli hits del knowledge in CONTESTO TESTUALE nascosto
private static function build_knowledge_context(array $hits): string {
    if (empty($hits)) return '';
    // Ordina per score discendente
    usort($hits, function($a, $b){
        return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });
    $buf = "— NOTE INTERNE (KNOWLEDGE):\n";
    foreach ($hits as $h) {
        $title   = !empty($h['title'])   ? $h['title']   : 'Documento';
        $section = !empty($h['section']) ? " — ".$h['section'] : '';
        $snip    = self::str_snippet($h['snippet'] ?? '', 420);
        $buf    .= "• {$title}{$section} — {$snip}\n";
    }
    return $buf . "\n";
}


/* ==============================
 * WHITEPAPER / KNOWLEDGE LOCALE
 * assets/data/alfassa-knowledge.json
 * Schema suggerito:
 * [
 *   {"id":"wp-001","section":"Cos'è Alfassa","title":"Visione","keywords":["alfassa","missione"],"text":"...", "bullets":["...","..."]},
 *   ...
 * ]
 * Se il file non esiste, le funzioni tornano [] e non succede nulla.
 * ============================== */

private static function load_alfassa_knowledge(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $base = plugin_dir_path(dirname(__FILE__)); // /includes -> root plugin
    $file = $base . 'assets/data/alfassa-knowledge.json';

    if (!file_exists($file)) { $cache = []; return $cache; }

    $json = @file_get_contents($file);
    if ($json === false) { error_log('[AlfaAI] Impossibile leggere alfassa-knowledge.json'); $cache = []; return $cache; }

    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        error_log('[AlfaAI] JSON knowledge non valido: ' . json_last_error_msg());
        $cache = [];
        return $cache;
    }

    // Caso 1: già lista di doc {id,title,section,keywords[],text}
    $is_list = array_keys($data) === range(0, count($data)-1);
    if ($is_list && isset($data[0]['text'])) { $cache = $data; return $cache; }

    // Caso 2: oggetto a sezioni -> flatten
    $docs = [];

    // FAQ -> un doc per Q/A
    if (!empty($data['faqs']) && is_array($data['faqs'])) {
        foreach ($data['faqs'] as $i => $faq) {
            if (!is_array($faq)) continue;
            $answer = $faq['answer'] ?? '';
            if (is_array($answer)) { $answer = "- " . implode("\n- ", array_map('strval', $answer)); }
            $docs[] = [
                'id'       => $faq['id'] ?? ('faq-'.$i),
                'section'  => 'FAQ',
                'title'    => trim((string)($faq['question'] ?? 'FAQ')),
                'text'     => trim((string)$answer),
                'keywords' => !empty($faq['tags']) && is_array($faq['tags']) ? $faq['tags'] : [],
            ];
        }
    }

    // Overview -> un doc riassuntivo
    if (!empty($data['overview']) && is_array($data['overview'])) {
        $ov = $data['overview'];
        $parts = [];
        foreach (['synopsis','value_proposition'] as $k) {
            if (!empty($ov[$k]) && is_string($ov[$k])) $parts[] = $ov[$k];
        }
        if (!empty($ov['keypoints']) && is_array($ov['keypoints'])) {
            $parts[] = implode('; ', array_map('strval', $ov['keypoints']));
        }
        if (!empty($ov['core_principles']) && is_array($ov['core_principles'])) {
            $parts[] = implode('; ', array_map('strval', $ov['core_principles']));
        }
        $docs[] = [
            'id'       => 'overview',
            'section'  => 'Overview',
            'title'    => 'Che cos’è ALFASSA',
            'text'     => trim(implode("\n\n", $parts)),
            'keywords' => ['alfassa','overview','sistema','ecosistema'],
        ];
    }

    // Entities -> tipo glossario
    if (!empty($data['entities']) && is_array($data['entities'])) {
        foreach ($data['entities'] as $name => $tags) {
            $slug = 'entity-' . trim(preg_replace('/[^a-z0-9\-]+/i', '-', strtolower((string)$name)), '-');
            $docs[] = [
                'id'       => $slug,
                'section'  => 'Glossario',
                'title'    => (string)$name,
                'text'     => is_array($tags) ? implode(', ', array_map('strval', $tags)) : (string)$tags,
                'keywords' => is_array($tags) ? array_map('strval', $tags) : [(string)$name],
            ];
        }
    }

    // Sistema di sviluppo -> subsystems (se presenti)
    if (!empty($data['sistema_di_sviluppo']['subsystems']) && is_array($data['sistema_di_sviluppo']['subsystems'])) {
        foreach ($data['sistema_di_sviluppo']['subsystems'] as $i => $sub) {
            if (!is_array($sub)) continue;
            $docs[] = [
                'id'       => $sub['id'] ?? ('sub-'.$i),
                'section'  => 'Sistema di sviluppo',
                'title'    => $sub['title'] ?? 'Sottosistema',
                'text'     => trim((string)($sub['description'] ?? '')),
                'keywords' => !empty($sub['keywords']) && is_array($sub['keywords']) ? $sub['keywords'] : [],
            ];
        }
    }

    $cache = $docs;
    return $cache;
}



private static function search_alfassa_knowledge(string $query, int $limit = 6): array {
    $docs = self::load_alfassa_knowledge();
    if (empty($docs)) return [];

    // tokenizza query togliendo punteggiatura e stop-words
    $q = mb_strtolower($query, 'UTF-8');
    $raw = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
    $stop = ['che','cosa','cos','cos\'','è','e','il','la','lo','i','gli','le','di','del','della','un','una','uno','what','is','who','chi'];
    $tokens = [];
    foreach ($raw as $t) {
        $t = preg_replace('/[^\p{L}\p{N}]+/u', '', $t);
        if ($t !== '' && !in_array($t, $stop, true)) $tokens[] = $t;
    }
    if (empty($tokens)) $tokens = ['alfassa'];

    // scoring semplice
    $scores = [];
    foreach ($docs as $i => $d) {
        $hay = mb_strtolower(
            ($d['title'] ?? '') . ' ' .
            ($d['section'] ?? '') . ' ' .
            ($d['text'] ?? '') . ' ' .
            implode(' ', $d['keywords'] ?? []),
            'UTF-8'
        );
        $score = 0;
        foreach ($tokens as $tok) {
            if (mb_strpos($hay, $tok) !== false) $score += 2;
        }
        if (mb_strpos($hay, 'alfassa') !== false) $score += 1;
        if ($score > 0) $scores[$i] = $score;
    }

    arsort($scores);
    $hits = [];
    foreach (array_slice(array_keys($scores), 0, $limit) as $i) {
        $d = $docs[$i];
        $hits[] = [
            'id'      => $d['id'] ?? ('doc-'.$i),
            'title'   => $d['title'] ?? 'Alfassa',
            'section' => $d['section'] ?? '',
            'snippet' => self::str_snippet($d['text'] ?? '', 450),
            'score'   => $scores[$i],
        ];
    }
    return $hits;
}


// --- Helper: crea uno snippet pulito e sicuro (UTF-8)
private static function str_snippet($text, $max = 450) {
    if (is_array($text) || is_object($text)) {
        $text = wp_json_encode($text, JSON_UNESCAPED_UNICODE);
    }
    $text = wp_strip_all_tags((string)$text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    if (mb_strlen($text, 'UTF-8') <= $max) return $text;

    $cut = mb_substr($text, 0, $max, 'UTF-8');
    $cut = preg_replace('/[^\p{L}\p{N}]+$/u', '', $cut); // non troncare su simboli
    return rtrim($cut).'…';
}

private static function generate_response_from_knowledge(string $query, array $hits): array {
    // Ordina per score desc (per sicurezza)
    usort($hits, function($a, $b){
        return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });

    $best = $hits[0] ?? null;

    $content = "";
    if ($best) {
        $content .= "### Risposta sintetica\n";
        $content .= self::str_snippet(($best['snippet'] ?? ''), 600) . "\n\n";
    }

    $content .= "### Estratti dal whitepaper Alfassa\n";
    foreach ($hits as $h) {
        $line = "• " . (!empty($h['title']) ? $h['title'] : 'Documento');
        if (!empty($h['section'])) $line .= " — " . $h['section'];
        $line .= " — " . ($h['snippet'] ?? '');
        $content .= self::str_snippet($line, 700) . "\n";
    }

    // Allego "fonti" fittizie per il frontend (dominio dedicato)
    $attachments = [
        'web_sources' => array_map(function($h){
            return [
                'title'  => ($h['title'] ?: 'Alfassa whitepaper'),
                'url'    => '#', // knowledge locale, nessun URL pubblico
                'domain' => 'alfassa-knowledge'
            ];
        }, $hits),
        'images' => []
    ];

    return [
        'content'     => $content,
        'provider'    => 'alfassa_knowledge',
        'model'       => 'retrieval',
        'attachments' => $attachments
    ];
}


// Assicurati che anche questa funzione di supporto sia presente e corretta
private static function send_stream_event($type, $data) {
    echo "event: $type\n";
    echo "data: " . json_encode($data) . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

// Funzione helper per terminare lo stream con un errore
private static function send_final_error($message) {
    $is_sse = isset($_GET['stream']);
    if ($is_sse) {
        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
        }
        self::send_stream_event('error', ['message' => $message]);
        exit;
    } else {
        wp_send_json_error(['message' => $message]);
    }
}
/**
 * Invia la risposta (con SSE) e salva su DB.
 * Supporta il campo opzionale 'format' (es. 'markdown') per istruire il frontend su come renderizzare.
 */
private static function send_and_save_response($user_message, $response, $is_sse = false) {
    // Normalizza
    $content     = is_array($response) && isset($response['content']) ? (string)$response['content'] : (string)$response;
    $attachments = is_array($response) && isset($response['attachments']) ? $response['attachments'] : [];
    $provider    = is_array($response) && isset($response['provider'])    ? (string)$response['provider'] : 'alfassa_db';
    $model       = is_array($response) && isset($response['model'])       ? (string)$response['model']    : 'retrieval';
    $format      = is_array($response) && isset($response['format'])      ? (string)$response['format']   : 'plain';

    // 1) Comunica metadati (formato) PRIMA dei chunk
    self::send_stream_event('meta', ['format' => $format]);

    // 2) Chunk stream
    self::send_stream_event('response_chunk', ['content' => $content]);

    // 3) Evento 'response' con il testo completo (fallback)
    self::send_stream_event('response', ['content' => $content]);

    // 4) Salvataggio conversazione
    $conversation_id = absint($_REQUEST['conversation_id'] ?? 0);
    if ($conversation_id === 0 && !empty($user_message)) {
        $conversation_id = AlfaAI_Database::save_conversation(get_current_user_id(), substr($user_message, 0, 50));
    }
    if ($conversation_id > 0) {
        AlfaAI_Database::save_message($conversation_id, 'user', $user_message);
        AlfaAI_Database::save_message(
            $conversation_id,
            'assistant',
            $content,
            wp_json_encode($attachments ?: []),
            $provider,
            $model
        );
    }

    // 5) Fine stream (allego attachments e format)
    self::send_stream_event('done', [
        'attachments' => $attachments ?: new \stdClass(),
        'format'      => $format,
        'message'     => 'Stream completed successfully'
    ]);
    exit;
}





  private static function search_alfassa_databases($query) {
    $external_dbs = AlfaAI_Database::get_external_databases();
    if (empty($external_dbs)) { return []; }

    $results = [];

    foreach ($external_dbs as $dbcfg) {
        $db_config = (object) $dbcfg;

        try {
            $connection = @new mysqli($db_config->host, $db_config->username, $db_config->password, $db_config->database, $db_config->port);
            if ($connection->connect_error) {
                error_log('[AlfaAI] DB Connection Error: ' . $connection->connect_error);
                continue;
            }
            $connection->set_charset('utf8mb4');

            // Lista tabelle
            $tables_result = $connection->query("SHOW TABLES");
            if (!$tables_result) { $connection->close(); continue; }

            while ($table_row = $tables_result->fetch_array()) {
                $table_name = $table_row[0];

                // Skip tabelle chiaramente di log o opzioni se vuoi (riduce rumore)
                if (preg_match('/(_logs?|_options|_sessions?|_cache)$/i', $table_name)) {
                    continue;
                }

                $columns_result = $connection->query("DESCRIBE `$table_name`");
                if (!$columns_result) { continue; }

                $text_columns = [];
                while ($column = $columns_result->fetch_assoc()) {
                    $type_lower = strtolower($column['Type']);
                    if (strpos($type_lower, 'char') !== false || strpos($type_lower, 'text') !== false || strpos($type_lower, 'json') !== false) {
                        $text_columns[] = $column['Field'];
                    }
                }
                $columns_result->free();

                if (!empty($text_columns)) {
                    $where = [];
                    foreach ($text_columns as $col) {
                        $where[] = "`$col` LIKE '%" . $connection->real_escape_string($query) . "%'";
                    }
                    $search_sql = "SELECT * FROM `$table_name` WHERE " . implode(' OR ', $where) . " LIMIT 5";
                    $search_result = $connection->query($search_sql);

                    if ($search_result && $search_result->num_rows > 0) {
                        while ($row = $search_result->fetch_assoc()) {
                            // Decodifica JSON (Elementor & co.)
                            foreach($row as $key => $value) {
                                $decoded = json_decode($value, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $row[$key] = $decoded;
                                }
                            }
                            $results[] = [
                                'database' => ($db_config->name ?? ($db_config->host . '|' . $db_config->database)),
                                'table'    => $table_name,
                                'data'     => $row,
                                'source'   => ($db_config->name ?? 'external'),
                            ];
                        }
                        $search_result->free();
                    }
                }
            }
            $tables_result->free();
            $connection->close();
        } catch (Exception $e) {
            error_log('[AlfaAI] DB Search Exception: ' . $e->getMessage());
            continue;
        }
    }

    return $results;
}

    
   private static function generate_response_from_alfassa($query, $results) {
    $site_map    = self::get_site_url_map();
    $links       = [];
    $attachments = ['web_sources' => [], 'images' => []];

    foreach ($results as $r) {
        $source = $r['source'] ?? 'external';
        $table  = $r['table']  ?? 'table';
        $row    = (array)($r['data'] ?? []);

        // TITOLO
        $title = '';
        foreach (['post_title','title','name','headline'] as $k) {
            if (!empty($row[$k])) { $title = self::clean_text($row[$k]); break; }
        }
        if ($title === '') $title = ucfirst(str_replace('_',' ', $table));

        // SNIPPET
        $snippet = '';
        foreach (['excerpt','description','content','bio','post_excerpt'] as $k) {
            if (!empty($row[$k])) { $snippet = self::str_snippet($row[$k], 180); break; }
        }
        if ($snippet === '' && !empty($row['post_content'])) {
            $snippet = self::str_snippet($row['post_content'], 180);
        }

        // URL
        $url = self::build_article_url($row, $table, $source, $site_map);
        if (!$url) continue;

        // IMMAGINE (best effort)
        foreach (['image_url','thumbnail','thumb','featured_image'] as $ik) {
            if (!empty($row[$ik]) && filter_var($row[$ik], FILTER_VALIDATE_URL)) {
                $attachments['images'][] = $row[$ik];
                break;
            }
        }

        $links[] = [
            'title'   => $title,
            'url'     => $url,
            'snippet' => $snippet,
            'source'  => $source,
        ];

        // Allegati con etichetta leggibile
        $label = self::build_source_label($title, $url);
        $attachments['web_sources'][] = [
            'title'  => $label,
            'url'    => $url,
            'domain' => $label,
        ];
    }

    if (empty($links)) {
        return [
            'content'     => "📚 Risultati dagli articoli ALFASSA\nNessun contenuto collegabile trovato. Riprova con un termine più specifico (es. \"AI Alfassa\", \"identity\", \"sustainable platforms\").",
            'provider'    => 'alfassa_db',
            'model'       => 'retrieval',
            'attachments' => ['web_sources' => [] , 'images' => []]
        ];
    }

    // Ordina dando priorità ai domini ufficiali
    usort($links, function($a,$b){
        $prio = function($u){
            $h = parse_url($u, PHP_URL_HOST) ?: '';
            if (stripos($h, 'alfassa.org') !== false) return 1;
            if (stripos($h, 'alfassa.net') !== false) return 2;
            return 3;
        };
        return $prio($a['url']) <=> $prio($b['url']);
    });

    // Testo finale formattato (no markdown)
    $content = "📚 Risultati dagli articoli ALFASSA\n\n";
    $i = 1;
    foreach ($links as $lnk) {
        $content .= self::format_article_block($i++, $lnk['title'], $lnk['url'], '', $lnk['snippet']);
    }

    return [
        'content'     => $content,
        'provider'    => 'alfassa_db',
        'model'       => 'retrieval',
        'attachments' => $attachments
    ];
}



    
    private static function needs_web_search($message) {
        $web_keywords = array('news', 'notizie', 'oggi', 'current', 'latest', 'recent', 'what happened', 'cosa è successo');
        $message_lower = strtolower($message);
        
        foreach ($web_keywords as $keyword) {
            if (strpos($message_lower, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private static function perform_web_search($query) {
    $api_key = get_option('alfaai_pro_google_key') ?: (defined('ALFAAI_GOOGLE_KEY') ? ALFAAI_GOOGLE_KEY : '');
    $cx      = get_option('alfaai_pro_google_cx')  ?: (defined('ALFAAI_GOOGLE_CX')   ? ALFAAI_GOOGLE_CX   : '');

    if (empty($api_key) || empty($cx)) {
        // Senza CSE non possiamo usare Google — restituiamo vuoto (oppure potresti tenere Brave come fallback)
        return [];
    }

    $url = add_query_arg([
        'q'   => $query,
        'key' => $api_key,
        'cx'  => $cx,
        'num' => 5,
        'gl'  => 'it',
        'hl'  => 'it',
    ], 'https://www.googleapis.com/customsearch/v1');

    $response = wp_remote_get($url, [
        'headers' => ['Accept' => 'application/json'],
        'timeout' => 12
    ]);

    if (is_wp_error($response)) return [];

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || empty($data['items'])) return [];

    $results = [];
    foreach (array_slice($data['items'], 0, 5) as $it) {
        $results[] = [
            'title'       => isset($it['title']) ? $it['title'] : '',
            'url'         => isset($it['link'])  ? $it['link']  : '',
            'description' => isset($it['snippet']) ? $it['snippet'] : '',
        ];
    }
    return $results;
}

    // Trova il miglior profilo LinkedIn per una PERSONA usando Brave (site:linkedin.com/in ...)
// Trova il profilo LinkedIn migliore via Google CSE (site:linkedin.com/in ...)
private static function perform_linkedin_lookup($query) {
    $api_key = get_option('alfaai_pro_google_key') ?: (defined('ALFAAI_GOOGLE_KEY') ? ALFAAI_GOOGLE_KEY : '');
    $cx      = get_option('alfaai_pro_google_cx')  ?: (defined('ALFAAI_GOOGLE_CX')   ? ALFAAI_GOOGLE_CX   : '');
    if (empty($api_key) || empty($cx)) return null;

    // Pulisci "chi è / who is" e punteggiatura finale
    $clean = trim(preg_replace('/^(chi\\s?è|who\\s?is)\\s*/i', '', $query));
    $clean = trim($clean, " ?.!;:");
    $q     = 'site:linkedin.com/in ' . $clean;

    $url = add_query_arg([
        'q'   => $q,
        'key' => $api_key,
        'cx'  => $cx,
        'num' => 3,
        'gl'  => 'it',
        'hl'  => 'it',
    ], 'https://www.googleapis.com/customsearch/v1');

    $resp = wp_remote_get($url, ['timeout' => 10]);
    if (is_wp_error($resp)) return null;

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($data['items'])) return null;

    foreach ($data['items'] as $it) {
        if (empty($it['link'])) continue;
        if (preg_match('#https?://(www\\.)?linkedin\\.com/(in|pub)/#i', $it['link'])) {
            return [
                'title'  => !empty($it['title']) ? $it['title'] : 'LinkedIn',
                'url'    => esc_url_raw($it['link']),
                'domain' => 'www.linkedin.com',
            ];
        }
    }
    return null;
}


    // Ricerca immagini con catena di fallback:
// 1) Google Knowledge Graph (persone) -> immagini ufficiali/affidabili
// 2) Brave Images
// 3) Meta image (og:image, twitter:image) dai primi risultati web
// 4) Wikipedia thumbnail
// Immagini (Google first): 1) Google Knowledge Graph (persone) 2) Google CSE Immagini 3) Meta image 4) Wikipedia
// Immagini (Google-first) con filtro volto e anti-false-positive (Francesca ecc.)
// Immagini (persona: face, non-persona: foto generiche) + filtri anti-falsi-positivi
private static function perform_image_search($query, $web_results = []) {
    $out      = [];
    $isPerson = self::is_person_query($query);

    // Se è persona: prova LinkedIn e Knowledge Graph prima
    if ($isPerson) {
        $li = self::perform_linkedin_lookup($query);
        if ($li && !empty($li['url'])) {
            $liImg = self::fetch_meta_image($li['url']);
            if ($liImg) $out[] = $liImg;
        }
        if (count($out) < 3) {
            $kg = self::google_kg_images($query);
            if (!empty($kg)) $out = array_merge($out, $kg);
        }
    }

    // Google CSE immagini
    if (count($out) < 3) {
        $api_key = get_option('alfaai_pro_google_key') ?: (defined('ALFAAI_GOOGLE_KEY') ? ALFAAI_GOOGLE_KEY : '');
        $cx      = get_option('alfaai_pro_google_cx')  ?: (defined('ALFAAI_GOOGLE_CX')   ? ALFAAI_GOOGLE_CX   : '');
        if (!empty($api_key) && !empty($cx)) {
            $args = [
                'q'          => $query,
                'key'        => $api_key,
                'cx'         => $cx,
                'num'        => 10,
                'gl'         => 'it',
                'hl'         => 'it',
                'safe'       => 'active',
                'searchType' => 'image',
                'imgSize'    => 'medium',
            ];
            // SOLO per persone cerchiamo volti
            if ($isPerson) $args['imgType'] = 'face';

            // exactTerms: se è persona, forza "nome cognome"
            if ($isPerson) {
                $tokens = self::extract_person_name_tokens($query); // già presente più sotto
                if (!empty($tokens)) $args['exactTerms'] = implode(' ', $tokens);
            }

            $url  = add_query_arg($args, 'https://www.googleapis.com/customsearch/v1');
            $resp = wp_remote_get($url, ['timeout' => 12]);
            if (!is_wp_error($resp)) {
                $data = json_decode(wp_remote_retrieve_body($resp), true);
                if (!empty($data['items'])) {
                    // blacklist di nomi ricorrenti per il cognome Diotallevi (evita Francesca/Maurizio ecc.)
                    $banned = $isPerson ? ['francesca','maurizio','giovanni','pierluigi','marco'] : [];

                    foreach ($data['items'] as $it) {
                        if (empty($it['link'])) continue;

                        $hay = mb_strtolower(
                            ($it['title'] ?? '') . ' ' .
                            ($it['image']['contextLink'] ?? '') . ' ' .
                            ($it['displayLink'] ?? ''),
                            'UTF-8'
                        );

                        // per persona: richiedi che compaiano i token nome/cognome
                        if ($isPerson) {
                            $toks = self::extract_person_name_tokens($query);
                            if (!empty($toks) && !self::contains_all_tokens($hay, $toks)) continue;
                            if (self::contains_any_token($hay, $banned)) continue;
                        }

                        $out[] = esc_url_raw($it['link']);
                        if (count($out) >= 6) break;
                    }
                }
            }
        }
    }

    // Meta image dalle fonti (sempre utile)
    if (count($out) < 3 && !empty($web_results)) {
        foreach (array_slice($web_results, 0, 6) as $r) {
            if (empty($r['url'])) continue;
            $meta = self::fetch_meta_image($r['url']);
            if ($meta) {
                $out[] = $meta;
                if (count($out) >= 6) break;
            }
        }
    }

    // Wikipedia fallback
    if (count($out) < 3) {
        $wiki = self::wikipedia_thumb($query);
        if ($wiki) $out[] = $wiki;
    }

    $out = array_values(array_unique(array_filter($out)));
    return array_slice($out, 0, 3);
}

private static function extract_person_name_tokens($q) {
    // rimuovi "chi è / who is" e punteggiatura finale
    $q = preg_replace('/^(chi\\s?è|who\\s?is)\\s*/i', '', trim($q));
    $q = trim($q, " ?.!,;:\"'");

    $parts  = preg_split('/\\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
    $tokens = [];
    foreach ($parts as $p) {
        $pl = mb_strtolower($p, 'UTF-8');
        // salta preposizioni tipiche nei cognomi composti (de, di, da, del, della, van, von)
        if (in_array($pl, ['de','di','da','del','della','dalla','van','von'], true)) continue;
        $pl = trim($pl, "’'\"");
        if ($pl !== '') $tokens[] = $pl;
        if (count($tokens) >= 2) break; // ci bastano nome + cognome
    }
    return $tokens;
}

private static function contains_all_tokens($text, $tokens) {
    foreach ($tokens as $t) {
        if (mb_strpos($text, $t) === false) return false;
    }
    return true;
}

private static function contains_any_token($text, $tokens) {
    foreach ($tokens as $t) {
        if (mb_strpos($text, $t) !== false) return true;
    }
    return false;
}
// Restituisce immagini da Google Knowledge Graph per entità (persone).
// Richiede una Google API Key valida (impostata in "Google Services")
// e l'API "Knowledge Graph Search API" abilitata sul tuo progetto.
private static function google_kg_images($query) {
    $api_key = get_option('alfaai_pro_google_key') ?: (defined('ALFAAI_GOOGLE_KEY') ? ALFAAI_GOOGLE_KEY : '');
    if (empty($api_key)) return [];

    $url = add_query_arg([
        'query'     => $query,
        'key'       => $api_key,
        'limit'     => 5,
        'languages' => 'it',
    ], 'https://kgsearch.googleapis.com/v1/entities:search');

    $resp = wp_remote_get($url, ['timeout' => 10]);
    if (is_wp_error($resp)) return [];

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($data) || empty($data['itemListElement'])) return [];

    $out = [];
    foreach ($data['itemListElement'] as $elt) {
        if (!empty($elt['result']['image']['contentUrl'])) {
            $out[] = esc_url_raw($elt['result']['image']['contentUrl']);
        }
        // a volte è in "result.detailedDescription.url" → possiamo tentare meta image
        if (!empty($elt['result']['detailedDescription']['url'])) {
            $meta = self::fetch_meta_image($elt['result']['detailedDescription']['url']);
            if ($meta) $out[] = $meta;
        }
    }
    return array_values(array_unique($out));
}
// Heuristics avanzate per capire se la query è su una PERSONA
// Heuristics robuste: persona solo quando è davvero probabile
private static function is_person_query($q) {
    $q  = trim($q);
    $ql = mb_strtolower($q, 'UTF-8');

    // 0) parole-chiave per persone (IT/EN)
    $person_keywords = [
        'chi è','chi e','who is','biografia','bio','età','eta','nato','nata','nascita','born','age',
        'linkedin','profilo linkedin','cv','curriculum','dove lavora','moglie','marito','figlio','figlia'
    ];
    foreach ($person_keywords as $kw) {
        if (mb_strpos($ql, $kw) !== false) return true;
    }

    // 1) onorificenze/professioni tipiche di persone
    if (preg_match('/\b(dott\.?|dr\.?|prof\.?|ing\.?|avv\.?|arch\.?|mr\.?|mrs\.?|ms\.?|sir|san|santa|papa|mons\.)\b/u', $ql)) {
        return true;
    }

    // 2) se compare un profilo LinkedIn personale
    if (preg_match('#linkedin\.com/(in|pub)/#i', $q)) return true;

    // 3) esclusioni forti per luoghi/strutture/aziende/prodotti
    $place_words = [
        'arena','stadio','teatro','duomo','basilica','chiesa','museo','parco','monumento','castello',
        'lago','mare','fiume','montagna','valle','isola','spiaggia','piazza','via','viale','corso',
        'città','citta','comune','provincia','regione','stato','paese','nazione',
        'hotel','ristorante','pizzeria','trattoria','bar','caffè','cafe','negozio','centro commerciale',
        'azienda','società','societa','spa','srl','s.p.a','brand','modello','prodotto'
    ];
    foreach ($place_words as $pw) {
        if (mb_strpos($ql, $pw) !== false) return false;
    }

    // 4) pattern "Nome Cognome [Nome2 Cognome2]"
    if (self::looks_like_person_name($q)) return true;

    // 5) fallback molto prudente: 2 parole con iniziale maiuscola, ma
    // rifiuta se contiene parole da place_words
    $tokens = self::extract_person_name_tokens($q);
    if (count($tokens) === 2) {
        foreach ($place_words as $pw) {
            if (mb_strpos($ql, $pw) !== false) return false;
        }
        return true;
    }

    return false;
}







// Riconosce “Nome Cognome [Nome2 Cognome2]” (supporta preposizioni e apostrofi)
private static function looks_like_person_name($q) {
    $q = trim(preg_replace('/[?.!]+$/u', '', $q)); // togli punteggiatura finale

    // Consenti cognomi composti: De Angelis, D'Amico, Di Caprio, Van Gogh, etc.
    $namePart = '(?:\p{Lu}\p{Ll}+|d\'\p{Lu}\p{Ll}+|D\'\p{Lu}\p{Ll}+|(?:de|di|da|del|della|dalla|van|von)\s+\p{Lu}\p{Ll}+)';
    $pattern  = '/^\s*' . $namePart . '(?:\s+' . $namePart . '){1,3}\s*$/u';

    if (preg_match($pattern, $q)) {
        // Limita a max 6 parole per evitare frasi complete
        $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        return count($words) >= 2 && count($words) <= 6;
    }
    return false;
}



// Estrae la prima immagine "meta" da una pagina: og:image / twitter:image / <link rel="image_src"> / primo <img>
private static function fetch_meta_image($url) {
    $resp = wp_remote_get($url, [
        'headers' => ['Accept' => 'text/html'],
        'timeout' => 8,
        'redirection' => 3,
        'user-agent' => 'Mozilla/5.0 AlfaAI Bot'
    ]);
    if (is_wp_error($resp)) return null;

    $body = wp_remote_retrieve_body($resp);
    if (!$body) return null;

    // tieni solo i primi ~200KB per cercare i meta (veloce)
    $body = substr($body, 0, 200000);

    $candidates = [];

    // og:image
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $m)) {
        $candidates[] = trim($m[1]);
    }
    // twitter:image
    if (preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $m)) {
        $candidates[] = trim($m[1]);
    }
    // link rel image_src
    if (preg_match('/<link[^>]+rel=["\']image_src["\'][^>]+href=["\']([^"\']+)["\']/i', $body, $m)) {
        $candidates[] = trim($m[1]);
    }
    // primo <img src="...">
    if (empty($candidates) && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $body, $m)) {
        $candidates[] = trim($m[1]);
    }

    foreach ($candidates as $img) {
        $abs = self::absolutize_url($url, $img);
        if ($abs) return $abs;
    }
    return null;
}

// Converte eventuali URL relativi in assoluti, basandosi sulla pagina sorgente
private static function absolutize_url($base, $maybeRelative) {
    if (!$maybeRelative) return null;
    // già assoluto
    if (preg_match('#^https?://#i', $maybeRelative)) return $maybeRelative;
    if (strpos($maybeRelative, '//') === 0) {
        // //cdn.example.com/.. -> https:
        return 'https:' . $maybeRelative;
    }

    $p = wp_parse_url($base);
    if (empty($p['scheme']) || empty($p['host'])) return null;
    $scheme = $p['scheme'];
    $host   = $p['host'];

    if (strpos($maybeRelative, '/') === 0) {
        // percorso assoluto sul dominio
        return $scheme . '://' . $host . $maybeRelative;
    }

    // relativo rispetto al path
    $path = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
    return $scheme . '://' . $host . $path . $maybeRelative;
}

// Wikipedia fallback (prima IT, poi EN) -> thumbnail ~400px
private static function wikipedia_thumb($query) {
    $langs = ['it', 'en'];
    foreach ($langs as $lang) {
        $search = wp_remote_get("https://{$lang}.wikipedia.org/w/api.php?action=opensearch&search=" . urlencode($query) . "&limit=1&namespace=0&format=json", ['timeout' => 8]);
        if (is_wp_error($search)) continue;
        $arr = json_decode(wp_remote_retrieve_body($search), true);
        if (!is_array($arr) || empty($arr[1][0])) continue;
        $title = $arr[1][0];

        $page = wp_remote_get("https://{$lang}.wikipedia.org/w/api.php?action=query&prop=pageimages&format=json&pithumbsize=400&redirects=1&titles=" . urlencode($title), ['timeout' => 8]);
        if (is_wp_error($page)) continue;
        $pjson = json_decode(wp_remote_retrieve_body($page), true);
        if (!empty($pjson['query']['pages']) && is_array($pjson['query']['pages'])) {
            foreach ($pjson['query']['pages'] as $pg) {
                if (!empty($pg['thumbnail']['source'])) {
                    return esc_url_raw($pg['thumbnail']['source']);
                }
            }
        }
    }
    return null;
}




    
    private static function route_to_model($message, $provider_preference) {
        $model_mode = get_option('alfaai_pro_model_mode', 'auto');
        
        if ($provider_preference !== 'auto') {
            return $provider_preference;
        }
        
        if ($model_mode !== 'auto') {
            return $model_mode;
        }
        
        // Auto routing logic
        $message_lower = strtolower($message);
        
        // Coding/debugging -> DeepSeek
        if (preg_match('/\b(code|coding|debug|programming|function|class|error|bug|fix)\b/', $message_lower)) {
            return 'deepseek';
        }
        
        // Long documents/vision -> Gemini
        if (strlen($message) > 1000 || preg_match('/\b(analyze|document|image|vision|long|detailed)\b/', $message_lower)) {
            return 'gemini';
        }
        
        // Default -> OpenAI
        return 'openai';
    }
    
   private static function generate_ai_response($message, $provider, $web_results = array()) {
    $context = $message;

    if (!empty($web_results)) {
        $context .= "\n\nInformazioni web recenti:\n";
        foreach ($web_results as $result) {
            $context .= "- " . $result['title'] . ": " . $result['description'] . "\n";
        }
    }

    switch ($provider) {
        case 'openai':
            return self::call_openai($context, $web_results);
        case 'gemini':
            return self::call_gemini($context, $web_results);
        case 'deepseek':
            return self::call_deepseek($context, $web_results);
        default:
            // Fallback a OpenAI se il provider non è valido
            return self::call_openai($context, $web_results);
    }
}
  /**
 * Chiama OpenAI Chat Completions in streaming.
 * Forza l'output in Markdown pulito, senza emoji/icone.
 */
private static function call_openai($message, $attachments = null) {
    $api_key = get_option('alfaai_pro_openai_key', '') ?: (defined('ALFAAI_OPENAI_KEY') ? ALFAAI_OPENAI_KEY : '');
    if (empty($api_key)) {
        self::send_stream_event('response', ['content' => 'Errore: Chiave API OpenAI non configurata.']);
        self::send_stream_event('done', ['attachments' => new \stdClass(), 'format' => 'plain', 'message' => 'Stream completed with error']);
        return;
    }

    $model = 'gpt-3.5-turbo';
    $full_response_content = '';

    $system = implode("\n", [
        "Sei **AlfaAI**, assistente professionale del Team IT di ALFASSA.",
        "Rispondi **in italiano** e in **Markdown pulito**: usa titoli (##), elenchi numerati (1., 2., …), paragrafi brevi e link in formato [testo](url).",
        "Evita assolutamente emoji o icone.",
        "Non menzionare database o note interne; scrivi in prosa naturale e professionale."
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $message]
        ],
        'temperature' => 0.6,
        'stream' => true,
    ]));

    // Comunica formato al frontend
    self::send_stream_event('meta', ['format' => 'markdown']);

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$full_response_content) {
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'data:') !== 0) { continue; }
            $json_data = trim(substr($line, 5));
            if ($json_data === '' || $json_data === '[DONE]') { continue; }
            $chunk = json_decode($json_data, true);
            if (isset($chunk['choices'][0]['delta']['content'])) {
                $piece = (string)$chunk['choices'][0]['delta']['content'];
                $full_response_content .= $piece;
                self::send_stream_event('response_chunk', ['content' => $piece]);
            }
        }
        return strlen($data);
    });

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    // 'response' finale
    if ($full_response_content === '') {
        $fallback = $err ? ('Errore generazione risposta: ' . $err) : 'Non sono riuscito a generare una risposta in questo momento.';
        self::send_stream_event('response', ['content' => $fallback]);
    } else {
        self::send_stream_event('response', ['content' => $full_response_content]);
    }

    // Salvataggio conversazione
    $conversation_id = absint($_REQUEST['conversation_id'] ?? 0);
    if ($conversation_id === 0 && !empty($message)) {
        $conversation_id = AlfaAI_Database::save_conversation(get_current_user_id(), substr($message, 0, 50));
    }
    if ($conversation_id > 0) {
        AlfaAI_Database::save_message($conversation_id, 'user', $message);
        AlfaAI_Database::save_message(
            $conversation_id,
            'assistant',
            $full_response_content ?: '',
            wp_json_encode($attachments ?: []),
            'openai',
            $model
        );
    }

    // Evento finale 'done'
    self::send_stream_event('done', [
        'attachments' => $attachments ?: new \stdClass(),
        'format'      => 'markdown',
        'message'     => 'Stream completed successfully'
    ]);
}



    
   private static function call_gemini($message, $web_results = array()) {
    $api_key = get_option('alfaai_pro_gemini_key', '');
    if (empty($api_key)) {
        return array(
            'content' => 'Errore: La chiave API per Gemini non è configurata nel pannello di amministrazione.',
            'provider' => 'system_error',
            'model' => 'none', // <-- Aggiungi questa riga
            'attachments' => array()
        );
    }
    
        
        $response = wp_remote_post('https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=' . $api_key, array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'contents' => array(
                    array(
                        'parts' => array(
                            array('text' => $message)
                        )
                    )
                )
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'content' => 'Errore di connessione Gemini: ' . $response->get_error_message(),
                'provider' => 'error',
                'model' => 'none',
                'attachments' => array()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $content = $data['candidates'][0]['content']['parts'][0]['text'];
            
            // Add citations if web results were used
            if (!empty($web_results)) {
                $content .= "\n\n**Fonti web:**\n";
                foreach ($web_results as $i => $result) {
                    $content .= ($i + 1) . ". [" . $result['title'] . "](" . $result['url'] . ")\n";
                }
            }
            
            return array(
                'content' => $content,
                'provider' => 'gemini',
                'model' => 'gemini-pro',
                'attachments' => array(
                    'web_sources' => $web_results
                )
            );
        }
        
        return array(
            'content' => 'Errore nella risposta Gemini',
            'provider' => 'error',
            'model' => 'none',
            'attachments' => array()
        );
    }
    
    private static function call_deepseek($message, $web_results = array()) {
    $api_key = get_option('alfaai_pro_deepseek_key', '');
    if (empty($api_key)) {
        return array(
            'content' => 'Errore: La chiave API per DeepSeek non è configurata nel pannello di amministrazione.',
            'provider' => 'system_error',
            'model' => 'none', // <-- Aggiungi questa riga
            'attachments' => array()
        );
    }
        
        $response = wp_remote_post('https://api.deepseek.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => 'deepseek-chat',
              'messages' => array(
    array('role' => 'system', 'content' => 'Sei AlfaAI, un assistente AI professionale creato dal Team IT di Alfassa. Rispondi in modo cortese, professionale e dettagliato in lingua italiana. Non dire mai che sei un modello linguistico di OpenAI.'),
    array('role' => 'user', 'content' => $message)
),
                'max_tokens' => 2000,
                'temperature' => 0.7
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'content' => 'Errore di connessione DeepSeek: ' . $response->get_error_message(),
                'provider' => 'error',
                'model' => 'none',
                'attachments' => array()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
            
            // Add citations if web results were used
            if (!empty($web_results)) {
                $content .= "\n\n**Fonti web:**\n";
                foreach ($web_results as $i => $result) {
                    $content .= ($i + 1) . ". [" . $result['title'] . "](" . $result['url'] . ")\n";
                }
            }
            
            return array(
                'content' => $content,
                'provider' => 'deepseek',
                'model' => 'deepseek-chat',
                'attachments' => array(
                    'web_sources' => $web_results
                )
            );
        }
        
        return array(
            'content' => 'Errore nella risposta DeepSeek',
            'provider' => 'error',
            'model' => 'none',
            'attachments' => array()
        );
    }
    
    // Image generation
    public static function handle_generate_image() {
        if (!wp_verify_nonce($_POST['nonce'], 'alfaai_frontend_nonce')) {
            wp_send_json_error(array('message' => 'Nonce verification failed'));
            return;
        }
        
        $prompt = sanitize_textarea_field($_POST['prompt']);
        $api_key = get_option('alfaai_pro_openai_key') ?: (defined('ALFAAI_OPENAI_KEY') ? ALFAAI_OPENAI_KEY : '');
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'Chiave API OpenAI non configurata'));
            return;
        }
        
        $response = wp_remote_post('https://api.openai.com/v1/images/generations', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => 'dall-e-3',
                'prompt' => $prompt,
                'n' => 1,
                'size' => '1024x1024'
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Errore di connessione: ' . $response->get_error_message()));
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['data'][0]['url'])) {
            wp_send_json_success(array(
                'image_url' => $data['data'][0]['url'],
                'prompt' => $prompt
            ));
        } else {
            wp_send_json_error(array('message' => 'Errore nella generazione dell\'immagine'));
        }
    }
    
    // Video generation
    public static function handle_generate_video() {
        if (!wp_verify_nonce($_POST['nonce'], 'alfaai_frontend_nonce')) {
            wp_send_json_error(array('message' => 'Nonce verification failed'));
            return;
        }
        
        $prompt = sanitize_textarea_field($_POST['prompt']);
        $google_key = get_option('alfaai_pro_google_key') ?: (defined('ALFAAI_GOOGLE_KEY') ? ALFAAI_GOOGLE_KEY : '');
        
        if (empty($google_key)) {
            // Mock response for demo
            $job_id = 'mock_' . uniqid();
            wp_send_json_success(array(
                'job_id' => $job_id,
                'status' => 'queued',
                'message' => 'Video job creato (mock). Abilita Google API per funzionalità reale.'
            ));
            return;
        }
        
        // TODO: Implement actual Google Video API call
        // For now, return mock response
        $job_id = 'google_' . uniqid();
        wp_send_json_success(array(
            'job_id' => $job_id,
            'status' => 'processing',
            'message' => 'Video job avviato con Google Video AI'
        ));
    }
    
    // Video status polling
    public static function handle_video_status() {
        if (!wp_verify_nonce($_POST['nonce'], 'alfaai_frontend_nonce')) {
            wp_send_json_error(array('message' => 'Nonce verification failed'));
            return;
        }
        
        $job_id = sanitize_text_field($_POST['job_id']);
        
        // Mock status for demo
        if (strpos($job_id, 'mock_') === 0) {
            wp_send_json_success(array(
                'status' => 'completed',
                'result_url' => 'https://example.com/mock-video.mp4',
                'message' => 'Video completato (mock)'
            ));
            return;
        }
        
        // TODO: Implement actual Google Video API status check
        wp_send_json_success(array(
            'status' => 'processing',
            'message' => 'Video in elaborazione...'
        ));
    }
    
    // REST API endpoints
    public static function rest_send_message($request) {
        // Similar to handle_send_message but for REST API
        return new WP_REST_Response(array('message' => 'REST endpoint not implemented yet'), 501);
    }
    
    public static function rest_generate_image($request) {
        return new WP_REST_Response(array('message' => 'REST endpoint not implemented yet'), 501);
    }
    
    public static function rest_generate_video($request) {
        return new WP_REST_Response(array('message' => 'REST endpoint not implemented yet'), 501);
    }
    
    public static function rest_video_status($request) {
        return new WP_REST_Response(array('message' => 'REST endpoint not implemented yet'), 501);
    }
    
    // Utility functions
    
    

    /**
     * Handle audio transcription
     */
    public static function transcribe_audio() {
        // Verify nonce
        if (!check_ajax_referer('alfaai_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Nonce verification failed'));
            return;
        }
        
        // Check if audio file was uploaded
        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => 'Nessun file audio ricevuto'));
            return;
        }
        
        $audio_file = $_FILES['audio'];
        $allowed_types = array('audio/webm', 'audio/ogg', 'audio/mp3', 'audio/wav');
        
        if (!in_array($audio_file['type'], $allowed_types)) {
            wp_send_json_error(array('message' => 'Formato audio non supportato'));
            return;
        }
        
        try {
            // Try OpenAI Whisper first
            $openai_key = get_option('alfaai_pro_openai_key', '');
            if (!empty($openai_key)) {
                $transcription = self::transcribe_with_openai($audio_file, $openai_key);
                if ($transcription) {
                    wp_send_json_success(array('text' => $transcription));
                    return;
                }
            }
            
            // Try Google Speech-to-Text as fallback
            $google_key = get_option('alfaai_pro_google_key', '');
            if (!empty($google_key)) {
                $transcription = self::transcribe_with_google($audio_file, $google_key);
                if ($transcription) {
                    wp_send_json_success(array('text' => $transcription));
                    return;
                }
            }
            
            wp_send_json_error(array('message' => 'Speech-to-text non configurato. Configura le chiavi API nelle impostazioni.'));
            
        } catch (Exception $e) {
            error_log('[AlfaAI] Transcription error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Errore nella trascrizione audio'));
        }
    }
    
    /**
     * Transcribe audio using OpenAI Whisper
     */
    private static function transcribe_with_openai($audio_file, $api_key) {
        $url = 'https://api.openai.com/v1/audio/transcriptions';
        
        $curl_file = new CURLFile($audio_file['tmp_name'], $audio_file['type'], $audio_file['name']);
        
        $data = array(
            'file' => $curl_file,
            'model' => 'whisper-1',
            'language' => 'it'
        );
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $api_key
        ));
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $result = json_decode($response, true);
            return isset($result['text']) ? trim($result['text']) : false;
        }
        
        return false;
    }
    
    /**
     * Transcribe audio using Google Speech-to-Text
     */
    private static function transcribe_with_google($audio_file, $api_key) {
        // This is a simplified implementation
        // In a real scenario, you would use Google Cloud Speech-to-Text API
        return false;
    }
}
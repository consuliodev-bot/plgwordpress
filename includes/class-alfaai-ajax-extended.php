<?php
if (!defined('ABSPATH')) exit;

class AlfaAI_Ajax_Extended {

    public static function init(){
        // Vision second answer
        add_action('wp_ajax_alfaai_get_vision',        [__CLASS__, 'handle_get_vision']);
        add_action('wp_ajax_nopriv_alfaai_get_vision', [__CLASS__, 'handle_get_vision']);
        // Optional explicit save (your API already saves via SSE)
        add_action('wp_ajax_alfaai_save_turn',         [__CLASS__, 'handle_save_turn']);
        add_action('wp_ajax_nopriv_alfaai_save_turn',  [__CLASS__, 'handle_save_turn']);
        add_action('wp_ajax_alfaai_analyze_image', [__CLASS__, 'handle_analyze_image']);
        add_action('wp_ajax_alfaai_google_speech', [__CLASS__, 'handle_google_speech']);
        add_action('wp_ajax_alfaai_google_tts', [__CLASS__, 'handle_google_tts']);
        // Google Cloud endpoints
        self::init_gcloud();
    }

    private static function nonce_ok(){
        if (check_ajax_referer('alfaai_frontend_nonce','nonce', false))   return true;
        if (check_ajax_referer('alfaai_frontend_nonce','security', false))return true;
        return false;
    }

    public static function handle_get_vision(){
        if (!self::nonce_ok()) wp_send_json_error(['message'=>'bad nonce'],403);
        $prompt = sanitize_text_field($_POST['prompt'] ?? '');
        if (!$prompt) wp_send_json_error(['message'=>'prompt missing'],400);

        $ocrText = sanitize_textarea_field($_POST['ocr'] ?? '');
        $opts = ['ocr' => $ocrText, 'deep' => !empty($_POST['deep'])];

        // Usa Response_Builder se presente
        if (class_exists('AlfaAI_Response_Builder')){
            $pair = AlfaAI_Response_Builder::dual($prompt, $opts);
            wp_send_json_success([
                'message'  => $pair['vision']['answer'],
                'format'   => 'markdown',
                'badges'   => $pair['vision']['badges'],
                'evidence' => $pair['vision']['evidence'],
            ]);
        }

        wp_send_json_success(['message'=>'', 'format'=>'markdown','badges'=>[],'evidence'=>[]]);
    }

    public static function handle_save_turn(){
        if (!self::nonce_ok()) wp_send_json_error(['message'=>'bad nonce'],403);
        $conv_id = isset($_POST['conv_id']) ? (int)$_POST['conv_id'] : 0;
        $u = get_current_user_id() ?: null;
        $user_msg     = wp_unslash($_POST['user'] ?? '');
        $assistant_std= wp_unslash($_POST['assistant_std'] ?? '');
        $assistant_vis= wp_unslash($_POST['assistant_vis'] ?? '');
        if (!$conv_id && class_exists('AlfaAI_Database')){
            $conv_id = AlfaAI_Database::save_conversation($u, wp_trim_words(wp_strip_all_tags($user_msg), 10, '…'));
        }
        if (class_exists('AlfaAI_Database')){
            if ($user_msg)      AlfaAI_Database::save_message($conv_id,'user',$user_msg,[],null,null);
            if ($assistant_std) AlfaAI_Database::save_message($conv_id,'assistant',$assistant_std,[],null,null);
            if ($assistant_vis) AlfaAI_Database::save_message($conv_id,'assistant',$assistant_vis,[],null,'vision');
        }
        wp_send_json_success(['conv_id'=>$conv_id]);
    }

    public static function init_gcloud(){
        add_action('wp_ajax_alfaai_gcloud_stt',       [__CLASS__, 'gcloud_stt']);
        add_action('wp_ajax_nopriv_alfaai_gcloud_stt',[__CLASS__, 'gcloud_stt']);
        add_action('wp_ajax_alfaai_gcloud_tts',       [__CLASS__, 'gcloud_tts']);
        add_action('wp_ajax_nopriv_alfaai_gcloud_tts',[__CLASS__, 'gcloud_tts']);
        add_action('wp_ajax_alfaai_gcloud_ocr',       [__CLASS__, 'gcloud_ocr']);
        add_action('wp_ajax_nopriv_alfaai_gcloud_ocr',[__CLASS__, 'gcloud_ocr']);
        add_action('wp_ajax_alfaai_gcloud_translate',       [__CLASS__, 'gcloud_translate']);
        add_action('wp_ajax_nopriv_alfaai_gcloud_translate',[__CLASS__, 'gcloud_translate']);
    }

    public static function gcloud_stt(){
        if (!self::nonce_ok()) wp_send_json_error(['message'=>'bad nonce'],403);
        if (!class_exists('AlfaAI_Google_Cloud')) wp_send_json_error(['message'=>'missing gcloud'],500);
        $audio = $_POST['audio'] ?? ''; $lang = sanitize_text_field($_POST['lang'] ?? 'it-IT');
        $resp = AlfaAI_Google_Cloud::stt_transcribe($audio,$lang);
        wp_send_json_success($resp);
    }
    public static function handle_analyze_image() {
    check_ajax_referer('alfaai_nonce', 'nonce');
    
    if (!wp_verify_nonce($_POST['nonce'], 'alfaai_nonce')) {
        wp_send_json_error(['message' => 'Verifica nonce fallita']);
        return;
    }
    
    if (!current_user_can('upload_files')) {
        wp_send_json_error(['message' => 'Permessi insufficienti']);
        return;
    }
    
    if (empty($_FILES['file'])) {
        wp_send_json_error(['message' => 'Nessun file fornito']);
        return;
    }
    
    $file = $_FILES['file'];
    
    // Verifica tipo file
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowed_types)) {
        wp_send_json_error(['message' => 'Tipo file non supportato']);
        return;
    }
    
    // Sposta il file temporaneo
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['path'] . '/' . sanitize_file_name($file['name']);
    
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        $image_data = file_get_contents($file_path);
        $base64 = base64_encode($image_data);
        $analysis = AlfaAI_Google_Cloud::vision_analyze($base64);
        if (isset($analysis['error'])) {
            wp_send_json_error(['message' => $analysis['error']]);
        } else {
            wp_send_json_success($analysis['json']['responses'][0] ?? []);
        }
        unlink($file_path);
    } else {
        wp_send_json_error(['message' => 'Upload file fallito']);
    }
}

public static function handle_google_speech() {
    check_ajax_referer('alfaai_nonce', 'nonce');
    
    if (!wp_verify_nonce($_POST['nonce'], 'alfaai_nonce')) {
        wp_send_json_error(['message' => 'Verifica nonce fallita']);
        return;
    }
    
    if (empty($_FILES['audio'])) {
        wp_send_json_error(['message' => 'Nessun file audio fornito']);
        return;
    }
    
    $file = $_FILES['audio'];
    $language = !empty($_POST['language']) ? sanitize_text_field($_POST['language']) : 'it-IT';
    
    // Sposta il file temporaneo
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['path'] . '/' . sanitize_file_name($file['name']);
    
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        $audio_data = file_get_contents($file_path);
        $base64 = base64_encode($audio_data);
        $resp = AlfaAI_Google_Cloud::stt_transcribe($base64, $language);
        if (isset($resp['error'])) {
            wp_send_json_error(['message' => $resp['error']]);
        } else {
            $text = $resp['json']['results'][0]['alternatives'][0]['transcript'] ?? '';
            wp_send_json_success(['text' => $text]);
        }
        unlink($file_path);
    } else {
        wp_send_json_error(['message' => 'Upload file audio fallito']);
    }
}

public static function handle_google_tts() {
    check_ajax_referer('alfaai_nonce', 'nonce');
    
    if (!wp_verify_nonce($_POST['nonce'], 'alfaai_nonce')) {
        wp_send_json_error(['message' => 'Verifica nonce fallita']);
        return;
    }
    
    $text = !empty($_POST['text']) ? sanitize_text_field($_POST['text']) : '';
    $language = !empty($_POST['language']) ? sanitize_text_field($_POST['language']) : 'it-IT';
    $voice = !empty($_POST['voice']) ? sanitize_text_field($_POST['voice']) : 'it-IT-Standard-A';
    
    if (empty($text)) {
        wp_send_json_error(['message' => 'Nessun testo fornito']);
        return;
    }
    
    $resp = AlfaAI_Google_Cloud::tts_speak($text, $language, $voice);
    if (isset($resp['error'])) {
        wp_send_json_error(['message' => $resp['error']]);
    } else {
        $audio_content = $resp['json']['audioContent'] ?? '';
        $audio = base64_decode($audio_content);
        header('Content-Type: audio/mpeg');
        header('Content-Disposition: inline; filename="tts.mp3"');
        header('Content-Length: ' . strlen($audio));
        echo $audio;
        exit;
    }
}
    public static function gcloud_tts(){
        if (!self::nonce_ok()) wp_send_json_error(['message'=>'bad nonce'],403);
        if (!class_exists('AlfaAI_Google_Cloud')) wp_send_json_error(['message'=>'missing gcloud'],500);
        $text = wp_unslash($_POST['text'] ?? ''); $lang = sanitize_text_field($_POST['lang'] ?? 'it-IT'); $voice = sanitize_text_field($_POST['voice'] ?? 'it-IT-Wavenet-D');
        $resp = AlfaAI_Google_Cloud::tts_speak($text,$lang,$voice);
        wp_send_json_success($resp);
    }
    public static function gcloud_ocr(){
        if (!self::nonce_ok()) wp_send_json_error(['message'=>'bad nonce'],403);
        if (!class_exists('AlfaAI_Google_Cloud')) wp_send_json_error(['message'=>'missing gcloud'],500);
        $img = $_POST['image'] ?? '';
        $resp = AlfaAI_Google_Cloud::vision_ocr($img);
        wp_send_json_success($resp);
    }
    public static function gcloud_translate(){
        if (!self::nonce_ok()) wp_send_json_error(['message'=>'bad nonce'],403);
        if (!class_exists('AlfaAI_Google_Cloud')) wp_send_json_error(['message'=>'missing gcloud'],500);
        $text = wp_unslash($_POST['text'] ?? ''); $tgt = sanitize_text_field($_POST['target'] ?? 'en');
        $resp = AlfaAI_Google_Cloud::translate($text,$tgt);
        wp_send_json_success($resp);
    }
}

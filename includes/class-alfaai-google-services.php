<?php
class AlfaAI_Google_Services {
    private static $instance = null;

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_filter('alfaai_pro_settings_fields', array($this, 'add_settings_fields'));
    }

    public function register_settings() {
        register_setting('alfaai_pro_settings', 'alfaai_pro_google_vision_key');
        register_setting('alfaai_pro_settings', 'alfaai_pro_google_speech_key');
        register_setting('alfaai_pro_settings', 'alfaai_pro_google_tts_key');
    }

    public function add_settings_fields($fields) {
        $google_fields = array(
            array(
                'name' => 'google_vision_key',
                'label' => 'Google Cloud Vision API Key',
                'type' => 'password',
                'default' => '',
                'description' => 'Chiave API per Google Vision (analisi immagini)'
            ),
            array(
                'name' => 'google_speech_key',
                'label' => 'Google Cloud Speech-to-Text API Key',
                'type' => 'password',
                'default' => '',
                'description' => 'Chiave API per riconoscimento vocale'
            ),
            array(
                'name' => 'google_tts_key',
                'label' => 'Google Cloud Text-to-Speech API Key',
                'type' => 'password',
                'default' => '',
                'description' => 'Chiave API per sintesi vocale'
            )
        );

        return array_merge($fields, $google_fields);
    }

    public function analyze_image($image_path) {
        $api_key = get_option('alfaai_pro_google_vision_key');
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', 'Google Vision API key non configurata');
        }

        $image_data = file_get_contents($image_path);
        $base64_image = base64_encode($image_data);

        $url = 'https://vision.googleapis.com/v1/images:annotate?key=' . urlencode($api_key);

        $data = array(
            'requests' => array(
                array(
                    'image' => array(
                        'content' => $base64_image
                    ),
                    'features' => array(
                        array(
                            'type' => 'LABEL_DETECTION',
                            'maxResults' => 10
                        ),
                        array(
                            'type' => 'TEXT_DETECTION',
                            'maxResults' => 10
                        ),
                        array(
                            'type' => 'SAFE_SEARCH_DETECTION',
                            'maxResults' => 10
                        )
                    )
                )
            )
        );

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            error_log('Google Vision API Error: ' . $response->get_error_message());
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (isset($result['error'])) {
            error_log('Google Vision API Error: ' . $result['error']['message']);
            return new WP_Error('api_error', $result['error']['message']);
        }

        return $result['responses'][0];
    }

    public function speech_to_text($audio_path, $language = 'it-IT') {
        $api_key = get_option('alfaai_pro_google_speech_key');
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', 'Google Speech API key non configurata');
        }

        $audio_data = file_get_contents($audio_path);
        $base64_audio = base64_encode($audio_data);

        $url = 'https://speech.googleapis.com/v1/speech:recognize?key=' . urlencode($api_key);

        $data = array(
            'config' => array(
                'encoding' => 'WEBM_OPUS',
                'sampleRateHertz' => 48000,
                'languageCode' => $language,
            ),
            'audio' => array(
                'content' => $base64_audio
            )
        );

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            error_log('Google Speech-to-Text API Error: ' . $response->get_error_message());
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (isset($result['error'])) {
            error_log('Google Speech-to-Text API Error: ' . $result['error']['message']);
            return new WP_Error('api_error', $result['error']['message']);
        }

        $transcript = '';
        if (isset($result['results'][0]['alternatives'][0]['transcript'])) {
            $transcript = $result['results'][0]['alternatives'][0]['transcript'];
        }

        return $transcript;
    }

    public function text_to_speech($text, $language = 'it-IT', $voice_name = 'it-IT-Standard-A') {
        $api_key = get_option('alfaai_pro_google_tts_key');
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', 'Google TTS API key non configurata');
        }

        $url = 'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . urlencode($api_key);

        $data = array(
            'input' => array(
                'text' => $text
            ),
            'voice' => array(
                'languageCode' => $language,
                'name' => $voice_name
            ),
            'audioConfig' => array(
                'audioEncoding' => 'MP3'
            )
        );

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            error_log('Google Text-to-Speech API Error: ' . $response->get_error_message());
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (isset($result['error'])) {
            error_log('Google Text-to-Speech API Error: ' . $result['error']['message']);
            return new WP_Error('api_error', $result['error']['message']);
        }

        $audio_content = $result['audioContent'];
        return base64_decode($audio_content);
    }
}
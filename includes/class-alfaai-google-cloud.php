<?php
if (!defined('ABSPATH')) exit;
class AlfaAI_Google_Cloud {
    public static function init(){
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_filter('alfaai_pro_settings_fields', [__CLASS__, 'add_settings_fields']);
    }
    protected static function key($service=''){
        $k = '';
        if ($service) $k = get_option('alfaai_pro_google_'.$service.'_key','');
        if (!$k) $k = get_option('alfaai_pro_google_key','');
        if (!$k) $k = get_option('alfaai_google_services_key','');
        if (!$k && defined('ALFAAI_GOOGLE_KEY')) $k = ALFAAI_GOOGLE_KEY;
        return $k;
    }
    protected static function post_json($url, $body){
        $res = wp_remote_post($url, [
            'timeout'=>60,
            'headers'=>['Content-Type'=>'application/json'],
            'body'=> wp_json_encode($body)
        ]);
        if (is_wp_error($res)) return ['error'=>$res->get_error_message()];
        return ['code'=>wp_remote_retrieve_response_code($res), 'json'=> json_decode(wp_remote_retrieve_body($res), true)];
    }
    public static function register_settings(){
        register_setting('alfaai_pro_settings','alfaai_pro_google_vision_key');
        register_setting('alfaai_pro_settings','alfaai_pro_google_speech_key');
        register_setting('alfaai_pro_settings','alfaai_pro_google_tts_key');
    }
    public static function add_settings_fields($fields){
        $google_fields=[
            ['name'=>'google_vision_key','label'=>'Google Cloud Vision API Key','type'=>'password','default'=>'','description'=>'Chiave API per Google Vision (analisi immagini)'],
            ['name'=>'google_speech_key','label'=>'Google Cloud Speech-to-Text API Key','type'=>'password','default'=>'','description'=>'Chiave API per riconoscimento vocale'],
            ['name'=>'google_tts_key','label'=>'Google Cloud Text-to-Speech API Key','type'=>'password','default'=>'','description'=>'Chiave API per sintesi vocale']
        ];
        return array_merge($fields,$google_fields);
    }
    public static function stt_transcribe($audio_base64,$language='it-IT'){
        $key=self::key('speech'); if(!$key) return ['error'=>'missing key'];
        $url='https://speech.googleapis.com/v1/speech:recognize?key='.$key;
        $body=[ 'config'=>['languageCode'=>$language,'enableAutomaticPunctuation'=>true,'encoding'=>'WEBM_OPUS'],'audio'=>['content'=>$audio_base64] ];
        return self::post_json($url,$body);
    }
    public static function tts_speak($text,$language='it-IT',$voice='it-IT-Wavenet-D'){
        $key=self::key('tts'); if(!$key) return ['error'=>'missing key'];
        $url='https://texttospeech.googleapis.com/v1/text:synthesize?key='.$key;
        $body=[ 'input'=>['text'=>$text], 'voice'=>['languageCode'=>$language,'name'=>$voice], 'audioConfig'=>['audioEncoding'=>'MP3'] ];
        return self::post_json($url,$body);
    }
    public static function vision_ocr($image_base64){
        $key=self::key('vision'); if(!$key) return ['error'=>'missing key'];
        $url='https://vision.googleapis.com/v1/images:annotate?key='.$key;
        $body=['requests'=>[[ 'image'=>['content'=>$image_base64], 'features'=>[['type'=>'TEXT_DETECTION']] ]]];
        return self::post_json($url,$body);
    }
    public static function vision_analyze($image_base64){
        $key=self::key('vision'); if(!$key) return ['error'=>'missing key'];
        $url='https://vision.googleapis.com/v1/images:annotate?key='.$key;
        $body=[
            'requests'=>[[
                'image'=>['content'=>$image_base64],
                'features'=>[
                    ['type'=>'LABEL_DETECTION','maxResults'=>10],
                    ['type'=>'TEXT_DETECTION','maxResults'=>10],
                    ['type'=>'SAFE_SEARCH_DETECTION','maxResults'=>10]
                ]
            ]]
        ];
        return self::post_json($url,$body);
    }
    public static function translate($text,$target='en'){
        $key=self::key(); if(!$key) return ['error'=>'missing key'];
        $url='https://translation.googleapis.com/language/translate/v2?key='.$key;
        $body=['q'=>$text,'target'=>$target];
        return self::post_json($url,$body);
    }
}

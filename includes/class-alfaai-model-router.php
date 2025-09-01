<?php
if (!defined('ABSPATH')) exit;
class AlfaAI_Model_Router {
    public static function pick_provider($intent='chat'){
        $keys = [
            'openai'   => get_option('alfaai_pro_openai_key','')   ?: (defined('ALFAAI_OPENAI_KEY')  ? ALFAAI_OPENAI_KEY  : ''),
            'gemini'   => get_option('alfaai_pro_gemini_key','')   ?: (defined('ALFAAI_GEMINI_KEY')  ? ALFAAI_GEMINI_KEY  : ''),
            'deepseek' => get_option('alfaai_pro_deepseek_key','') ?: (defined('ALFAAI_DEEPSEEK_KEY')? ALFAAI_DEEPSEEK_KEY: ''),
        ];
        $order = ($intent==='code') ? ['deepseek','openai','gemini'] : ['openai','gemini','deepseek'];
        foreach ($order as $p){ if (!empty($keys[$p])) return $p; }
        return 'openai';
    }
}

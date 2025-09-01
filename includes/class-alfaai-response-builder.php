<?php
if (!defined('ABSPATH')) exit;

class AlfaAI_Response_Builder {

    public static function dual($prompt, $opts = []){
        $deep  = !empty($opts['deep']);
        $ocr   = isset($opts['ocr']) ? trim((string)$opts['ocr']) : '';
        $mode  = $opts['mode'] ?? 'chat';

        $system = "Sei un assistente professionale. Dai risposte chiare, concise e utili.";
        if ($deep) $system .= " Ragiona con rigore e fornisci SOLO la risposta finale (niente passaggi interni).";

        $standard = self::call_provider($prompt, [
            'system'      => $system,
            'temperature' => $deep ? 0.6 : 0.7,
            'max_tokens'  => 1200,
        ]);

        $vision_prompt = "Fornisci una seconda risposta 'Alfassa Vision' complementare e strategica.\n"
                       . "- Se presente OCR, sintetizzalo e integralo.\n"
                       . "- Elenca 3–5 punti d’azione.\n"
                       . "- Se utile, aggiungi rischi/mitigazioni.\n";
        if ($ocr) $vision_prompt .= "\n---\nTESTO OCR:\n{$ocr}\n---\n";

        $vision = self::call_provider($prompt."\n\n".$vision_prompt, [
            'system'      => "Sei 'Alfassa Vision', un analista in italiano. Nessun chain-of-thought, solo risultato.",
            'temperature' => 0.65,
            'max_tokens'  => 1400,
        ]);

        return [
            'standard' => ['answer' => $standard['text'] ?? '', 'badges' => $standard['badges'] ?? []],
            'vision'   => ['answer' => $vision['text']   ?? '', 'badges' => $vision['badges']   ?? ['Vision'], 'evidence'=>[]],
        ];
    }

    private static function call_provider($prompt, $params){
        $provider = class_exists('AlfaAI_Model_Router') ? AlfaAI_Model_Router::pick_provider('chat') : 'openai';
        switch ($provider){
            case 'gemini':   return self::call_gemini($prompt, $params);
            case 'deepseek': return self::call_deepseek($prompt, $params);
            default:         return self::call_openai($prompt, $params);
        }
    }

    private static function call_openai($prompt, $p){
        $key = get_option('alfaai_pro_openai_key','') ?: (defined('ALFAAI_OPENAI_KEY') ? ALFAAI_OPENAI_KEY : '');
        if (!$key) return ['text'=>'', 'badges'=>['OpenAI: missing key']];
        $body = [
            'model' => 'gpt-4o-mini',
            'temperature' => isset($p['temperature']) ? (float)$p['temperature'] : 0.7,
            'max_tokens'  => isset($p['max_tokens']) ? (int)$p['max_tokens'] : 1200,
            'messages' => [
                ['role'=>'system', 'content'=>$p['system'] ?? ''],
                ['role'=>'user',   'content'=>$prompt],
            ],
        ];
        $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => ['Content-Type'=>'application/json','Authorization'=>'Bearer '.$key],
            'body'    => wp_json_encode($body),
        ]);
        if (is_wp_error($res)) return ['text'=>'', 'badges'=>['OpenAI error: '.$res->get_error_message()]];
        $j = json_decode(wp_remote_retrieve_body($res), true);
        $txt = $j['choices'][0]['message']['content'] ?? '';
        return ['text'=>$txt, 'badges'=>['OpenAI']];
    }

    private static function call_gemini($prompt, $p){
        $key = get_option('alfaai_pro_gemini_key','') ?: (defined('ALFAAI_GEMINI_KEY') ? ALFAAI_GEMINI_KEY : '');
        if (!$key) return ['text'=>'', 'badges'=>['Gemini: missing key']];
        $model = 'gemini-1.5-flash';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent?key='.$key;
        $body = [
            'contents' => [[ 'parts' => [[ 'text' => (($p['system'] ?? '')."\n\n").$prompt ]] ]],
            'generationConfig' => [
                'temperature'     => isset($p['temperature']) ? (float)$p['temperature'] : 0.7,
                'maxOutputTokens' => isset($p['max_tokens']) ? (int)$p['max_tokens'] : 1200,
            ],
        ];
        $res = wp_remote_post($url, ['timeout'=>60,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode($body)]);
        if (is_wp_error($res)) return ['text'=>'', 'badges'=>['Gemini error: '.$res->get_error_message()]];
        $j = json_decode(wp_remote_retrieve_body($res), true);
        $txt = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return ['text'=>$txt, 'badges'=>['Gemini']];
    }

    private static function call_deepseek($prompt, $p){
        $key = get_option('alfaai_pro_deepseek_key','') ?: (defined('ALFAAI_DEEPSEEK_KEY') ? ALFAAI_DEEPSEEK_KEY : '');
        if (!$key) return ['text'=>'', 'badges'=>['DeepSeek: missing key']];
        $body = [
            'model' => 'deepseek-chat',
            'temperature' => isset($p['temperature']) ? (float)$p['temperature'] : 0.7,
            'max_tokens'  => isset($p['max_tokens']) ? (int)$p['max_tokens'] : 1200,
            'messages' => [
                ['role'=>'system', 'content'=>$p['system'] ?? ''],
                ['role'=>'user',   'content'=>$prompt],
            ],
        ];
        $res = wp_remote_post('https://api.deepseek.com/chat/completions', [
            'timeout'=>60,
            'headers'=>['Content-Type'=>'application/json','Authorization'=>'Bearer '.$key],
            'body'=> wp_json_encode($body),
        ]);
        if (is_wp_error($res)) return ['text'=>'', 'badges'=>['DeepSeek error: '.$res->get_error_message()]];
        $j = json_decode(wp_remote_retrieve_body($res), true);
        $txt = $j['choices'][0]['message']['content'] ?? '';
        return ['text'=>$txt, 'badges'=>['DeepSeek']];
    }
}

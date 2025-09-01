<?php
if (!defined('ABSPATH')) exit;
class AlfaAI_Admin_Enterprise {
    public static function log($event, $data=[]){
        if (defined('WP_DEBUG') && WP_DEBUG) error_log('[AlfaAI EE] '.$event.': '.wp_json_encode($data));
    }
}

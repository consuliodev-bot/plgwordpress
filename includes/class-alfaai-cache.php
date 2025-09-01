<?php
if (!defined('ABSPATH')) exit;
class AlfaAI_Cache {
    protected static function key($ns,$id){ return 'alfaai_'.$ns.'_'.md5($id); }
    public static function put($ns,$id,$val,$ttl=300){ set_transient(self::key($ns,$id), $val, $ttl); }
    public static function get($ns,$id){ return get_transient(self::key($ns,$id)); }
    public static function forget($ns,$id){ delete_transient(self::key($ns,$id)); }
}

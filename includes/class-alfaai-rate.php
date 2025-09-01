<?php
if (!defined('ABSPATH')) exit;
class AlfaAI_Rate {
    public static function check_and_consume($key,$points=1,$window=60){
        $now = time();
        $bucket = get_transient('alfaai_rate_'.$key);
        if (!is_array($bucket)) $bucket = ['ts'=>$now,'used'=>0];
        if ($now - $bucket['ts'] > $window){ $bucket = ['ts'=>$now,'used'=>0]; }
        if ($bucket['used'] + $points > 100) return false;
        $bucket['used'] += $points;
        set_transient('alfaai_rate_'.$key, $bucket, $window);
        return true;
    }
}

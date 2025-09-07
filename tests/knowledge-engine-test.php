<?php
define('ABSPATH', __DIR__ . '/');
require_once __DIR__ . '/../includes/class-alfaai-knowledge-engine.php';

AlfaAI_Knowledge_Engine::init();

$result = AlfaAI_Knowledge_Engine::find('ALFASSA');

if (empty($result['json'])) {
    echo "No JSON results found\n";
    exit(1);
}

echo 'Found ' . count($result['json']) . " JSON result(s)\n";

<?php
// Configurazione Health Tracker
return [
    'auth_code' => '123456', // Codice di accesso a 6 cifre
    'openai_api_key' => '', // Inserire la chiave API di OpenAI
    'cookie_name' => 'healthtracker_auth',
    'cookie_duration' => 30 * 24 * 60 * 60, // 30 giorni in secondi
    'data_path' => __DIR__ . '/data'
];
?>

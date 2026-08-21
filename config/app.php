<?php

use App\Services\EnvLoader;

// Garante que o .env seja carregado
$envPath = __DIR__ . '/../.env';
EnvLoader::load($envPath);

/**
 * Função utilitária para buscar variáveis de ambiente com fallback
 */
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        switch (strtolower((string) $value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        return $value;
    }
}

return [
    'inter' => [
        'client_id'       => env('INTER_CLIENT_ID', ''),
        'client_secret'   => env('INTER_CLIENT_SECRET', ''),
        'cert_path'       => env('INTER_CERT_PATH', ''),
        'key_path'        => env('INTER_KEY_PATH', ''),
        'cert_passphrase' => env('INTER_CERT_PASSPHRASE', ''),
        'pix_key'         => env('INTER_PIX_KEY', ''),
        'env'             => env('INTER_ENV', 'production'), // production | sandbox
        'base_url'        => env('INTER_ENV', 'production') === 'sandbox'
            ? 'https://cdpj-sandbox.partners.uatinter.co'
            : 'https://cdpj.partners.bancointer.com.br',
    ],
    'loja_integrada' => [
        'chave_api'       => env('LOJA_INTEGRADA_CHAVE_API', ''),
        'chave_aplicacao' => env('LOJA_INTEGRADA_CHAVE_APLICACAO', ''),
        'base_url'        => 'https://api.awsli.com.br/v1',
    ],
    'storage' => [
        'data_path' => __DIR__ . '/../storage/data',
        'cache_file' => __DIR__ . '/../storage/data/pix_cache.json',
        'token_file' => __DIR__ . '/../storage/data/inter_token.json',
    ],
    'evolution' => [
        'enabled'              => env('EVOLUTION_ENABLED', false),
        'api_url'              => rtrim(env('EVOLUTION_API_URL', ''), '/'),
        'api_key'              => env('EVOLUTION_API_KEY', ''),
        'instance'             => env('EVOLUTION_INSTANCE', ''),
        'template_file'        => __DIR__ . '/whatsapp_pix_template.txt',
        'pagali_template_file' => __DIR__ . '/whatsapp_pagali_template.txt',
    ]
];

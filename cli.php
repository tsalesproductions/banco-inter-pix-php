<?php

// Autoload simples
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require $file;
});

$config = require __DIR__ . '/config/app.php';

use App\Services\InterPixService;
use App\Services\LojaIntegradaService;
use App\Services\PixCacheRepository;

$command = $argv[1] ?? 'help';

echo "====================================================\n";
echo "   CLI - BANCO INTER PIX & LOJA INTEGRADA (PHP)      \n";
echo "====================================================\n\n";

switch ($command) {
    case 'test-env':
        echo "🔹 Verificando ambiente e arquivos...\n";
        echo "  - INTER_CLIENT_ID: " . ($config['inter']['client_id'] ? 'OK ✅' : 'NÃO CONFIGURADO ❌') . "\n";
        echo "  - INTER_CLIENT_SECRET: " . ($config['inter']['client_secret'] ? 'OK ✅' : 'NÃO CONFIGURADO ❌') . "\n";
        echo "  - INTER_PIX_KEY: " . ($config['inter']['pix_key'] ?: 'NÃO CONFIGURADO ❌') . "\n";
        echo "  - LOJA_INTEGRADA_CHAVE_API: " . ($config['loja_integrada']['chave_api'] ? 'OK ✅' : 'NÃO CONFIGURADO ❌') . "\n";
        echo "  - LOJA_INTEGRADA_CHAVE_APLICACAO: " . ($config['loja_integrada']['chave_aplicacao'] ? 'OK ✅' : 'NÃO CONFIGURADO ❌') . "\n";
        break;

    case 'test-token':
    case 'test-inter':
        echo "🔹 Testando obtenção de Token OAuth no Banco Inter...\n";
        try {
            $inter = new InterPixService(
                $config['inter']['client_id'],
                $config['inter']['client_secret'],
                $config['inter']['pix_key'],
                $config['inter']['base_url'],
                $config['storage']['token_file'],
                $config['inter']['cert_path'] ?? null,
                $config['inter']['key_path'] ?? null,
                $config['inter']['cert_passphrase'] ?? null
            );
            $token = $inter->getAccessToken();
            echo "✅ Token OAuth obtido com sucesso!\n";
            echo "   Token: " . substr($token, 0, 20) . "...\n";
        } catch (\Exception $e) {
            echo "❌ Erro ao obter token: " . $e->getMessage() . "\n";
        }
        break;

    case 'test-li':
        $pedido = $argv[2] ?? 165;
        echo "🔹 Testando consulta de pedido #{$pedido} na Loja Integrada...\n";
        try {
            $li = new LojaIntegradaService(
                $config['loja_integrada']['chave_api'],
                $config['loja_integrada']['chave_aplicacao'],
                $config['loja_integrada']['base_url']
            );
            $order = $li->getOrderByNumber($pedido);
            echo "✅ Pedido retornado com sucesso!\n";
            echo "   Cliente: " . ($order['cliente']['nome'] ?? 'N/I') . "\n";
            echo "   Valor Total: R$ " . ($order['valor_total'] ?? '0.00') . "\n";
        } catch (\Exception $e) {
            echo "❌ Erro ao buscar pedido na LI: " . $e->getMessage() . "\n";
        }
        break;

    case 'generate-pix':
        $pedido = $argv[2] ?? null;
        if (!$pedido) {
            echo "Uso: php cli.php generate-pix <numero_pedido>\n";
            exit(1);
        }
        echo "🔹 Gerando Pix Inteligente para pedido #{$pedido}...\n";
        try {
            $repo = new PixCacheRepository($config['storage']['cache_file']);
            $active = $repo->getActivePixForOrder($pedido);
            if ($active) {
                echo "⚡ PIX RECUPERADO DO CACHE INTELIGENTE!\n";
                print_r($active);
                break;
            }

            $li = new LojaIntegradaService(
                $config['loja_integrada']['chave_api'],
                $config['loja_integrada']['chave_aplicacao'],
                $config['loja_integrada']['base_url']
            );
            $inter = new InterPixService(
                $config['inter']['client_id'],
                $config['inter']['client_secret'],
                $config['inter']['pix_key'],
                $config['inter']['base_url'],
                $config['storage']['token_file'],
                $config['inter']['cert_path'] ?? null,
                $config['inter']['key_path'] ?? null,
                $config['inter']['cert_passphrase'] ?? null
            );

            $orderData = $li->getOrderByNumber($pedido);
            $txid = InterPixService::generateTxid($pedido);
            $cobv = $inter->createCobv($txid, $orderData, 24);

            $pixCopyPaste = $cobv['pixCopiaECola'] ?? '';
            $qrCodeUrl = !empty($pixCopyPaste) 
                ? 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($pixCopyPaste)
                : '';

            $record = [
                'order_number'    => (int)$pedido,
                'txid'            => $txid,
                'pix_copy_paste'  => $pixCopyPaste,
                'qr_code_url'     => $qrCodeUrl,
                'location'        => $cobv['loc']['location'] ?? '',
                'status'          => 'PENDING',
                'expires_at'      => date('Y-m-d H:i:s', time() + 86400),
                'valor_total'     => $orderData['valor_total'] ?? '0.00',
                'cliente' => [
                    'nome' => $orderData['cliente']['nome'] ?? '',
                    'email' => $orderData['cliente']['email'] ?? ''
                ],
                'created_at'      => date('Y-m-d H:i:s')
            ];
            $repo->save($record);

            echo "🟢 NOVO PIX GERADO NO BANCO INTER!\n";
            print_r($record);

        } catch (\Exception $e) {
            echo "❌ Erro: " . $e->getMessage() . "\n";
        }
        break;

    case 'list-webhooks':
        echo "🔹 Consultando Webhooks no Banco Inter...\n";
        try {
            $inter = new InterPixService(
                $config['inter']['client_id'],
                $config['inter']['client_secret'],
                $config['inter']['pix_key'],
                $config['inter']['base_url'],
                $config['storage']['token_file'],
                $config['inter']['cert_path'] ?? null,
                $config['inter']['key_path'] ?? null,
                $config['inter']['cert_passphrase'] ?? null
            );
            $res = $inter->getWebhook();
            print_r($res);
        } catch (\Exception $e) {
            echo "❌ Erro: " . $e->getMessage() . "\n";
        }
        break;

    case 'register-webhook':
        $url = $argv[2] ?? null;
        if (!$url) {
            echo "Uso: php cli.php register-webhook <https://suadominio.com/api/webhook/inter>\n";
            exit(1);
        }
        echo "🔹 Cadastrando Webhook no Banco Inter para URL: {$url}...\n";
        try {
            $inter = new InterPixService(
                $config['inter']['client_id'],
                $config['inter']['client_secret'],
                $config['inter']['pix_key'],
                $config['inter']['base_url'],
                $config['storage']['token_file'],
                $config['inter']['cert_path'] ?? null,
                $config['inter']['key_path'] ?? null,
                $config['inter']['cert_passphrase'] ?? null
            );
            $res = $inter->registerWebhook($url);
            echo "✅ Webhook registrado com sucesso!\n";
            print_r($res);
        } catch (\Exception $e) {
            echo "❌ Erro: " . $e->getMessage() . "\n";
        }
        break;

    case 'delete-webhook':
        $chave = $argv[2] ?? null;
        echo "🔹 Removendo Webhook do Banco Inter...\n";
        try {
            $inter = new InterPixService(
                $config['inter']['client_id'],
                $config['inter']['client_secret'],
                $config['inter']['pix_key'],
                $config['inter']['base_url'],
                $config['storage']['token_file'],
                $config['inter']['cert_path'] ?? null,
                $config['inter']['key_path'] ?? null,
                $config['inter']['cert_passphrase'] ?? null
            );
            $res = $inter->deleteWebhook($chave);
            echo "✅ Webhook removido com sucesso!\n";
            print_r($res);
        } catch (\Exception $e) {
            echo "❌ Erro ao remover webhook: " . $e->getMessage() . "\n";
        }
        break;

    case 'simulate-payment':
        $pedido = $argv[2] ?? null;
        if (!$pedido) {
            echo "Uso: php cli.php simulate-payment <numero_pedido>\n";
            exit(1);
        }
        echo "🔹 Simulando liquidação/pagamento Pix para o pedido #{$pedido}...\n";
        $repo = new PixCacheRepository($config['storage']['cache_file']);
        $record = $repo->getActivePixForOrder($pedido);
        $txid = $record['txid'] ?? InterPixService::generateTxid($pedido);
        $valor = $record['valor_total'] ?? '10.00';

        $inter = new InterPixService(
            $config['inter']['client_id'],
            $config['inter']['client_secret'],
            $config['inter']['pix_key'],
            $config['inter']['base_url'],
            $config['storage']['token_file'],
            $config['inter']['cert_path'] ?? null,
            $config['inter']['key_path'] ?? null,
            $config['inter']['cert_passphrase'] ?? null
        );
        $li = new LojaIntegradaService(
            $config['loja_integrada']['chave_api'],
            $config['loja_integrada']['chave_aplicacao'],
            $config['loja_integrada']['base_url']
        );
        $webhook = new \App\Controllers\WebhookController($inter, $li, $repo);

        $GLOBALS['SIMULATED_RAW_BODY'] = json_encode([
            'pix' => [
                [
                    'txid' => $txid,
                    'e2eId' => 'E00000000' . date('YmdHis') . '000',
                    'valor' => $valor,
                    'horario' => date('Y-m-d\TH:i:s\Z'),
                    'solicitacaoPagador' => "Pagamento do Pedido #{$pedido}"
                ]
            ]
        ]);

        ob_start();
        $webhook->handleNotification();
        $res = ob_get_clean();
        echo "✅ Resultado da Simulação:\n" . $res . "\n";
        break;

    case 'simulate-refund':
        $pedido = $argv[2] ?? null;
        if (!$pedido) {
            echo "Uso: php cli.php simulate-refund <numero_pedido>\n";
            exit(1);
        }
        echo "🔹 Simulando estorno/devolução Pix para o pedido #{$pedido}...\n";
        $repo = new PixCacheRepository($config['storage']['cache_file']);
        $record = $repo->getActivePixForOrder($pedido);
        $txid = $record['txid'] ?? InterPixService::generateTxid($pedido);
        $valor = $record['valor_total'] ?? '10.00';

        $inter = new InterPixService(
            $config['inter']['client_id'],
            $config['inter']['client_secret'],
            $config['inter']['pix_key'],
            $config['inter']['base_url'],
            $config['storage']['token_file'],
            $config['inter']['cert_path'] ?? null,
            $config['inter']['key_path'] ?? null,
            $config['inter']['cert_passphrase'] ?? null
        );
        $li = new LojaIntegradaService(
            $config['loja_integrada']['chave_api'],
            $config['loja_integrada']['chave_aplicacao'],
            $config['loja_integrada']['base_url']
        );
        $webhook = new \App\Controllers\WebhookController($inter, $li, $repo);

        $GLOBALS['SIMULATED_RAW_BODY'] = json_encode([
            'pix' => [
                [
                    'txid' => $txid,
                    'e2eId' => 'E00000000' . date('YmdHis') . '000',
                    'valor' => $valor,
                    'horario' => date('Y-m-d\TH:i:s\Z'),
                    'status' => 'DEVOLVIDO',
                    'devolucoes' => [
                        [
                            'id' => 'DEV' . time(),
                            'rtrnId' => 'D00000000' . date('YmdHis') . '000',
                            'valor' => $valor,
                            'status' => 'DEVOLVIDO'
                        ]
                    ],
                    'solicitacaoPagador' => "Estorno do Pedido #{$pedido}"
                ]
            ]
        ]);

        ob_start();
        $webhook->handleNotification();
        $res = ob_get_clean();
        echo "✅ Resultado da Simulação de Estorno:\n" . $res . "\n";
        break;

    default:
        echo "Comandos disponíveis:\n";
        echo "  php cli.php test-env              # Testa leitura do arquivo .env\n";
        echo "  php cli.php test-inter            # Obter token OAuth Banco Inter\n";
        echo "  php cli.php test-li <numero>      # Consultar pedido na Loja Integrada\n";
        echo "  php cli.php generate-pix <numero> # Gerar/Buscar Pix Inteligente\n";
        echo "  php cli.php list-webhooks         # Listar Webhooks cadastrados no Inter\n";
        echo "  php cli.php register-webhook <url> # Cadastrar Webhook no Inter\n";
        echo "  php cli.php delete-webhook         # Remover Webhook no Inter\n";
        echo "  php cli.php simulate-payment <numero> # Simular pagamento de um pedido\n";
        echo "  php cli.php simulate-refund <numero>  # Simular estorno/devolução de um pedido\n";
        break;
}

echo "\nDone!\n";

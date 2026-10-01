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
use App\Services\WppQueueRepository;

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

    case 'test-wpp':
        $pedido = $argv[2] ?? null;
        $testPhone = $argv[3] ?? null;
        if (!$pedido) {
            echo "Uso: php cli.php test-wpp <numero_pedido> [telefone_opcional]\n";
            exit(1);
        }
        echo "🔹 Testando envio de WhatsApp (Evolution API) para o pedido #{$pedido}...\n";
        try {
            $li = new LojaIntegradaService(
                $config['loja_integrada']['chave_api'],
                $config['loja_integrada']['chave_aplicacao'],
                $config['loja_integrada']['base_url']
            );
            $repo = new PixCacheRepository($config['storage']['cache_file']);
            $evo = new App\Services\EvolutionService(
                $config['evolution']['api_url'] ?? '',
                $config['evolution']['api_key'] ?? '',
                $config['evolution']['instance'] ?? '',
                true, // Força ativado no comando CLI de teste
                $config['evolution']['template_file'] ?? ''
            );

            $orderData = $li->getOrderByNumber($pedido);
            if ($testPhone) {
                $orderData['cliente']['celular'] = $testPhone;
                echo "ℹ️ Usando telefone informado no terminal: {$testPhone}\n";
            }

            $pixRecord = $repo->getActivePixForOrder($pedido);

            $pixCopyPaste = $pixRecord['pix_copy_paste'] ?? '00020126580014br.gov.bcb.pix0136test-br-code';
            $qrCodeUrl = $pixRecord['qr_code_url'] ?? 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($pixCopyPaste);

            $res = $evo->sendPixNotification($orderData, $pixCopyPaste, $qrCodeUrl);
            echo "✅ Resultado do envio de WhatsApp:\n";
            print_r($res);
        } catch (\Exception $e) {
            echo "❌ Erro ao enviar WhatsApp: " . $e->getMessage() . "\n";
        }
        break;

    case 'test-li-webhook':
        $pedido = $argv[2] ?? null;
        if (!$pedido) {
            echo "Uso: php cli.php test-li-webhook <numero_pedido>\n";
            exit(1);
        }
        echo "🔹 Simulando Webhook da Loja Integrada (Pagali/LI Nativo) para o pedido #{$pedido}...\n";
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
            $li = new LojaIntegradaService(
                $config['loja_integrada']['chave_api'],
                $config['loja_integrada']['chave_aplicacao'],
                $config['loja_integrada']['base_url']
            );
            $repo = new PixCacheRepository($config['storage']['cache_file']);
            $evo = new App\Services\EvolutionService(
                $config['evolution']['api_url'] ?? '',
                $config['evolution']['api_key'] ?? '',
                $config['evolution']['instance'] ?? '',
                true, // Força ativado no comando CLI de teste
                $config['evolution']['template_file'] ?? ''
            );

            $webhook = new \App\Controllers\WebhookController($inter, $li, $repo, $evo);

            $orderData = $li->getOrderByNumber((int)$pedido);
            // Simula a situação 'aguardando_pagamento'
            $orderData['situacao'] = [
                'codigo' => 'aguardando_pagamento',
                'nome'   => 'Aguardando pagamento'
            ];
            $hasPix = false;
            foreach ($orderData['pagamentos'] ?? [] as $pag) {
                if (!empty($pag['pix_code'])) {
                    $hasPix = true;
                    break;
                }
            }
            if (!$hasPix) {
                $orderData['pagamentos'] = [
                    [
                        'forma_pagamento' => ['codigo' => 'pix', 'nome' => 'Pix (Pagali)'],
                        'pix_code'        => '00020126580014br.gov.bcb.pix0136test-br-code-pagali',
                        'pix_qrcode'      => 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=test'
                    ]
                ];
            }

            $GLOBALS['SIMULATED_RAW_BODY'] = json_encode($orderData);

            ob_start();
            $webhook->handleLojaIntegradaOrderWebhook();
            $res = ob_get_clean();
            echo "✅ Resultado do Webhook Loja Integrada:\n" . $res . "\n";
        } catch (\Exception $e) {
            echo "❌ Erro ao simular Webhook da Loja Integrada: " . $e->getMessage() . "\n";
        }
        break;

    case 'process-wpp-queue':
        echo "🔹 Processando fila de envio de WhatsApp (modo queue)...\n";
        try {
            $queueRepo = new WppQueueRepository($config['storage']['queue_file']);
            $dueItems = $queueRepo->getDueItems();

            $logLi = function (string $msg) {
                $logDir = __DIR__ . '/storage/logs';
                if (!is_dir($logDir)) {
                    mkdir($logDir, 0755, true);
                }
                $timestamp = date('Y-m-d H:i:s');
                file_put_contents($logDir . '/li_webhooks.log', "[{$timestamp}] {$msg}\n", FILE_APPEND);
            };

            if (empty($dueItems)) {
                echo "ℹ️ Nenhum pedido pendente para envio na fila neste momento.\n";
                break;
            }

            echo "📦 Encontrado(s) " . count($dueItems) . " pedido(s) pronto(s) para validação e envio.\n\n";
            $logLi("CRON WORKER: Encontrado(s) " . count($dueItems) . " pedido(s) pronto(s) para validação na fila.");

            $li = new LojaIntegradaService(
                $config['loja_integrada']['chave_api'],
                $config['loja_integrada']['chave_aplicacao'],
                $config['loja_integrada']['base_url']
            );

            $evo = new App\Services\EvolutionService(
                $config['evolution']['api_url'] ?? '',
                $config['evolution']['api_key'] ?? '',
                $config['evolution']['instance'] ?? '',
                $config['evolution']['enabled'] ?? false,
                $config['evolution']['template_file'] ?? ''
            );

            $pagaliTemplateFile = __DIR__ . '/config/whatsapp_pagali_template.txt';

            foreach ($dueItems as $item) {
                $orderNumber = $item['order_number'];
                $itemId = $item['id'];
                echo "----------------------------------------------------\n";
                echo "🔍 Verificando Pedido #{$orderNumber} (Item ID: {$itemId})...\n";

                try {
                    $currentOrder = $li->getOrderByNumber($orderNumber);
                    $situacaoCodigo = strtolower($currentOrder['situacao']['codigo'] ?? '');
                    $situacaoNome = strtolower($currentOrder['situacao']['nome'] ?? '');
                    $isAprovado = $currentOrder['situacao']['aprovado'] ?? false;

                    echo "   Situação atual na LI: '{$situacaoCodigo}' ({$situacaoNome})\n";
                    $logLi("CRON WORKER PEDIDO #{$orderNumber}: Situação atual na Loja Integrada = '{$situacaoCodigo}' ({$situacaoNome})");

                    if ($situacaoCodigo === 'aguardando_pagamento' || $situacaoNome === 'aguardando pagamento') {
                        echo "   ⚠️ Pedido continua SEM PAGAMENTO! Enviando WhatsApp...\n";
                        $logLi("CRON WORKER PEDIDO #{$orderNumber}: Continua SEM PAGAMENTO. Enviando WhatsApp via Evolution API...");
                        if ($evo->isEnabled()) {
                            $wppResult = $evo->sendPixNotification(
                                $currentOrder,
                                $item['pix_code'],
                                $item['pix_qrcode'],
                                $pagaliTemplateFile
                            );
                            $queueRepo->updateStatus($itemId, 'sent', ['wpp_result' => $wppResult]);
                            echo "   ✅ WhatsApp enviado com sucesso!\n";
                            $logLi("CRON WORKER PEDIDO #{$orderNumber}: WhatsApp ENVIADO com sucesso. Resposta: " . json_encode($wppResult, JSON_UNESCAPED_UNICODE));
                        } else {
                            $queueRepo->updateStatus($itemId, 'failed', ['reason' => 'Evolution API disabled in .env']);
                            echo "   ❌ Erro: Evolution API está desativada no .env\n";
                            $logLi("CRON WORKER PEDIDO #{$orderNumber} ERROR: Evolution API desativada no .env");
                        }
                    } elseif ($situacaoCodigo === 'pedido_pago' || $isAprovado || in_array($situacaoCodigo, ['faturado', 'pedido_em_separacao', 'pedido_enviado', 'pedido_entregue'])) {
                        $queueRepo->updateStatus($itemId, 'skipped_paid', ['reason' => "Order paid ({$situacaoCodigo})"]);
                        echo "   🟢 Pedido JÁ FOI PAGO! Envio de WhatsApp cancelado.\n";
                        $logLi("CRON WORKER PEDIDO #{$orderNumber} SKIPPED: O cliente JÁ PAGOU o pedido (Situação: '{$situacaoCodigo}'). Lembrete de Pix CANCELADO.");
                    } elseif ($situacaoCodigo === 'pedido_cancelado') {
                        $queueRepo->updateStatus($itemId, 'skipped_cancelled', ['reason' => 'Order cancelled']);
                        echo "   ℹ️ Pedido foi CANCELADO. Envio de WhatsApp ignorado.\n";
                        $logLi("CRON WORKER PEDIDO #{$orderNumber} SKIPPED: O pedido foi CANCELADO na Loja Integrada. Envio de WhatsApp ignorado.");
                    } else {
                        $queueRepo->updateStatus($itemId, 'skipped_other', ['reason' => "Status is '{$situacaoCodigo}'"]);
                        echo "   ℹ️ Pedido com status '{$situacaoCodigo}'. Envio de WhatsApp ignorado.\n";
                        $logLi("CRON WORKER PEDIDO #{$orderNumber} SKIPPED: Situação '{$situacaoCodigo}'. Envio de WhatsApp ignorado.");
                    }
                } catch (\Throwable $e) {
                    echo "   ❌ Erro ao processar pedido #{$orderNumber}: " . $e->getMessage() . "\n";
                    $logLi("CRON WORKER PEDIDO #{$orderNumber} ERROR: " . $e->getMessage());
                    $queueRepo->updateStatus($itemId, 'failed', ['reason' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            echo "❌ Erro ao processar fila: " . $e->getMessage() . "\n";
        }
        break;

    case 'list-wpp-queue':
        echo "🔹 Consultando fila de envio de WhatsApp...\n";
        try {
            $queueRepo = new WppQueueRepository($config['storage']['queue_file']);
            $items = $queueRepo->getAll();

            if (empty($items)) {
                echo "ℹ️ A fila está vazia.\n";
                break;
            }

            echo sprintf("%-25s %-12s %-18s %-20s %-20s\n", "ID", "PEDIDO", "STATUS", "AGENDADO PARA", "CRIADO EM");
            echo str_repeat("-", 98) . "\n";

            foreach ($items as $item) {
                echo sprintf(
                    "%-25s %-12s %-18s %-20s %-20s\n",
                    substr($item['id'] ?? '', 0, 24),
                    $item['order_number'] ?? 'N/A',
                    strtoupper($item['status'] ?? 'UNKNOWN'),
                    $item['scheduled_at'] ?? 'N/A',
                    $item['created_at'] ?? 'N/A'
                );
            }
        } catch (\Throwable $e) {
            echo "❌ Erro ao consultar fila: " . $e->getMessage() . "\n";
        }
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
        echo "  php cli.php test-wpp <numero>     # Testar envio de Pix via WhatsApp\n";
        echo "  php cli.php test-li-webhook <numero> # Testar Webhook Loja Integrada + WhatsApp\n";
        echo "  php cli.php process-wpp-queue     # Processar fila de WhatsApp agendada\n";
        echo "  php cli.php list-wpp-queue        # Listar itens na fila de WhatsApp\n";
        break;
}

echo "\nDone!\n";

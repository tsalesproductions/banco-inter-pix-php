<?php

namespace App\Controllers;

use App\Services\InterPixService;
use App\Services\LojaIntegradaService;
use App\Services\PixCacheRepository;

class WebhookController
{
    private InterPixService $interService;
    private LojaIntegradaService $liService;
    private PixCacheRepository $pixRepository;
    private string $logFilePath;

    public function __construct(
        InterPixService $interService,
        LojaIntegradaService $liService,
        PixCacheRepository $pixRepository
    ) {
        $this->interService = $interService;
        $this->liService = $liService;
        $this->pixRepository = $pixRepository;

        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFilePath = $logDir . '/webhooks.log';
    }

    /**
     * Escreve uma mensagem estruturada no log de Webhooks
     */
    private function logMessage(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFilePath, $entry, FILE_APPEND);
    }

    /**
     * Listener do Webhook do Banco Inter (POST /api/webhook/inter)
     */
    public function handleNotification(): void
    {
        @header('Content-Type: application/json; charset=utf-8');

        $rawBody = file_get_contents('php://input');
        if (empty($rawBody) && !empty($GLOBALS['SIMULATED_RAW_BODY'])) {
            $rawBody = $GLOBALS['SIMULATED_RAW_BODY'];
        }

        $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        
        // Dados de mTLS do cliente recebidos do servidor web/proxy (se configurado)
        $sslClientDn = $_SERVER['SSL_CLIENT_S_DN'] ?? $_SERVER['HTTP_X_SSL_CLIENT_S_DN'] ?? $_SERVER['REDIRECT_SSL_CLIENT_S_DN'] ?? 'N/A';
        $sslVerify = $_SERVER['SSL_CLIENT_VERIFY'] ?? $_SERVER['HTTP_X_SSL_CLIENT_VERIFY'] ?? $_SERVER['REDIRECT_SSL_CLIENT_VERIFY'] ?? 'N/A';

        // 1. REGISTRO DE LOG INICIAL DA REQUISIÇÃO RECEBIDA
        $headerLog = "================================================================================\n"
            . "[NOTIFICATION RECEIVED] IP: {$clientIp} | UA: {$userAgent}\n"
            . "mTLS Client Cert DN: {$sslClientDn} | mTLS Status: {$sslVerify}\n"
            . "RAW PAYLOAD: " . ($rawBody ?: '(BODY VAZIO)') . "\n"
            . "--------------------------------------------------------------------------------";
        $this->logMessage($headerLog);

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            $msg = "ERROR: Payload JSON inválido recebido no Webhook.";
            $this->logMessage($msg);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
            return;
        }

        $processed = [];
        $pixList = $payload['pix'] ?? [];

        // Suporta payload individual
        if (isset($payload['txid'])) {
            $pixList = [$payload];
        }

        if (empty($pixList)) {
            $msg = "WARNING: Nenhum objeto 'pix' ou 'txid' encontrado no payload recebido.";
            $this->logMessage($msg);
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
            return;
        }

        foreach ($pixList as $pixItem) {
            $txid = $pixItem['txid'] ?? null;
            $valorPago = $pixItem['valor'] ?? '0.00';
            $e2eId = $pixItem['e2eId'] ?? '';
            $horario = $pixItem['horario'] ?? date('Y-m-d H:i:s');
            $statusItem = strtoupper($pixItem['status'] ?? '');

            // Detecta se a notificação se refere a uma devolução / estorno
            $hasDevolucao = !empty($pixItem['devolucoes']) 
                || isset($pixItem['devolucao']) 
                || in_array($statusItem, ['DEVOLVIDO', 'CANCELADO', 'REFUNDED', 'EM_DEVOLUCAO']);

            if (!$txid) {
                $this->logMessage("WARNING: Notificação de Pix sem TXID ignorada.");
                continue;
            }

            $this->logMessage("PROCESSING TXID: {$txid} | Tipo: " . ($hasDevolucao ? 'ESTORNO / DEVOLUÇÃO' : 'PAGAMENTO') . " | Valor: R$ {$valorPago} | E2E: {$e2eId}");

            // Busca pedido no cache local pelo TXID
            $pixRecord = $this->pixRepository->getByTxid($txid);
            $orderNumber = $pixRecord['order_number'] ?? null;

            // Se não encontrou no cache pelo TXID, tenta extrair o número do pedido via solicitacaoPagador
            if (!$orderNumber && isset($pixItem['solicitacaoPagador'])) {
                if (preg_match('/#(\d+)/', $pixItem['solicitacaoPagador'], $matches)) {
                    $orderNumber = (int) $matches[1];
                }
            }

            if (!$orderNumber) {
                $this->logMessage("ERROR: Não foi possível associar o TXID '{$txid}' a nenhum número de pedido na Loja Integrada.");
                $processed[] = [
                    'txid' => $txid,
                    'status' => 'error',
                    'reason' => 'order_not_found'
                ];
                continue;
            }

            $liAction = 'none';
            $liResponse = null;

            // =========================================================================
            // FLUXO 1: ESTORNO / DEVOLUÇÃO DO PIX -> Atualiza para 'pedido_cancelado'
            // =========================================================================
            if ($hasDevolucao) {
                // Atualiza o status local no cache para REFUNDED
                $this->pixRepository->updateStatus($txid, 'REFUNDED', [
                    'refunded_at' => $horario,
                    'devolucoes'  => $pixItem['devolucoes'] ?? $pixItem['devolucao'] ?? []
                ]);

                try {
                    $orderData = $this->liService->getOrderByNumber($orderNumber);
                    $situacaoAtual = strtolower($orderData['situacao']['codigo'] ?? '');
                    $situacaoNome = $orderData['situacao']['nome'] ?? 'Desconhecida';

                    $this->logMessage("LOJA INTEGRADA PEDIDO #{$orderNumber}: Situação Atual = '{$situacaoAtual}' ({$situacaoNome})");

                    if ($situacaoAtual === 'pedido_cancelado') {
                        $liAction = 'already_cancelled_skipped';
                        $this->logMessage("INFO: Pedido #{$orderNumber} JÁ CONSTA COMO CANCELADO na Loja Integrada. Nenhuma atualização necessária.");
                    } else {
                        $this->logMessage("ACTION: Pix estornado/devolvido. Atualizando situação do pedido #{$orderNumber} na Loja Integrada para 'pedido_cancelado'...");
                        $liResponse = $this->liService->updateOrderStatus($orderNumber, 'pedido_cancelado');
                        $liAction = 'updated_to_cancelled';
                        $this->logMessage("SUCCESS: Pedido #{$orderNumber} alterado para CANCELADO na Loja Integrada. Resposta API: " . json_encode($liResponse, JSON_UNESCAPED_UNICODE));
                    }
                } catch (\Throwable $e) {
                    $liAction = 'error';
                    $liResponse = ['error' => $e->getMessage()];
                    $this->logMessage("ERROR: Falha ao cancelar pedido #{$orderNumber} na Loja Integrada: " . $e->getMessage());
                }

                $processed[] = [
                    'txid'         => $txid,
                    'order_number' => $orderNumber,
                    'status'       => 'REFUNDED',
                    'action'       => $liAction,
                    'li_response'  => $liResponse
                ];
                continue;
            }

            // =========================================================================
            // FLUXO 2: CONFIRMAÇÃO DE PAGAMENTO DO PIX -> Atualiza para 'pedido_pago'
            // =========================================================================
            // Atualiza o status local para PAID
            $this->pixRepository->updateStatus($txid, 'PAID', [
                'paid_at'    => $horario,
                'e2e_id'     => $e2eId,
                'valor_pago' => $valorPago
            ]);

            try {
                // Consulta a situação atual do pedido na Loja Integrada
                $orderData = $this->liService->getOrderByNumber($orderNumber);
                $situacaoAtual = strtolower($orderData['situacao']['codigo'] ?? '');
                $situacaoNome = $orderData['situacao']['nome'] ?? 'Desconhecida';
                $isAprovado = $orderData['situacao']['aprovado'] ?? false;

                $this->logMessage("LOJA INTEGRADA PEDIDO #{$orderNumber}: Situação Atual = '{$situacaoAtual}' ({$situacaoNome}) | Aprovado = " . ($isAprovado ? 'SIM' : 'NÃO'));

                // Se o pedido JÁ ESTIVER como PAGO ou APROVADO (e não cancelado), não faz nada
                if ($situacaoAtual === 'pedido_pago' || ($isAprovado && $situacaoAtual !== 'pedido_cancelado')) {
                    $liAction = 'already_paid_skipped';
                    $this->logMessage("INFO: Pedido #{$orderNumber} JÁ CONSTA COMO PAGO na Loja Integrada. Nenhuma atualização necessária.");
                } else {
                    // Se NÃO estiver pago, chama o endpoint PUT /v1/situacao/pedido/{numero} com {"codigo": "pedido_pago"}
                    $this->logMessage("ACTION: Atualizando situação do pedido #{$orderNumber} na Loja Integrada para 'pedido_pago'...");
                    $liResponse = $this->liService->updateOrderStatus($orderNumber, 'pedido_pago');
                    $liAction = 'updated_to_paid';
                    $this->logMessage("SUCCESS: Pedido #{$orderNumber} atualizado na Loja Integrada. Resposta API: " . json_encode($liResponse, JSON_UNESCAPED_UNICODE));
                }

            } catch (\Throwable $e) {
                $liAction = 'error';
                $liResponse = ['error' => $e->getMessage()];
                $this->logMessage("ERROR: Falha ao atualizar pedido #{$orderNumber} na Loja Integrada: " . $e->getMessage());
            }

            $processed[] = [
                'txid'         => $txid,
                'order_number' => $orderNumber,
                'status'       => 'PAID',
                'action'       => $liAction,
                'li_response'  => $liResponse
            ];
        }

        $this->logMessage("================================================================================\n");

        http_response_code(200);
        echo json_encode([
            'success'   => true,
            'message'   => 'Webhook processado e registrado no log com sucesso!',
            'processed' => $processed
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Endpoint para consultar os Logs de Webhooks (GET /api/webhook/logs)
     */
    public function getLogs(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!file_exists($this->logFilePath)) {
            echo json_encode(['success' => true, 'logs' => 'Nenhum log registrado ainda.']);
            return;
        }

        $content = file_get_contents($this->logFilePath);
        $lines = array_filter(explode("\n", $content));
        $recentLines = array_slice($lines, -150); // Devolve as últimas 150 linhas

        echo json_encode([
            'success' => true,
            'total_lines' => count($lines),
            'logs' => implode("\n", $recentLines)
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Endpoint para Listar / Consultar Webhooks cadastrados no Inter (GET /api/webhooks)
     */
    public function listWebhooks(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $result = $this->interService->getWebhook();
            echo json_encode([
                'success' => true,
                'data'    => $result
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Endpoint para Cadastrar/Atualizar Webhook no Inter (POST /api/webhooks)
     */
    public function registerWebhook(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $url = $input['webhookUrl'] ?? $_POST['webhookUrl'] ?? $_GET['webhookUrl'] ?? null;
        $chave = $input['chave'] ?? $_POST['chave'] ?? $_GET['chave'] ?? null;

        if (empty($url)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'O campo webhookUrl é obrigatório'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $result = $this->interService->registerWebhook($url, $chave);
            $this->logMessage("WEBHOOK REGISTERED: URL={$url}");
            echo json_encode([
                'success' => true,
                'message' => 'Webhook cadastrado com sucesso no Banco Inter!',
                'data'    => $result
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao cadastrar webhook: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Endpoint para Remover Webhook do Inter (DELETE /api/webhooks)
     */
    public function deleteWebhook(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $chave = $input['chave'] ?? $_GET['chave'] ?? null;

        try {
            $result = $this->interService->deleteWebhook($chave);
            $this->logMessage("WEBHOOK DELETED for key: " . ($chave ?: 'default'));
            echo json_encode([
                'success' => true,
                'message' => 'Webhook removido com sucesso do Banco Inter!',
                'data'    => $result
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao remover webhook: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}

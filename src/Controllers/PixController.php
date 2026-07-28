<?php

namespace App\Controllers;

use App\Services\InterPixService;
use App\Services\LojaIntegradaService;
use App\Services\PixCacheRepository;

class PixController
{
    private InterPixService $interService;
    private LojaIntegradaService $liService;
    private PixCacheRepository $pixRepository;

    public function __construct(
        InterPixService $interService,
        LojaIntegradaService $liService,
        PixCacheRepository $pixRepository
    ) {
        $this->interService = $interService;
        $this->liService = $liService;
        $this->pixRepository = $pixRepository;
    }

    /**
     * Endpoint Inteligente para Geração e Consulta de Pix por Número de Pedido
     */
    public function generate(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // Pega o número do pedido via GET ou POST JSON
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $orderNumber = $_GET['numero'] ?? $input['numero'] ?? $_GET['pedido'] ?? $input['pedido'] ?? null;

        if (empty($orderNumber)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Número do pedido é obrigatório. Envie o parâmetro ?numero=165 ou no body JSON {"numero": 165}.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $orderNumber = (int) $orderNumber;

        try {
            // 1. Verifica no Cache Local se já existe um Pix ATIVO para este pedido
            $cachedPix = $this->pixRepository->getActivePixForOrder($orderNumber);

            if ($cachedPix !== null) {
                if (empty($cachedPix['qr_code_url']) && !empty($cachedPix['pix_copy_paste'])) {
                    $cachedPix['qr_code_url'] = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($cachedPix['pix_copy_paste']);
                }
                http_response_code(200);
                echo json_encode([
                    'success'    => true,
                    'from_cache' => true,
                    'message'    => 'Pix ativo recuperado do cache inteligente.',
                    'data'       => $cachedPix
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                return;
            }

            // 2. Se não existir ou estiver expirado, busca os dados completos do pedido na Loja Integrada
            $orderData = $this->liService->getOrderByNumber($orderNumber);

            // 3. Gera um TXID único de 26 a 35 caracteres
            $txid = InterPixService::generateTxid($orderNumber);

            // 4. Gera a Cobrança Pix com Vencimento no Banco Inter (24 Horas)
            $interCobv = $this->interService->createCobv($txid, $orderData, 24);

            // 5. Monta o registro padronizado para o cache
            $expirationTimestamp = time() + (24 * 3600);
            $expiresAtFormatted = date('Y-m-d H:i:s', $expirationTimestamp);
            $pixCopyPaste = $interCobv['pixCopiaECola'] ?? $interCobv['brcode'] ?? '';
            $qrCodeUrl = !empty($pixCopyPaste) 
                ? 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($pixCopyPaste)
                : '';

            $pixRecord = [
                'order_number'    => $orderNumber,
                'txid'            => $txid,
                'pix_copy_paste'  => $pixCopyPaste,
                'qr_code_url'     => $qrCodeUrl,
                'location'        => $interCobv['loc']['location'] ?? $interCobv['location'] ?? '',
                'status'          => 'PENDING',
                'expires_at'      => $expiresAtFormatted,
                'valor_total'     => $orderData['valor_total'] ?? '0.00',
                'cliente' => [
                    'nome' => $orderData['cliente']['nome'] ?? $orderData['endereco_entrega']['nome'] ?? '',
                    'email' => $orderData['cliente']['email'] ?? '',
                    'cpf_cnpj' => $orderData['cliente']['cpf'] ?? $orderData['cliente']['cnpj'] ?? ''
                ],
                'inter_response'  => $interCobv,
                'created_at'      => date('Y-m-d H:i:s')
            ];

            // 6. Armazena no repositório local
            $this->pixRepository->save($pixRecord);

            // 7. Retorna a resposta
            http_response_code(201);
            echo json_encode([
                'success'    => true,
                'from_cache' => false,
                'message'    => 'Pix gerado com sucesso no Banco Inter!',
                'data'       => $pixRecord
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao processar Pix: ' . $e->getMessage(),
                'trace'   => $e->getFile() . ':' . $e->getLine()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Lista todos os Pix salvos no histórico
     */
    public function listAll(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $records = array_reverse($this->pixRepository->getAll());
        echo json_encode([
            'success' => true,
            'total'   => count($records),
            'data'    => $records
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

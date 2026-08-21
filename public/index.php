<?php

// PSR-4 Autoload simples
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Carrega configurações da aplicação
$config = require __DIR__ . '/../config/app.php';

use App\Router;
use App\Services\InterPixService;
use App\Services\LojaIntegradaService;
use App\Services\PixCacheRepository;
use App\Services\EvolutionService;
use App\Controllers\PixController;
use App\Controllers\WebhookController;

// Instancia Serviços
$pixRepository = new PixCacheRepository($config['storage']['cache_file']);

$interService = new InterPixService(
    $config['inter']['client_id'],
    $config['inter']['client_secret'],
    $config['inter']['pix_key'],
    $config['inter']['base_url'],
    $config['storage']['token_file'],
    $config['inter']['cert_path'] ?? null,
    $config['inter']['key_path'] ?? null,
    $config['inter']['cert_passphrase'] ?? null
);

$liService = new LojaIntegradaService(
    $config['loja_integrada']['chave_api'],
    $config['loja_integrada']['chave_aplicacao'],
    $config['loja_integrada']['base_url']
);

$evolutionService = new EvolutionService(
    $config['evolution']['api_url'] ?? '',
    $config['evolution']['api_key'] ?? '',
    $config['evolution']['instance'] ?? '',
    (bool) ($config['evolution']['enabled'] ?? false),
    $config['evolution']['template_file'] ?? ''
);

// Instancia Controllers
$pixController = new PixController($interService, $liService, $pixRepository, $evolutionService);
$webhookController = new WebhookController($interService, $liService, $pixRepository, $evolutionService);

// Configura Roteador
$router = new Router();

// Rotas da API Pix
$router->get('/api/pix/generate', [$pixController, 'generate']);
$router->post('/api/pix/generate', [$pixController, 'generate']);
$router->get('/api/pix/list', [$pixController, 'listAll']);

// Rotas de Webhook Banco Inter
$router->post('/api/webhook/inter', [$webhookController, 'handleNotification']);
$router->get('/api/webhook/logs', [$webhookController, 'getLogs']);
$router->get('/api/webhooks', [$webhookController, 'listWebhooks']);
$router->post('/api/webhooks', [$webhookController, 'registerWebhook']);
$router->delete('/api/webhooks', [$webhookController, 'deleteWebhook']);

// Rotas de Webhook Loja Integrada
$router->post('/api/webhook/loja-integrada', [$webhookController, 'handleLojaIntegradaOrderWebhook']);
$router->get('/api/webhook/li-logs', [$webhookController, 'getLiLogs']);

// Rota de Interface Web (Painel de Controle)
$router->get('/', function () use ($config) {
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Painel Pix Inteligente - Banco Inter & Loja Integrada</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            :root {
                --bg-main: #0b0f19;
                --bg-card: rgba(22, 31, 49, 0.7);
                --bg-card-hover: rgba(30, 42, 66, 0.8);
                --border-color: rgba(255, 255, 255, 0.08);
                --primary: #ff7a00;
                --primary-gradient: linear-gradient(135deg, #ff7a00 0%, #ff5500 100%);
                --accent-blue: #3b82f6;
                --accent-green: #10b981;
                --text-main: #f3f4f6;
                --text-muted: #9ca3af;
            }

            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
                font-family: 'Inter', sans-serif;
            }

            body {
                background-color: var(--bg-main);
                background-image: 
                    radial-gradient(at 10% 10%, rgba(255, 122, 0, 0.12) 0px, transparent 50%),
                    radial-gradient(at 90% 90%, rgba(59, 130, 246, 0.1) 0px, transparent 50%);
                color: var(--text-main);
                min-height: 100vh;
                padding: 2rem 1rem;
            }

            .container {
                max-width: 1200px;
                margin: 0 auto;
            }

            header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 2rem;
                padding-bottom: 1.5rem;
                border-bottom: 1px solid var(--border-color);
            }

            .logo {
                display: flex;
                align-items: center;
                gap: 0.75rem;
            }

            .logo-icon {
                width: 44px;
                height: 44px;
                background: var(--primary-gradient);
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.5rem;
                box-shadow: 0 8px 20px rgba(255, 122, 0, 0.3);
            }

            .logo h1 {
                font-size: 1.35rem;
                font-weight: 700;
                letter-spacing: -0.5px;
            }

            .logo h1 span {
                color: var(--primary);
            }

            .badge-env {
                background: rgba(255, 122, 0, 0.15);
                color: var(--primary);
                border: 1px solid rgba(255, 122, 0, 0.3);
                padding: 0.35rem 0.75rem;
                border-radius: 20px;
                font-size: 0.8rem;
                font-weight: 600;
            }

            .grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1.5rem;
                margin-bottom: 2rem;
            }

            @media (max-width: 900px) {
                .grid { grid-template-columns: 1fr; }
            }

            .card {
                background: var(--bg-card);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                border: 1px solid var(--border-color);
                border-radius: 16px;
                padding: 1.5rem;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            }

            .card-title {
                font-size: 1.1rem;
                font-weight: 600;
                margin-bottom: 1rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            .form-group {
                margin-bottom: 1rem;
            }

            label {
                display: block;
                font-size: 0.85rem;
                color: var(--text-muted);
                margin-bottom: 0.5rem;
                font-weight: 500;
            }

            input[type="text"], input[type="number"], input[type="url"] {
                width: 100%;
                padding: 0.75rem 1rem;
                background: rgba(10, 15, 25, 0.6);
                border: 1px solid var(--border-color);
                border-radius: 10px;
                color: #fff;
                font-size: 0.95rem;
                transition: all 0.2s ease;
            }

            input:focus {
                outline: none;
                border-color: var(--primary);
                box-shadow: 0 0 0 3px rgba(255, 122, 0, 0.2);
            }

            .btn {
                width: 100%;
                padding: 0.85rem 1.25rem;
                background: var(--primary-gradient);
                border: none;
                border-radius: 10px;
                color: #fff;
                font-weight: 600;
                font-size: 0.95rem;
                cursor: pointer;
                transition: all 0.2s ease;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
            }

            .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(255, 122, 0, 0.4);
            }

            .btn-secondary {
                background: rgba(255, 255, 255, 0.08);
                border: 1px solid var(--border-color);
            }

            .btn-secondary:hover {
                background: rgba(255, 255, 255, 0.15);
                box-shadow: none;
            }

            .btn-danger {
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            }

            .result-box {
                margin-top: 1.25rem;
                background: rgba(0, 0, 0, 0.4);
                border: 1px solid var(--border-color);
                border-radius: 10px;
                padding: 1rem;
                font-size: 0.85rem;
                display: none;
            }

            .result-box.active {
                display: block;
            }

            .status-badge {
                display: inline-block;
                padding: 0.25rem 0.6rem;
                border-radius: 6px;
                font-size: 0.75rem;
                font-weight: 700;
                text-transform: uppercase;
            }

            .status-cache { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
            .status-new { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
            .status-paid { background: rgba(16, 185, 129, 0.25); color: #34d399; }
            .status-pending { background: rgba(245, 158, 11, 0.25); color: #fbbf24; }

            .pix-payload {
                background: #050811;
                border: 1px dashed var(--border-color);
                padding: 0.75rem;
                border-radius: 8px;
                word-break: break-all;
                font-family: monospace;
                font-size: 0.8rem;
                color: #a7f3d0;
                margin: 0.75rem 0;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 1rem;
            }

            th, td {
                padding: 0.85rem 1rem;
                text-align: left;
                border-bottom: 1px solid var(--border-color);
                font-size: 0.9rem;
            }

            th {
                color: var(--text-muted);
                font-weight: 500;
            }

            tr:hover td {
                background: rgba(255, 255, 255, 0.02);
            }

            .copy-btn {
                background: rgba(255, 255, 255, 0.1);
                border: none;
                color: #fff;
                padding: 0.4rem 0.75rem;
                border-radius: 6px;
                cursor: pointer;
                font-size: 0.75rem;
            }

            .copy-btn:hover {
                background: var(--primary);
            }
        </style>
    </head>
    <body>
        <div class="container">
            <header>
                <div class="logo">
                    <div class="logo-icon">⚡</div>
                    <div>
                        <h1>Inter <span>Pix</span> Inteligente</h1>
                        <p style="font-size:0.8rem; color:var(--text-muted)">Integração Banco Inter & Loja Integrada</p>
                    </div>
                </div>
                <div class="badge-env">
                    Ambiente: <?= strtoupper($config['inter']['env']) ?>
                </div>
            </header>

            <div class="grid">
                <!-- Card Geração Pix -->
                <div class="card">
                    <div class="card-title">
                        <span>📲</span> Gerar ou Consultar Pix
                    </div>
                    <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom: 1rem;">
                        Digitar o número do pedido da Loja Integrada (ex: 165). Se o Pix tiver menos de 24h, o sistema reaproveita o mesmo Pix sem chamar a API do Inter!
                    </p>
                    <div class="form-group">
                        <label for="inputOrder">Número do Pedido (LI)</label>
                        <input type="number" id="inputOrder" placeholder="Ex: 165" value="165">
                    </div>
                    <button class="btn" onclick="generatePix()">⚡ Gerar Pix Inteligente</button>

                    <div id="pixResult" class="result-box"></div>
                </div>

                <!-- Card Webhooks -->
                <div class="card">
                    <div class="card-title">
                        <span>🔗</span> Gerenciador de Webhooks Banco Inter
                    </div>
                    <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom: 1rem;">
                        Cadastre ou remova o endpoint do Webhook no Banco Inter para receber atualizações de pagamento em tempo real.
                    </p>
                    <div class="form-group">
                        <label for="webhookUrl">URL do Webhook do seu sistema</label>
                        <input type="url" id="webhookUrl" placeholder="https://seu-dominio.com/api/webhook/inter">
                    </div>
                    <div style="display:flex; gap:0.5rem; flex-wrap: wrap;">
                        <button class="btn" onclick="registerWebhook()" style="flex:1">Cadastrar</button>
                        <button class="btn btn-secondary" onclick="loadWebhook()" style="flex:1">Consultar</button>
                        <button class="btn btn-secondary" onclick="viewLogs()" style="flex:1">📜 Ver Logs</button>
                        <button class="btn btn-danger" onclick="deleteWebhook()" style="flex:1">Remover</button>
                    </div>

                    <div id="webhookResult" class="result-box"></div>
                </div>
            </div>

            <!-- Card Tabela de Histórico -->
            <div class="card">
                <div class="card-title" style="justify-content: space-between;">
                    <div><span>📋</span> Cobranças Pix Geradas no Cache</div>
                    <button class="btn btn-secondary" style="width: auto; padding: 0.4rem 0.8rem; font-size:0.8rem;" onclick="loadHistory()">🔄 Atualizar</button>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Pedido #</th>
                                <th>TXID</th>
                                <th>Cliente</th>
                                <th>Valor Total</th>
                                <th>Status</th>
                                <th>Expira Em</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody id="historyTable">
                            <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">Carregando histórico...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
            async function generatePix() {
                const order = document.getElementById('inputOrder').value;
                const resultDiv = document.getElementById('pixResult');
                if (!order) return alert('Informe o número do pedido');

                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '⌛ Consultando/Gerando Pix...';

                try {
                    const res = await fetch(`/api/pix/generate?numero=${order}`);
                    const data = await res.json();

                    if (!data.success) {
                        resultDiv.innerHTML = `<div style="color:#ef4444">❌ Erro: ${data.message}</div>`;
                        return;
                    }

                    const record = data.data;
                    const cacheBadge = data.from_cache 
                        ? `<span class="status-badge status-cache">⚡ Do Cache (Sem duplicar no Inter)</span>` 
                        : `<span class="status-badge status-new">🟢 Novo Pix Gerado no Inter</span>`;

                    resultDiv.innerHTML = `
                        <div style="margin-bottom:0.5rem">${cacheBadge}</div>
                        <div><strong>Pedido:</strong> #${record.order_number}</div>
                        <div><strong>Cliente:</strong> ${record.cliente?.nome || 'N/I'}</div>
                        <div><strong>Valor:</strong> R$ ${record.valor_total}</div>
                        <div><strong>Expira em:</strong> ${record.expires_at}</div>
                        ${record.qr_code_url ? `<div style="text-align:center; margin: 1rem 0;"><img src="${record.qr_code_url}" alt="QR Code Pix" style="width:200px; height:200px; border-radius:12px; border:2px solid var(--primary); background:#fff; padding:8px; box-shadow: 0 8px 25px rgba(0,0,0,0.5);" /></div>` : ''}
                        <div><strong>Copia e Cola:</strong></div>
                        <div class="pix-payload" id="copiaColaText">${record.pix_copy_paste || 'N/A'}</div>
                        <button class="copy-btn" onclick="navigator.clipboard.writeText('${record.pix_copy_paste}')">📋 Copiar Pix Copia e Cola</button>
                    `;
                    loadHistory();
                } catch (e) {
                    resultDiv.innerHTML = `<div style="color:#ef4444">❌ Erro na requisição: ${e.message}</div>`;
                }
            }

            async function registerWebhook() {
                const url = document.getElementById('webhookUrl').value;
                const resultDiv = document.getElementById('webhookResult');
                if (!url) return alert('Informe a URL do webhook');

                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '⌛ Cadastrando no Banco Inter...';

                try {
                    const res = await fetch('/api/webhooks', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ webhookUrl: url })
                    });
                    const data = await res.json();
                    resultDiv.innerHTML = data.success 
                        ? `<div style="color:#10b981">✅ ${data.message}</div><pre>${JSON.stringify(data.data, null, 2)}</pre>`
                        : `<div style="color:#ef4444">❌ Erro: ${data.message}</div>`;
                } catch (e) {
                    resultDiv.innerHTML = `<div style="color:#ef4444">❌ Erro: ${e.message}</div>`;
                }
            }

            async function loadWebhook() {
                const resultDiv = document.getElementById('webhookResult');
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '⌛ Consultando webhooks no Banco Inter...';
                try {
                    const res = await fetch('/api/webhooks');
                    const data = await res.json();
                    resultDiv.innerHTML = `<pre style="color:#a7f3d0">${JSON.stringify(data, null, 2)}</pre>`;
                } catch (e) {
                    resultDiv.innerHTML = `<div style="color:#ef4444">❌ Erro: ${e.message}</div>`;
                }
            }

            async function viewLogs() {
                const resultDiv = document.getElementById('webhookResult');
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '⌛ Carregando logs de auditoria dos webhooks...';
                try {
                    const res = await fetch('/api/webhook/logs');
                    const data = await res.json();
                    resultDiv.innerHTML = `<div style="font-weight:600; margin-bottom:0.5rem; color:var(--primary);">📜 Logs de Auditoria de Webhooks (storage/logs/webhooks.log):</div><pre style="color:#a7f3d0; max-height:300px; overflow-y:auto; font-size:0.75rem; background:#050811; padding:0.75rem; border-radius:8px; white-space:pre-wrap;">${data.logs || 'Sem logs registrados.'}</pre>`;
                } catch (e) {
                    resultDiv.innerHTML = `<div style="color:#ef4444">❌ Erro ao buscar logs: ${e.message}</div>`;
                }
            }

            async function deleteWebhook() {
                if (!confirm('Deseja remover o Webhook cadastrado no Banco Inter?')) return;
                const resultDiv = document.getElementById('webhookResult');
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '⌛ Removendo webhook...';
                try {
                    const res = await fetch('/api/webhooks', { method: 'DELETE' });
                    const data = await res.json();
                    resultDiv.innerHTML = `<div style="color:#10b981">✅ ${data.message}</div>`;
                } catch (e) {
                    resultDiv.innerHTML = `<div style="color:#ef4444">❌ Erro: ${e.message}</div>`;
                }
            }

            async function loadHistory() {
                try {
                    const res = await fetch('/api/pix/list');
                    const data = await res.json();
                    const table = document.getElementById('historyTable');
                    
                    if (!data.data || data.data.length === 0) {
                        table.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--text-muted);">Nenhum Pix gerado até o momento.</td></tr>';
                        return;
                    }

                    table.innerHTML = data.data.map(item => {
                        const statusClass = item.status === 'PAID' ? 'status-paid' : 'status-pending';
                        return `
                            <tr>
                                <td><strong>#${item.order_number}</strong></td>
                                <td style="font-family:monospace; font-size:0.8rem">${item.txid.substring(0, 12)}...</td>
                                <td>${item.cliente?.nome || 'Cliente'}</td>
                                <td>R$ ${item.valor_total}</td>
                                <td><span class="status-badge ${statusClass}">${item.status}</span></td>
                                <td style="font-size:0.8rem">${item.expires_at}</td>
                                <td>
                                    <button class="copy-btn" onclick="navigator.clipboard.writeText('${item.pix_copy_paste}')">Copiar Pix</button>
                                </td>
                            </tr>
                        `;
                    }).join('');
                } catch (e) {
                    console.error('Erro ao carregar histórico', e);
                }
            }

            // Carrega o histórico na abertura
            loadHistory();
        </script>
    </body>
    </html>
    <?php
});

// Executa o roteamento
$router->dispatch();

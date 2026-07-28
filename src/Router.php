<?php

namespace App;

class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable|array $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, callable|array $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable|array $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'path'    => '/' . trim($path, '/'),
            'handler' => $handler
        ];
    }

    public function dispatch(): void
    {
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';

        // Trava de segurança no nível da aplicação: NUNCA permite servir .env, storage, certs, config ou src
        if (preg_match('#(\.env|\.key|\.crt|/storage/|/config/|/src/)#i', $rawUri)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => '403 Forbidden: Acesso a dados sensíveis negado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $uri = parse_url($rawUri, PHP_URL_PATH);
        $uri = str_replace('\\', '/', $uri);

        // Normaliza o diretório do script removendo /index.php e /public do final
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir = dirname($scriptName);
        $scriptDir = preg_replace('#/public$#', '', $scriptDir);

        if (!empty($scriptDir) && $scriptDir !== '/' && $scriptDir !== '.' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }

        // Remove prefixo /public se ainda constar na URI
        if (str_starts_with($uri, '/public')) {
            $uri = substr($uri, 7);
        }

        $uri = '/' . trim($uri, '/');

        // Habilita cabeçalhos CORS globais para permitir chamadas de qualquer loja/front-end
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept');

        // Suporte para requisições de preflight (OPTIONS)
        if ($requestMethod === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] === $requestMethod && $route['path'] === $uri) {
                $handler = $route['handler'];

                if (is_array($handler)) {
                    list($class, $method) = $handler;
                    if (is_string($class)) {
                        $instance = new $class();
                        $instance->$method();
                    } else {
                        $class->$method();
                    }
                } else {
                    call_user_func($handler);
                }
                return;
            }
        }

        // Se nenhuma rota for encontrada
        if (str_starts_with($uri, '/api')) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(404);
            echo json_encode([
                'success'     => false,
                'message'     => 'Rota da API não encontrada',
                'parsed_path' => $uri,
                'raw_uri'     => $_SERVER['REQUEST_URI'] ?? '',
                'script_name' => $_SERVER['SCRIPT_NAME'] ?? ''
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            http_response_code(404);
            echo "<h1>404 - Página Não Encontrada</h1><p>Rota solicitada: " . htmlspecialchars($uri) . "</p>";
        }
    }
}

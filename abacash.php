<?php
// abacash.php - API Backend para integração com Abacash

// ==================== CONFIGURAÇÕES ====================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

error_log("abacash.php carregado em " . date('Y-m-d H:i:s'));

define('ABACASH_SECRET_KEY', getenv('ABACASH_SECRET_KEY') ?: 'SUA_SECRET_KEY');
define('ABACASH_BASE_URL', 'https://app.abacash.com/api/payment.php');

// Configuração do MySQL no Railway
define('DB_HOST', getenv('MYSQLHOST') ?: 'metro.proxy.rlw.y.net');
define('DB_PORT', getenv('MYSQLPORT') ?: '17165');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'railway');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: 'sua_senha');

// ==================== FUNÇÕES AUXILIARES ====================
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function getPDO() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::MYSQL_ATTR_SSL_CA => null,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Erro de conexão MySQL: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Erro de conexão com banco de dados'], 500);
        }
    }
    return $pdo;
}

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function callAbacashAPI($data) {
    $ch = curl_init(ABACASH_BASE_URL);
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . ABACASH_SECRET_KEY
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_error($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'message' => 'Erro na comunicação com Abacash: ' . $error];
    }
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'message' => 'Erro na comunicação com Abacash'];
    }

    $decoded = json_decode($response, true);
    
    if ($httpCode >= 400 || !$decoded) {
        return ['success' => false, 'message' => 'Erro na API Abacash', 'response' => $response];
    }

    return ['success' => true, 'data' => $decoded];
}

// ==================== ROTEAMENTO ====================
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$basePath = '/abacash.php';
if (strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}
if ($path === '') $path = '/';

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Rota raiz para health check
if ($path === '/') {
    jsonResponse(['success' => true, 'message' => 'API Abacash funcionando!'], 200);
}

// Roteamento principal
switch ($path) {
    case '/api/create-payment':
        if ($requestMethod !== 'POST') {
            jsonResponse(['success' => false, 'message' => 'Método não permitido'], 405);
        }
        handleCreatePayment();
        break;

    case '/api/check-status':
        if ($requestMethod !== 'POST') {
            jsonResponse(['success' => false, 'message' => 'Método não permitido'], 405);
        }
        handleCheckStatus();
        break;

    case '/webhook/abacash':
        if ($requestMethod !== 'POST') {
            jsonResponse(['success' => false, 'message' => 'Método não permitido'], 405);
        }
        handleWebhook();
        break;

    case '/test-db':
        testDatabaseConnection();
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Rota não encontrada: ' . $path], 404);
}

// ==================== HANDLERS ====================
function handleCreatePayment() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['success' => false, 'message' => 'JSON inválido'], 400);
    }

    // Validações
    $productId = $input['product_id'] ?? '';
    if (empty($productId)) {
        jsonResponse(['success' => false, 'message' => 'product_id é obrigatório'], 422);
    }

    $amount = $input['amount'] ?? null;
    $customer = $input['customer'] ?? null;

    // Validar customer se fornecido
    if ($customer) {
        if (empty($customer['name']) || empty($customer['email'])) {
            jsonResponse(['success' => false, 'message' => 'customer deve conter name e email'], 422);
        }
    }

    // Montar payload para Abacash
    $payload = [
        'action' => 'create',
        'product_id' => $productId
    ];

    if ($amount !== null) {
        $payload['amount'] = floatval($amount);
    }

    if ($customer) {
        $payload['customer'] = [
            'name' => $customer['name'],
            'email' => $customer['email']
        ];
        if (!empty($customer['cpf'])) {
            $payload['customer']['cpf'] = preg_replace('/\D/', '', $customer['cpf']);
        }
    }

    // Chamar API Abacash
    $response = callAbacashAPI($payload);

    if (!$response['success']) {
        jsonResponse(['success' => false, 'message' => $response['message']], 500);
    }

    $data = $response['data'];

    // Salvar no banco de dados
    $pdo = getPDO();
    $localId = generateUUID();
    
    $sql = "INSERT INTO abacash_payments 
            (id, payment_id, product_id, amount, customer_name, customer_email, customer_cpf, status, created_at) 
            VALUES 
            (:id, :payment_id, :product_id, :amount, :customer_name, :customer_email, :customer_cpf, :status, NOW())";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $localId,
        ':payment_id' => $data['payment_id'] ?? $data['data']['payment_id'] ?? null,
        ':product_id' => $productId,
        ':amount' => $amount ?? 0,
        ':customer_name' => $customer['name'] ?? null,
        ':customer_email' => $customer['email'] ?? null,
        ':customer_cpf' => $customer['cpf'] ?? null,
        ':status' => $data['data']['status'] ?? 'pending'
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'Pagamento criado com sucesso',
        'data' => [
            'id' => $localId,
            'payment_id' => $data['payment_id'] ?? $data['data']['payment_id'],
            'status' => $data['data']['status'] ?? 'pending',
            'redirect_url' => $data['data']['redirect_url'] ?? null,
            'qr_code' => $data['data']['qr_code'] ?? null
        ]
    ], 201);
}

function handleCheckStatus() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['success' => false, 'message' => 'JSON inválido'], 400);
    }

    $paymentId = $input['payment_id'] ?? $input['id'] ?? '';
    if (empty($paymentId)) {
        jsonResponse(['success' => false, 'message' => 'payment_id é obrigatório'], 422);
    }

    // Opção 1: Polling na Abacash
    $payload = [
        'action' => 'check_status',
        'payment_id' => $paymentId
    ];

    $response = callAbacashAPI($payload);

    if (!$response['success']) {
        jsonResponse(['success' => false, 'message' => $response['message']], 500);
    }

    $data = $response['data'];

    // Atualizar banco de dados se necessário
    if (isset($data['data']['status'])) {
        $pdo = getPDO();
        $stmt = $pdo->prepare("UPDATE abacash_payments SET status = ?, updated_at = NOW() WHERE payment_id = ?");
        $stmt->execute([$data['data']['status'], $paymentId]);
    }

    jsonResponse([
        'success' => true,
        'data' => [
            'status' => $data['data']['status'] ?? 'pending',
            'redirect_url' => $data['data']['redirect_url'] ?? null
        ]
    ]);
}

function handleWebhook() {
    $payload = file_get_contents('php://input');
    
    error_log("Webhook Abacash recebido");
    error_log("Payload: " . $payload);

    $data = json_decode($payload, true);
    if (!$data) {
        jsonResponse(['success' => false, 'message' => 'Payload inválido'], 400);
    }

    $event = $data['event'] ?? '';
    $eventData = $data['data'] ?? [];

    // Abacash não usa assinatura HMAC, confiar no payload
    if ($event === 'payment.approved') {
        $paymentId = $eventData['payment_id'] ?? $eventData['external_id'] ?? null;
        
        if ($paymentId) {
            $pdo = getPDO();
            $stmt = $pdo->prepare("UPDATE abacash_payments SET status = 'completed', confirmed_at = NOW(), updated_at = NOW() WHERE payment_id = ?");
            $stmt->execute([$paymentId]);
            
            error_log("Pagamento $paymentId confirmado via webhook");
        }
    }

    jsonResponse(['success' => true, 'received' => true]);
}

function testDatabaseConnection() {
    try {
        $pdo = getPDO();
        $result = $pdo->query("SHOW TABLES LIKE 'abacash_payments'");
        $tableExists = $result->rowCount() > 0;
        
        $response = [
            'success' => true,
            'message' => 'Conexão com banco de dados OK',
            'data' => [
                'connected' => true,
                'table_exists' => $tableExists
            ]
        ];
        
        if (!$tableExists) {
            $response['message'] .= ' (Tabela abacash_payments não encontrada)';
        }
        
        jsonResponse($response);
    } catch (Exception $e) {
        jsonResponse([
            'success' => false,
            'message' => 'Erro ao conectar com banco de dados',
            'error' => $e->getMessage()
        ], 500);
    }
}

// Criar tabela automaticamente se não existir
function createTableIfNotExists() {
    try {
        $pdo = getPDO();
        $sql = "CREATE TABLE IF NOT EXISTS abacash_payments (
            id CHAR(36) PRIMARY KEY,
            payment_id VARCHAR(100),
            product_id VARCHAR(100) NOT NULL,
            amount DECIMAL(10,2),
            customer_name VARCHAR(255),
            customer_email VARCHAR(255),
            customer_cpf VARCHAR(14),
            status ENUM('pending', 'completed', 'expired', 'cancelled') DEFAULT 'pending' NOT NULL,
            redirect_url VARCHAR(500),
            created_at DATETIME NOT NULL,
            confirmed_at DATETIME,
            updated_at DATETIME,
            INDEX idx_payment_id (payment_id),
            INDEX idx_product_id (product_id),
            INDEX idx_status (status),
            INDEX idx_customer_email (customer_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $pdo->exec($sql);
        error_log("Tabela abacash_payments verificada/criada com sucesso");
    } catch (Exception $e) {
        error_log("Erro ao criar tabela: " . $e->getMessage());
    }
}

// Chamar a criação da tabela
createTableIfNotExists();

<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

session_set_cookie_params([
    'lifetime' => 315360000,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = $_POST['action'] ?? '';
$username = trim((string)($_POST['nome_de_usuario'] ?? ''));
$password = (string)($_POST['senha'] ?? '');

if (!in_array($action, ['login', 'register'], true)) {
    respond(422, ['success' => false, 'message' => 'Ação inválida.']);
}

if ($username === '' || $password === '') {
    respond(422, ['success' => false, 'message' => 'Usuário e senha são obrigatórios.']);
}

if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
    respond(422, ['success' => false, 'message' => 'Nome de usuário deve ter entre 3 e 50 caracteres.']);
}

if (mb_strlen($password) < 8) {
    respond(422, ['success' => false, 'message' => 'Senha deve ter pelo menos 8 caracteres.']);
}

$pdo = db();

if ($action === 'register') {
    $checkStmt = $pdo->prepare('SELECT id FROM usuarios WHERE nome_de_usuario = :username LIMIT 1');
    $checkStmt->execute(['username' => $username]);

    if ($checkStmt->fetch()) {
        respond(409, ['success' => false, 'message' => 'Nome de usuário já existe.']);
    }

    $hash = password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
        'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
        'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
    ]);

    if ($hash === false) {
        respond(500, ['success' => false, 'message' => 'Falha ao proteger senha.']);
    }

    $insertStmt = $pdo->prepare('INSERT INTO usuarios (nome_de_usuario, senha_hash) VALUES (:username, :hash)');
    $insertStmt->execute([
        'username' => $username,
        'hash' => $hash,
    ]);

    respond(201, ['success' => true, 'message' => 'Conta criada com sucesso. Faça login.']);
}

$loginStmt = $pdo->prepare('SELECT id, nome_de_usuario, senha_hash FROM usuarios WHERE nome_de_usuario = :username LIMIT 1');
$loginStmt->execute(['username' => $username]);
$user = $loginStmt->fetch();

if (!$user || !password_verify($password, (string)$user['senha_hash'])) {
    respond(401, ['success' => false, 'message' => 'Credenciais inválidas.']);
}

if (password_needs_rehash((string)$user['senha_hash'], PASSWORD_ARGON2ID)) {
    $newHash = password_hash($password, PASSWORD_ARGON2ID);
    if ($newHash !== false) {
        $rehashStmt = $pdo->prepare('UPDATE usuarios SET senha_hash = :hash WHERE id = :id');
        $rehashStmt->execute([
            'hash' => $newHash,
            'id' => $user['id'],
        ]);
    }
}

session_regenerate_id(true);
$_SESSION['usuario_id'] = (int)$user['id'];
$_SESSION['nome_de_usuario'] = (string)$user['nome_de_usuario'];

respond(200, ['success' => true, 'message' => 'Login realizado com sucesso.']);

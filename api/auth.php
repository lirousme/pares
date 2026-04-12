<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

function jsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function respond(int $statusCode, bool $success, string $message, array $extra = []): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';
$data = jsonInput();

$username = trim((string) ($data['nome_de_usuario'] ?? ''));
$password = (string) ($data['senha'] ?? '');

if ($username === '' || $password === '') {
    respond(422, false, 'Nome de usuário e senha são obrigatórios.');
}

if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
    respond(422, false, 'Nome de usuário inválido.');
}

if (mb_strlen($password) < 8) {
    respond(422, false, 'A senha deve ter no mínimo 8 caracteres.');
}

try {
    $pdo = db();

    if ($action === 'register') {
        $check = $pdo->prepare('SELECT id FROM usuarios WHERE nome_de_usuario = :username LIMIT 1');
        $check->execute(['username' => $username]);

        if ($check->fetch()) {
            respond(409, false, 'Nome de usuário já existe.');
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if ($hash === false) {
            respond(500, false, 'Falha ao gerar hash da senha.');
        }

        $insert = $pdo->prepare('INSERT INTO usuarios (nome_de_usuario, senha_hash) VALUES (:username, :hash)');
        $insert->execute([
            'username' => $username,
            'hash' => $hash,
        ]);

        respond(201, true, 'Conta criada com sucesso.');
    }

    if ($action === 'login') {
        $stmt = $pdo->prepare('SELECT id, nome_de_usuario, senha_hash FROM usuarios WHERE nome_de_usuario = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['senha_hash'])) {
            respond(401, false, 'Credenciais inválidas.');
        }

        if (password_needs_rehash($user['senha_hash'], PASSWORD_ARGON2ID)) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID);
            if ($newHash !== false) {
                $update = $pdo->prepare('UPDATE usuarios SET senha_hash = :hash WHERE id = :id');
                $update->execute([
                    'hash' => $newHash,
                    'id' => $user['id'],
                ]);
            }
        }

        startLongSession();
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['nome_de_usuario'] = $user['nome_de_usuario'];

        respond(200, true, 'Login efetuado com sucesso.', [
            'user_id' => (int) $user['id'],
            'nome_de_usuario' => $user['nome_de_usuario'],
        ]);
    }

    respond(400, false, 'Ação inválida. Use action=login ou action=register.');
} catch (Throwable $e) {
    respond(500, false, 'Erro interno no servidor.');
}

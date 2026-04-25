<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

function respond(int $statusCode, bool $success, string $message, array $extra = []): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

startLongSession();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    respond(401, false, 'Usuário não autenticado.');
}

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $stmt = $pdo->prepare('SELECT ptbr FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            respond(404, false, 'Usuário não encontrado.');
        }

        $ptbr = (int) ($user['ptbr'] ?? 1);
        $ptbrAtivo = $ptbr !== 2;

        respond(200, true, 'Preferências carregadas com sucesso.', [
            'ptbr' => $ptbrAtivo ? 1 : 2,
            'ptbr_ativo' => $ptbrAtivo,
        ]);
    }

    if ($method === 'PUT') {
        $payload = jsonInput();
        $ptbr = (int) ($payload['ptbr'] ?? 1);
        if (!in_array($ptbr, [1, 2], true)) {
            respond(422, false, 'Valor de ptbr inválido. Use 1 (ativo) ou 2 (desativado).');
        }

        $update = $pdo->prepare('UPDATE usuarios SET ptbr = :ptbr WHERE id = :id');
        $update->execute([
            'ptbr' => $ptbr,
            'id' => $userId,
        ]);

        respond(200, true, 'Preferência pt-BR atualizada com sucesso.', [
            'ptbr' => $ptbr,
            'ptbr_ativo' => $ptbr === 1,
        ]);
    }

    respond(405, false, 'Método não permitido.');
} catch (Throwable $e) {
    respond(500, false, 'Erro interno no servidor.', [
        'detail' => $e->getMessage() !== '' ? $e->getMessage() : 'Falha ao processar preferência de idioma.',
    ]);
}

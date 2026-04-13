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

startLongSession();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    respond(401, false, 'Usuário não autenticado.');
}

$idPaiParam = $_GET['id_pai'] ?? null;
$idPai = null;

if ($idPaiParam !== null && $idPaiParam !== '') {
    if (!ctype_digit((string) $idPaiParam)) {
        respond(422, false, 'Parâmetro id_pai inválido.');
    }

    $idPai = (int) $idPaiParam;
}

try {
    $pdo = db();

    if ($idPai === null) {
        $stmt = $pdo->prepare('SELECT id, nome, id_pai FROM diretorios WHERE id_usuario = :id_usuario AND id_pai IS NULL ORDER BY nome ASC');
        $stmt->execute(['id_usuario' => $userId]);
    } else {
        $stmt = $pdo->prepare('SELECT id, nome, id_pai FROM diretorios WHERE id_usuario = :id_usuario AND id_pai = :id_pai ORDER BY nome ASC');
        $stmt->execute([
            'id_usuario' => $userId,
            'id_pai' => $idPai,
        ]);
    }

    $diretorios = $stmt->fetchAll();

    respond(200, true, 'Diretórios carregados com sucesso.', [
        'id_pai' => $idPai,
        'diretorios' => $diretorios,
    ]);
} catch (Throwable $e) {
    respond(500, false, 'Erro interno no servidor.');
}

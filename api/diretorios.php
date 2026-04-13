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

function parseIdPai(?string $idPaiParam): ?int
{
    if ($idPaiParam === null || $idPaiParam === '') {
        return null;
    }

    if (!ctype_digit($idPaiParam)) {
        respond(422, false, 'Parâmetro id_pai inválido.');
    }

    return (int) $idPaiParam;
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

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = jsonInput();
        $nome = trim((string) ($payload['nome'] ?? ''));
        if ($nome === '') {
            respond(422, false, 'Nome do diretório é obrigatório.');
        }

        if (mb_strlen($nome) > 120) {
            respond(422, false, 'Nome do diretório deve ter no máximo 120 caracteres.');
        }

        $idPaiPayload = $payload['id_pai'] ?? null;
        if ($idPaiPayload === null || $idPaiPayload === '') {
            $idPai = null;
        } elseif (is_int($idPaiPayload)) {
            $idPai = $idPaiPayload;
        } elseif (is_string($idPaiPayload) && ctype_digit($idPaiPayload)) {
            $idPai = (int) $idPaiPayload;
        } else {
            respond(422, false, 'Parâmetro id_pai inválido.');
        }

        if ($idPai !== null) {
            $checkParent = $pdo->prepare('SELECT id FROM diretorios WHERE id = :id AND id_usuario = :id_usuario LIMIT 1');
            $checkParent->execute([
                'id' => $idPai,
                'id_usuario' => $userId,
            ]);

            if (!$checkParent->fetch()) {
                respond(404, false, 'Diretório pai não encontrado.');
            }
        }

        $insert = $pdo->prepare('INSERT INTO diretorios (nome, id_pai, id_usuario) VALUES (:nome, :id_pai, :id_usuario)');
        $insert->bindValue(':nome', $nome);
        $insert->bindValue(':id_usuario', $userId, PDO::PARAM_INT);
        if ($idPai === null) {
            $insert->bindValue(':id_pai', null, PDO::PARAM_NULL);
        } else {
            $insert->bindValue(':id_pai', $idPai, PDO::PARAM_INT);
        }
        $insert->execute();

        respond(201, true, 'Diretório criado com sucesso.', [
            'diretorio' => [
                'id' => (int) $pdo->lastInsertId(),
                'nome' => $nome,
                'id_pai' => $idPai,
                'id_usuario' => $userId,
            ],
        ]);
    }

    $idPai = parseIdPai(isset($_GET['id_pai']) ? (string) $_GET['id_pai'] : null);

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

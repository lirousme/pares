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

function parseTipo(mixed $tipoPayload): int
{
    if ($tipoPayload === null || $tipoPayload === '') {
        return 1;
    }

    if (is_int($tipoPayload)) {
        $tipo = $tipoPayload;
    } elseif (is_string($tipoPayload) && ctype_digit($tipoPayload)) {
        $tipo = (int) $tipoPayload;
    } else {
        respond(422, false, 'Tipo inválido.');
    }

    if ($tipo !== 1 && $tipo !== 2) {
        respond(422, false, 'Tipo inválido. Use 1 (Diretório) ou 2 (Pares).');
    }

    return $tipo;
}

function parseRequiredId(mixed $idPayload): int
{
    if (is_int($idPayload) && $idPayload > 0) {
        return $idPayload;
    }

    if (is_string($idPayload) && ctype_digit($idPayload) && (int) $idPayload > 0) {
        return (int) $idPayload;
    }

    respond(422, false, 'ID do diretório inválido.');
}

function parseTempoMeta(mixed $tempoPayload): string
{
    if (!is_string($tempoPayload)) {
        respond(422, false, 'Tempo inválido. Use Diário ou Semanal.');
    }

    $tempo = trim($tempoPayload);
    if ($tempo !== 'Diário' && $tempo !== 'Semanal') {
        respond(422, false, 'Tempo inválido. Use Diário ou Semanal.');
    }

    return $tempo;
}

function parseQuantidadeMeta(mixed $quantidadeMetaPayload): int
{
    if (is_int($quantidadeMetaPayload)) {
        $quantidadeMeta = $quantidadeMetaPayload;
    } elseif (is_string($quantidadeMetaPayload) && ctype_digit($quantidadeMetaPayload)) {
        $quantidadeMeta = (int) $quantidadeMetaPayload;
    } else {
        respond(422, false, 'Quantidade meta inválida.');
    }

    if ($quantidadeMeta < 0) {
        respond(422, false, 'Quantidade meta não pode ser negativa.');
    }

    return $quantidadeMeta;
}

function resetMetaCountersIfNeeded(PDO $pdo, int $userId, ?int $directoryId = null): void
{
    $sql = 'UPDATE diretorios
            SET quantidade_atual = 0,
                contagem_atualizada_em = NOW()
            WHERE id_usuario = :id_usuario
              AND quantidade_meta > 0
              AND ((tempo = "Diário" AND (contagem_atualizada_em IS NULL OR DATE(contagem_atualizada_em) < CURRENT_DATE()))
                OR (tempo = "Semanal" AND (contagem_atualizada_em IS NULL OR YEARWEEK(contagem_atualizada_em, 1) < YEARWEEK(CURDATE(), 1))))';

    $params = [
        'id_usuario' => $userId,
    ];

    if ($directoryId !== null) {
        $sql .= ' AND id = :id_diretorio';
        $params['id_diretorio'] = $directoryId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
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

        $tipo = parseTipo($payload['tipo'] ?? null);
        $tempo = parseTempoMeta($payload['tempo'] ?? 'Diário');
        $quantidadeMeta = parseQuantidadeMeta($payload['quantidade_meta'] ?? 0);

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

        $insert = $pdo->prepare('INSERT INTO diretorios (nome, id_pai, tipo, id_usuario, tempo, quantidade_meta, quantidade_atual, meta_atualizada_em, contagem_atualizada_em) VALUES (:nome, :id_pai, :tipo, :id_usuario, :tempo, :quantidade_meta, 0, NOW(), NOW())');
        $insert->bindValue(':nome', $nome);
        $insert->bindValue(':tipo', $tipo, PDO::PARAM_INT);
        $insert->bindValue(':id_usuario', $userId, PDO::PARAM_INT);
        $insert->bindValue(':tempo', $tempo);
        $insert->bindValue(':quantidade_meta', $quantidadeMeta, PDO::PARAM_INT);
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
                'tipo' => $tipo,
                'tempo' => $tempo,
                'quantidade_meta' => $quantidadeMeta,
                'quantidade_atual' => 0,
                'id_usuario' => $userId,
            ],
        ]);
    }

    if ($method === 'PUT') {
        $payload = jsonInput();
        $id = parseRequiredId($payload['id'] ?? null);
        $nome = trim((string) ($payload['nome'] ?? ''));
        if ($nome === '') {
            respond(422, false, 'Nome do diretório é obrigatório.');
        }

        if (mb_strlen($nome) > 120) {
            respond(422, false, 'Nome do diretório deve ter no máximo 120 caracteres.');
        }

        $tipo = parseTipo($payload['tipo'] ?? null);
        $tempo = parseTempoMeta($payload['tempo'] ?? 'Diário');
        $quantidadeMeta = parseQuantidadeMeta($payload['quantidade_meta'] ?? 0);

        $update = $pdo->prepare('UPDATE diretorios SET nome = :nome, tipo = :tipo, tempo = :tempo, quantidade_meta = :quantidade_meta, meta_atualizada_em = NOW() WHERE id = :id AND id_usuario = :id_usuario');
        $update->execute([
            'nome' => $nome,
            'tipo' => $tipo,
            'tempo' => $tempo,
            'quantidade_meta' => $quantidadeMeta,
            'id' => $id,
            'id_usuario' => $userId,
        ]);

        if ($update->rowCount() === 0) {
            $check = $pdo->prepare('SELECT id FROM diretorios WHERE id = :id AND id_usuario = :id_usuario LIMIT 1');
            $check->execute([
                'id' => $id,
                'id_usuario' => $userId,
            ]);

            if (!$check->fetch()) {
                respond(404, false, 'Diretório não encontrado.');
            }
        }

        resetMetaCountersIfNeeded($pdo, $userId, $id);

        $refreshDirectory = $pdo->prepare('SELECT quantidade_atual FROM diretorios WHERE id = :id AND id_usuario = :id_usuario LIMIT 1');
        $refreshDirectory->execute([
            'id' => $id,
            'id_usuario' => $userId,
        ]);
        $directoryUpdated = $refreshDirectory->fetch();
        $quantidadeAtual = $directoryUpdated ? (int) $directoryUpdated['quantidade_atual'] : 0;

        respond(200, true, 'Diretório atualizado com sucesso.', [
            'diretorio' => [
                'id' => $id,
                'nome' => $nome,
                'tipo' => $tipo,
                'tempo' => $tempo,
                'quantidade_meta' => $quantidadeMeta,
                'quantidade_atual' => $quantidadeAtual,
            ],
        ]);
    }

    if ($method === 'DELETE') {
        $payload = jsonInput();
        $id = parseRequiredId($payload['id'] ?? null);

        $delete = $pdo->prepare('DELETE FROM diretorios WHERE id = :id AND id_usuario = :id_usuario');
        $delete->execute([
            'id' => $id,
            'id_usuario' => $userId,
        ]);

        if ($delete->rowCount() === 0) {
            respond(404, false, 'Diretório não encontrado.');
        }

        respond(200, true, 'Diretório excluído com sucesso.', [
            'id' => $id,
        ]);
    }

    if ($method !== 'GET') {
        respond(405, false, 'Método não permitido.');
    }

    $idPai = parseIdPai(isset($_GET['id_pai']) ? (string) $_GET['id_pai'] : null);
    resetMetaCountersIfNeeded($pdo, $userId);

    if ($idPai === null) {
        $stmt = $pdo->prepare('SELECT id, nome, id_pai, tipo, tempo, quantidade_meta, quantidade_atual, contagem_atualizada_em FROM diretorios WHERE id_usuario = :id_usuario AND id_pai IS NULL ORDER BY nome ASC');
        $stmt->execute(['id_usuario' => $userId]);
    } else {
        $stmt = $pdo->prepare('SELECT id, nome, id_pai, tipo, tempo, quantidade_meta, quantidade_atual, contagem_atualizada_em FROM diretorios WHERE id_usuario = :id_usuario AND id_pai = :id_pai ORDER BY nome ASC');
        $stmt->execute([
            'id_usuario' => $userId,
            'id_pai' => $idPai,
        ]);
    }

    $diretorios = $stmt->fetchAll();

    foreach ($diretorios as &$diretorio) {
        $diretorio['has_revisao_vencida'] = false;
        $diretorio['meta_pendente'] = (int) $diretorio['quantidade_meta'] > 0
            && (int) $diretorio['quantidade_atual'] < (int) $diretorio['quantidade_meta'];
    }
    unset($diretorio);

    respond(200, true, 'Diretórios carregados com sucesso.', [
        'id_pai' => $idPai,
        'diretorios' => $diretorios,
    ]);
} catch (Throwable $e) {
    respond(500, false, 'Erro interno no servidor.');
}

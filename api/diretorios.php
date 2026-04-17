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

        $insert = $pdo->prepare('INSERT INTO diretorios (nome, id_pai, tipo, id_usuario) VALUES (:nome, :id_pai, :tipo, :id_usuario)');
        $insert->bindValue(':nome', $nome);
        $insert->bindValue(':tipo', $tipo, PDO::PARAM_INT);
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
                'tipo' => $tipo,
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

        $update = $pdo->prepare('UPDATE diretorios SET nome = :nome, tipo = :tipo WHERE id = :id AND id_usuario = :id_usuario');
        $update->execute([
            'nome' => $nome,
            'tipo' => $tipo,
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

        respond(200, true, 'Diretório atualizado com sucesso.', [
            'diretorio' => [
                'id' => $id,
                'nome' => $nome,
                'tipo' => $tipo,
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

    if ($idPai === null) {
        $stmt = $pdo->prepare('SELECT id, nome, id_pai, tipo FROM diretorios WHERE id_usuario = :id_usuario AND id_pai IS NULL ORDER BY nome ASC');
        $stmt->execute(['id_usuario' => $userId]);
    } else {
        $stmt = $pdo->prepare('SELECT id, nome, id_pai, tipo FROM diretorios WHERE id_usuario = :id_usuario AND id_pai = :id_pai ORDER BY nome ASC');
        $stmt->execute([
            'id_usuario' => $userId,
            'id_pai' => $idPai,
        ]);
    }

    $diretorios = $stmt->fetchAll();

    $arvoreStmt = $pdo->prepare('SELECT id, id_pai, tipo FROM diretorios WHERE id_usuario = :id_usuario');
    $arvoreStmt->execute(['id_usuario' => $userId]);
    $todosDiretorios = $arvoreStmt->fetchAll();

    $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $agoraFormatado = $agora->format('Y-m-d H:i:s');

    $diretoriosTipoDoisIds = [];
    $idParaPai = [];
    foreach ($todosDiretorios as $diretorioArvore) {
        $idDiretorio = (int) ($diretorioArvore['id'] ?? 0);
        if ($idDiretorio <= 0) {
            continue;
        }

        $idPaiDiretorio = $diretorioArvore['id_pai'];
        $idParaPai[$idDiretorio] = $idPaiDiretorio === null ? null : (int) $idPaiDiretorio;

        if ((int) ($diretorioArvore['tipo'] ?? 0) === 2) {
            $diretoriosTipoDoisIds[] = $idDiretorio;
        }
    }

    $diretoriosComRevisaoVencida = [];
    if (count($diretoriosTipoDoisIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($diretoriosTipoDoisIds), '?'));
        $revisoesStmt = $pdo->prepare(
            "SELECT p.id_diretorio
             FROM pares p
             LEFT JOIN revisoes r ON r.id_par = p.id AND r.id_usuario = ?
             WHERE p.id_diretorio IN ($placeholders)
               AND (r.id IS NULL OR r.proxima_revisao <= ?)
             GROUP BY p.id_diretorio"
        );

        $revisoesStmt->execute([
            $userId,
            ...$diretoriosTipoDoisIds,
            $agoraFormatado,
        ]);

        foreach ($revisoesStmt->fetchAll() as $revisaoDiretorio) {
            $idDiretorioVencido = (int) ($revisaoDiretorio['id_diretorio'] ?? 0);
            if ($idDiretorioVencido <= 0) {
                continue;
            }

            $idAtual = $idDiretorioVencido;
            while ($idAtual !== null && $idAtual > 0 && !isset($diretoriosComRevisaoVencida[$idAtual])) {
                $diretoriosComRevisaoVencida[$idAtual] = true;
                $idAtual = $idParaPai[$idAtual] ?? null;
            }
        }
    }

    foreach ($diretorios as &$diretorio) {
        $idDiretorio = (int) ($diretorio['id'] ?? 0);
        $diretorio['has_revisao_vencida'] = isset($diretoriosComRevisaoVencida[$idDiretorio]);
    }
    unset($diretorio);

    respond(200, true, 'Diretórios carregados com sucesso.', [
        'id_pai' => $idPai,
        'diretorios' => $diretorios,
    ]);
} catch (Throwable $e) {
    respond(500, false, 'Erro interno no servidor.');
}

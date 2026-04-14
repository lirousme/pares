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

function parsePositiveInt(mixed $value, string $fieldName): int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }

    if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
        return (int) $value;
    }

    respond(422, false, sprintf('%s inválido.', $fieldName));
}

function parseIdioma(mixed $idioma): string
{
    if (!is_string($idioma)) {
        respond(422, false, 'Idioma inválido.');
    }

    $idiomaNormalizado = trim($idioma);
    $idiomasDisponiveis = ['pt-BR', 'en-GB', 'en-US'];
    if (!in_array($idiomaNormalizado, $idiomasDisponiveis, true)) {
        respond(422, false, 'Idioma inválido. Use pt-BR, en-GB ou en-US.');
    }

    return $idiomaNormalizado;
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
        $action = (string) ($_GET['action'] ?? '');
        $idDiretorioParam = $_GET['id_diretorio'] ?? null;
        $idDiretorio = parsePositiveInt($idDiretorioParam, 'ID do diretório');

        $checkDirectory = $pdo->prepare('SELECT id FROM diretorios WHERE id = :id AND id_usuario = :id_usuario LIMIT 1');
        $checkDirectory->execute([
            'id' => $idDiretorio,
            'id_usuario' => $userId,
        ]);

        if (!$checkDirectory->fetch()) {
            respond(404, false, 'Diretório não encontrado.');
        }

        if ($action === 'card_criacao') {
            $stmt = $pdo->prepare(
                'SELECT id, texto
                 FROM cards
                 WHERE id_diretorio = :id_diretorio AND ok = 1
                 ORDER BY id ASC
                 LIMIT 1'
            );
            $stmt->execute(['id_diretorio' => $idDiretorio]);
            $card = $stmt->fetch();

            if (!$card) {
                respond(200, true, 'Nenhum card elegível encontrado.', [
                    'id_diretorio' => $idDiretorio,
                    'card' => null,
                ]);
            }

            respond(200, true, 'Card carregado com sucesso.', [
                'id_diretorio' => $idDiretorio,
                'card' => $card,
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT
                p.id,
                p.id_card_um,
                p.id_card_dois,
                p.id_diretorio,
                c1.texto AS card_um_texto,
                c2.texto AS card_dois_texto
            FROM pares p
            LEFT JOIN cards c1 ON c1.id = p.id_card_um
            LEFT JOIN cards c2 ON c2.id = p.id_card_dois
            WHERE p.id_diretorio = :id_diretorio
            ORDER BY p.id ASC'
        );
        $stmt->execute(['id_diretorio' => $idDiretorio]);
        $pares = $stmt->fetchAll();

        respond(200, true, 'Pares carregados com sucesso.', [
            'id_diretorio' => $idDiretorio,
            'pares' => $pares,
        ]);
    }

    if ($method === 'POST') {
        $payload = jsonInput();
        $action = (string) ($payload['action'] ?? '');

        if ($action === 'criar_card') {
            $idDiretorio = parsePositiveInt($payload['id_diretorio'] ?? null, 'ID do diretório');
            $texto = trim((string) ($payload['texto'] ?? ''));
            $idioma = parseIdioma($payload['idioma'] ?? 'pt-BR');

            if ($texto === '') {
                respond(422, false, 'Texto do card é obrigatório.');
            }

            if (mb_strlen($texto) > 1500) {
                respond(422, false, 'Texto do card deve ter no máximo 1500 caracteres.');
            }

            $checkDirectory = $pdo->prepare('SELECT id FROM diretorios WHERE id = :id AND id_usuario = :id_usuario LIMIT 1');
            $checkDirectory->execute([
                'id' => $idDiretorio,
                'id_usuario' => $userId,
            ]);

            if (!$checkDirectory->fetch()) {
                respond(404, false, 'Diretório não encontrado.');
            }

            $insertCard = $pdo->prepare(
                'INSERT INTO cards (id_diretorio, texto, idioma)
                 VALUES (:id_diretorio, :texto, :idioma)'
            );
            $insertCard->execute([
                'id_diretorio' => $idDiretorio,
                'texto' => $texto,
                'idioma' => $idioma,
            ]);

            respond(201, true, 'Card criado com sucesso.', [
                'card' => [
                    'id' => (int) $pdo->lastInsertId(),
                    'id_diretorio' => $idDiretorio,
                    'texto' => $texto,
                    'idioma' => $idioma,
                ],
            ]);
        }

        if ($action !== 'concluir_revisao') {
            respond(422, false, 'Ação inválida.');
        }

        $idPar = parsePositiveInt($payload['id_par'] ?? null, 'ID do par');

        $checkPair = $pdo->prepare(
            'SELECT p.id
             FROM pares p
             INNER JOIN diretorios d ON d.id = p.id_diretorio
             WHERE p.id = :id_par AND d.id_usuario = :id_usuario
             LIMIT 1'
        );
        $checkPair->execute([
            'id_par' => $idPar,
            'id_usuario' => $userId,
        ]);

        if (!$checkPair->fetch()) {
            respond(404, false, 'Par não encontrado.');
        }

        $pdo->beginTransaction();

        $selectReview = $pdo->prepare(
            'SELECT id, quantidade
             FROM revisoes
             WHERE id_par = :id_par AND id_usuario = :id_usuario
             LIMIT 1
             FOR UPDATE'
        );
        $selectReview->execute([
            'id_par' => $idPar,
            'id_usuario' => $userId,
        ]);
        $review = $selectReview->fetch();

        $quantidadeAtual = $review ? (int) $review['quantidade'] : 0;
        $novaQuantidade = $quantidadeAtual + 1;

        $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $proximaRevisao = $agora->add(new DateInterval(sprintf('P%dD', $novaQuantidade)));
        $proximaRevisaoFormatada = $proximaRevisao->format('Y-m-d H:i:s');

        if ($review) {
            $updateReview = $pdo->prepare(
                'UPDATE revisoes
                 SET quantidade = :quantidade, proxima_revisao = :proxima_revisao
                 WHERE id = :id'
            );
            $updateReview->execute([
                'quantidade' => $novaQuantidade,
                'proxima_revisao' => $proximaRevisaoFormatada,
                'id' => (int) $review['id'],
            ]);
        } else {
            $insertReview = $pdo->prepare(
                'INSERT INTO revisoes (id_par, id_usuario, quantidade, proxima_revisao)
                 VALUES (:id_par, :id_usuario, :quantidade, :proxima_revisao)'
            );
            $insertReview->execute([
                'id_par' => $idPar,
                'id_usuario' => $userId,
                'quantidade' => $novaQuantidade,
                'proxima_revisao' => $proximaRevisaoFormatada,
            ]);
        }

        $pdo->commit();

        respond(200, true, 'Revisão concluída com sucesso.', [
            'id_par' => $idPar,
            'quantidade' => $novaQuantidade,
            'proxima_revisao' => $proximaRevisaoFormatada,
        ]);
    }

    respond(405, false, 'Método não permitido.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, false, 'Erro interno no servidor.');
}

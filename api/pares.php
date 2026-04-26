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


function nextExpansionAvailability(DateTimeImmutable $agora, int $expansions): string
{
    if ($expansions <= -2) {
        $minutes = match ($expansions) {
            -2 => 5,
            -1 => 10,
            default => 15,
        };

        return $agora->add(new DateInterval(sprintf('PT%dM', $minutes)))->format('Y-m-d H:i:s');
    }

    if ($expansions >= 1) {
        return $agora->add(new DateInterval(sprintf('P%dD', $expansions)))->format('Y-m-d H:i:s');
    }

    return $agora->add(new DateInterval('PT15M'))->format('Y-m-d H:i:s');
}

function getGoogleCloudApiKey(): string
{
    $apiKey = trim((string) (getenv('GOOGLE_CLOUD_API_KEY') ?: ''));
    if ($apiKey === '') {
        respond(500, false, 'GOOGLE_CLOUD_API_KEY não configurada no .env.');
    }

    return $apiKey;
}

function voiceNameByIdioma(string $idioma): string
{
    return match ($idioma) {
        'pt-BR' => 'pt-BR-Chirp3-HD-Enceladus',
        'en-US' => 'en-US-Chirp3-HD-Enceladus',
        'en-GB' => 'en-GB-Chirp3-HD-Enceladus',
        default => throw new RuntimeException('Idioma sem voz configurada.'),
    };
}

function synthesizeAudioWithGoogleCloud(string $texto, string $idioma): string
{
    $apiKey = getGoogleCloudApiKey();
    $voiceName = voiceNameByIdioma($idioma);

    $payload = [
        'input' => [
            'text' => $texto,
        ],
        'voice' => [
            'languageCode' => $idioma,
            'name' => $voiceName,
        ],
        'audioConfig' => [
            'audioEncoding' => 'MP3',
        ],
    ];

    $endpoint = 'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . rawurlencode($apiKey);
    $ch = curl_init($endpoint);
    if ($ch === false) {
        throw new RuntimeException('Falha ao inicializar requisição de áudio.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);

    $rawResponse = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        throw new RuntimeException($curlError !== '' ? $curlError : 'Falha ao chamar serviço de áudio.');
    }

    $decoded = json_decode($rawResponse, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Resposta inválida do serviço de áudio.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $apiMessage = trim((string) ($decoded['error']['message'] ?? ''));
        throw new RuntimeException($apiMessage !== '' ? $apiMessage : 'Falha ao gerar áudio.');
    }

    $audioContent = trim((string) ($decoded['audioContent'] ?? ''));
    if ($audioContent === '') {
        throw new RuntimeException('Serviço de áudio não retornou conteúdo.');
    }

    return $audioContent;
}

function synthesizeCardAudioOrRespond(string $texto, string $idioma): string
{
    try {
        return synthesizeAudioWithGoogleCloud($texto, $idioma);
    } catch (Throwable $e) {
        respond(502, false, 'Não foi possível gerar o áudio do card.', [
            'detail' => $e->getMessage(),
        ]);
    }
}

function synthesizeCardAudiosOrRespond(string $textoEnGb, string $textoPtBr, bool $ptbrAtivo = true): array
{
    $audioEnGb = synthesizeCardAudioOrRespond($textoEnGb, 'en-GB');
    $audioPtBr = null;
    if ($ptbrAtivo && $textoPtBr !== '') {
        $audioPtBr = synthesizeCardAudioOrRespond($textoPtBr, 'pt-BR');
    }

    return [
        'audio_engb' => $audioEnGb,
        'audio_ptbr' => $audioPtBr,
    ];
}

function getOpenAiApiKey(): string
{
    $apiKey = trim((string) (getenv('OPENAI_API_KEY') ?: ''));
    if ($apiKey === '') {
        respond(500, false, 'OPENAI_API_KEY não configurada no .env.');
    }

    return $apiKey;
}

function extractOpenAiText(array $responseData): string
{
    $outputText = trim((string) ($responseData['output_text'] ?? ''));
    if ($outputText !== '') {
        return $outputText;
    }

    $output = $responseData['output'] ?? null;
    if (!is_array($output)) {
        return '';
    }

    $chunks = [];

    foreach ($output as $item) {
        if (!is_array($item)) {
            continue;
        }

        $content = $item['content'] ?? null;
        if (!is_array($content)) {
            continue;
        }

        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }

            $text = trim((string) ($part['text'] ?? ''));
            if ($text !== '') {
                $chunks[] = $text;
            }
        }
    }

    return trim(implode("\n", $chunks));
}

function translateTextWithOpenAi(string $texto): array
{
    $apiKey = getOpenAiApiKey();
    $model = trim((string) (getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini'));

    $payload = [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => 'Você é um tradutor. Detecte automaticamente o idioma do texto de entrada. Se estiver em português brasileiro, traduza para inglês britânico (en-GB). Se estiver em inglês, traduza para português brasileiro. Responda APENAS com JSON válido no formato {"translated_text":"...","target_language":"pt-BR|en-GB"}.',
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $texto,
                    ],
                ],
            ],
        ],
        'temperature' => 0.1,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    if ($ch === false) {
        throw new RuntimeException('Falha ao inicializar requisição de tradução.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);

    $rawResponse = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        throw new RuntimeException($curlError !== '' ? $curlError : 'Falha ao chamar serviço de tradução.');
    }

    $decoded = json_decode($rawResponse, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Resposta inválida do serviço de tradução.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $apiMessage = '';
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $apiMessage = trim((string) ($decoded['error']['message'] ?? ''));
        }

        throw new RuntimeException($apiMessage !== '' ? $apiMessage : 'Falha ao traduzir texto com IA.');
    }

    $assistantText = extractOpenAiText($decoded);
    if ($assistantText === '') {
        throw new RuntimeException('A IA não retornou texto traduzido.');
    }

    $translationData = json_decode($assistantText, true);
    if (!is_array($translationData)) {
        throw new RuntimeException('A IA retornou um formato inesperado de tradução.');
    }

    $translatedText = trim((string) ($translationData['translated_text'] ?? ''));
    $targetLanguage = trim((string) ($translationData['target_language'] ?? ''));

    if ($translatedText === '') {
        throw new RuntimeException('A tradução retornada está vazia.');
    }

    if (!in_array($targetLanguage, ['pt-BR', 'en-GB'], true)) {
        throw new RuntimeException('Idioma de destino inválido retornado pela IA.');
    }

    return [
        'translated_text' => $translatedText,
        'target_language' => $targetLanguage,
    ];
}

function translateTextToEnGbWithOpenAi(string $texto): array
{
    $apiKey = getOpenAiApiKey();
    $model = trim((string) (getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini'));

    $payload = [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => 'Você é um tradutor especializado em português brasileiro para inglês britânico. Se o usuário enviar texto em português brasileiro, traduza para inglês britânico (en-GB). Se já estiver em inglês, mantenha em inglês natural en-GB. Responda APENAS com JSON válido no formato {"translated_text":"...","target_language":"en-GB"}.',
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $texto,
                    ],
                ],
            ],
        ],
        'temperature' => 0.1,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    if ($ch === false) {
        throw new RuntimeException('Falha ao inicializar requisição de tradução.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);

    $rawResponse = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        throw new RuntimeException($curlError !== '' ? $curlError : 'Falha ao chamar serviço de tradução.');
    }

    $decoded = json_decode($rawResponse, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Resposta inválida do serviço de tradução.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $apiMessage = '';
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $apiMessage = trim((string) ($decoded['error']['message'] ?? ''));
        }

        throw new RuntimeException($apiMessage !== '' ? $apiMessage : 'Falha ao traduzir texto com IA.');
    }

    $assistantText = extractOpenAiText($decoded);
    if ($assistantText === '') {
        throw new RuntimeException('A IA não retornou texto traduzido.');
    }

    $translationData = json_decode($assistantText, true);
    if (!is_array($translationData)) {
        throw new RuntimeException('A IA retornou um formato inesperado de tradução.');
    }

    $translatedText = trim((string) ($translationData['translated_text'] ?? ''));
    if ($translatedText === '') {
        throw new RuntimeException('A tradução retornada está vazia.');
    }

    return [
        'translated_text' => $translatedText,
        'target_language' => 'en-GB',
    ];
}

function getUserPtBrAtivo(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT ptbr FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        respond(404, false, 'Usuário não encontrado.');
    }

    return ((int) ($user['ptbr'] ?? 1)) !== 2;
}

function toRevisionCount(mixed $value): int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_string($value) && is_numeric($value)) {
        return (int) $value;
    }

    return 0;
}

function reorderPairsByRevisionPriority(array $pairs): array
{
    if (count($pairs) <= 1) {
        return $pairs;
    }

    usort($pairs, static function (array $a, array $b): int {
        $countA = toRevisionCount($a['revisao_quantidade'] ?? null);
        $countB = toRevisionCount($b['revisao_quantidade'] ?? null);

        if ($countA === $countB) {
            return 0;
        }

        return $countB <=> $countA;
    });

    $remaining = $pairs;
    $ordered = [];
    $lastCardUm = null;

    while ($remaining !== []) {
        $highestCount = toRevisionCount($remaining[0]['revisao_quantidade'] ?? null);
        $eligibleHighest = [];
        $eligibleHighestIndexes = [];

        foreach ($remaining as $index => $pair) {
            $currentCount = toRevisionCount($pair['revisao_quantidade'] ?? null);
            if ($currentCount !== $highestCount) {
                break;
            }

            if ($lastCardUm === null || (int) $pair['id_card_um'] !== $lastCardUm) {
                $eligibleHighest[] = $pair;
                $eligibleHighestIndexes[] = $index;
            }
        }

        $selectedIndex = null;

        if ($eligibleHighest !== []) {
            $pick = random_int(0, count($eligibleHighest) - 1);
            $selectedIndex = $eligibleHighestIndexes[$pick];
        } else {
            $fallbackHighest = [];
            $fallbackHighestIndexes = [];
            foreach ($remaining as $index => $pair) {
                $currentCount = toRevisionCount($pair['revisao_quantidade'] ?? null);
                if ($currentCount !== $highestCount) {
                    break;
                }

                $fallbackHighest[] = $pair;
                $fallbackHighestIndexes[] = $index;
            }

            $pick = random_int(0, count($fallbackHighest) - 1);
            $selectedIndex = $fallbackHighestIndexes[$pick];
        }

        $selectedPair = $remaining[$selectedIndex];
        $ordered[] = $selectedPair;
        $lastCardUm = (int) $selectedPair['id_card_um'];
        array_splice($remaining, $selectedIndex, 1);
    }

    return $ordered;
}

startLongSession();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    respond(401, false, 'Usuário não autenticado.');
}

try {
    $pdo = db();
    $ptbrAtivo = getUserPtBrAtivo($pdo, $userId);
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
            $idCardExcluir = null;
            if (array_key_exists('id_card_excluir', $_GET) && $_GET['id_card_excluir'] !== '') {
                $idCardExcluir = parsePositiveInt($_GET['id_card_excluir'], 'ID do card para excluir');
            }

            $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
            $agoraFormatado = $agora->format('Y-m-d H:i:s');

            $baseSelect = 'SELECT
                    c.id,
                    c.texto_engb AS texto,
                    c.texto_engb,
                    c.texto_ptbr,
                    c.ok,
                    c.audio_engb AS audio,
                    c.audio_engb,
                    c.audio_ptbr,
                    c.expansions,
                    c.proxima_expansion,
                    0 AS revisao_quantidade_max
                FROM cards c
                WHERE c.id_diretorio = :id_diretorio
                  AND c.expansions < 7';
            $params = [
                'id_diretorio' => $idDiretorio,
                'agora_prioridade' => $agoraFormatado,
            ];

            $extraFiltro = '';
            if ($idCardExcluir !== null) {
                $extraFiltro = ' AND c.id <> :id_card_excluir';
                $params['id_card_excluir'] = $idCardExcluir;
            }

            $query = '(' . $baseSelect . $extraFiltro . '
                  AND c.proxima_expansion <= :agora_prioridade
                ORDER BY
                    c.expansions DESC,
                    c.proxima_expansion ASC,
                    c.id ASC
                LIMIT 1)
                UNION ALL
                (' . $baseSelect . $extraFiltro . '
                  AND (c.proxima_expansion > :agora_prioridade OR c.proxima_expansion IS NULL)
                ORDER BY
                    c.expansions DESC,
                    c.proxima_expansion ASC,
                    c.id ASC
                LIMIT 1)
                LIMIT 1';

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $card = $stmt->fetch();

            if (!$card) {
                respond(200, true, 'Nenhum card elegível encontrado.', [
                    'id_diretorio' => $idDiretorio,
                    'card' => null,
                    'ptbr_ativo' => $ptbrAtivo,
                ]);
            }

            if (!$ptbrAtivo) {
                $card['texto_ptbr'] = '';
                $card['audio_ptbr'] = null;
            }

            respond(200, true, 'Card carregado com sucesso.', [
                'id_diretorio' => $idDiretorio,
                'card' => $card,
                'ptbr_ativo' => $ptbrAtivo,
            ]);
        }

        respond(410, false, 'A revisão de pares foi removida. Use apenas o fluxo de criação de cards.');
    }

    if ($method === 'POST') {
        $payload = jsonInput();
        $action = (string) ($payload['action'] ?? '');

        if ($action === 'traduzir_texto') {
            $texto = trim((string) ($payload['texto'] ?? ''));

            if ($texto === '') {
                respond(422, false, 'Texto para tradução é obrigatório.');
            }

            if (mb_strlen($texto) > 1500) {
                respond(422, false, 'Texto para tradução deve ter no máximo 1500 caracteres.');
            }

            try {
                $translation = translateTextWithOpenAi($texto);
            } catch (Throwable $e) {
                respond(502, false, 'Não foi possível traduzir o texto.', [
                    'detail' => $e->getMessage(),
                ]);
            }

            respond(200, true, 'Texto traduzido com sucesso.', [
                'texto_original' => $texto,
                'texto_traduzido' => $translation['translated_text'],
                'idioma_destino' => $translation['target_language'],
            ]);
        }

        if ($action === 'traduzir_texto_para_engb') {
            $texto = trim((string) ($payload['texto'] ?? ''));

            if ($texto === '') {
                respond(422, false, 'Texto para tradução é obrigatório.');
            }

            if (mb_strlen($texto) > 1500) {
                respond(422, false, 'Texto para tradução deve ter no máximo 1500 caracteres.');
            }

            try {
                $translation = translateTextToEnGbWithOpenAi($texto);
            } catch (Throwable $e) {
                respond(502, false, 'Não foi possível traduzir o texto para en-GB.', [
                    'detail' => $e->getMessage(),
                ]);
            }

            respond(200, true, 'Texto traduzido para en-GB com sucesso.', [
                'texto_original' => $texto,
                'texto_traduzido' => $translation['translated_text'],
                'idioma_destino' => 'en-GB',
            ]);
        }

        if ($action === 'alternar_ok_card') {
            $idCard = parsePositiveInt($payload['id_card'] ?? null, 'ID do card');

            $pdo->beginTransaction();

            $selectCard = $pdo->prepare(
                'SELECT c.id, c.ok
                 FROM cards c
                 INNER JOIN diretorios d ON d.id = c.id_diretorio
                 WHERE c.id = :id_card AND d.id_usuario = :id_usuario
                 LIMIT 1
                 FOR UPDATE'
            );
            $selectCard->execute([
                'id_card' => $idCard,
                'id_usuario' => $userId,
            ]);
            $card = $selectCard->fetch();

            if (!$card) {
                respond(404, false, 'Card não encontrado.');
            }

            $okAnterior = (int) $card['ok'];
            $okNovo = $okAnterior === 1 ? 2 : 1;

            $updateCard = $pdo->prepare(
                'UPDATE cards
                 SET ok = :ok
                 WHERE id = :id_card'
            );
            $updateCard->execute([
                'ok' => $okNovo,
                'id_card' => $idCard,
            ]);

            $pdo->commit();

            respond(200, true, 'Valor da coluna ok atualizado com sucesso.', [
                'id_card' => $idCard,
                'ok_anterior' => $okAnterior,
                'ok_novo' => $okNovo,
            ]);
        }

        if ($action === 'gerar_audio_card') {
            $idCard = parsePositiveInt($payload['id_card'] ?? null, 'ID do card');

            $selectCard = $pdo->prepare(
                'SELECT c.id, c.id_diretorio, c.texto_engb, c.texto_ptbr
                 FROM cards c
                 INNER JOIN diretorios d ON d.id = c.id_diretorio
                 WHERE c.id = :id_card AND d.id_usuario = :id_usuario
                 LIMIT 1'
            );
            $selectCard->execute([
                'id_card' => $idCard,
                'id_usuario' => $userId,
            ]);
            $card = $selectCard->fetch();

            if (!$card) {
                respond(404, false, 'Card não encontrado.');
            }

            $textoEnGb = trim((string) ($card['texto_engb'] ?? ''));
            $textoPtBr = $ptbrAtivo ? trim((string) ($card['texto_ptbr'] ?? '')) : '';
            if ($textoEnGb === '' || ($ptbrAtivo && $textoPtBr === '')) {
                respond(422, false, 'Card sem texto necessário para gerar áudio.');
            }

            $audios = synthesizeCardAudiosOrRespond($textoEnGb, $textoPtBr, $ptbrAtivo);

            $updateCard = $pdo->prepare(
                'UPDATE cards
                 SET audio_engb = :audio_engb, audio_ptbr = :audio_ptbr
                 WHERE id = :id_card'
            );
            $updateCard->execute([
                'audio_engb' => $audios['audio_engb'],
                'audio_ptbr' => $audios['audio_ptbr'],
                'id_card' => $idCard,
            ]);

            respond(200, true, 'Áudio do card gerado com sucesso.', [
                'card' => [
                    'id' => $idCard,
                    'id_diretorio' => (int) $card['id_diretorio'],
                    'audio' => $audios['audio_engb'],
                    'audio_engb' => $audios['audio_engb'],
                    'audio_ptbr' => $ptbrAtivo ? $audios['audio_ptbr'] : null,
                ],
            ]);
        }

        if ($action === 'editar_card') {
            $idCard = parsePositiveInt($payload['id_card'] ?? null, 'ID do card');
            $textoEnGb = trim((string) ($payload['texto_engb'] ?? ''));
            $textoPtBr = trim((string) ($payload['texto_ptbr'] ?? ''));

            if ($textoEnGb === '' || ($ptbrAtivo && $textoPtBr === '')) {
                respond(422, false, 'Texto en-GB é obrigatório e pt-BR é obrigatório apenas quando estiver ativo.');
            }

            if (mb_strlen($textoEnGb) > 1500 || mb_strlen($textoPtBr) > 1500) {
                respond(422, false, 'Cada texto do card deve ter no máximo 1500 caracteres.');
            }

            $selectCard = $pdo->prepare(
                'SELECT c.id, c.id_diretorio
                 FROM cards c
                 INNER JOIN diretorios d ON d.id = c.id_diretorio
                 WHERE c.id = :id_card AND d.id_usuario = :id_usuario
                 LIMIT 1'
            );
            $selectCard->execute([
                'id_card' => $idCard,
                'id_usuario' => $userId,
            ]);
            $card = $selectCard->fetch();

            if (!$card) {
                respond(404, false, 'Card não encontrado.');
            }

            $updateCard = $pdo->prepare(
                'UPDATE cards
                 SET texto_engb = :texto_engb, texto_ptbr = :texto_ptbr, audio_engb = NULL, audio_ptbr = NULL
                 WHERE id = :id_card'
            );
            $updateCard->execute([
                'texto_engb' => $textoEnGb,
                'texto_ptbr' => $ptbrAtivo ? $textoPtBr : '',
                'id_card' => $idCard,
            ]);

            respond(200, true, 'Card atualizado com sucesso.', [
                'card' => [
                    'id' => $idCard,
                    'id_diretorio' => (int) $card['id_diretorio'],
                    'texto' => $textoEnGb,
                    'texto_engb' => $textoEnGb,
                    'texto_ptbr' => $ptbrAtivo ? $textoPtBr : '',
                ],
            ]);
        }

        if ($action === 'criar_card_relacionado' || $action === 'criar_par_por_texto') {
            $idDiretorio = parsePositiveInt($payload['id_diretorio'] ?? null, 'ID do diretório');
            $idCardUm = parsePositiveInt($payload['id_card_base'] ?? $payload['id_card_um'] ?? null, 'ID do card base');
            $textoEnGb = trim((string) ($payload['texto_engb'] ?? ''));
            $textoPtBr = trim((string) ($payload['texto_ptbr'] ?? ''));

            if ($textoEnGb === '' || ($ptbrAtivo && $textoPtBr === '')) {
                respond(422, false, 'Texto en-GB é obrigatório e pt-BR é obrigatório apenas quando estiver ativo.');
            }

            if (mb_strlen($textoEnGb) > 1500 || mb_strlen($textoPtBr) > 1500) {
                respond(422, false, 'Cada texto do card deve ter no máximo 1500 caracteres.');
            }

            $checkDirectory = $pdo->prepare('SELECT id FROM diretorios WHERE id = :id AND id_usuario = :id_usuario LIMIT 1');
            $checkDirectory->execute([
                'id' => $idDiretorio,
                'id_usuario' => $userId,
            ]);

            if (!$checkDirectory->fetch()) {
                respond(404, false, 'Diretório não encontrado.');
            }

            $checkCardUm = $pdo->prepare(
                'SELECT id
                 FROM cards
                 WHERE id = :id_card AND id_diretorio = :id_diretorio
                 LIMIT 1
                 FOR UPDATE'
            );
            $checkCardUm->execute([
                'id_card' => $idCardUm,
                'id_diretorio' => $idDiretorio,
            ]);

            if (!$checkCardUm->fetch()) {
                respond(404, false, 'Card base não encontrado no diretório informado.');
            }

            $textoPtBr = $ptbrAtivo ? $textoPtBr : '';
            $audios = synthesizeCardAudiosOrRespond($textoEnGb, $textoPtBr, $ptbrAtivo);

            $pdo->beginTransaction();

            $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
            $agoraFormatado = $agora->format('Y-m-d H:i:s');

            $insertCard = $pdo->prepare(
                'INSERT INTO cards (id_diretorio, texto_engb, texto_ptbr, audio_engb, audio_ptbr, expansions, proxima_expansion)
                 VALUES (:id_diretorio, :texto_engb, :texto_ptbr, :audio_engb, :audio_ptbr, :expansions, :proxima_expansion)'
            );
            $insertCard->execute([
                'id_diretorio' => $idDiretorio,
                'texto_engb' => $textoEnGb,
                'texto_ptbr' => $textoPtBr,
                'audio_engb' => $audios['audio_engb'],
                'audio_ptbr' => $audios['audio_ptbr'],
                'expansions' => -3,
                'proxima_expansion' => $agoraFormatado,
            ]);

            $idCardDois = (int) $pdo->lastInsertId();

            $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

            $cardBaseStmt = $pdo->prepare(
                'SELECT id, expansions, proxima_expansion
                 FROM cards
                 WHERE id = :id_card_um AND id_diretorio = :id_diretorio
                 LIMIT 1
                 FOR UPDATE'
            );
            $cardBaseStmt->execute([
                'id_card_um' => $idCardUm,
                'id_diretorio' => $idDiretorio,
            ]);
            $cardBase = $cardBaseStmt->fetch();

            if (!$cardBase) {
                $pdo->rollBack();
                respond(404, false, 'Card base não encontrado no diretório informado.');
            }

            $expansionsAtual = (int) $cardBase['expansions'];
            $expansionsNovo = $expansionsAtual + 1;

            if ($expansionsNovo > 7) {
                $pdo->rollBack();
                respond(422, false, 'Card base já atingiu o limite máximo de expansões (7).');
            }

            $proximaDisponivelAnterior = trim((string) ($cardBase['proxima_expansion'] ?? ''));
            if ($proximaDisponivelAnterior !== '' && $proximaDisponivelAnterior > $agora->format('Y-m-d H:i:s')) {
                $pdo->rollBack();
                respond(422, false, 'Card base ainda não está disponível para nova expansão.', [
                    'proxima_expansion' => $proximaDisponivelAnterior,
                ]);
            }

            $baseCardRemovido = false;
            $proximaExpansion = null;

            if ($expansionsNovo >= 7) {
                $deleteCardBase = $pdo->prepare(
                    'DELETE FROM cards
                     WHERE id = :id_card'
                );
                $deleteCardBase->execute([
                    'id_card' => $idCardUm,
                ]);
                $baseCardRemovido = true;
            } else {
                $proximaExpansion = nextExpansionAvailability($agora, $expansionsNovo);

                $updateCardBase = $pdo->prepare(
                    'UPDATE cards
                     SET expansions = :expansions, proxima_expansion = :proxima_expansion
                     WHERE id = :id_card'
                );
                $updateCardBase->execute([
                    'expansions' => $expansionsNovo,
                    'proxima_expansion' => $proximaExpansion,
                    'id_card' => $idCardUm,
                ]);
            }

            $pdo->commit();

            respond(201, true, 'Card relacionado criado com sucesso.', [
                'base_card' => [
                    'id' => $idCardUm,
                    'expansions' => $expansionsNovo,
                    'proxima_expansion' => $proximaExpansion,
                    'removido' => $baseCardRemovido,
                ],
                'card' => [
                    'id' => $idCardDois,
                    'id_diretorio' => $idDiretorio,
                    'texto' => $textoEnGb,
                    'texto_engb' => $textoEnGb,
                    'texto_ptbr' => $textoPtBr,
                    'audio' => $audios['audio_engb'],
                    'audio_engb' => $audios['audio_engb'],
                    'audio_ptbr' => $ptbrAtivo ? $audios['audio_ptbr'] : null,
                ],
            ]);
        }

        if ($action === 'criar_card') {
            $idDiretorio = parsePositiveInt($payload['id_diretorio'] ?? null, 'ID do diretório');
            $textoEnGb = trim((string) ($payload['texto_engb'] ?? ''));
            $textoPtBr = trim((string) ($payload['texto_ptbr'] ?? ''));

            if ($textoEnGb === '' || ($ptbrAtivo && $textoPtBr === '')) {
                respond(422, false, 'Texto en-GB é obrigatório e pt-BR é obrigatório apenas quando estiver ativo.');
            }

            if (mb_strlen($textoEnGb) > 1500 || mb_strlen($textoPtBr) > 1500) {
                respond(422, false, 'Cada texto do card deve ter no máximo 1500 caracteres.');
            }

            $checkDirectory = $pdo->prepare('SELECT id FROM diretorios WHERE id = :id AND id_usuario = :id_usuario LIMIT 1');
            $checkDirectory->execute([
                'id' => $idDiretorio,
                'id_usuario' => $userId,
            ]);

            if (!$checkDirectory->fetch()) {
                respond(404, false, 'Diretório não encontrado.');
            }

            $textoPtBr = $ptbrAtivo ? $textoPtBr : '';
            $audios = synthesizeCardAudiosOrRespond($textoEnGb, $textoPtBr, $ptbrAtivo);
            $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
            $agoraFormatado = $agora->format('Y-m-d H:i:s');

            $insertCard = $pdo->prepare(
                'INSERT INTO cards (id_diretorio, texto_engb, texto_ptbr, audio_engb, audio_ptbr, expansions, proxima_expansion)
                 VALUES (:id_diretorio, :texto_engb, :texto_ptbr, :audio_engb, :audio_ptbr, :expansions, :proxima_expansion)'
            );
            $insertCard->execute([
                'id_diretorio' => $idDiretorio,
                'texto_engb' => $textoEnGb,
                'texto_ptbr' => $textoPtBr,
                'audio_engb' => $audios['audio_engb'],
                'audio_ptbr' => $audios['audio_ptbr'],
                'expansions' => -3,
                'proxima_expansion' => $agoraFormatado,
            ]);

            respond(201, true, 'Card criado com sucesso.', [
                'card' => [
                    'id' => (int) $pdo->lastInsertId(),
                    'id_diretorio' => $idDiretorio,
                    'texto' => $textoEnGb,
                    'texto_engb' => $textoEnGb,
                    'texto_ptbr' => $textoPtBr,
                    'audio' => $audios['audio_engb'],
                    'audio_engb' => $audios['audio_engb'],
                    'audio_ptbr' => $ptbrAtivo ? $audios['audio_ptbr'] : null,
                    'ok' => 1,
                ],
            ]);
        }

        respond(422, false, 'Ação inválida.');
    }

    respond(405, false, 'Método não permitido.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $detail = 'Erro interno no servidor.';
    if ($e->getMessage() !== '') {
        $detail = $e->getMessage();
    }

    respond(500, false, 'Erro interno no servidor.', [
        'detail' => $detail,
    ]);
}

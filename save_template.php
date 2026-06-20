<?php

declare(strict_types=1);

/**
 * save_template.php
 * -----------------------------------------------------------------------
 * Riceve dal frontend (app.js) un JSON con:
 *   {
 *     template_id: number|null,   // null = nuovo template, number = aggiorna
 *     name: string,
 *     filename: string,           // restituito da upload.php (solo su nuovo template)
 *     canvas_width_px: number,
 *     canvas_height_px: number,
 *     fields: [
 *       { db_variable, static_text, pos_x, pos_y, width, height,
 *         font_size, font_family, font_weight, text_align, group_name, field_order },
 *       ...
 *     ]
 *   }
 *
 * Sicurezza: ogni db_variable ricevuto viene verificato contro le colonne
 * reali di DATA_TABLE (whitelist via INFORMATION_SCHEMA) prima di essere
 * salvato, cosi' un payload manomesso non puo' agganciare un campo PDF a
 * una colonna arbitraria del database.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $httpCode = 200): never
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Metodo non consentito.'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);

if (!is_array($payload)) {
    respond(['success' => false, 'error' => 'JSON non valido.'], 400);
}

$name = trim((string) ($payload['name'] ?? ''));
$templateId = isset($payload['template_id']) && $payload['template_id'] !== null
    ? (int) $payload['template_id']
    : null;
$filename = isset($payload['filename']) ? trim((string) $payload['filename']) : null;
$canvasWidthPx = (float) ($payload['canvas_width_px'] ?? 0);
$canvasHeightPx = (float) ($payload['canvas_height_px'] ?? 0);
$fieldsInput = $payload['fields'] ?? [];

if ($name === '') {
    respond(['success' => false, 'error' => 'Il nome del template e\' obbligatorio.'], 400);
}
if ($canvasWidthPx <= 0 || $canvasHeightPx <= 0) {
    respond(['success' => false, 'error' => 'Dimensioni canvas mancanti o non valide.'], 400);
}
if (!is_array($fieldsInput)) {
    respond(['success' => false, 'error' => 'Elenco campi non valido.'], 400);
}

$pdo = Database::getConnection();

// --- Risolvi il file PDF sorgente e le sue dimensioni reali (mm) ---
// Sempre ricalcolate lato server da FPDI: non ci si fida del valore
// eventualmente inviato dal client.
if ($templateId !== null) {
    $stmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
    $stmt->execute([':id' => $templateId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        respond(['success' => false, 'error' => 'Template non trovato (ID ' . $templateId . ').'], 404);
    }
    $finalFilename = $filename !== null && $filename !== '' ? $filename : $existing['filename'];
} else {
    if ($filename === null || $filename === '') {
        respond(['success' => false, 'error' => 'Nessun PDF caricato: trascina prima un template nell\'area centrale.'], 400);
    }
    $finalFilename = $filename;
}

// Il filename deve corrispondere esattamente al pattern generato da upload.php
if (preg_match('/^[a-f0-9]{24}\.pdf$/', $finalFilename) !== 1) {
    respond(['success' => false, 'error' => 'Riferimento al file PDF non valido.'], 400);
}

$templateFilePath = TEMPLATES_DIR . '/' . $finalFilename;
if (!is_file($templateFilePath)) {
    respond(['success' => false, 'error' => 'Il file PDF del template non esiste piu\' sul server. Ricaricalo.'], 404);
}

try {
    $probe = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'mm');
    $probe->setSourceFile($templateFilePath);
    $tplIdx = $probe->importPage(1);
    $size = $probe->getTemplateSize($tplIdx);
    $pageWidthMm = round((float) $size['width'], 3);
    $pageHeightMm = round((float) $size['height'], 3);
} catch (\Throwable $e) {
    respond(['success' => false, 'error' => 'Impossibile leggere il PDF template: ' . $e->getMessage()], 422);
}

// --- Whitelist delle colonne ammesse come db_variable ---
$validColumns = array_column(Database::getTableColumns(DATA_TABLE), 'name');
$validColumns = array_diff($validColumns, DATA_TABLE_HIDDEN_COLUMNS);

// --- Valida e normalizza ogni campo ---
$cleanFields = [];
foreach ($fieldsInput as $i => $f) {
    if (!is_array($f)) {
        respond(['success' => false, 'error' => "Campo #$i non valido."], 400);
    }

    $dbVariable = isset($f['db_variable']) && $f['db_variable'] !== '' ? (string) $f['db_variable'] : null;
    $staticText = isset($f['static_text']) ? (string) $f['static_text'] : null;

    if ($dbVariable !== null && !in_array($dbVariable, $validColumns, true)) {
        respond([
            'success' => false,
            'error' => "Il campo #$i fa riferimento a una variabile DB inesistente (\"$dbVariable\")."
        ], 400);
    }
    if ($dbVariable === null && ($staticText === null || $staticText === '')) {
        // Campo senza DB e senza testo: lo si salva comunque come testo vuoto,
        // l'utente potra' valorizzarlo in seguito dal pannello proprieta'.
        $staticText = '';
    }

    $posX = (float) ($f['pos_x'] ?? 0);
    $posY = (float) ($f['pos_y'] ?? 0);
    $width = (float) ($f['width'] ?? 0);
    $height = (float) ($f['height'] ?? 0);

    if ($width <= 0 || $height <= 0) {
        respond(['success' => false, 'error' => "Il campo #$i ha larghezza o altezza non valida."], 400);
    }

    $fontSize = (float) ($f['font_size'] ?? 9);
    $fontSize = max(4, min(72, $fontSize));

    $fontFamily = in_array($f['font_family'] ?? '', ['helvetica', 'times', 'courier'], true)
        ? $f['font_family']
        : 'helvetica';

    $fontWeight = ($f['font_weight'] ?? 'normal') === 'bold' ? 'bold' : 'normal';

    $textAlign = in_array($f['text_align'] ?? '', ['L', 'C', 'R', 'J'], true) ? $f['text_align'] : 'L';

    $groupName = isset($f['group_name']) && trim((string) $f['group_name']) !== ''
        ? substr(trim((string) $f['group_name']), 0, 50)
        : null;

    $cleanFields[] = [
        'db_variable' => $dbVariable,
        'static_text' => $staticText,
        'pos_x'       => $posX,
        'pos_y'       => $posY,
        'width'       => $width,
        'height'      => $height,
        'font_size'   => $fontSize,
        'font_family' => $fontFamily,
        'font_weight' => $fontWeight,
        'text_align'  => $textAlign,
        'group_name'  => $groupName,
        'field_order' => (int) ($f['field_order'] ?? $i),
    ];
}

// --- Persisti in transazione (template + campi) ---
try {
    $pdo->beginTransaction();

    if ($templateId !== null) {
        $stmt = $pdo->prepare(
            'UPDATE templates
             SET name = :name, filename = :filename, page_width_mm = :pw, page_height_mm = :ph,
                 canvas_width_px = :cw, canvas_height_px = :ch
             WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $name,
            ':filename' => $finalFilename,
            ':pw' => $pageWidthMm,
            ':ph' => $pageHeightMm,
            ':cw' => $canvasWidthPx,
            ':ch' => $canvasHeightPx,
            ':id' => $templateId,
        ]);

        $pdo->prepare('DELETE FROM template_fields WHERE template_id = :id')->execute([':id' => $templateId]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO templates (name, filename, page_width_mm, page_height_mm, canvas_width_px, canvas_height_px)
             VALUES (:name, :filename, :pw, :ph, :cw, :ch)'
        );
        $stmt->execute([
            ':name' => $name,
            ':filename' => $finalFilename,
            ':pw' => $pageWidthMm,
            ':ph' => $pageHeightMm,
            ':cw' => $canvasWidthPx,
            ':ch' => $canvasHeightPx,
        ]);
        $templateId = (int) $pdo->lastInsertId();
    }

    if (count($cleanFields) > 0) {
        $insertField = $pdo->prepare(
            'INSERT INTO template_fields
                (template_id, db_variable, static_text, pos_x, pos_y, width, height,
                 font_size, font_family, font_weight, text_align, group_name, field_order)
             VALUES
                (:template_id, :db_variable, :static_text, :pos_x, :pos_y, :width, :height,
                 :font_size, :font_family, :font_weight, :text_align, :group_name, :field_order)'
        );

        foreach ($cleanFields as $f) {
            $insertField->execute([
                ':template_id' => $templateId,
                ':db_variable' => $f['db_variable'],
                ':static_text' => $f['static_text'],
                ':pos_x'       => $f['pos_x'],
                ':pos_y'       => $f['pos_y'],
                ':width'       => $f['width'],
                ':height'      => $f['height'],
                ':font_size'   => $f['font_size'],
                ':font_family' => $f['font_family'],
                ':font_weight' => $f['font_weight'],
                ':text_align'  => $f['text_align'],
                ':group_name'  => $f['group_name'],
                ':field_order' => $f['field_order'],
            ]);
        }
    }

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    respond(['success' => false, 'error' => 'Errore durante il salvataggio: ' . $e->getMessage()], 500);
}

respond([
    'success'     => true,
    'template_id' => $templateId,
    'fields_count' => count($cleanFields),
]);

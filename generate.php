<?php

declare(strict_types=1);

/**
 * generate.php
 * -----------------------------------------------------------------------
 * Genera il PDF finale unendo un template salvato con un record dati.
 *
 * Parametri GET:
 *   template_id   (obbligatorio)
 *   customer_id   (obbligatorio) - id del record in DATA_TABLE (customers)
 *   download=1    (opzionale)    - forza il download invece dell'anteprima inline
 *
 * Esempio:
 *   generate.php?template_id=3&customer_id=12
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';

function failWith(string $message, int $httpCode = 400): never
{
    http_response_code($httpCode);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Errore generazione PDF</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#0b0e13;color:#e7eaf0;padding:48px;}
          .box{max-width:560px;margin:0 auto;background:#171c25;border:1px solid #242b38;border-radius:8px;padding:32px;}
          h1{color:#ef4444;font-size:1.1rem;margin-top:0} a{color:#5b9dff}</style></head><body>';
    echo '<div class="box"><h1>Impossibile generare il PDF</h1><p>' . htmlspecialchars($message) . '</p>';
    echo '<p><a href="index.php">&larr; Torna all\'editor</a></p></div></body></html>';
    exit;
}

$templateId = isset($_GET['template_id']) ? (int) $_GET['template_id'] : 0;
$customerId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
$forceDownload = isset($_GET['download']) && $_GET['download'] === '1';

if ($templateId <= 0) {
    failWith('Parametro template_id mancante o non valido.');
}
if ($customerId <= 0) {
    failWith('Parametro customer_id mancante o non valido.');
}

$pdo = Database::getConnection();

// --- Carica template ---
$stmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
$stmt->execute([':id' => $templateId]);
$template = $stmt->fetch();
if (!$template) {
    failWith('Template non trovato (ID ' . $templateId . ').', 404);
}

$templateFilePath = TEMPLATES_DIR . '/' . $template['filename'];
if (!is_file($templateFilePath)) {
    failWith('Il file PDF del template non e\' piu\' presente sul server.', 404);
}

// --- Carica campi del template (ordinati: prima i gruppi, poi l'ordine interno) ---
$stmt = $pdo->prepare(
    'SELECT * FROM template_fields
     WHERE template_id = :id
     ORDER BY (group_name IS NULL), group_name, field_order, pos_y'
);
$stmt->execute([':id' => $templateId]);
$fields = $stmt->fetchAll();

if (count($fields) === 0) {
    failWith('Questo template non ha ancora nessun campo mappato. Apri l\'editor e trascina almeno un campo sul PDF.');
}

// --- Carica il record dati (query sicura: nome tabella e' una costante
//     applicativa definita in config.php, mai input utente) ---
$stmt = $pdo->prepare('SELECT * FROM `' . DATA_TABLE . '` WHERE id = :id');
$stmt->execute([':id' => $customerId]);
$data = $stmt->fetch();
if (!$data) {
    failWith('Record dati non trovato (ID ' . $customerId . ') nella tabella ' . DATA_TABLE . '.', 404);
}

// --- Genera ---
try {
    $generator = new PDFGenerator();
    $generator->build(
        $templateFilePath,
        $fields,
        $data,
        (float) $template['canvas_width_px'],
        (float) $template['canvas_height_px']
    );
} catch (\Throwable $e) {
    failWith('Errore durante la generazione del PDF: ' . $e->getMessage(), 500);
}

// --- Log generazione (best-effort: non deve bloccare la risposta) ---
$outputFilename = sprintf(
    '%s_%s_%s.pdf',
    preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($data['order_number'] ?? $template['name'])),
    preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($data['customer_name'] ?? 'documento')),
    date('Ymd_His')
);

try {
    if (!is_dir(GENERATED_DIR)) {
        mkdir(GENERATED_DIR, 0775, true);
    }
    $pdo->prepare(
        'INSERT INTO generation_log (template_id, customer_id, output_filename) VALUES (:t, :c, :f)'
    )->execute([':t' => $templateId, ':c' => $customerId, ':f' => $outputFilename]);
} catch (\Throwable $e) {
    // non bloccante
}

$generator->stream($outputFilename, $forceDownload);

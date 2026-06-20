<?php

declare(strict_types=1);

/**
 * upload.php
 * -----------------------------------------------------------------------
 * Riceve il PDF base trascinato sull'Area Canvas dell'IDE, lo valida, lo
 * salva in /templates con un nome univoco e ne restituisce le dimensioni
 * reali di pagina (mm) lette direttamente dal PDF tramite FPDI. Non scrive
 * ancora nulla nel database: l'inserimento del template avviene solo al
 * click su "Salva Template" (vedi save_template.php), come richiesto.
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

if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
    $errorMap = [
        UPLOAD_ERR_INI_SIZE   => 'Il file supera la dimensione massima consentita dal server.',
        UPLOAD_ERR_FORM_SIZE  => 'Il file supera la dimensione massima consentita dal form.',
        UPLOAD_ERR_PARTIAL    => 'Caricamento interrotto, riprova.',
        UPLOAD_ERR_NO_FILE    => 'Nessun file ricevuto.',
        UPLOAD_ERR_NO_TMP_DIR => 'Cartella temporanea mancante sul server.',
        UPLOAD_ERR_CANT_WRITE => 'Impossibile scrivere il file sul server.',
    ];
    $code = $_FILES['pdf_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    respond(['success' => false, 'error' => $errorMap[$code] ?? 'Errore di upload sconosciuto.'], 400);
}

$file = $_FILES['pdf_file'];

if ($file['size'] > MAX_UPLOAD_SIZE) {
    respond(['success' => false, 'error' => 'Il PDF supera i ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . ' MB consentiti.'], 400);
}

// Validazione MIME reale (non ci si fida dell'estensione/Content-Type dichiarati dal client)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
if ($mime !== 'application/pdf') {
    respond(['success' => false, 'error' => 'Il file caricato non e\' un PDF valido (MIME rilevato: ' . $mime . ').'], 400);
}

if (!is_dir(TEMPLATES_DIR)) {
    mkdir(TEMPLATES_DIR, 0775, true);
}
if (!is_writable(TEMPLATES_DIR)) {
    respond(['success' => false, 'error' => 'La cartella /templates non e\' scrivibile dal server (controlla i permessi).'], 500);
}

// Nome file univoco e sicuro: nessuna parte del nome originale finisce nel
// percorso fisico, evitando path traversal o sovrascritture accidentali.
$safeFilename = bin2hex(random_bytes(12)) . '.pdf';
$destination = TEMPLATES_DIR . '/' . $safeFilename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    respond(['success' => false, 'error' => 'Impossibile salvare il file sul server.'], 500);
}

// Verifica che FPDI riesca davvero ad aprire il PDF e ne legge le
// dimensioni reali di pagina (in mm, stessa unita' usata da PDFGenerator).
try {
    $probe = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'mm');
    $pageCount = $probe->setSourceFile($destination);
    $tplIdx = $probe->importPage(1);
    $size = $probe->getTemplateSize($tplIdx);
} catch (\Throwable $e) {
    unlink($destination);
    respond([
        'success' => false,
        'error' => 'Il PDF non e\' leggibile o e\' protetto/danneggiato: ' . $e->getMessage(),
    ], 422);
}

respond([
    'success'        => true,
    'filename'       => $safeFilename,
    'url'            => TEMPLATES_URL . '/' . $safeFilename,
    'page_width_mm'  => round((float) $size['width'], 3),
    'page_height_mm' => round((float) $size['height'], 3),
    'pages'          => $pageCount,
    'pages_warning'  => $pageCount > 1
        ? 'Il PDF ha ' . $pageCount . ' pagine: verra\' usata solo la pagina 1 come template.'
        : null,
]);

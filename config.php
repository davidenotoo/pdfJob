<?php
/**
 * config.php
 * -----------------------------------------------------------------------
 * Configurazione centrale dell'applicazione "Laser PDF Mapper".
 *
 * AMBIENTE LOCALE (XAMPP):
 *   - Copia questa cartella in C:\xampp\htdocs\laser-pdf-mapper
 *   - DB_HOST = 'localhost', DB_USER = 'root', DB_PASS = ''
 *   - Importa database.sql in phpMyAdmin (crea automaticamente il DB)
 *
 * AMBIENTE PRODUZIONE (Altervista):
 *   - Carica tutti i file via FTP nella root del tuo spazio Altervista
 *   - Cambia DB_USER / DB_NAME / DB_PASS con quelli mostrati nel pannello
 *     "MySQL" del tuo account Altervista (di solito DB_NAME = DB_USER =
 *     il tuo nome utente Altervista).
 *   - Imposta APP_DEBUG a false.
 * -----------------------------------------------------------------------
 */

declare(strict_types=1);

// ===================== AMBIENTE =====================
// true in sviluppo locale (mostra errori dettagliati), false in produzione
define('APP_DEBUG', true);

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

date_default_timezone_set('Europe/Rome');

// ===================== DATABASE =====================
define('DB_HOST', 'localhost');
define('DB_NAME', 'my_testjobstatus');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ===================== PERCORSI FILESYSTEM =====================
define('BASE_DIR', __DIR__);
define('TEMPLATES_DIR', BASE_DIR . '/templates');   // PDF template caricati (filesystem)
define('GENERATED_DIR', BASE_DIR . '/generated');   // PDF generati in output (filesystem)
define('VENDOR_AUTOLOAD', BASE_DIR . '/vendor/autoload.php');

// ===================== URL BASE (auto-rilevato) =====================
// Funziona sia su http://localhost/laser-pdf-mapper sia su Altervista
// (es. https://tuosito.altervista.org), senza bisogno di configurazione manuale.
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$scriptDir = rtrim($scriptDir, '/');
define('BASE_URL', $scriptDir);
define('TEMPLATES_URL', BASE_URL . '/templates');

// ===================== LIMITI UPLOAD =====================
define('MAX_UPLOAD_SIZE', 20 * 1024 * 1024); // 20 MB

// ===================== TABELLA DATI DOCUMENTI =====================
// Tabella del DB my_testjobstatus da cui vengono lette le variabili
// disponibili nella "Libreria Campi" dell'editor e i dati per la
// generazione dei PDF finali.
define('DATA_TABLE', 'customers');

// Colonne della tabella DATA_TABLE da escludere dalla Libreria Campi
// (campi tecnici/interni, non destinati alla stampa sul PDF)
define('DATA_TABLE_HIDDEN_COLUMNS', ['id', 'created_at', 'updated_at']);

// ===================== AUTOLOAD LIBRERIE PDF =====================
if (!file_exists(VENDOR_AUTOLOAD)) {
    http_response_code(500);
    die(
        'Libreria PDF mancante. Verifica che la cartella "vendor/" sia presente '
        . 'accanto a config.php (deve contenere vendor/tecnickcom e vendor/setasign), '
        . 'oppure esegui "composer require setasign/fpdi-tcpdf" nella root del progetto.'
    );
}
require_once VENDOR_AUTOLOAD;

// ===================== AUTOLOAD CLASSI APPLICATIVE =====================
spl_autoload_register(function (string $class): void {
    $path = BASE_DIR . '/classes/' . $class . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

<?php
/**
 * Mini autoloader per le librerie PDF (TCPDF + FPDI), incluse pre-installate
 * in questo progetto cosi' non serve eseguire "composer install" sull'hosting
 * (utile su Altervista, dove non sempre e' disponibile l'accesso SSH/Composer).
 *
 * Se in futuro preferisci gestirle tramite Composer, esegui:
 *   composer require setasign/fpdi-tcpdf
 * e questo file verra' sostituito automaticamente da vendor/autoload.php di Composer.
 */
require_once __DIR__ . '/tecnickcom/tcpdf/tcpdf.php';
require_once __DIR__ . '/setasign/fpdi/src/autoload.php';

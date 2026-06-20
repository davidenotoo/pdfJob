<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$pdo = Database::getConnection();

/** Converte uno snake_case del DB in un'etichetta leggibile per la UI. */
function humanizeColumn(string $col): string
{
    return ucwords(str_replace('_', ' ', $col));
}

// ===================== Templates disponibili (per il selettore in alto) =====================
$templates = $pdo->query('SELECT id, name FROM templates ORDER BY name ASC')->fetchAll();

// ===================== Record dati disponibili (per il pannello "Genera Documento") =====================
$customers = $pdo->query(
    'SELECT id, customer_name, order_number FROM `' . DATA_TABLE . '` ORDER BY id DESC'
)->fetchAll();

// ===================== Libreria campi: colonne reali di DATA_TABLE =====================
$dataColumns = array_values(array_filter(
    Database::getTableColumns(DATA_TABLE),
    fn(array $c) => !in_array($c['name'], DATA_TABLE_HIDDEN_COLUMNS, true)
));

// ===================== Template attivo (se presente in querystring) =====================
$activeTemplateId = isset($_GET['template_id']) ? (int) $_GET['template_id'] : null;
$activeTemplate = null;
$activeFields = [];

if ($activeTemplateId) {
    $stmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
    $stmt->execute([':id' => $activeTemplateId]);
    $activeTemplate = $stmt->fetch() ?: null;

    if ($activeTemplate) {
        $stmt = $pdo->prepare(
            'SELECT * FROM template_fields
             WHERE template_id = :id
             ORDER BY (group_name IS NULL), group_name, field_order, pos_y'
        );
        $stmt->execute([':id' => $activeTemplateId]);
        $activeFields = $stmt->fetchAll();
    } else {
        $activeTemplateId = null;
    }
}

// JSON di hydration per app.js: se non c'e' un template attivo, il canvas
// parte vuoto e mostra la dropzone di upload.
$templateDataJson = json_encode([
    'templateId'     => $activeTemplate ? (int) $activeTemplate['id'] : null,
    'templateName'   => $activeTemplate ? $activeTemplate['name'] : '',
    'filename'       => $activeTemplate ? $activeTemplate['filename'] : null,
    'pdfUrl'         => $activeTemplate ? (TEMPLATES_URL . '/' . $activeTemplate['filename']) : null,
    'pageWidthMm'    => $activeTemplate ? (float) $activeTemplate['page_width_mm'] : null,
    'pageHeightMm'   => $activeTemplate ? (float) $activeTemplate['page_height_mm'] : null,
    'canvasWidthPx'  => $activeTemplate ? (float) $activeTemplate['canvas_width_px'] : null,
    'canvasHeightPx' => $activeTemplate ? (float) $activeTemplate['canvas_height_px'] : null,
], JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laser PDF Mapper · Web Tuned</title>
<link rel="stylesheet" href="assets/css/style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/interactjs/1.10.27/interact.min.js"></script>
</head>
<body>

<!-- Dati di hydration per app.js -->
<script id="template-data" type="application/json"><?= $templateDataJson ?></script>

<header class="topbar">
    <div class="topbar-brand">
        <span class="brand-mark">⌁</span>
        <div class="brand-text">
            <strong>Laser PDF Mapper</strong>
            <small>Web Tuned · Sheet Report Generator</small>
        </div>
    </div>

    <div class="topbar-template">
        <label class="field-inline">
            <span>Template</span>
            <select id="template-select" onchange="if(this.value){location.href='index.php?template_id='+this.value}else{location.href='index.php'}">
                <option value="">+ Nuovo template</option>
                <?php foreach ($templates as $t): ?>
                    <option value="<?= (int) $t['id'] ?>" <?= ($activeTemplateId === (int) $t['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <input type="text" id="template-name-input" placeholder="Nome template…"
               value="<?= $activeTemplate ? htmlspecialchars($activeTemplate['name']) : '' ?>">
    </div>

    <div class="topbar-actions">
        <span id="save-status" class="save-status"></span>
        <button id="btn-save-template" class="btn btn-primary" type="button">💾 Salva Template</button>
    </div>
</header>

<div class="app-body">

    <!-- ===================== SIDEBAR SINISTRA: Libreria Campi ===================== -->
    <aside class="sidebar sidebar-left">
        <h2 class="sidebar-title">Libreria Campi</h2>
        <p class="sidebar-hint">Trascina una variabile sul PDF per creare una cella.</p>

        <ul class="field-lib-list">
            <li class="field-lib-item field-lib-static" draggable="true" data-type="static">
                <span class="lib-icon">＋</span>
                <span class="lib-label">Testo Libero</span>
            </li>
            <?php foreach ($dataColumns as $col): ?>
                <li class="field-lib-item" draggable="true" data-type="db" data-db-variable="<?= htmlspecialchars($col['name']) ?>">
                    <span class="lib-icon">⛁</span>
                    <span class="lib-label"><?= htmlspecialchars(humanizeColumn($col['name'])) ?></span>
                    <span class="lib-var"><?= htmlspecialchars($col['name']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="sidebar-section">
            <h2 class="sidebar-title">Tabella dati</h2>
            <p class="sidebar-hint">
                Sorgente: <code><?= htmlspecialchars(DATA_TABLE) ?></code><br>
                <?= count($dataColumns) ?> variabili disponibili
            </p>
        </div>
    </aside>

    <!-- ===================== AREA CANVAS CENTRALE ===================== -->
    <main class="canvas-area">

        <div id="dropzone" class="dropzone <?= $activeTemplate ? 'hidden' : '' ?>">
            <input type="file" id="file-input" accept="application/pdf" hidden>
            <div class="dropzone-content">
                <div class="dropzone-icon">⇪</div>
                <p><strong>Trascina qui il PDF base</strong> oppure clicca per selezionarlo</p>
                <p class="dropzone-sub">Verra' usata la pagina 1 come template (max 20&nbsp;MB)</p>
            </div>
        </div>

        <div id="canvas-stage" class="canvas-stage <?= $activeTemplate ? '' : 'hidden' ?>">
            <div id="canvas-scroll">
                <div id="canvas-inner">
                    <canvas id="pdf-canvas"></canvas>
                    <div id="field-overlay">
                        <?php foreach ($activeFields as $f): ?>
                            <?php
                                $isStatic = $f['db_variable'] === null;
                                $labelText = $isStatic
                                    ? ($f['static_text'] !== '' ? $f['static_text'] : 'Testo libero')
                                    : $f['db_variable'];
                            ?>
                            <div class="field-box"
                                 data-field-id="<?= (int) $f['id'] ?>"
                                 data-type="<?= $isStatic ? 'static' : 'db' ?>"
                                 data-db-variable="<?= htmlspecialchars((string) $f['db_variable']) ?>"
                                 data-static-text="<?= htmlspecialchars((string) $f['static_text']) ?>"
                                 data-font-size="<?= htmlspecialchars((string) $f['font_size']) ?>"
                                 data-font-family="<?= htmlspecialchars((string) $f['font_family']) ?>"
                                 data-font-weight="<?= htmlspecialchars((string) $f['font_weight']) ?>"
                                 data-text-align="<?= htmlspecialchars((string) $f['text_align']) ?>"
                                 data-group-name="<?= htmlspecialchars((string) $f['group_name']) ?>"
                                 data-field-order="<?= (int) $f['field_order'] ?>"
                                 style="left:<?= (float) $f['pos_x'] ?>px; top:<?= (float) $f['pos_y'] ?>px; width:<?= (float) $f['width'] ?>px; height:<?= (float) $f['height'] ?>px;">
                                <span class="field-box-label"><?= htmlspecialchars($labelText) ?></span>
                                <span class="field-box-resize"></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ===================== SIDEBAR DESTRA: Proprietà + Generazione ===================== -->
    <aside class="sidebar sidebar-right">

        <section id="properties-panel" class="properties-panel hidden">
            <h2 class="sidebar-title">Proprietà Cella</h2>

            <div id="prop-db-block" class="prop-block hidden">
                <label class="prop-label">Variabile DB associata</label>
                <div class="prop-readonly-badge" id="prop-db-variable">—</div>
            </div>

            <div id="prop-static-block" class="prop-block hidden">
                <label class="prop-label" for="prop-static-text">Testo predefinito</label>
                <textarea id="prop-static-text" rows="2" placeholder="Testo da stampare…"></textarea>
            </div>

            <div class="prop-row">
                <div class="prop-block">
                    <label class="prop-label" for="prop-x">X (px)</label>
                    <input type="number" id="prop-x" step="0.5">
                </div>
                <div class="prop-block">
                    <label class="prop-label" for="prop-y">Y (px)</label>
                    <input type="number" id="prop-y" step="0.5">
                </div>
            </div>

            <div class="prop-row">
                <div class="prop-block">
                    <label class="prop-label" for="prop-w">Larghezza (px)</label>
                    <input type="number" id="prop-w" step="0.5" min="5">
                </div>
                <div class="prop-block">
                    <label class="prop-label" for="prop-h">Altezza (px)</label>
                    <input type="number" id="prop-h" step="0.5" min="5">
                </div>
            </div>

            <div class="prop-row">
                <div class="prop-block">
                    <label class="prop-label" for="prop-font-size">Font (pt)</label>
                    <input type="number" id="prop-font-size" step="0.5" min="4" max="72">
                </div>
                <div class="prop-block">
                    <label class="prop-label" for="prop-font-family">Famiglia</label>
                    <select id="prop-font-family">
                        <option value="helvetica">Helvetica</option>
                        <option value="times">Times</option>
                        <option value="courier">Courier</option>
                    </select>
                </div>
            </div>

            <div class="prop-row">
                <div class="prop-block">
                    <label class="prop-label">Allineamento</label>
                    <div class="segmented" id="prop-align">
                        <button type="button" data-value="L" class="active">Sx</button>
                        <button type="button" data-value="C">Centro</button>
                        <button type="button" data-value="R">Dx</button>
                    </div>
                </div>
                <div class="prop-block">
                    <label class="prop-label" for="prop-bold">Stile</label>
                    <label class="checkbox-row">
                        <input type="checkbox" id="prop-bold">
                        <span>Grassetto</span>
                    </label>
                </div>
            </div>

            <div class="prop-block">
                <label class="prop-label" for="prop-group">Gruppo cascata (opzionale)</label>
                <input type="text" id="prop-group" maxlength="50" placeholder="es. tabella_parti">
                <p class="prop-hint">Le celle con lo stesso gruppo si spingono verso il basso a catena se il testo eccede l'altezza tracciata.</p>
            </div>

            <button id="btn-delete-field" type="button" class="btn btn-danger btn-block">🗑 Elimina Cella</button>
        </section>

        <section id="empty-properties-hint" class="properties-panel">
            <p class="sidebar-hint">Seleziona una cella sul PDF per modificarne le proprietà, oppure trascina una variabile dalla libreria a sinistra per crearne una nuova.</p>
        </section>

        <section class="generate-panel <?= $activeTemplate ? '' : 'hidden' ?>" id="generate-panel">
            <h2 class="sidebar-title">Genera Documento</h2>
            <?php if (count($customers) === 0): ?>
                <p class="sidebar-hint">Nessun record trovato nella tabella <code><?= htmlspecialchars(DATA_TABLE) ?></code>.</p>
            <?php else: ?>
                <form action="generate.php" method="get" target="_blank">
                    <input type="hidden" name="template_id" value="<?= (int) $activeTemplateId ?>">
                    <label class="prop-label" for="customer-select">Record dati</label>
                    <select name="customer_id" id="customer-select">
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int) $c['id'] ?>">
                                #<?= (int) $c['id'] ?> — <?= htmlspecialchars($c['customer_name']) ?> (<?= htmlspecialchars($c['order_number']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="generate-actions">
                        <button type="submit" class="btn btn-primary btn-block">📄 Anteprima PDF</button>
                        <button type="submit" name="download" value="1" class="btn btn-ghost btn-block">⬇ Scarica</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

    </aside>
</div>

<footer class="statusbar" id="statusbar">
    <span id="status-text">Nessun template caricato</span>
</footer>

<div id="toast-container"></div>

<script src="assets/js/app.js"></script>
</body>
</html>

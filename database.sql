-- =========================================================================
-- database.sql
-- Schema del database "my_testjobstatus" per Laser PDF Mapper (Web Tuned)
--
-- Importazione:
--   - XAMPP: apri phpMyAdmin -> tab "Importa" -> seleziona questo file
--            (oppure incolla il contenuto nella tab "SQL"). Lo script crea
--            il database se non esiste.
--   - Altervista: pannello "MySQL" -> phpMyAdmin del tuo account -> Importa.
--            Su Altervista il database esiste gia' (non puoi crearne uno
--            nuovo con CREATE DATABASE): salta la prima istruzione e
--            importa solo a partire da "CREATE TABLE templates...".
-- =========================================================================

CREATE DATABASE IF NOT EXISTS my_testjobstatus
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE my_testjobstatus;

-- -------------------------------------------------------------------------
-- TABELLA: templates
-- Un template = un PDF base caricato nell'IDE + le dimensioni del canvas
-- usate al momento della mappatura (servono a generate.php per convertire
-- correttamente le coordinate px -> mm).
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS templates (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(150)    NOT NULL,
    filename          VARCHAR(255)    NOT NULL COMMENT 'Nome file fisico dentro /templates',
    page_width_mm     DECIMAL(10,3)   NOT NULL,
    page_height_mm    DECIMAL(10,3)   NOT NULL,
    canvas_width_px   DECIMAL(10,3)   NOT NULL COMMENT 'Larghezza canvas in editor al momento del salvataggio',
    canvas_height_px  DECIMAL(10,3)   NOT NULL,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------------
-- TABELLA: template_fields
-- Le "celle / bounding box" disegnate nell'IDE sopra un template.
-- Ogni riga e' un campo: o agganciato a una colonna DB (db_variable) o a
-- testo statico (static_text). group_name e' la chiave di "cascata": campi
-- con lo stesso group_name vengono spinti verso il basso in sequenza se
-- uno di essi genera testo piu' lungo del previsto (vedi PDFGenerator.php).
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS template_fields (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id     INT UNSIGNED    NOT NULL,
    db_variable     VARCHAR(100)    NULL COMMENT 'Nome colonna in DATA_TABLE, NULL se testo statico',
    static_text     VARCHAR(1000)   NULL,
    pos_x           DECIMAL(10,3)   NOT NULL COMMENT 'px, origine in alto a sinistra del canvas',
    pos_y           DECIMAL(10,3)   NOT NULL,
    width           DECIMAL(10,3)   NOT NULL,
    height          DECIMAL(10,3)   NOT NULL,
    font_size       DECIMAL(5,2)    NOT NULL DEFAULT 9.00,
    font_family     VARCHAR(30)     NOT NULL DEFAULT 'helvetica',
    font_weight     VARCHAR(10)     NOT NULL DEFAULT 'normal' COMMENT 'normal | bold',
    text_align      VARCHAR(1)      NOT NULL DEFAULT 'L' COMMENT 'L | C | R | J',
    group_name      VARCHAR(50)     NULL COMMENT 'Gruppo di cascata (es. nome tabella/griglia), NULL = campo indipendente',
    field_order     INT             NOT NULL DEFAULT 0 COMMENT 'Ordine di rendering dentro al gruppo',
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_template_fields_template
        FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
    INDEX idx_template_fields_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------------
-- TABELLA: customers
-- Tabella dati di esempio (DATA_TABLE in config.php). Le sue colonne
-- alimentano automaticamente la "Libreria Campi" nella sidebar dell'IDE.
-- Struttura ricavata dai campi presenti nei due Sheet Report campione
-- (ALEX-0605 / ALEX-0606): cliente, materiale, macchina, parametri di
-- taglio Amada ENSIS3015AJ.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_name         VARCHAR(150)   NOT NULL,
    order_number          VARCHAR(50)    NOT NULL,
    order_date            DATE           NULL,
    due_date              DATE           NULL,
    data_name             VARCHAR(50)    NULL COMMENT 'Es. ALEX-0605',
    part_rev              VARCHAR(10)    NULL,
    quantity              INT            NOT NULL DEFAULT 0,
    total_parts_qty       INT            NULL,
    material              VARCHAR(150)   NULL COMMENT 'Es. A5052-3.0',
    material_type         VARCHAR(150)   NULL COMMENT 'Es. 3MM ALUM 5005',
    material_size         VARCHAR(50)    NULL COMMENT 'Es. 2400 x 1200',
    thickness              DECIMAL(6,2)   NULL,
    sheet_code             VARCHAR(50)    NULL,
    sheet_type              VARCHAR(50)    NULL,
    machine_name           VARCHAR(100)   NULL,
    raw_material_weight    DECIMAL(8,2)   NULL,
    process_length          INT            NULL,
    process_quantity        INT            NULL,
    process_time             VARCHAR(20)    NULL COMMENT 'Es. 00:14:58',
    utilization               DECIMAL(5,2)   NULL,
    clamp_values              VARCHAR(100)   NULL COMMENT 'Es. 179 / 704 / 1754 / 2279',
    in_stock                  VARCHAR(10)    NULL COMMENT 'YES / NO',
    notes                     TEXT           NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------------
-- TABELLA: generation_log
-- Storico dei PDF generati (utile per audit/tracciabilita' in produzione).
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS generation_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id     INT UNSIGNED NOT NULL,
    customer_id     INT UNSIGNED NULL,
    output_filename VARCHAR(255) NOT NULL,
    generated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_generation_log_template
        FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------------
-- DATI DI ESEMPIO (basati sui due Sheet Report campione allegati)
-- -------------------------------------------------------------------------
INSERT INTO customers (
    customer_name, order_number, order_date, due_date, data_name, part_rev,
    quantity, total_parts_qty, material, material_type, material_size, thickness,
    sheet_code, sheet_type, machine_name, raw_material_weight, process_length,
    process_quantity, process_time, utilization, clamp_values, in_stock, notes
) VALUES
(
    'WADE SAMIN', 'ALEX-0605', '2022-11-16', '2022-12-05', 'ALEX-0605', NULL,
    1, 70, 'A5052-3.0', NULL, '2400 x 1200', 3.00,
    NULL, NULL, 'ENSIS3015AJ', 23.30, 68486,
    1, '00:14:58', 81.80, '179 / 704 / 1754 / 2279', NULL, NULL
),
(
    'WADE SAMIN', 'ALEX-0606', '2022-11-21', '2022-12-05', 'ALEX-0606', 'A',
    2, 112, 'A5052-3.0', '3MM ALUM 5005', '3000 x 1500', 3.00,
    NULL, NULL, 'ENSIS3015AJ', 36.40, 109578,
    2, '00:24:01', 83.40, '179 / 704 / 1754 / 2279', 'YES', NULL
);

# Laser PDF Mapper — Web Tuned Dev Solutions

Editor IDE per mappare campi dinamici su template PDF (Sheet Report / Parts
List) e generare automaticamente i documenti finali a partire dal database
`my_testjobstatus`, con wrap automatico del testo dentro le bounding box
tracciate (mai sovrapposizioni a barcode/disegni tecnici).

## Stack

- Frontend: HTML5 + CSS3 + JS vanilla, **PDF.js** (anteprima PDF) e
  **interact.js** (drag & resize celle), caricati da cdnjs.
- Backend: PHP 8+ puro, PDO, **FPDI + TCPDF** per import/fusione PDF.
- Database: MySQL, database `my_testjobstatus`.
- **Le librerie PDF (TCPDF + FPDI) sono già incluse e pronte in `vendor/`** —
  non serve eseguire `composer install` per partire (utile su Altervista,
  dove non sempre è disponibile l'accesso SSH/Composer). `composer.json` è
  comunque incluso se in futuro preferisci gestirle tu via Composer.

## 1. Setup locale (XAMPP)

1. Copia l'intera cartella `laser-pdf-mapper` in `C:\xampp\htdocs\`.
2. Avvia Apache e MySQL da XAMPP Control Panel.
3. Apri `http://localhost/phpmyadmin`, scheda **Importa**, seleziona
   `database.sql` ed esegui. Crea il database `my_testjobstatus` con le
   tabelle `templates`, `template_fields`, `customers`, `generation_log`
   (più 2 record di esempio in `customers`, ricavati dai PDF campione
   ALEX-0605 / ALEX-0606).
4. Verifica che `config.php` abbia `DB_USER = 'root'`, `DB_PASS = ''`
   (default XAMPP — di solito non serve modificare nulla).
5. Apri `http://localhost/laser-pdf-mapper/index.php`.

Le cartelle `templates/` e `generated/` devono essere scrivibili dal server
web (su XAMPP/Windows di solito lo sono già).

## 2. Setup produzione (Altervista)

1. Pannello Altervista → **MySQL** → apri phpMyAdmin del tuo spazio →
   **Importa** `database.sql` (se il tuo DB esiste già e non puoi eseguire
   `CREATE DATABASE`, salta la prima riga e importa da `CREATE TABLE
   templates...` in poi).
2. Carica via FTP **tutti** i file del progetto (incluso `vendor/`) nella
   root del tuo spazio (es. `/public_html` o la root indicata da
   Altervista).
3. Modifica `config.php`:
   ```php
   define('DB_NAME', 'tuonome_my_testjobstatus'); // come mostrato nel pannello MySQL
   define('DB_USER', 'tuonome');
   define('DB_PASS', 'la-tua-password-mysql');
   define('APP_DEBUG', false);
   ```
4. Verifica che `templates/` e `generated/` siano scrivibili (permessi
   `755`/`775`; se Altervista si lamenta, prova `777` solo su queste due
   cartelle).
5. Apri `https://tuosito.altervista.org/index.php`.

## 3. Uso dell'editor

1. **Nuovo Template** → trascina (o clicca per selezionare) il PDF base
   nell'Area Canvas. Verrà usata la pagina 1.
2. Trascina le variabili dalla **Libreria Campi** (colonne reali della
   tabella `customers`, lette automaticamente da `INFORMATION_SCHEMA`) sopra
   il PDF, oppure trascina **"Testo Libero"** per un'etichetta statica.
3. Clicca una cella per selezionarla: nel **Pannello Proprietà** puoi
   correggere a mano X, Y, Larghezza, Altezza, font, allineamento, e
   collegarla a un **gruppo di cascata** (vedi sotto).
4. Dai un nome al template e clicca **💾 Salva Template**.
5. Nel pannello **Genera Documento**, scegli un record e clicca
   **📄 Anteprima PDF** (si apre in una nuova scheda) o **⬇ Scarica**.

### Wrap automatico e cascata verticale

Se il valore del database è più lungo della cella tracciata, il testo va
automaticamente a capo dentro i bordi (`MultiCell`), non sovrascrive mai i
contenuti adiacenti del PDF originale (barcode, disegno tecnico, ecc.).

Se vuoi che più celle si comportino come le righe di una tabella (es. una
lista di codici parte), assegna loro lo **stesso "Gruppo cascata"** nel
Pannello Proprietà: se una cella genera più testo del previsto, tutte le
celle sottostanti dello stesso gruppo vengono spinte verso il basso della
stessa quantità, così non si sovrappongono mai.

## 4. Struttura dei file

```
laser-pdf-mapper/
├── database.sql                 Schema + dati di esempio
├── composer.json                Dipendenze (opzionale, vendor/ già pronto)
├── config.php                   Credenziali DB e costanti applicative
├── index.php                    Dashboard IDE (Canvas + Sidebar)
├── upload.php                   Upload e validazione del PDF template
├── save_template.php            Persistenza template + campi (con whitelist colonne)
├── generate.php                 Generazione PDF finale (stream al browser)
├── classes/
│   ├── Database.php             Wrapper PDO + introspezione colonne
│   └── PDFGenerator.php         Motore FPDI/TCPDF: px→mm, MultiCell, cascata
├── assets/
│   ├── css/style.css            UI dark mode
│   └── js/app.js                PDF.js + interact.js + pannello proprietà
├── templates/                   PDF base caricati (scrivibile, protetta da .htaccess)
├── generated/                   Copia di log dei PDF generati (scrivibile)
└── vendor/                      TCPDF + FPDI pre-installati
```

## 5. Note di sicurezza già implementate

- Upload: verifica del MIME type reale (non dell'estensione dichiarata),
  limite 20 MB, nome file rigenerato in modo casuale, `.htaccess` che nega
  l'esecuzione di PHP dentro `templates/` e `generated/`.
- `save_template.php`: ogni `db_variable` ricevuto dal frontend viene
  validato contro le colonne reali di `customers` via
  `INFORMATION_SCHEMA` — un payload manomesso non può agganciare un campo
  PDF a una colonna arbitraria del database.
- Tutte le query usano PDO con parametri bindati (nessuna concatenazione di
  input utente in SQL).
- `generate.php` logga ogni generazione in `generation_log` per
  tracciabilità.

## 6. Estendere il progetto

- Per cambiare la tabella dati di origine, modifica `DATA_TABLE` in
  `config.php` (la Libreria Campi si aggiorna da sola).
- Per font con caratteri non latini, sostituisci `helvetica`/`times`/
  `courier` con un font TrueType TCPDF (serve aggiungerlo in `vendor/...
  /fonts`, qui inclusi solo i font core per restare leggeri).

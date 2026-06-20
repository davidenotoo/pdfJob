<?php

declare(strict_types=1);

use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * PDFGenerator.php
 * -----------------------------------------------------------------------
 * Motore di fusione Dati + Template PDF.
 *
 * Funzionamento:
 *  1. Importa la pagina 1 del PDF template (caricato dall'utente nell'IDE)
 *     come "pagina di sfondo" tramite FPDI (useTemplate).
 *  2. Per ogni campo definito nel template (tabella template_fields),
 *     calcola le coordinate in millimetri a partire dai pixel registrati
 *     nel canvas dell'editor (conversione px -> mm in base al rapporto
 *     reale fra dimensione del canvas e dimensione fisica della pagina).
 *  3. Scrive il valore con MultiCell(): se il testo e' piu' lungo della
 *     larghezza della cella, va automaticamente a capo restando dentro
 *     i bordi tracciati nell'IDE (mai sovrapposizioni con barcode/disegni).
 *  4. CASCATA: i campi che condividono lo stesso "group_name" (es. le
 *     righe di una tabella) vengono ordinati dall'alto verso il basso;
 *     se una cella genera piu' righe di quelle previste (overflow), tutte
 *     le celle successive dello stesso gruppo vengono spinte verso il
 *     basso della stessa quantita', cosi' non si sovrappongono mai.
 * -----------------------------------------------------------------------
 */
class PDFGenerator
{
    private Fpdi $pdf;

    /** Larghezza/altezza pagina PDF in millimetri (dal template originale) */
    private float $pageWidthMm;
    private float $pageHeightMm;

    public function __construct()
    {
        $this->pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(0, 0, 0);
        $this->pdf->SetAutoPageBreak(false, 0);
        $this->pdf->SetCreator('Web Tuned - Laser PDF Mapper');
    }

    /**
     * Costruisce il PDF finale.
     *
     * @param string                $templateFilePath Percorso assoluto del PDF template sorgente
     * @param array<int,array>      $fields           Righe da template_fields (gia' ordinate per group/field_order)
     * @param array<string,mixed>   $data             Riga del record dati (es. da tabella customers)
     * @param float                 $canvasWidthPx    Larghezza in px del canvas usato nell'editor per quel template
     * @param float                 $canvasHeightPx   Altezza in px del canvas usato nell'editor per quel template
     */
    public function build(
        string $templateFilePath,
        array $fields,
        array $data,
        float $canvasWidthPx,
        float $canvasHeightPx
    ): void {
        if (!is_file($templateFilePath)) {
            throw new RuntimeException('File template PDF non trovato sul server: ' . basename($templateFilePath));
        }
        if ($canvasWidthPx <= 0 || $canvasHeightPx <= 0) {
            throw new RuntimeException('Dimensioni canvas del template non valide.');
        }

        // 1. Importa la pagina 1 del template come sfondo
        $this->pdf->setSourceFile($templateFilePath);
        $tplIdx = $this->pdf->importPage(1);
        $size = $this->pdf->getTemplateSize($tplIdx); // mm, perche' il documento e' in unita' 'mm'

        $this->pageWidthMm  = (float) $size['width'];
        $this->pageHeightMm = (float) $size['height'];

        $this->pdf->AddPage($size['orientation'], [$this->pageWidthMm, $this->pageHeightMm]);
        $this->pdf->useTemplate($tplIdx, 0, 0, $this->pageWidthMm, $this->pageHeightMm);

        // 2. Fattori di conversione px (editor) -> mm (PDF reale)
        $scaleX = $this->pageWidthMm / $canvasWidthPx;
        $scaleY = $this->pageHeightMm / $canvasHeightPx;

        // 3. Ordina: prima per gruppo (i campi senza gruppo restano indipendenti
        //    e non cascano su nessun altro), poi per field_order/Y crescente,
        //    cosi' la cascata avviene sempre dall'alto verso il basso.
        usort($fields, function (array $a, array $b): int {
            $groupA = $a['group_name'] ?? '';
            $groupB = $b['group_name'] ?? '';
            if ($groupA !== $groupB) {
                return $groupA <=> $groupB;
            }
            $orderCmp = ($a['field_order'] ?? 0) <=> ($b['field_order'] ?? 0);
            if ($orderCmp !== 0) {
                return $orderCmp;
            }
            return ((float) $a['pos_y']) <=> ((float) $b['pos_y']);
        });

        // Offset cumulativo di spostamento verticale (mm), per ciascun gruppo
        $groupOffset = [];

        foreach ($fields as $i => $field) {
            $value = $this->resolveFieldValue($field, $data);

            // Un campo senza group_name e' "solista": non riceve offset da
            // altri campi e non ne propaga a nessuno (chiave univoca per record)
            $groupKey = $field['group_name'] !== null && $field['group_name'] !== ''
                ? $field['group_name']
                : '__solo_' . $i;

            $offset = $groupOffset[$groupKey] ?? 0.0;

            $xMm = ((float) $field['pos_x']) * $scaleX;
            $yMm = ((float) $field['pos_y']) * $scaleY + $offset;
            $wMm = ((float) $field['width']) * $scaleX;
            $hMm = ((float) $field['height']) * $scaleY;

            $fontStyle = ($field['font_weight'] === 'bold') ? 'B' : '';
            $fontFamily = $field['font_family'] ?: 'helvetica';
            $fontSize = ((float) ($field['font_size'] ?: 9)) * 0.75;
            $align = in_array($field['text_align'], ['L', 'C', 'R', 'J'], true) ? $field['text_align'] : 'L';

            $this->pdf->SetFont($fontFamily, $fontStyle, $fontSize);
            $this->pdf->SetXY($xMm, $yMm);

            // MultiCell con altezza minima $hMm: se il contenuto richiede
            // piu' spazio di quello tracciato nell'IDE, la cella si espande
            // automaticamente verso il basso (mai taglia o sovrascrive testo).
            $this->pdf->MultiCell(
                $wMm,
                $hMm,
                $value,
                0,          // border
                $align,
                false,      // fill
                1,          // ln: vai a capo dopo la cella
                '',
                '',
                true,       // reseth
                0,          // stretch
                false,      // ishtml
                false,       // autopadding
                0,          // maxh (0 = nessun limite, si espande)
                'T',
                false       // fitcell
            );

            $actualBottomMm = $this->pdf->GetY();
            $expectedBottomMm = $yMm + $hMm;
            $overflow = max(0.0, $actualBottomMm - $expectedBottomMm);

            if ($overflow > 0.01) {
                $groupOffset[$groupKey] = $offset + $overflow;
            }
        }
    }

    /**
     * Determina il testo da scrivere per un campo: valore dal DB se il
     * campo e' agganciato a una colonna (db_variable), altrimenti il
     * testo statico configurato nel pannello proprieta'.
     */
    private function resolveFieldValue(array $field, array $data): string
    {
        if (!empty($field['db_variable'])) {
            $raw = $data[$field['db_variable']] ?? '';
            return $this->formatValue($raw);
        }

        return (string) ($field['static_text'] ?? '');
    }

    /**
     * Normalizza i valori provenienti dal DB per la stampa:
     * - le date in formato Y-m-d (o Y-m-d H:i:s) diventano d/m/Y, lo
     *   standard usato nei report (vedi "Due Date" 2022/12/05 nei PDF
     *   campione, "Order date 21/11/22" ecc.)
     * - i NULL diventano stringa vuota (mai la scritta "NULL" stampata)
     */
    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}:\d{2})?$/', $value) === 1) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('d/m/Y', $timestamp);
            }
        }

        return (string) $value;
    }

    /**
     * Invia il PDF al browser.
     * @param bool $download true = forza il download, false = anteprima inline
     */
    public function stream(string $filename, bool $download = false): void
    {
        $this->pdf->Output($filename, $download ? 'D' : 'I');
    }

    /** Salva il PDF su filesystem e ne restituisce il percorso assoluto. */
    public function save(string $filePath): string
    {
        $this->pdf->Output($filePath, 'F');
        return $filePath;
    }
}

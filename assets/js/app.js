/* =========================================================================
 * app.js — Laser PDF Mapper (Web Tuned)
 * -------------------------------------------------------------------------
 * Il DOM e' la fonte di verita': ogni cella (.field-box) porta con se',
 * negli attributi data-* e nello style inline (left/top/width/height),
 * tutto cio' che serve per essere salvata. Non esiste uno stato JS
 * parallelo da tenere sincronizzato: si legge/scrive sempre il DOM.
 * ========================================================================= */

(function () {
    'use strict';

    // ===================== Setup PDF.js =====================
    pdfjsLib.GlobalWorkerOptions.workerSrc =
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    // ===================== Stato minimo (non duplicato dal DOM) =====================
    const state = {
        templateId: null,
        pageWidthMm: null,
        pageHeightMm: null,
        canvasWidthPx: null,   // dimensione CSS effettiva del canvas: e' lo spazio di
        canvasHeightPx: null,  // coordinate condiviso da tutte le field-box
        pendingUpload: null,   // { filename, url, page_width_mm, page_height_mm } finche' non si salva
        selectedEl: null,
        fieldIdCounter: 0,
    };

    // ===================== Riferimenti DOM =====================
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('file-input');
    const overlay = document.getElementById('overlay') || document.querySelector('.overlay');
    const pdfCanvas = document.getElementById('pdf-canvas');
    const btnSaveTemplate = document.getElementById('save-template-btn') || document.getElementById('btn-save-template');
    const saveStatusEl = document.getElementById('save-status');

    // Mappatura selettori pannello proprietà con fallback flessibili basati sulle classi CSS
    const propFields = {
        name: document.getElementById('prop-name') || document.getElementById('field-name') || document.querySelector('[data-prop="name"]'),
        text: document.getElementById('prop-text') || document.getElementById('property-input-text') || document.getElementById('field-text') || document.querySelector('[data-prop="text"]'),
        fontSize: document.getElementById('prop-font-size') || document.getElementById('field-font-size') || document.querySelector('[data-prop="font-size"]'),
        align: document.getElementById('prop-align') || document.getElementById('field-align') || document.querySelector('[data-prop="align"]')
    };

    // =========================================================================
    // TOAST & STATUS BAR
    // =========================================================================
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container') || document.body;
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }

    function updateStatusBar() {
        const statusText = document.getElementById('status-text');
        if (!statusText) return;
        if (state.pageWidthMm && state.pageHeightMm) {
            statusText.innerHTML = `Template ID: <span class="hl">${state.templateId || 'Nuovo'}</span> | Dimensioni PDF: <span class="hl">${state.pageWidthMm}x${state.pageHeightMm} mm</span>`;
        } else {
            statusText.textContent = 'Nessun template caricato. Carica un PDF per iniziare.';
        }
    }

    // =========================================================================
    // PDF RENDERING
    // =========================================================================
    async function renderPdf(pdfUrl, forcedWidthPx = null) {
        try {
            const loadingTask = pdfjsLib.getDocument(pdfUrl);
            const pdf = await loadingTask.promise;
            const page = await pdf.getPage(1);

            const baseWidth = forcedWidthPx || 800;
            const unscaledViewport = page.getViewport({ scale: 1 });
            const scale = baseWidth / unscaledViewport.width;
            const viewport = page.getViewport({ scale: scale });

            pdfCanvas.width = viewport.width;
            pdfCanvas.height = viewport.height;
            state.canvasWidthPx = viewport.width;
            state.canvasHeightPx = viewport.height;

            if (overlay) {
                overlay.style.width = `${viewport.width}px`;
                overlay.style.height = `${viewport.height}px`;
            }

            const renderContext = {
                canvasContext: pdfCanvas.getContext('2d'),
                viewport: viewport
            };
            await page.render(renderContext).promise;
        } catch (err) {
            console.error('Errore nel rendering del PDF:', err);
            throw err;
        }
    }

    // =========================================================================
    // DROPZONE & UPLOAD
    // =========================================================================
    function setupDropzone() {
        if (!dropzone || !fileInput) return;

        dropzone.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', (e) => handleFiles(e.target.files));

        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });

        dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));

        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            handleFiles(e.dataTransfer.files);
        });
    }

   async function handleFiles(files) {
        if (!files || files.length === 0) return;
        const file = files[0];
        if (file.type !== 'application/pdf') {
            showToast('Il file deve essere un PDF.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('pdf_file', file);

        try {
            const response = await fetch('upload.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                state.templateId = data.template_id || null;
                state.pageWidthMm = data.page_width_mm;
                state.pageHeightMm = data.page_height_mm;
                
                await renderPdf(data.url);
                if (overlay) overlay.querySelectorAll('.field-box').forEach(el => el.remove());
                
                // --- QUESTE TRE RIGHE SONO QUELLE CHE MANCAVANO ---
                const canvasStage = document.getElementById('canvas-stage');
                if (dropzone) dropzone.classList.add('hidden');
                if (canvasStage) canvasStage.classList.remove('hidden');
                // --------------------------------------------------

                updateStatusBar();
                showToast('PDF caricato con successo.');
            } else {
                showToast(data.error || 'Errore durante l\'upload del PDF.', 'error');
            }
        } catch (err) {
            console.error(err);
            showToast('Errore di rete durante l\'upload.', 'error');
        }
    }

    // =========================================================================
    // INTERAZIONE CELLE (SPOSTAMENTO, RIDIMENSIONAMENTO E SELEZIONE)
    // =========================================================================
    function createFieldBox(config) {
        state.fieldIdCounter++;
        const id = config.id || `field_${Date.now()}_${state.fieldIdCounter}`;

        const el = document.createElement('div');
        el.className = 'field-box';
        el.id = id;
        
        // Configurazione iniziale dei nodi descrittivi nel Dataset
        el.dataset.id = id;
        el.dataset.fieldName = config.fieldName || `campo_${state.fieldIdCounter}`;
        el.dataset.staticText = config.staticText || '';
        el.dataset.fontSize = config.fontSize || '10';
        el.dataset.align = config.align || 'L';

        // Geometria inline espressa rigorosamente in pixel per il mappaggio dell'overlay
        el.style.position = 'absolute';
        el.style.left = `${config.left || 40}px`;
        el.style.top = `${config.top || 40}px`;
        el.style.width = `${config.width || 120}px`;
        el.style.height = `${config.height || 30}px`;

        el.innerHTML = `
            <div class="field-label"></div>
            <div class="resize-handle"></div>
        `;

        updateFieldBoxLabel(el);
        setupFieldEvents(el);

        if (overlay) overlay.appendChild(el);
        return el;
    }

    function setupFieldEvents(el) {
        el.addEventListener('mousedown', (e) => {
            // Se l'evento intercetta l'ancora di ridimensionamento, isola il flusso dal drag nativo
            if (e.target.classList.contains('resize-handle')) {
                e.preventDefault();
                e.stopPropagation();
                startResizing(el, e);
                return;
            }

            e.preventDefault();
            selectField(el);
            startDragging(el, e);
        });
    }

    function selectField(el) {
        if (state.selectedEl) {
            state.selectedEl.classList.remove('selected');
        }
        state.selectedEl = el;
        el.classList.add('selected');

        // Allineamento bidirezionale: compila la form leggendo direttamente gli attributi del DOM
        if (propFields.name) propFields.name.value = el.dataset.fieldName || '';
        if (propFields.text) propFields.text.value = el.dataset.staticText || '';
        if (propFields.fontSize) propFields.fontSize.value = el.dataset.fontSize || '10';
        if (propFields.align) propFields.align.value = el.dataset.align || 'L';
        
        const panel = document.getElementById('properties-panel') || document.querySelector('.properties-panel');
        if (panel) panel.classList.add('active');
    }

    function deselectAll() {
        if (state.selectedEl) {
            state.selectedEl.classList.remove('selected');
            state.selectedEl = null;
        }
        const panel = document.getElementById('properties-panel') || document.querySelector('.properties-panel');
        if (panel) panel.classList.remove('active');
    }

    // Algoritmo di Dragging per lo spostamento delle celle sull'asse cartesiano del Canvas
    function startDragging(el, e) {
        const bounds = overlay.getBoundingClientRect();
        const startX = e.clientX;
        const startY = e.clientY;
        const initialLeft = parseInt(el.style.left, 10) || 0;
        const initialTop = parseInt(el.style.top, 10) || 0;

        function onMouseMove(moveEvent) {
            const deltaX = moveEvent.clientX - startX;
            const deltaY = moveEvent.clientY - startY;

            let newLeft = initialLeft + deltaX;
            let newTop = initialTop + deltaY;

            // Limitazione del perimetro all'interno dell'overlay di sfondo del PDF
            const maxLeft = bounds.width - el.offsetWidth;
            const maxTop = bounds.height - el.offsetHeight;

            newLeft = Math.max(0, Math.min(newLeft, maxLeft));
            newTop = Math.max(0, Math.min(newTop, maxTop));

            el.style.left = `${newLeft}px`;
            el.style.top = `${newTop}px`;
        }

        function onMouseUp() {
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
        }

        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    }

    // Algoritmo di Ridimensionamento basato su trascinamento della maniglia d'angolo
    function startResizing(el, e) {
        const bounds = overlay.getBoundingClientRect();
        const startX = e.clientX;
        const startY = e.clientY;
        const initialWidth = parseInt(el.style.width, 10) || el.offsetWidth;
        const initialHeight = parseInt(el.style.height, 10) || el.offsetHeight;

        function onMouseMove(moveEvent) {
            const deltaX = moveEvent.clientX - startX;
            const deltaY = moveEvent.clientY - startY;

            let newWidth = initialWidth + deltaX;
            let newHeight = initialHeight + deltaY;

            // Dimensioni minime di sicurezza della cella e vincolo sul bordo destro del contenitore
            const currentLeft = parseInt(el.style.left, 10) || 0;
            const currentTop = parseInt(el.style.top, 10) || 0;

            newWidth = Math.max(30, Math.min(newWidth, bounds.width - currentLeft));
            newHeight = Math.max(16, Math.min(newHeight, bounds.height - currentTop));

            el.style.width = `${newWidth}px`;
            el.style.height = `${newHeight}px`;
        }

        function onMouseUp() {
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
        }

        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    }

    // =========================================================================
    // PANNELLO DELLE PROPRIETÀ (SINCRO REAL-TIME: INPUT -> DOM)
    // =========================================================================
    function setupPropertiesPanel() {
        if (overlay) {
            overlay.addEventListener('mousedown', (e) => {
                if (e.target === overlay || e.target.id === 'pdf-canvas') {
                    deselectAll();
                }
            });
        }

        // Ascoltatori reattivi sui campi di input: salvano i dati nel DOM ad ogni pressione di tasto
        if (propFields.name) {
            propFields.name.addEventListener('input', (e) => {
                if (!state.selectedEl) return;
                state.selectedEl.dataset.fieldName = e.target.value;
                updateFieldBoxLabel(state.selectedEl);
            });
        }

        if (propFields.text) {
            propFields.text.addEventListener('input', (e) => {
                if (!state.selectedEl) return;
                state.selectedEl.dataset.staticText = e.target.value;
                updateFieldBoxLabel(state.selectedEl);
            });
        }

        if (propFields.fontSize) {
            propFields.fontSize.addEventListener('input', (e) => {
                if (!state.selectedEl) return;
                state.selectedEl.dataset.fontSize = e.target.value;
            });
        }

        if (propFields.align) {
            propFields.align.addEventListener('change', (e) => {
                if (!state.selectedEl) return;
                state.selectedEl.dataset.align = e.target.value;
            });
        }
    }

    function updateFieldBoxLabel(el) {
        const label = el.querySelector('.field-label');
        if (label) {
            const txt = (el.dataset.staticText || '').trim();
            // Mostra il testo personalizzato fisso; se vuoto, mostra l'identificatore di stringa dinamica
            label.textContent = txt !== '' ? txt : (el.dataset.fieldName || '');
        }
    }

   // =========================================================================
    // LIBRERIA CAMPI (DELEGAZIONE EVENTI GLOBALE - BLINDATA)
    // =========================================================================
    function setupFieldLibrary() {
        // 1. GESTIONE CLICK GLOBALE (Ignora la struttura DOM)
        document.addEventListener('click', function(e) {
            // Cerca se hai cliccato qualcosa che assomiglia a una celletta della sidebar
            const cell = e.target.closest('.sidebar button, .sidebar li, .sidebar div, [data-name], [data-field]');
            
            // Se non è una celletta della sidebar, ignora il click
            if (!cell || e.target.closest('.field-box') || e.target.closest('#properties-panel')) return;
            
            e.preventDefault();
            
            if (!state.canvasWidthPx) {
                showToast('Devi prima caricare il PDF "BLANK"!', 'error');
                return;
            }
            
            // Estrae il nome. Cerca gli attributi data-name o data-field, altrimenti prende il testo del bottone
            const nomeCampo = cell.getAttribute('data-name') || cell.getAttribute('data-field') || cell.textContent.trim();
            
            const field = createFieldBox({
                fieldName: nomeCampo,
                staticText: '',
                left: 50,
                top: 50,
                width: 150,
                height: 26
            });
            selectField(field);
        });

        // 2. FORZATURA DEL DRAG-AND-DROP HTML5
        // Rende draggabile qualsiasi cosa nella sidebar
        const sidebar = document.querySelector('.sidebar') || document.querySelector('aside');
        if (sidebar) {
            const items = sidebar.querySelectorAll('button, li, div');
            items.forEach(item => {
                item.setAttribute('draggable', 'true');
                item.style.cursor = 'grab';
            });
        }

        // Cattura l'inizio del trascinamento a livello globale
        document.addEventListener('dragstart', (e) => {
            const cell = e.target.closest('[draggable="true"]');
            if (!cell) return;
            
            const nomeCampo = cell.getAttribute('data-name') || cell.getAttribute('data-field') || cell.textContent.trim();
            e.dataTransfer.setData('text/plain', nomeCampo);
        });

        // 3. LA ZONA DI ATTERRAGGIO (IL PDF)
        const dropArea = document.getElementById('canvas-stage') || document.getElementById('overlay') || document.body;

        // FONDAMENTALE: Se non si previene il default qui, il browser rifiuta il drop
        dropArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        });

        dropArea.addEventListener('drop', (e) => {
            e.preventDefault();
            
            // Ignora se stai droppando un file PDF vero e proprio
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) return;
            
            if (!state.canvasWidthPx) {
                showToast('Nessun PDF caricato.', 'error');
                return;
            }

            const nomeCampo = e.dataTransfer.getData('text/plain');
            if (!nomeCampo) return; // Non era una cella valida

            // Calcolo coordinate di caduta (protezione contro i bordi sballati)
            let dropX = e.clientX;
            let dropY = e.clientY;
            
            const overlayTarget = document.getElementById('overlay');
            if (overlayTarget) {
                const rect = overlayTarget.getBoundingClientRect();
                dropX = e.clientX - rect.left;
                dropY = e.clientY - rect.top;
            }

            const field = createFieldBox({
                fieldName: nomeCampo,
                staticText: '',
                left: Math.max(0, dropX),
                top: Math.max(0, dropY),
                width: 150,
                height: 26
            });
            selectField(field);
        });
    }

    // =========================================================================
    // IDRATAZIONE (CARICAMENTO GEOMETRIE DA DB ESPRESSE IN MM)
    // =========================================================================
    function hydrateExistingFields() {
        const dataEl = document.getElementById('template-data');
        if (!dataEl) return;
        const tplData = JSON.parse(dataEl.textContent || '{}');
        if (!tplData.fields || !Array.isArray(tplData.fields)) return;

        // Fattore di conversione metrico-lineare da millimetri a pixel basato sulla scala del canvas generato
        const mmToPxX = state.canvasWidthPx / state.pageWidthMm;
        const mmToPxY = state.canvasHeightPx / state.pageHeightMm;

        tplData.fields.forEach(f => {
            createFieldBox({
                id: f.id || null,
                fieldName: f.field_name,
                staticText: f.static_text || '',
                fontSize: f.font_size || '10',
                align: f.align || 'L',
                left: Math.round(f.x_mm * mmToPxX),
                top: Math.round(f.y_mm * mmToPxY),
                width: Math.round(f.width_mm * mmToPxX),
                height: Math.round(f.height_mm * mmToPxY)
            });
        });
    }

    // =========================================================================
    // CONVERSIONE SPAZIO PX -> MM E PERSISTENZA VIA POST
    // =========================================================================
    async function saveTemplate() {
        if (!state.templateId && !state.pageWidthMm) {
            showToast('Nessun dataset di mappatura attivo rilevato.', 'error');
            return;
        }

        if (btnSaveTemplate) btnSaveTemplate.disabled = true;
        if (saveStatusEl) {
            saveStatusEl.textContent = 'Salvataggio...';
            saveStatusEl.className = 'save-status';
        }

        const fieldBoxes = document.querySelectorAll('.field-box');
        const fieldsData = [];

        // Rapporto inverso da pixel a millimetri per garantire l'assoluta indipendenza dalla risoluzione CSS
        const pxToMmX = state.pageWidthMm / state.canvasWidthPx;
        const pxToMmY = state.pageHeightMm / state.canvasHeightPx;

        fieldBoxes.forEach(box => {
            const leftPx = parseInt(box.style.left, 10) || 0;
            const topPx = parseInt(box.style.top, 10) || 0;
            const widthPx = parseInt(box.style.width, 10) || box.offsetWidth;
            const heightPx = parseInt(box.style.height, 10) || box.offsetHeight;

            fieldsData.push({
                field_name: box.dataset.fieldName,
                static_text: box.dataset.staticText || '',
                font_size: box.dataset.fontSize || '10',
                align: box.dataset.align || 'L',
                x_mm: parseFloat((leftPx * pxToMmX).toFixed(2)),
                y_mm: parseFloat((topPx * pxToMmY).toFixed(2)),
                width_mm: parseFloat((widthPx * pxToMmX).toFixed(2)),
                height_mm: parseFloat((heightPx * pxToMmY).toFixed(2))
            });
        });

        const payload = {
            template_id: state.templateId,
            fields: fieldsData
        };

        try {
            const response = await fetch('save_template.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            const data = await response.json();

            if (data.success) {
                if (saveStatusEl) {
                    saveStatusEl.textContent = 'Modifiche salvate';
                    saveStatusEl.className = 'save-status ok';
                }
                showToast('Template salvato (' + data.fields_count + ' campi).');
            } else {
                if (saveStatusEl) {
                    saveStatusEl.textContent = 'Errore di scrittura';
                    saveStatusEl.className = 'save-status err';
                }
                showToast(data.error || 'Errore interno nel salvataggio.', 'error');
            }
        } catch (err) {
            console.error(err);
            if (saveStatusEl) {
                saveStatusEl.textContent = 'Errore di rete';
                saveStatusEl.className = 'save-status err';
            }
            showToast('Connessione interrotta durante il salvataggio.', 'error');
        } finally {
            if (btnSaveTemplate) btnSaveTemplate.disabled = false;
        }
    }

    // =========================================================================
    // INIZIALIZZAZIONE APPLICAZIONE
    // =========================================================================
    async function init() {
        const dataEl = document.getElementById('template-data');
        const tplData = dataEl ? JSON.parse(dataEl.textContent || '{}') : {};

        setupDropzone();
        setupFieldLibrary();
        setupPropertiesPanel();
        
        if (btnSaveTemplate) {
            btnSaveTemplate.addEventListener('click', saveTemplate);
        }

        if (tplData.templateId && tplData.pdfUrl) {
            state.templateId = tplData.templateId;
            state.pageWidthMm = tplData.pageWidthMm;
            state.pageHeightMm = tplData.pageHeightMm;

            try {
                await renderPdf(tplData.pdfUrl, tplData.canvasWidthPx || 800);
                hydrateExistingFields();
                updateStatusBar();
            } catch (err) {
                showToast('Impossibile renderizzare il PDF del template di origine.', 'error');
            }
        } else {
            updateStatusBar();
        }
    }

    document.addEventListener('DOMContentLoaded', init);
})();
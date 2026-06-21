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
    const canvasStage = document.getElementById('canvas-stage');
    const canvasInner = document.getElementById('canvas-inner');
    const pdfCanvas = document.getElementById('pdf-canvas');
    const overlay = document.getElementById('field-overlay');
    const canvasScroll = document.getElementById('canvas-scroll');

    const templateNameInput = document.getElementById('template-name-input');
    const btnSaveTemplate = document.getElementById('btn-save-template');
    const saveStatusEl = document.getElementById('save-status');

    const propertiesPanel = document.getElementById('properties-panel');
    const emptyPropertiesHint = document.getElementById('empty-properties-hint');
    const generatePanel = document.getElementById('generate-panel');

    const propDbBlock = document.getElementById('prop-db-block');
    const propDbVariable = document.getElementById('prop-db-variable');
    const propStaticBlock = document.getElementById('prop-static-block');
    const propStaticText = document.getElementById('prop-static-text');
    const propX = document.getElementById('prop-x');
    const propY = document.getElementById('prop-y');
    const propW = document.getElementById('prop-w');
    const propH = document.getElementById('prop-h');
    const propFontSize = document.getElementById('prop-font-size');
    const propFontFamily = document.getElementById('prop-font-family');
    const propAlign = document.getElementById('prop-align');
    const propBold = document.getElementById('prop-bold');
    const propGroup = document.getElementById('prop-group');
    const btnDeleteField = document.getElementById('btn-delete-field');

    const statusText = document.getElementById('status-text');
    const toastContainer = document.getElementById('toast-container');

    // ===================== Toast =====================
    function showToast(message, type) {
        const el = document.createElement('div');
        el.className = 'toast' + (type === 'error' ? ' toast-error' : '');
        el.textContent = message;
        toastContainer.appendChild(el);
        setTimeout(() => el.remove(), 4200);
    }

    // ===================== Status bar =====================
    function updateStatusBar() {
        if (!state.pageWidthMm) {
            statusText.textContent = 'Nessun template caricato';
            return;
        }
        const count = overlay.querySelectorAll('.field-box').length;
        const scaleXmm = state.canvasWidthPx ? (state.pageWidthMm / state.canvasWidthPx) : 0;
        statusText.innerHTML =
            `Pagina <span class="hl">${state.pageWidthMm.toFixed(1)} × ${state.pageHeightMm.toFixed(1)} mm</span>` +
            `<span class="sep">|</span>Canvas <span class="hl">${Math.round(state.canvasWidthPx)} × ${Math.round(state.canvasHeightPx)} px</span>` +
            `<span class="sep">|</span>Scala <span class="hl">1px ≈ ${scaleXmm.toFixed(3)}mm</span>` +
            `<span class="sep">|</span>Campi <span class="hl">${count}</span>`;
    }

    // =========================================================================
    // RENDERING PDF (PDF.js)
    // =========================================================================

    async function renderPdf(url, lockedWidthPx) {
        const loadingTask = pdfjsLib.getDocument(url);
        const pdf = await loadingTask.promise;
        const page = await pdf.getPage(1);

        const nativeViewport = page.getViewport({ scale: 1 });

        let cssWidth;
        if (lockedWidthPx) {
            cssWidth = lockedWidthPx;
        } else {
            const available = Math.max(360, canvasScroll.clientWidth - 64);
            cssWidth = Math.min(available, 1000);
        }
        const scale = cssWidth / nativeViewport.width;
        const viewport = page.getViewport({ scale });

        const dpr = window.devicePixelRatio || 1;

        pdfCanvas.width = Math.floor(viewport.width * dpr);
        pdfCanvas.height = Math.floor(viewport.height * dpr);
        pdfCanvas.style.width = viewport.width + 'px';
        pdfCanvas.style.height = viewport.height + 'px';

        canvasInner.style.width = viewport.width + 'px';
        canvasInner.style.height = viewport.height + 'px';
        overlay.style.width = viewport.width + 'px';
        overlay.style.height = viewport.height + 'px';

        const ctx = pdfCanvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        await page.render({ canvasContext: ctx, viewport }).promise;

        state.canvasWidthPx = viewport.width;
        state.canvasHeightPx = viewport.height;

        dropzone.classList.add('hidden');
        canvasStage.classList.remove('hidden');
        updateStatusBar();
    }

    // =========================================================================
    // UPLOAD (nuovo template)
    // =========================================================================

    function setupDropzone() {
        dropzone.addEventListener('click', () => fileInput.click());

        ['dragenter', 'dragover'].forEach(evt =>
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                dropzone.classList.add('drag-over');
            })
        );
        ['dragleave', 'drop'].forEach(evt =>
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                dropzone.classList.remove('drag-over');
            })
        );
        dropzone.addEventListener('drop', (e) => {
            const file = e.dataTransfer.files && e.dataTransfer.files[0];
            if (file) handleFileSelected(file);
        });
        fileInput.addEventListener('change', () => {
            if (fileInput.files[0]) handleFileSelected(fileInput.files[0]);
        });
    }

    function handleFileSelected(file) {
        if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
            showToast('Seleziona un file PDF.', 'error');
            return;
        }
        if (file.size > 20 * 1024 * 1024) {
            showToast('Il PDF supera i 20 MB consentiti.', 'error');
            return;
        }
        uploadFile(file);
    }

    async function uploadFile(file) {
        dropzone.classList.add('drag-over');
        const formData = new FormData();
        formData.append('pdf_file', file);

        try {
            const res = await fetch('upload.php', { method: 'POST', body: formData });
            const data = await res.json();
            dropzone.classList.remove('drag-over');

            if (!data.success) {
                showToast(data.error || 'Errore durante il caricamento.', 'error');
                return;
            }
            if (data.pages_warning) {
                showToast(data.pages_warning, 'error');
            }

            state.pendingUpload = data;
            state.pageWidthMm = data.page_width_mm;
            state.pageHeightMm = data.page_height_mm;
            state.templateId = null;

            overlay.innerHTML = '';

            await renderPdf(data.url, null);

            if (!templateNameInput.value.trim()) {
                templateNameInput.value = file.name.replace(/\.pdf$/i, '');
            }

            showToast('PDF caricato. Trascina i campi dalla libreria a sinistra.');
        } catch (err) {
            dropzone.classList.remove('drag-over');
            showToast('Errore di rete durante il caricamento del PDF.', 'error');
        }
    }

    // =========================================================================
    // CREAZIONE / INTERAZIONE CELLE
    // =========================================================================

    const DEFAULT_W = 130;
    const DEFAULT_H = 22;

    function createFieldBox(opts) {
        const el = document.createElement('div');
        el.className = 'field-box';
        el.dataset.fieldId = opts.fieldId || ('new_' + (++state.fieldIdCounter));
        el.dataset.type = opts.type;
        el.dataset.dbVariable = opts.type === 'db' ? opts.dbVariable : '';
        el.dataset.staticText = opts.type === 'static' ? (opts.staticText || 'Testo') : '';
        el.dataset.fontSize = opts.fontSize || 9;
        el.dataset.fontFamily = opts.fontFamily || 'helvetica';
        el.dataset.fontWeight = opts.fontWeight || 'normal';
        el.dataset.textAlign = opts.textAlign || 'L';
        el.dataset.groupName = opts.groupName || '';

        const w = opts.width || DEFAULT_W;
        const h = opts.height || DEFAULT_H;
        let x = opts.x !== undefined ? opts.x : 20;
        let y = opts.y !== undefined ? opts.y : 20;

        x = Math.max(0, Math.min(x, state.canvasWidthPx - w));
        y = Math.max(0, Math.min(y, state.canvasHeightPx - h));

        el.style.left = x + 'px';
        el.style.top = y + 'px';
        el.style.width = w + 'px';
        el.style.height = h + 'px';

        const label = document.createElement('span');
        label.className = 'field-box-label';
        label.textContent = opts.type === 'db' ? opts.dbVariable : (opts.staticText || 'Testo');
        el.appendChild(label);

        const resizer = document.createElement('span');
        resizer.className = 'field-box-resize';
        el.appendChild(resizer);

        overlay.appendChild(el);
        makeInteractive(el);
        
        // Applica gli stili visivi appena creata!
        applyVisualStyles(el);

        el.addEventListener('click', (e) => {
            e.stopPropagation();
            selectField(el);
        });

        updateStatusBar();
        return el;
    }

    function makeInteractive(el) {
        interact(el)
            .draggable({
                listeners: { move: dragMoveListener },
                modifiers: [
                    interact.modifiers.restrictRect({ restriction: overlay, endOnly: false }),
                ],
                inertia: false,
            })
            .resizable({
                // COLLEGATO AL QUADRATINO BLU IN BASSO A DESTRA!
                edges: { left: false, top: false, right: '.field-box-resize', bottom: '.field-box-resize' },
                listeners: { move: resizeMoveListener },
                modifiers: [
                    interact.modifiers.restrictSize({ min: { width: 14, height: 10 } }),
                    interact.modifiers.restrictEdges({ outer: overlay }),
                ],
                inertia: false,
            });
    }

    function dragMoveListener(event) {
        const target = event.target;
        const x = (parseFloat(target.style.left) || 0) + event.dx;
        const y = (parseFloat(target.style.top) || 0) + event.dy;
        target.style.left = x + 'px';
        target.style.top = y + 'px';
        if (target === state.selectedEl) syncPropertiesFromElement(target);
    }

    function resizeMoveListener(event) {
        const target = event.target;
        target.style.width = event.rect.width + 'px';
        target.style.height = event.rect.height + 'px';
        if (target === state.selectedEl) syncPropertiesFromElement(target);
    }

    function hydrateExistingFields() {
        overlay.querySelectorAll('.field-box').forEach((el) => {
            makeInteractive(el);
            applyVisualStyles(el); // Applica la grafica anche ai campi caricati
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                selectField(el);
            });
        });
    }

    // ===================== Drag dalla Libreria Campi =====================
    function setupFieldLibrary() {
        document.querySelectorAll('.field-lib-item').forEach((li) => {
            li.addEventListener('dragstart', (e) => {
                li.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'copy';
                e.dataTransfer.setData('text/plain', JSON.stringify({
                    type: li.dataset.type,
                    dbVariable: li.dataset.dbVariable || null,
                }));
            });
            li.addEventListener('dragend', () => li.classList.remove('dragging'));
        });

        overlay.addEventListener('dragover', (e) => {
            if (!state.canvasWidthPx) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        });

        overlay.addEventListener('drop', (e) => {
            e.preventDefault();
            if (!state.canvasWidthPx) {
                showToast('Carica prima un PDF base.', 'error');
                return;
            }
            let payload;
            try {
                payload = JSON.parse(e.dataTransfer.getData('text/plain'));
            } catch (err) {
                return;
            }
            const rect = overlay.getBoundingClientRect();
            const dropX = e.clientX - rect.left - DEFAULT_W / 2;
            const dropY = e.clientY - rect.top - DEFAULT_H / 2;

            const el = createFieldBox({
                type: payload.type,
                dbVariable: payload.dbVariable,
                staticText: payload.type === 'static' ? 'Testo' : null,
                x: dropX,
                y: dropY,
            });
            selectField(el);
        });
    }

    // =========================================================================
    // PANNELLO PROPRIETÀ E STILI VISIVI
    // =========================================================================

    // FUNZIONE AGGIUNTA PER APPLICARE LA GRAFICA AL DOM
    function applyVisualStyles(el) {
        const label = el.querySelector('.field-box-label');
        if (!label) return;

        label.style.fontSize = (parseFloat(el.dataset.fontSize) || 9) + 'pt';
        
        label.style.fontFamily = el.dataset.fontFamily === 'courier' ? 'monospace' :
                                 el.dataset.fontFamily === 'times' ? 'serif' : 'sans-serif';
                                 
        label.style.fontWeight = el.dataset.fontWeight === 'bold' ? 'bold' : 'normal';

        const align = el.dataset.textAlign;
        label.style.textAlign = align === 'C' ? 'center' : align === 'R' ? 'right' : 'left';
        label.style.display = 'block';
        label.style.width = '100%';
    }

    function selectField(el) {
        overlay.querySelectorAll('.field-box.selected').forEach(e => e.classList.remove('selected'));
        el.classList.add('selected');
        state.selectedEl = el;

        propertiesPanel.classList.remove('hidden');
        emptyPropertiesHint.classList.add('hidden');

        const isDb = el.dataset.type === 'db';
        propDbBlock.classList.toggle('hidden', !isDb);
        propStaticBlock.classList.toggle('hidden', isDb);

        if (isDb) {
            propDbVariable.textContent = el.dataset.dbVariable;
        } else {
            propStaticText.value = el.dataset.staticText;
        }

        syncPropertiesFromElement(el);
    }

    function deselectField() {
        if (state.selectedEl) state.selectedEl.classList.remove('selected');
        state.selectedEl = null;
        propertiesPanel.classList.add('hidden');
        emptyPropertiesHint.classList.remove('hidden');
    }

    function syncPropertiesFromElement(el) {
        propX.value = round1(parseFloat(el.style.left) || 0);
        propY.value = round1(parseFloat(el.style.top) || 0);
        propW.value = round1(parseFloat(el.style.width) || 0);
        propH.value = round1(parseFloat(el.style.height) || 0);
        propFontSize.value = el.dataset.fontSize;
        propFontFamily.value = el.dataset.fontFamily;
        propBold.checked = el.dataset.fontWeight === 'bold';
        propGroup.value = el.dataset.groupName || '';
        setAlignButtons(el.dataset.textAlign);
    }

    function round1(n) { return Math.round(n * 10) / 10; }

    function setAlignButtons(value) {
        propAlign.querySelectorAll('button').forEach(b => {
            b.classList.toggle('active', b.dataset.value === value);
        });
    }

    function updateLabel(el) {
        const label = el.querySelector('.field-box-label');
        label.textContent = el.dataset.type === 'db'
            ? el.dataset.dbVariable
            : (el.dataset.staticText || 'Testo libero');
    }

    function setupPropertiesPanel() {
        propX.addEventListener('input', () => applyGeometry());
        propY.addEventListener('input', () => applyGeometry());
        propW.addEventListener('input', () => applyGeometry());
        propH.addEventListener('input', () => applyGeometry());

        function applyGeometry() {
            const el = state.selectedEl;
            if (!el) return;
            const w = Math.max(5, parseFloat(propW.value) || DEFAULT_W);
            const h = Math.max(5, parseFloat(propH.value) || DEFAULT_H);
            const x = Math.max(0, Math.min(parseFloat(propX.value) || 0, state.canvasWidthPx - w));
            const y = Math.max(0, Math.min(parseFloat(propY.value) || 0, state.canvasHeightPx - h));
            el.style.left = x + 'px';
            el.style.top = y + 'px';
            el.style.width = w + 'px';
            el.style.height = h + 'px';
        }

        // EVENTI MODIFICATI PER AGGIORNARE LA GRAFICA IN TEMPO REALE
        propFontSize.addEventListener('input', () => {
            if (state.selectedEl) {
                state.selectedEl.dataset.fontSize = propFontSize.value || 9;
                applyVisualStyles(state.selectedEl);
            }
        });
        
        propFontFamily.addEventListener('change', () => {
            if (state.selectedEl) {
                state.selectedEl.dataset.fontFamily = propFontFamily.value;
                applyVisualStyles(state.selectedEl);
            }
        });
        
        propBold.addEventListener('change', () => {
            if (state.selectedEl) {
                state.selectedEl.dataset.fontWeight = propBold.checked ? 'bold' : 'normal';
                applyVisualStyles(state.selectedEl);
            }
        });

        propGroup.addEventListener('input', () => {
            if (state.selectedEl) state.selectedEl.dataset.groupName = propGroup.value.trim();
        });
        
        propStaticText.addEventListener('input', () => {
            if (!state.selectedEl) return;
            state.selectedEl.dataset.staticText = propStaticText.value;
            updateLabel(state.selectedEl);
        });

        propAlign.querySelectorAll('button').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (!state.selectedEl) return;
                state.selectedEl.dataset.textAlign = btn.dataset.value;
                setAlignButtons(btn.dataset.value);
                applyVisualStyles(state.selectedEl);
            });
        });

        btnDeleteField.addEventListener('click', () => {
            if (!state.selectedEl) return;
            state.selectedEl.remove();
            deselectField();
            updateStatusBar();
        });

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) deselectField();
        });
    }

    // =========================================================================
    // SALVATAGGIO TEMPLATE
    // =========================================================================

    function collectFieldsPayload() {
        const boxes = Array.from(overlay.querySelectorAll('.field-box'));

        const fields = boxes.map((el) => ({
            db_variable: el.dataset.type === 'db' ? el.dataset.dbVariable : null,
            static_text: el.dataset.type === 'static' ? el.dataset.staticText : null,
            pos_x: parseFloat(el.style.left) || 0,
            pos_y: parseFloat(el.style.top) || 0,
            width: parseFloat(el.style.width) || DEFAULT_W,
            height: parseFloat(el.style.height) || DEFAULT_H,
            font_size: parseFloat(el.dataset.fontSize) || 9,
            font_family: el.dataset.fontFamily || 'helvetica',
            font_weight: el.dataset.fontWeight || 'normal',
            text_align: el.dataset.textAlign || 'L',
            group_name: el.dataset.groupName || null,
        }));

        fields.sort((a, b) => {
            const ga = a.group_name || '';
            const gb = b.group_name || '';
            if (ga !== gb) return ga < gb ? -1 : 1;
            return a.pos_y - b.pos_y;
        });
        fields.forEach((f, i) => { f.field_order = i; });

        return fields;
    }

    async function saveTemplate() {
        const name = templateNameInput.value.trim();
        if (!name) {
            showToast('Inserisci un nome per il template.', 'error');
            templateNameInput.focus();
            return;
        }
        if (!state.canvasWidthPx) {
            showToast('Carica prima un PDF base.', 'error');
            return;
        }

        const payload = {
            template_id: state.templateId,
            name: name,
            filename: state.pendingUpload ? state.pendingUpload.filename : null,
            canvas_width_px: state.canvasWidthPx,
            canvas_height_px: state.canvasHeightPx,
            fields: collectFieldsPayload(),
        };

        btnSaveTemplate.disabled = true;
        saveStatusEl.textContent = 'Salvataggio…';
        saveStatusEl.className = 'save-status';

        try {
            const res = await fetch('save_template.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();

            if (!data.success) {
                saveStatusEl.textContent = 'Errore';
                saveStatusEl.className = 'save-status err';
                showToast(data.error || 'Errore durante il salvataggio.', 'error');
                btnSaveTemplate.disabled = false;
                return;
            }

            if (!state.templateId) {
                window.location.href = 'index.php?template_id=' + data.template_id;
                return;
            }

            saveStatusEl.textContent = 'Salvato ✓ ' + new Date().toLocaleTimeString('it-IT');
            saveStatusEl.className = 'save-status ok';
            showToast('Template salvato (' + data.fields_count + ' campi).');
            btnSaveTemplate.disabled = false;
        } catch (err) {
            saveStatusEl.textContent = 'Errore di rete';
            saveStatusEl.className = 'save-status err';
            showToast('Errore di rete durante il salvataggio.', 'error');
            btnSaveTemplate.disabled = false;
        }
    }

    // =========================================================================
    // INIT
    // =========================================================================

    async function init() {
        const dataEl = document.getElementById('template-data');
        const tplData = JSON.parse(dataEl.textContent || '{}');

        setupDropzone();
        setupFieldLibrary();
        setupPropertiesPanel();
        btnSaveTemplate.addEventListener('click', saveTemplate);

        if (tplData.templateId && tplData.pdfUrl) {
            state.templateId = tplData.templateId;
            state.pageWidthMm = tplData.pageWidthMm;
            state.pageHeightMm = tplData.pageHeightMm;

            try {
                await renderPdf(tplData.pdfUrl, tplData.canvasWidthPx);
                hydrateExistingFields();
                updateStatusBar();
            } catch (err) {
                showToast('Impossibile renderizzare il PDF del template.', 'error');
            }
        } else {
            updateStatusBar();
        }
    }

    document.addEventListener('DOMContentLoaded', init);
})();
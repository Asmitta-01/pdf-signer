(() => {
    const signForm = document.getElementById('signForm');
    if (!signForm) {
        return;
    }

    const pdfStage = document.getElementById('pdfStage');
    const pdfCanvas = document.getElementById('pdfCanvas');
    const signaturesLayer = document.getElementById('signaturesLayer');
    const pageInfoLabel = document.getElementById('pageInfoLabel');
    const prevPageButton = document.getElementById('prevPageButton');
    const nextPageButton = document.getElementById('nextPageButton');

    const addSignatureButton = document.getElementById('addSignatureButton');
    const removeSignatureButton = document.getElementById('removeSignatureButton');
    const clearSignaturesButton = document.getElementById('clearSignaturesButton');
    const signaturesList = document.getElementById('signaturesList');

    const signaturesDataInput = document.getElementById('signaturesData');

    const modeButtons = Array.from(document.querySelectorAll('.sign-mode-btn'));
    const panels = {
        draw: document.getElementById('panel-draw'),
        image: document.getElementById('panel-image'),
        text: document.getElementById('panel-text'),
    };

    const drawCanvas = document.getElementById('drawCanvas');
    const clearDrawCanvasButton = document.getElementById('clearDrawCanvas');
    const signatureImageInput = document.getElementById('signatureImage');
    const signatureImagePreview = document.getElementById('signatureImagePreview');
    const signatureTextInput = document.getElementById('signatureText');
    const signatureFontSelect = document.getElementById('signatureFont');
    const textSignaturePreview = document.getElementById('textSignaturePreview');

    const signatureLivePreview = document.getElementById('signatureLivePreview');
    const signaturePlaceholder = document.getElementById('signaturePlaceholder');

    const pdfContext = pdfCanvas.getContext('2d');
    const drawContext = drawCanvas.getContext('2d');

    let pdfDoc = null;
    let currentPage = 1;
    let totalPages = 1;

    let currentMode = 'draw';
    let drawing = false;
    let hasDrawn = false;
    let imageSignatureData = null;

    let signatures = [];
    let selectedSignatureId = null;
    let interaction = null;

    let signatureCounter = 1;

    // Late-bound renderer for the digital sig overlay (defined later in the file)
    let digitalSigOverlayRenderer = null;

    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

    const syncSignaturesField = () => {
        if (signatures.length === 0) {
            signaturesDataInput.value = '';
            return;
        }

        const payload = signatures.map((signature) => ({
            signatureData: signature.signatureData,
            pageNumber: signature.pageNumber,
            xRatio: Number(signature.xRatio.toFixed(6)),
            yRatio: Number(signature.yRatio.toFixed(6)),
            widthRatio: Number(signature.widthRatio.toFixed(6)),
        }));

        signaturesDataInput.value = JSON.stringify(payload);
    };

    const updateLivePreview = (dataUrl) => {
        if (!dataUrl) {
            signatureLivePreview.classList.add('hidden');
            signaturePlaceholder.classList.remove('hidden');
            return;
        }

        signatureLivePreview.src = dataUrl;
        signatureLivePreview.classList.remove('hidden');
        signaturePlaceholder.classList.add('hidden');
    };

    const getSignatureById = (id) => signatures.find((signature) => signature.id === id) || null;

    const computeDisplayRect = (signature) => {
        let width = clamp(signature.widthRatio * pdfCanvas.width, 50, pdfCanvas.width * 0.9);
        let height = width * signature.aspectRatio;

        if (height > pdfCanvas.height * 0.9) {
            height = pdfCanvas.height * 0.9;
            width = height / Math.max(signature.aspectRatio, 0.01);
        }

        let x = signature.xRatio * pdfCanvas.width;
        let y = signature.yRatio * pdfCanvas.height;

        x = clamp(x, 0, pdfCanvas.width - width);
        y = clamp(y, 0, pdfCanvas.height - height);

        return { x, y, width, height };
    };

    const normalizeFromRect = (signature, rect) => {
        signature.xRatio = rect.x / pdfCanvas.width;
        signature.yRatio = rect.y / pdfCanvas.height;
        signature.widthRatio = rect.width / pdfCanvas.width;
    };

    const selectSignature = (signatureId) => {
        selectedSignatureId = signatureId;
        renderSignaturesLayer();
        renderSignaturesList();
    };

    const renderSignaturesList = () => {
        signaturesList.innerHTML = '';

        if (signatures.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-xs text-slate-400';
            empty.textContent = 'Aucune signature ajoutée.';
            signaturesList.appendChild(empty);
            return;
        }

        signatures.forEach((signature, index) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'flex w-full items-center justify-between rounded-lg border px-3 py-2 text-xs text-left transition';

            const isSelected = signature.id === selectedSignatureId;
            item.className += isSelected
                ? ' border-cyan-300 bg-cyan-500/10 text-cyan-100'
                : ' border-slate-700 bg-slate-900 text-slate-200';

            item.innerHTML = `<span>Signature ${index + 1}</span><span class="text-[11px] opacity-80">Page ${signature.pageNumber}</span>`;
            item.addEventListener('click', async () => {
                selectedSignatureId = signature.id;

                if (currentPage !== signature.pageNumber) {
                    currentPage = signature.pageNumber;
                    await renderPdfPage();
                    return;
                }

                renderSignaturesLayer();
                renderSignaturesList();
            });

            signaturesList.appendChild(item);
        });
    };

    const renderSignaturesLayer = () => {
        signaturesLayer.innerHTML = '';

        const pageSignatures = signatures.filter((signature) => signature.pageNumber === currentPage);
        pageSignatures.forEach((signature) => {
            const rect = computeDisplayRect(signature);
            normalizeFromRect(signature, rect);

            const signatureElement = document.createElement('div');
            signatureElement.className = 'absolute pointer-events-auto select-none touch-none cursor-move border-2 bg-white/90 shadow-lg';
            signatureElement.classList.add(signature.id === selectedSignatureId ? 'border-cyan-300' : 'border-slate-300/70');
            signatureElement.style.left = `${rect.x}px`;
            signatureElement.style.top = `${rect.y}px`;
            signatureElement.style.width = `${rect.width}px`;
            signatureElement.style.height = `${rect.height}px`;
            signatureElement.dataset.signatureId = signature.id;

            const signatureImage = document.createElement('img');
            signatureImage.src = signature.signatureData;
            signatureImage.alt = 'Signature';
            signatureImage.className = 'h-full w-full object-contain';

            const resizeHandle = document.createElement('div');
            resizeHandle.className = 'absolute -bottom-2 -right-2 h-5 w-5 cursor-nwse-resize rounded-full border border-cyan-100 bg-cyan-400 shadow';
            resizeHandle.dataset.resizeHandle = '1';

            signatureElement.appendChild(signatureImage);
            signatureElement.appendChild(resizeHandle);

            signatureElement.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                selectSignature(signature.id);

                if (event.target instanceof HTMLElement && event.target.dataset.resizeHandle === '1') {
                    interaction = {
                        mode: 'resize',
                        signatureId: signature.id,
                        pointerX: event.clientX,
                        startX: rect.x,
                        startY: rect.y,
                        startWidth: rect.width,
                    };
                    return;
                }

                interaction = {
                    mode: 'move',
                    signatureId: signature.id,
                    pointerX: event.clientX,
                    pointerY: event.clientY,
                    startX: rect.x,
                    startY: rect.y,
                    width: rect.width,
                    height: rect.height,
                };
            });

            signaturesLayer.appendChild(signatureElement);
        });

        syncSignaturesField();
        digitalSigOverlayRenderer?.();
    };

    const moveSignature = (signature, nextX, nextY, width, height) => {
        const x = clamp(nextX, 0, pdfCanvas.width - width);
        const y = clamp(nextY, 0, pdfCanvas.height - height);
        normalizeFromRect(signature, { x, y, width, height });
    };

    window.addEventListener('pointermove', (event) => {
        // ── Digital sig move/resize ──────────────────────────────────────────
        if (window._digitalSigInteraction && window._digitalSig) {
            const dsI = window._digitalSigInteraction;
            const ds = window._digitalSig;

            if (dsI.mode === 'move') {
                const deltaX = event.clientX - dsI.pointerX;
                const deltaY = event.clientY - dsI.pointerY;
                ds.xRatio = clamp(dsI.startX + deltaX, 0, pdfCanvas.width - dsI.width) / pdfCanvas.width;
                ds.yRatio = clamp(dsI.startY + deltaY, 0, pdfCanvas.height - dsI.height) / pdfCanvas.height;
            } else if (dsI.mode === 'resize') {
                const deltaX = event.clientX - dsI.pointerX;
                const newW = clamp(dsI.startWidth + deltaX, 80, pdfCanvas.width * 0.9);
                ds.widthRatio = newW / pdfCanvas.width;
            }

            digitalSigOverlayRenderer?.();
            return;
        }

        // ── Regular signature move/resize ────────────────────────────────────
        if (!interaction) {
            return;
        }

        const signature = getSignatureById(interaction.signatureId);
        if (!signature || signature.pageNumber !== currentPage) {
            return;
        }

        if (interaction.mode === 'move') {
            const deltaX = event.clientX - interaction.pointerX;
            const deltaY = event.clientY - interaction.pointerY;

            moveSignature(
                signature,
                interaction.startX + deltaX,
                interaction.startY + deltaY,
                interaction.width,
                interaction.height,
            );

            renderSignaturesLayer();
            return;
        }

        if (interaction.mode === 'resize') {
            const deltaX = event.clientX - interaction.pointerX;
            let width = clamp(interaction.startWidth + deltaX, 50, pdfCanvas.width * 0.9);
            let height = width * signature.aspectRatio;

            const maxWidth = pdfCanvas.width - interaction.startX;
            const maxHeight = pdfCanvas.height - interaction.startY;

            if (width > maxWidth) {
                width = maxWidth;
                height = width * signature.aspectRatio;
            }

            if (height > maxHeight) {
                height = maxHeight;
                width = height / Math.max(signature.aspectRatio, 0.01);
            }

            normalizeFromRect(signature, {
                x: interaction.startX,
                y: interaction.startY,
                width,
                height,
            });

            renderSignaturesLayer();
        }
    });

    window.addEventListener('pointerup', () => {
        interaction = null;
        window._digitalSigInteraction = null;
    });

    pdfCanvas.addEventListener('click', (event) => {
        if (!selectedSignatureId) {
            return;
        }

        const signature = getSignatureById(selectedSignatureId);
        if (!signature || signature.pageNumber !== currentPage) {
            return;
        }

        const rect = computeDisplayRect(signature);
        const canvasRect = pdfCanvas.getBoundingClientRect();

        const clickX = event.clientX - canvasRect.left;
        const clickY = event.clientY - canvasRect.top;

        moveSignature(signature, clickX - (rect.width / 2), clickY - (rect.height / 2), rect.width, rect.height);
        renderSignaturesLayer();
    });

    const getImageAspectRatio = (dataUrl) => new Promise((resolve) => {
        const image = new Image();
        image.onload = () => {
            if (!image.naturalWidth || !image.naturalHeight) {
                resolve(0.35);
                return;
            }

            resolve(image.naturalHeight / image.naturalWidth);
        };

        image.onerror = () => resolve(0.35);
        image.src = dataUrl;
    });

    const buildTextSignature = async () => {
        const selectedFont = (signatureFontSelect?.value || 'Pacifico').trim();

        if (document.fonts && document.fonts.ready) {
            await document.fonts.ready;
            try {
                await document.fonts.load(`82px "${selectedFont}"`);
            } catch (error) {
                // Ignore and fallback to cursive if a font cannot be loaded at runtime.
            }
        }

        const signatureText = (signatureTextInput.value || '').trim();
        if (!signatureText) {
            return null;
        }

        const renderer = document.createElement('canvas');
        const context = renderer.getContext('2d');

        context.font = `82px "${selectedFont}", cursive`;
        const measuredWidth = context.measureText(signatureText).width;

        renderer.width = Math.max(360, Math.ceil(measuredWidth) + 120);
        renderer.height = 220;

        context.font = `82px "${selectedFont}", cursive`;
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillStyle = '#111827';
        context.fillText(signatureText, renderer.width / 2, renderer.height / 2);

        return renderer.toDataURL('image/png');
    };

    const resolveCurrentSignatureData = async () => {
        if (currentMode === 'draw') {
            if (!hasDrawn) {
                return null;
            }

            return drawCanvas.toDataURL('image/png');
        }

        if (currentMode === 'image') {
            return imageSignatureData;
        }

        if (currentMode === 'text') {
            return buildTextSignature();
        }

        return null;
    };

    const setMode = (mode) => {
        currentMode = mode;

        modeButtons.forEach((button) => {
            const isActive = button.dataset.signMode === mode;
            button.classList.toggle('bg-cyan-500/20', isActive);
            button.classList.toggle('text-cyan-100', isActive);
            button.classList.toggle('text-slate-300', !isActive);
        });

        Object.entries(panels).forEach(([key, panel]) => {
            panel.classList.toggle('hidden', key !== mode);
        });
    };

    modeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            setMode(button.dataset.signMode || 'draw');
        });
    });

    drawContext.lineWidth = 2.4;
    drawContext.lineCap = 'round';
    drawContext.lineJoin = 'round';
    drawContext.strokeStyle = '#111827';

    const drawCanvasPosition = (event) => {
        const rect = drawCanvas.getBoundingClientRect();
        const scaleX = drawCanvas.width / rect.width;
        const scaleY = drawCanvas.height / rect.height;

        return {
            x: (event.clientX - rect.left) * scaleX,
            y: (event.clientY - rect.top) * scaleY,
        };
    };

    drawCanvas.addEventListener('pointerdown', (event) => {
        drawing = true;
        hasDrawn = true;

        const { x, y } = drawCanvasPosition(event);
        drawContext.beginPath();
        drawContext.moveTo(x, y);
    });

    drawCanvas.addEventListener('pointermove', (event) => {
        if (!drawing) {
            return;
        }

        const { x, y } = drawCanvasPosition(event);
        drawContext.lineTo(x, y);
        drawContext.stroke();
    });

    window.addEventListener('pointerup', () => {
        drawing = false;

        if (currentMode === 'draw' && hasDrawn) {
            updateLivePreview(drawCanvas.toDataURL('image/png'));
        }
    });

    clearDrawCanvasButton.addEventListener('click', () => {
        drawContext.clearRect(0, 0, drawCanvas.width, drawCanvas.height);
        hasDrawn = false;
        updateLivePreview(null);
    });

    signatureImageInput.addEventListener('change', () => {
        const [file] = signatureImageInput.files || [];

        if (!file) {
            imageSignatureData = null;
            signatureImagePreview.classList.add('hidden');
            updateLivePreview(null);
            return;
        }

        const reader = new FileReader();
        reader.onload = () => {
            imageSignatureData = typeof reader.result === 'string' ? reader.result : null;
            if (!imageSignatureData) {
                return;
            }

            signatureImagePreview.src = imageSignatureData;
            signatureImagePreview.classList.remove('hidden');
            updateLivePreview(imageSignatureData);
        };

        reader.readAsDataURL(file);
    });

    signatureTextInput.addEventListener('input', () => {
        textSignaturePreview.textContent = (signatureTextInput.value || '').trim();
        textSignaturePreview.style.fontFamily = `"${(signatureFontSelect?.value || 'Pacifico').trim()}", cursive`;
    });

    signatureFontSelect?.addEventListener('change', () => {
        textSignaturePreview.style.fontFamily = `"${(signatureFontSelect.value || 'Pacifico').trim()}", cursive`;
    });

    const addSignature = async () => {
        const signatureData = await resolveCurrentSignatureData();

        if (!signatureData) {
            window.alert('Prepare une signature valide avant de l’ajouter.');
            return;
        }

        const aspectRatio = await getImageAspectRatio(signatureData);
        const signature = {
            id: `sig-${signatureCounter}`,
            pageNumber: currentPage,
            xRatio: 0.6,
            yRatio: 0.75,
            widthRatio: 0.28,
            aspectRatio,
            signatureData,
        };

        signatureCounter += 1;
        signatures.push(signature);

        selectedSignatureId = signature.id;
        updateLivePreview(signatureData);
        renderSignaturesLayer();
        renderSignaturesList();
    };

    addSignatureButton.addEventListener('click', addSignature);

    removeSignatureButton.addEventListener('click', () => {
        if (!selectedSignatureId) {
            window.alert('Sélectionne une signature à supprimer.');
            return;
        }

        signatures = signatures.filter((signature) => signature.id !== selectedSignatureId);
        selectedSignatureId = null;
        renderSignaturesLayer();
        renderSignaturesList();
    });

    clearSignaturesButton.addEventListener('click', () => {
        signatures = [];
        selectedSignatureId = null;
        renderSignaturesLayer();
        renderSignaturesList();
    });

    const renderPdfPage = async () => {
        if (!pdfDoc) {
            return;
        }

        const page = await pdfDoc.getPage(currentPage);
        const availableWidth = Math.max(320, pdfStage.clientWidth - 2);
        const baseViewport = page.getViewport({ scale: 1 });
        const scale = availableWidth / baseViewport.width;
        const viewport = page.getViewport({ scale });

        pdfCanvas.width = Math.floor(viewport.width);
        pdfCanvas.height = Math.floor(viewport.height);
        pdfCanvas.style.width = `${viewport.width}px`;
        pdfCanvas.style.height = `${viewport.height}px`;

        await page.render({ canvasContext: pdfContext, viewport }).promise;

        pageInfoLabel.textContent = `Page ${currentPage} / ${totalPages}`;

        prevPageButton.disabled = currentPage <= 1;
        nextPageButton.disabled = currentPage >= totalPages;
        prevPageButton.classList.toggle('opacity-40', currentPage <= 1);
        nextPageButton.classList.toggle('opacity-40', currentPage >= totalPages);

        renderSignaturesLayer();
        renderSignaturesList();
    };

    const loadPdf = async () => {
        const pdfUrl = pdfStage.dataset.pdfUrl;
        if (!pdfUrl || !window.pdfjsLib) {
            return;
        }

        window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        const loadingTask = window.pdfjsLib.getDocument(pdfUrl);
        pdfDoc = await loadingTask.promise;
        totalPages = pdfDoc.numPages;
        currentPage = clamp(currentPage, 1, totalPages);

        await renderPdfPage();
    };

    prevPageButton.addEventListener('click', async () => {
        if (currentPage <= 1) {
            return;
        }

        currentPage -= 1;
        await renderPdfPage();
    });

    nextPageButton.addEventListener('click', async () => {
        if (currentPage >= totalPages) {
            return;
        }

        currentPage += 1;
        await renderPdfPage();
    });

    let resizeRaf = null;
    window.addEventListener('resize', () => {
        if (!pdfDoc) {
            return;
        }

        if (resizeRaf) {
            window.cancelAnimationFrame(resizeRaf);
        }

        resizeRaf = window.requestAnimationFrame(() => {
            renderPdfPage();
            resizeRaf = null;
        });
    });

    signForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const digitalEnabled = document.getElementById('enableDigitalSign')?.checked ?? false;

        if (signatures.length === 0 && !digitalEnabled) {
            window.alert('Ajoute au moins une signature avant de générer le PDF.');
            return;
        }

        if (digitalEnabled) {
            const nameInput = document.getElementById('digitalSignNameInline');
            const name = (nameInput?.value || '').trim();
            if (!name) {
                nameInput?.focus();
                window.alert('Entrez votre nom pour la signature numérique.');
                return;
            }

            const digitalSignerNameInput = document.getElementById('digitalSignerName');
            if (digitalSignerNameInput) {
                digitalSignerNameInput.value = name;
            }

            // Write position fields from current digital sig state
            if (window._digitalSig) {
                const ds = window._digitalSig;
                document.getElementById('digitalSignPage').value = String(ds.pageNumber);
                document.getElementById('digitalSignXRatio').value = ds.xRatio.toFixed(6);
                document.getElementById('digitalSignYRatio').value = ds.yRatio.toFixed(6);
                document.getElementById('digitalSignWidthRatio').value = ds.widthRatio.toFixed(6);
            }
        }

        syncSignaturesField();

        const submitButton = signForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.classList.add('opacity-70', 'cursor-not-allowed');

        try {
            signForm.submit();
        } finally {
            submitButton.disabled = false;
            submitButton.classList.remove('opacity-70', 'cursor-not-allowed');
        }
    });

    // ── Digital Signature – checkbox + draggable overlay ────────────────────

    window._digitalSig = null;         // { pageNumber, xRatio, yRatio, widthRatio }
    window._digitalSigInteraction = null;

    const enableDigitalSignCheckbox = document.getElementById('enableDigitalSign');
    const digitalSignFieldsDiv = document.getElementById('digitalSignFields');
    const digitalSignNameInlineInput = document.getElementById('digitalSignNameInline');

    const syncDigitalSigPositionFields = () => {
        const ds = window._digitalSig;
        document.getElementById('digitalSignPage').value = ds ? String(ds.pageNumber) : '';
        document.getElementById('digitalSignXRatio').value = ds ? ds.xRatio.toFixed(6) : '';
        document.getElementById('digitalSignYRatio').value = ds ? ds.yRatio.toFixed(6) : '';
        document.getElementById('digitalSignWidthRatio').value = ds ? ds.widthRatio.toFixed(6) : '';
    };

    const renderDigitalSigOverlay = () => {
        // Remove old overlay
        signaturesLayer.querySelector('[data-digital-sig]')?.remove();

        const ds = window._digitalSig;
        if (!ds || ds.pageNumber !== currentPage) {
            return;
        }

        const name = (digitalSignNameInlineInput?.value || '').trim() || 'Nom du signataire';

        let width = clamp(ds.widthRatio * pdfCanvas.width, 80, pdfCanvas.width * 0.9);
        const aspectRatio = 0.28;
        let height = width * aspectRatio;
        let x = clamp(ds.xRatio * pdfCanvas.width, 0, pdfCanvas.width - width);
        let y = clamp(ds.yRatio * pdfCanvas.height, 0, pdfCanvas.height - height);

        // Persist clamped ratios
        ds.xRatio = x / pdfCanvas.width;
        ds.yRatio = y / pdfCanvas.height;
        ds.widthRatio = width / pdfCanvas.width;
        syncDigitalSigPositionFields();

        const overlay = document.createElement('div');
        overlay.dataset.digitalSig = '1';
        Object.assign(overlay.style, {
            position: 'absolute',
            left: `${x}px`,
            top: `${y}px`,
            width: `${width}px`,
            height: `${height}px`,
            cursor: 'move',
            pointerEvents: 'auto',
            userSelect: 'none',
            touchAction: 'none',
        });

        // Badge canvas
        const badgeCanvas = document.createElement('canvas');
        badgeCanvas.width = Math.round(width * 2);   // 2× for sharpness
        badgeCanvas.height = Math.round(height * 2);
        badgeCanvas.style.width = '100%';
        badgeCanvas.style.height = '100%';
        badgeCanvas.style.display = 'block';
        badgeCanvas.style.pointerEvents = 'none';
        badgeCanvas.style.borderRadius = '3px';

        const bCtx = badgeCanvas.getContext('2d');
        const bW = badgeCanvas.width;
        const bH = badgeCanvas.height;

        // White background
        bCtx.fillStyle = '#f8fafc';
        bCtx.fillRect(0, 0, bW, bH);

        // Left cyan accent bar
        const barW = Math.max(4, Math.round(bW * 0.02));
        bCtx.fillStyle = '#22d3ee';
        bCtx.fillRect(0, 0, barW, bH);

        // Thin outer border
        bCtx.strokeStyle = '#cbd5e1';
        bCtx.lineWidth = 1.5;
        bCtx.strokeRect(0.75, 0.75, bW - 1.5, bH - 1.5);

        const textX = barW + Math.round(bW * 0.03);
        const now = new Date();
        const dateStr = now.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' })
            + ' ' + now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

        // Line 1: label
        bCtx.fillStyle = '#475569';
        bCtx.font = `bold ${Math.round(bH * 0.22)}px "Segoe UI", sans-serif`;
        bCtx.textBaseline = 'top';
        bCtx.fillText('✓ Signature numérique', textX, Math.round(bH * 0.08));

        // Line 2: name (truncated)
        const maxChars = Math.floor(bW / (bH * 0.14));
        const nameDisplay = name.length > maxChars ? name.slice(0, maxChars - 1) + '…' : name;
        bCtx.fillStyle = '#0f172a';
        bCtx.font = `${Math.round(bH * 0.28)}px Georgia, serif`;
        bCtx.fillText(nameDisplay, textX, Math.round(bH * 0.38));

        // Line 3: date
        bCtx.fillStyle = '#94a3b8';
        bCtx.font = `${Math.round(bH * 0.17)}px "Segoe UI", sans-serif`;
        bCtx.fillText(dateStr, textX, Math.round(bH * 0.73));

        overlay.appendChild(badgeCanvas);

        // Resize handle
        const handle = document.createElement('div');
        Object.assign(handle.style, {
            position: 'absolute',
            bottom: '-7px',
            right: '-7px',
            width: '14px',
            height: '14px',
            borderRadius: '50%',
            background: '#22d3ee',
            border: '2px solid white',
            cursor: 'nwse-resize',
            pointerEvents: 'auto',
            boxShadow: '0 1px 3px rgba(0,0,0,0.3)',
        });
        handle.dataset.digitalSigResize = '1';
        overlay.appendChild(handle);

        overlay.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            event.stopPropagation();

            if (event.target instanceof HTMLElement && event.target.dataset.digitalSigResize === '1') {
                window._digitalSigInteraction = {
                    mode: 'resize',
                    pointerX: event.clientX,
                    startWidth: width,
                    startX: x,
                    startY: y,
                    width,
                    height,
                };
                return;
            }

            window._digitalSigInteraction = {
                mode: 'move',
                pointerX: event.clientX,
                pointerY: event.clientY,
                startX: x,
                startY: y,
                width,
                height,
            };
        });

        signaturesLayer.appendChild(overlay);
    };

    // Register so renderSignaturesLayer can call it via digitalSigOverlayRenderer
    digitalSigOverlayRenderer = renderDigitalSigOverlay;

    enableDigitalSignCheckbox?.addEventListener('change', () => {
        const checked = enableDigitalSignCheckbox.checked;
        digitalSignFieldsDiv?.classList.toggle('hidden', !checked);

        if (checked) {
            window._digitalSig = {
                pageNumber: currentPage,
                xRatio: 0.25,
                yRatio: 0.80,
                widthRatio: 0.5,
            };
            syncDigitalSigPositionFields();
        } else {
            window._digitalSig = null;
            syncDigitalSigPositionFields();
            signaturesLayer.querySelector('[data-digital-sig]')?.remove();
        }

        renderSignaturesLayer();
    });

    // Live-update: redraw the overlay when the user types their name
    digitalSignNameInlineInput?.addEventListener('input', () => {
        if (window._digitalSig) {
            renderDigitalSigOverlay();
        }
    });

    // When navigating pages, overlay must appear/disappear
    // (renderSignaturesLayer already calls digitalSigOverlayRenderer via the hook above)

    setMode('draw');
    renderSignaturesList();
    loadPdf();
})();


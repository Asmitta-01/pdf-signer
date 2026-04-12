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
            empty.textContent = 'Aucune signature ajoutee.';
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
    };

    const moveSignature = (signature, nextX, nextY, width, height) => {
        const x = clamp(nextX, 0, pdfCanvas.width - width);
        const y = clamp(nextY, 0, pdfCanvas.height - height);
        normalizeFromRect(signature, { x, y, width, height });
    };

    window.addEventListener('pointermove', (event) => {
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
            window.alert('Prepare une signature valide avant de lajouter.');
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
            window.alert('Selectionne une signature a supprimer.');
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

        if (signatures.length === 0) {
            window.alert('Ajoute au moins une signature avant de generer le PDF.');
            return;
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

    if (signatureFontSelect) {
        textSignaturePreview.style.fontFamily = `"${(signatureFontSelect.value || 'Pacifico').trim()}", cursive`;
    }

    setMode('draw');
    renderSignaturesList();
    loadPdf();
})();

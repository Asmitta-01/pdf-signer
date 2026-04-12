(() => {
    const signForm = document.getElementById('signForm');
    if (!signForm) {
        return;
    }

    const pdfStage = document.getElementById('pdfStage');
    const pdfCanvas = document.getElementById('pdfCanvas');
    const pageInfoLabel = document.getElementById('pageInfoLabel');
    const prevPageButton = document.getElementById('prevPageButton');
    const nextPageButton = document.getElementById('nextPageButton');

    const signatureOverlay = document.getElementById('signatureOverlay');
    const overlaySignatureImage = document.getElementById('overlaySignatureImage');
    const signatureResizeHandle = document.getElementById('signatureResizeHandle');

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
    const textSignaturePreview = document.getElementById('textSignaturePreview');

    const signatureDataInput = document.getElementById('signatureData');
    const pageNumberInput = document.getElementById('pageNumber');
    const xRatioInput = document.getElementById('xRatio');
    const yRatioInput = document.getElementById('yRatio');
    const widthRatioInput = document.getElementById('widthRatio');

    const signatureLivePreview = document.getElementById('signatureLivePreview');
    const signaturePlaceholder = document.getElementById('signaturePlaceholder');

    const drawContext = drawCanvas.getContext('2d');
    const pdfContext = pdfCanvas.getContext('2d');

    let currentMode = 'draw';
    let drawing = false;
    let hasDrawn = false;
    let imageSignatureData = null;

    let pdfDoc = null;
    let currentPage = 1;
    let totalPages = 1;

    let renderedSignatureData = null;

    const overlayState = {
        xRatio: Number.parseFloat(xRatioInput.value || '0.65') || 0.65,
        yRatio: Number.parseFloat(yRatioInput.value || '0.75') || 0.75,
        widthRatio: Number.parseFloat(widthRatioInput.value || '0.3') || 0.3,
        aspectRatio: 0.35,
    };

    let dragState = null;
    let resizeState = null;

    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

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

    const updateHiddenRatios = (x, y, width) => {
        if (!pdfCanvas.width || !pdfCanvas.height) {
            return;
        }

        overlayState.xRatio = x / pdfCanvas.width;
        overlayState.yRatio = y / pdfCanvas.height;
        overlayState.widthRatio = width / pdfCanvas.width;

        xRatioInput.value = overlayState.xRatio.toFixed(6);
        yRatioInput.value = overlayState.yRatio.toFixed(6);
        widthRatioInput.value = overlayState.widthRatio.toFixed(6);
    };

    const applyOverlayFromRatios = () => {
        if (!renderedSignatureData || !pdfCanvas.width || !pdfCanvas.height) {
            signatureOverlay.classList.add('hidden');
            return;
        }

        let width = clamp(overlayState.widthRatio * pdfCanvas.width, 60, pdfCanvas.width * 0.85);
        let height = width * overlayState.aspectRatio;

        if (height > pdfCanvas.height * 0.85) {
            height = pdfCanvas.height * 0.85;
            width = height / Math.max(overlayState.aspectRatio, 0.01);
        }

        let x = overlayState.xRatio * pdfCanvas.width;
        let y = overlayState.yRatio * pdfCanvas.height;

        x = clamp(x, 0, pdfCanvas.width - width);
        y = clamp(y, 0, pdfCanvas.height - height);

        signatureOverlay.style.width = `${width}px`;
        signatureOverlay.style.height = `${height}px`;
        signatureOverlay.style.left = `${x}px`;
        signatureOverlay.style.top = `${y}px`;
        signatureOverlay.classList.remove('hidden');

        updateHiddenRatios(x, y, width);
    };

    const setSignatureOverlayAsset = (dataUrl) => {
        renderedSignatureData = dataUrl;
        updateLivePreview(dataUrl);

        if (!dataUrl) {
            signatureOverlay.classList.add('hidden');
            return;
        }

        overlaySignatureImage.src = dataUrl;
        signatureOverlay.classList.remove('hidden');
    };

    overlaySignatureImage.addEventListener('load', () => {
        if (overlaySignatureImage.naturalWidth > 0) {
            overlayState.aspectRatio = overlaySignatureImage.naturalHeight / overlaySignatureImage.naturalWidth;
        }

        applyOverlayFromRatios();
    });

    const placeOverlayAt = (x, y) => {
        if (!renderedSignatureData) {
            return;
        }

        const currentWidth = signatureOverlay.offsetWidth || (pdfCanvas.width * overlayState.widthRatio);
        const currentHeight = signatureOverlay.offsetHeight || (currentWidth * overlayState.aspectRatio);

        const clampedX = clamp(x, 0, pdfCanvas.width - currentWidth);
        const clampedY = clamp(y, 0, pdfCanvas.height - currentHeight);

        signatureOverlay.style.left = `${clampedX}px`;
        signatureOverlay.style.top = `${clampedY}px`;

        updateHiddenRatios(clampedX, clampedY, currentWidth);
    };

    pdfCanvas.addEventListener('click', (event) => {
        if (!renderedSignatureData) {
            return;
        }

        const rect = pdfCanvas.getBoundingClientRect();
        const clickX = event.clientX - rect.left;
        const clickY = event.clientY - rect.top;

        const nextX = clickX - (signatureOverlay.offsetWidth / 2);
        const nextY = clickY - (signatureOverlay.offsetHeight / 2);

        placeOverlayAt(nextX, nextY);
    });

    signatureOverlay.addEventListener('pointerdown', (event) => {
        if (event.target === signatureResizeHandle || !renderedSignatureData) {
            return;
        }

        event.preventDefault();

        dragState = {
            pointerX: event.clientX,
            pointerY: event.clientY,
            startX: signatureOverlay.offsetLeft,
            startY: signatureOverlay.offsetTop,
        };
    });

    signatureResizeHandle.addEventListener('pointerdown', (event) => {
        if (!renderedSignatureData) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        resizeState = {
            pointerX: event.clientX,
            startWidth: signatureOverlay.offsetWidth,
            startX: signatureOverlay.offsetLeft,
            startY: signatureOverlay.offsetTop,
        };
    });

    window.addEventListener('pointermove', (event) => {
        if (dragState) {
            const deltaX = event.clientX - dragState.pointerX;
            const deltaY = event.clientY - dragState.pointerY;
            placeOverlayAt(dragState.startX + deltaX, dragState.startY + deltaY);
            return;
        }

        if (resizeState) {
            const deltaX = event.clientX - resizeState.pointerX;
            let nextWidth = clamp(resizeState.startWidth + deltaX, 60, pdfCanvas.width * 0.9);
            let nextHeight = nextWidth * overlayState.aspectRatio;

            const maxWidthByBounds = pdfCanvas.width - resizeState.startX;
            const maxHeightByBounds = pdfCanvas.height - resizeState.startY;

            if (nextWidth > maxWidthByBounds) {
                nextWidth = maxWidthByBounds;
                nextHeight = nextWidth * overlayState.aspectRatio;
            }

            if (nextHeight > maxHeightByBounds) {
                nextHeight = maxHeightByBounds;
                nextWidth = nextHeight / Math.max(overlayState.aspectRatio, 0.01);
            }

            signatureOverlay.style.width = `${nextWidth}px`;
            signatureOverlay.style.height = `${nextHeight}px`;

            updateHiddenRatios(resizeState.startX, resizeState.startY, nextWidth);
        }
    });

    window.addEventListener('pointerup', () => {
        dragState = null;
        resizeState = null;

        if (currentMode === 'draw' && hasDrawn) {
            setSignatureOverlayAsset(drawCanvas.toDataURL('image/png'));
        }
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
        pageNumberInput.value = String(currentPage);

        prevPageButton.disabled = currentPage <= 1;
        nextPageButton.disabled = currentPage >= totalPages;
        prevPageButton.classList.toggle('opacity-40', currentPage <= 1);
        nextPageButton.classList.toggle('opacity-40', currentPage >= totalPages);

        applyOverlayFromRatios();
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

    clearDrawCanvasButton.addEventListener('click', () => {
        drawContext.clearRect(0, 0, drawCanvas.width, drawCanvas.height);
        hasDrawn = false;

        if (currentMode === 'draw') {
            setSignatureOverlayAsset(null);
        }
    });

    signatureImageInput.addEventListener('change', () => {
        const [file] = signatureImageInput.files || [];

        if (!file) {
            imageSignatureData = null;
            signatureImagePreview.classList.add('hidden');

            if (currentMode === 'image') {
                setSignatureOverlayAsset(null);
            }
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

            if (currentMode === 'image') {
                setSignatureOverlayAsset(imageSignatureData);
            }
        };

        reader.readAsDataURL(file);
    });

    const buildTextSignature = async () => {
        if (document.fonts && document.fonts.ready) {
            await document.fonts.ready;
        }

        const signatureText = (signatureTextInput.value || '').trim();
        if (!signatureText) {
            return null;
        }

        const renderer = document.createElement('canvas');
        const context = renderer.getContext('2d');

        context.font = '82px Pacifico, cursive';
        const measuredWidth = context.measureText(signatureText).width;

        renderer.width = Math.max(360, Math.ceil(measuredWidth) + 120);
        renderer.height = 220;

        context.font = '82px Pacifico, cursive';
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillStyle = '#111827';
        context.fillText(signatureText, renderer.width / 2, renderer.height / 2);

        return renderer.toDataURL('image/png');
    };

    let textPreviewTimer = null;
    signatureTextInput.addEventListener('input', () => {
        const value = (signatureTextInput.value || '').trim();
        textSignaturePreview.textContent = value;

        if (currentMode !== 'text') {
            return;
        }

        if (textPreviewTimer) {
            window.clearTimeout(textPreviewTimer);
        }

        textPreviewTimer = window.setTimeout(async () => {
            const data = await buildTextSignature();
            setSignatureOverlayAsset(data);
        }, 100);
    });

    const setMode = async (mode) => {
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

        if (mode === 'draw') {
            setSignatureOverlayAsset(hasDrawn ? drawCanvas.toDataURL('image/png') : null);
            return;
        }

        if (mode === 'image') {
            setSignatureOverlayAsset(imageSignatureData);
            return;
        }

        if (mode === 'text') {
            const textData = await buildTextSignature();
            setSignatureOverlayAsset(textData);
        }
    };

    modeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            setMode(button.dataset.signMode || 'draw');
        });
    });

    const resolveSignatureData = async () => {
        if (currentMode === 'draw') {
            return hasDrawn ? drawCanvas.toDataURL('image/png') : null;
        }

        if (currentMode === 'image') {
            return imageSignatureData;
        }

        if (currentMode === 'text') {
            return buildTextSignature();
        }

        return null;
    };

    signForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const submitButton = signForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.classList.add('opacity-70', 'cursor-not-allowed');

        try {
            const signatureData = await resolveSignatureData();

            if (!signatureData) {
                window.alert('Ajoute une signature valide avant de continuer.');
                return;
            }

            setSignatureOverlayAsset(signatureData);
            signatureDataInput.value = signatureData;

            if (signatureOverlay.classList.contains('hidden')) {
                window.alert('Place ta signature sur le PDF avant de generer le fichier.');
                return;
            }

            signForm.submit();
        } finally {
            submitButton.disabled = false;
            submitButton.classList.remove('opacity-70', 'cursor-not-allowed');
        }
    });

    setMode('draw');
    loadPdf();
})();

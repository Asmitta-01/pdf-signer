(() => {
    const signForm = document.getElementById('signForm');
    if (!signForm) {
        return;
    }

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
    const signatureLivePreview = document.getElementById('signatureLivePreview');
    const signaturePlaceholder = document.getElementById('signaturePlaceholder');

    let currentMode = 'draw';
    let drawing = false;
    let hasDrawn = false;
    let imageSignatureData = null;

    const drawContext = drawCanvas.getContext('2d');
    drawContext.lineWidth = 2.4;
    drawContext.lineCap = 'round';
    drawContext.lineJoin = 'round';
    drawContext.strokeStyle = '#111827';

    const canvasPosition = (event) => {
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
        const { x, y } = canvasPosition(event);
        drawContext.beginPath();
        drawContext.moveTo(x, y);
    });

    drawCanvas.addEventListener('pointermove', (event) => {
        if (!drawing) {
            return;
        }

        const { x, y } = canvasPosition(event);
        drawContext.lineTo(x, y);
        drawContext.stroke();
    });

    window.addEventListener('pointerup', () => {
        drawing = false;
    });

    clearDrawCanvasButton.addEventListener('click', () => {
        drawContext.clearRect(0, 0, drawCanvas.width, drawCanvas.height);
        hasDrawn = false;
        if (currentMode === 'draw') {
            updateLivePreview(null);
        }
    });

    signatureImageInput.addEventListener('change', () => {
        const [file] = signatureImageInput.files || [];

        if (!file) {
            imageSignatureData = null;
            signatureImagePreview.classList.add('hidden');
            if (currentMode === 'image') {
                updateLivePreview(null);
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
                updateLivePreview(imageSignatureData);
            }
        };

        reader.readAsDataURL(file);
    });

    signatureTextInput.addEventListener('input', () => {
        const value = (signatureTextInput.value || '').trim();
        textSignaturePreview.textContent = value;

        if (currentMode === 'text') {
            updateLivePreview(null);
        }
    });

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

        updateLivePreview(null);
    };

    modeButtons.forEach((button) => {
        button.addEventListener('click', () => setMode(button.dataset.signMode || 'draw'));
    });

    const buildTextSignature = async () => {
        if (document.fonts && document.fonts.ready) {
            await document.fonts.ready;
        }

        const signatureText = (signatureTextInput.value || '').trim();
        if (signatureText === '') {
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

    const resolveSignatureData = async () => {
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

            signatureDataInput.value = signatureData;
            updateLivePreview(signatureData);

            signForm.submit();
        } finally {
            submitButton.disabled = false;
            submitButton.classList.remove('opacity-70', 'cursor-not-allowed');
        }
    });

    setMode('draw');
})();

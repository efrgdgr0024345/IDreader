function clamp(n, min, max) {
    return Math.max(min, Math.min(max, n));
}

function normalizeOcrText(text) {
    return String(text || '')
        .replace(/\r/g, '\n')
        .replace(/[ \t]+/g, ' ')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

function extractDateCandidates(text) {
    const matches = [];
    const regex = /\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{1,2}\s+[A-Z]{3,9}\s+\d{2,4})\b/gi;
    let m;
    while ((m = regex.exec(text)) !== null) matches.push(m[1]);
    return [...new Set(matches)];
}

function extractLicenceNumber(text) {
    const lines = normalizeOcrText(text).split('\n').map(s => s.trim()).filter(Boolean);

    for (const line of lines) {
        if (/lic|id|card/i.test(line)) {
            const cleaned = line.replace(/[^A-Z0-9 ]/gi, ' ').replace(/\s+/g, ' ').trim();
            const candidates = cleaned.match(/\b[A-Z0-9]{5,16}\b/g);
            if (candidates?.length) {
                const ranked = candidates
                    .filter(v => !/^(DRIVER|LICENCE|LICENSE|CLASS|CARD|AUSTRALIA|WESTERN|WA)$/i.test(v))
                    .sort((a, b) => b.length - a.length);
                if (ranked[0]) return ranked[0];
            }
        }
    }

    const broad = normalizeOcrText(text).match(/\b[A-Z0-9]{6,16}\b/g) || [];
    return broad
        .filter(v => /[0-9]/.test(v))
        .filter(v => !/^(AUSTRALIA|WESTERN|DRIVER|LICENCE|LICENSE)$/i.test(v))
        .sort((a, b) => b.length - a.length)[0] || null;
}

function extractUpperName(text) {
    const lines = normalizeOcrText(text).split('\n').map(s => s.trim()).filter(Boolean);
    for (const line of lines) {
        const cleaned = line.replace(/[^A-Z' -]/g, '').replace(/\s+/g, ' ').trim();
        if (!cleaned || cleaned.length < 4 || cleaned.length > 42) continue;
        if (!/^[A-Z][A-Z' -]+$/.test(cleaned)) continue;
        if (/\b(DRIVER|LICENCE|LICENSE|WESTERN|AUSTRALIA|DOB|EXP|ADDRESS|CLASS)\b/.test(cleaned)) continue;
        if (cleaned.split(' ').length >= 2) return cleaned;
    }
    return null;
}

function extractAddress(text) {
    const lines = normalizeOcrText(text).split('\n').map(s => s.trim()).filter(Boolean);
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (/\b(address|addr)\b/i.test(line)) {
            const next = lines[i + 1] || '';
            const combined = [line.replace(/\baddress\b[: ]*/i, '').trim(), next].filter(Boolean).join(' ').trim();
            if (combined) return combined;
        }
    }

    return lines.find(line => /\d+/.test(line) && /\b(ST|STREET|RD|ROAD|AVE|AVENUE|DR|DRIVE|WAY|CRES|COURT|CL|LANE|LN|BLVD|HWY|PLACE|PL)\b/i.test(line)) || null;
}

function parseOcrFields(fullText, perFieldText = {}) {
    const normalized = normalizeOcrText(fullText);
    const combinedName = normalizeOcrText(`${perFieldText.name || ''}\n${normalized}`);
    const combinedLicence = normalizeOcrText(`${perFieldText.licence_number || ''}\n${normalized}`);
    const combinedDates = normalizeOcrText(`${perFieldText.dob_expiry || ''}\n${normalized}`);
    const combinedAddress = normalizeOcrText(`${perFieldText.address || ''}\n${normalized}`);

    const dateCandidates = extractDateCandidates(combinedDates);
    let dob = null;
    let expiry = null;

    for (const line of combinedDates.split('\n').map(s => s.trim()).filter(Boolean)) {
        if (!dob && /\b(dob|birth|date of birth)\b/i.test(line)) {
            const m = line.match(/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{1,2}\s+[A-Z]{3,9}\s+\d{2,4})\b/i);
            if (m) dob = m[1];
        }
        if (!expiry && /\b(exp|expiry|expires)\b/i.test(line)) {
            const m = line.match(/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{1,2}\s+[A-Z]{3,9}\s+\d{2,4})\b/i);
            if (m) expiry = m[1];
        }
    }

    if (!dob && dateCandidates[0]) dob = dateCandidates[0];
    if (!expiry && dateCandidates[1]) expiry = dateCandidates[1];

    return {
        name: extractUpperName(combinedName),
        licence_number: extractLicenceNumber(combinedLicence),
        dob,
        expiry,
        address: extractAddress(combinedAddress),
        date_candidates: dateCandidates
    };
}

export function defaultRois() {
    return [
        { key: 'name', label: 'Name', x: 0.09, y: 0.17, w: 0.56, h: 0.18 },
        { key: 'licence_number', label: 'Licence No', x: 0.53, y: 0.06, w: 0.42, h: 0.16 },
        { key: 'dob_expiry', label: 'DOB / Expiry', x: 0.08, y: 0.50, w: 0.60, h: 0.18 },
        { key: 'address', label: 'Address', x: 0.08, y: 0.66, w: 0.68, h: 0.26 }
    ];
}

export function sanitizeRoi(roi) {
    return {
        ...roi,
        x: clamp(Number(roi.x || 0), 0, 0.98),
        y: clamp(Number(roi.y || 0), 0, 0.98),
        w: clamp(Number(roi.w || 0.1), 0.02, 1),
        h: clamp(Number(roi.h || 0.1), 0.02, 1)
    };
}

export function sanitizeRois(rois = []) {
    return rois.map(roi => {
        const cleaned = sanitizeRoi(roi);
        cleaned.w = clamp(cleaned.w, 0.02, 1 - cleaned.x);
        cleaned.h = clamp(cleaned.h, 0.02, 1 - cleaned.y);
        return cleaned;
    });
}

function roiToPixels(roi, width, height) {
    return {
        ...roi,
        px: Math.max(0, Math.round(width * roi.x)),
        py: Math.max(0, Math.round(height * roi.y)),
        pw: Math.max(1, Math.round(width * roi.w)),
        ph: Math.max(1, Math.round(height * roi.h))
    };
}

function drawRoiOverlay(canvas, boxes = [], options = {}) {
    const ctx = canvas?.getContext?.('2d');
    if (!ctx || !canvas) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (!boxes.length) return;

    const accent = options.accent || 'rgba(255, 59, 48, 0.95)';
    const fill = options.fill || 'rgba(255, 59, 48, 0.15)';

    ctx.lineWidth = 2;
    ctx.font = 'bold 12px Arial';

    for (const box of boxes) {
        ctx.fillStyle = fill;
        ctx.strokeStyle = accent;
        ctx.fillRect(box.px, box.py, box.pw, box.ph);
        ctx.strokeRect(box.px, box.py, box.pw, box.ph);

        const label = box.label || box.key;
        ctx.fillStyle = '#ffffff';
        ctx.strokeStyle = 'rgba(0, 0, 0, 0.75)';
        ctx.lineWidth = 3;
        ctx.strokeText(label, box.px + 4, box.py + 13);
        ctx.fillText(label, box.px + 4, box.py + 13);
    }
}

export function drawRoiOverlayNormalized(canvas, rois = [], options = {}) {
    const boxes = sanitizeRois(rois).map(roi => roiToPixels(roi, canvas.width, canvas.height));
    drawRoiOverlay(canvas, boxes, options);
}

function preprocessIntoCanvas(img, canvas) {
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const srcW = img.naturalWidth || img.width || 1;
    const srcH = img.naturalHeight || img.height || 1;
    const targetW = Math.min(2800, Math.max(1400, srcW));
    const scale = targetW / srcW;
    const targetH = Math.max(1, Math.round(srcH * scale));

    canvas.width = targetW;
    canvas.height = targetH;
    ctx.clearRect(0, 0, targetW, targetH);
    ctx.drawImage(img, 0, 0, targetW, targetH);

    return { canvas, width: targetW, height: targetH };
}

function cloneCanvas(sourceCanvas) {
    const out = document.createElement('canvas');
    out.width = sourceCanvas.width;
    out.height = sourceCanvas.height;
    out.getContext('2d', { willReadFrequently: true }).drawImage(sourceCanvas, 0, 0);
    return out;
}

function applyVariant(sourceCanvas, variant) {
    if (variant === 'base') return sourceCanvas;

    const out = cloneCanvas(sourceCanvas);
    const ctx = out.getContext('2d', { willReadFrequently: true });
    const imageData = ctx.getImageData(0, 0, out.width, out.height);
    const d = imageData.data;

    for (let i = 0; i < d.length; i += 4) {
        const gray = clamp((0.299 * d[i]) + (0.587 * d[i + 1]) + (0.114 * d[i + 2]), 0, 255);

        if (variant === 'contrast') {
            const boosted = clamp((gray - 128) * 1.65 + 128, 0, 255);
            d[i] = boosted;
            d[i + 1] = boosted;
            d[i + 2] = boosted;
        } else if (variant === 'binary') {
            const boosted = clamp((gray - 128) * 1.55 + 128, 0, 255);
            const bin = boosted > 150 ? 255 : boosted < 90 ? 0 : boosted;
            d[i] = bin;
            d[i + 1] = bin;
            d[i + 2] = bin;
        }
    }

    ctx.putImageData(imageData, 0, 0);
    return out;
}

function getRoiConfig(key) {
    if (key === 'name') return { tessedit_pageseg_mode: '7' };
    if (key === 'licence_number') return { tessedit_pageseg_mode: '7', tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' };
    if (key === 'dob_expiry') return { tessedit_pageseg_mode: '6', tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789/- ' };
    if (key === 'address') return { tessedit_pageseg_mode: '6' };
    return { tessedit_pageseg_mode: '6' };
}

async function recognizeBest(tesseract, canvas, config, progressCb, variants = ['base', 'contrast', 'binary']) {
    let best = null;

    for (const variant of variants) {
        const candidateCanvas = applyVariant(canvas, variant);
        const result = await tesseract.recognize(candidateCanvas, 'eng', {
            ...config,
            logger: (m) => progressCb?.(variant, m)
        });

        const confidence = Number(result?.data?.confidence || 0);
        const text = normalizeOcrText(result?.data?.text || '');
        const score = confidence + Math.min(12, text.length / 16);

        if (!best || score > best.score) {
            best = {
                variant,
                score,
                confidence,
                text,
                raw: result
            };
        }
    }

    return best;
}

export function createIdOcrEngine(options) {
    const { tesseract, roiCanvas, overlayCanvas, getRois } = options;
    if (!tesseract) throw new Error('Tesseract instance is required');
    if (!roiCanvas) throw new Error('ROI canvas is required');

    const resolveRois = () => sanitizeRois(typeof getRois === 'function' ? getRois() : defaultRois());

    return {
        clearOverlay() {
            drawRoiOverlay(overlayCanvas, []);
        },

        drawOverlayForPreview(width, height, options = {}) {
            if (!overlayCanvas) return;
            overlayCanvas.width = width;
            overlayCanvas.height = height;
            drawRoiOverlayNormalized(overlayCanvas, resolveRois(), options);
        },

        async run(img, progressCb) {
            const { canvas, width, height } = preprocessIntoCanvas(img, roiCanvas);
            const activeRois = resolveRois();
            const pixelBoxes = activeRois.map(r => roiToPixels(r, width, height));
            drawRoiOverlay(overlayCanvas, pixelBoxes);

            const full = await recognizeBest(
                tesseract,
                canvas,
                { tessedit_pageseg_mode: '6' },
                (variant, m) => progressCb?.(`full:${variant}`, m)
            );

            const perFieldText = {};
            const perFieldConfidence = {};
            const perFieldVariant = {};

            for (const box of pixelBoxes) {
                const crop = document.createElement('canvas');
                crop.width = box.pw;
                crop.height = box.ph;
                const cctx = crop.getContext('2d', { willReadFrequently: true });
                cctx.drawImage(canvas, box.px, box.py, box.pw, box.ph, 0, 0, box.pw, box.ph);

                const res = await recognizeBest(
                    tesseract,
                    crop,
                    { ...getRoiConfig(box.key) },
                    (variant, m) => progressCb?.(`${box.key}:${variant}`, m)
                );

                perFieldText[box.key] = res?.text || '';
                perFieldConfidence[box.key] = Number((res?.confidence || 0).toFixed(2));
                perFieldVariant[box.key] = res?.variant || 'base';
            }

            const fullText = full?.text || '';
            const parsed = parseOcrFields(fullText, perFieldText);
            const confidence = Number((full?.confidence || 0).toFixed(2));

            return {
                fullText,
                parsed,
                confidence,
                perFieldText,
                perFieldConfidence,
                perFieldVariant,
                boxes: pixelBoxes,
                rois: activeRois,
                fullVariant: full?.variant || 'base'
            };
        }
    };
}

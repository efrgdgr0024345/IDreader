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

function normalizeToken(text) {
    return String(text || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
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
        if (/lic|id|card|number|no/i.test(line)) {
            const cleaned = line.replace(/[^A-Z0-9 ]/gi, ' ').replace(/\s+/g, ' ').trim();
            const candidates = cleaned.match(/\b[A-Z0-9]{5,16}\b/g);
            if (candidates?.length) {
                const ranked = candidates
                    .filter(v => !/^(DRIVER|LICENCE|LICENSE|CLASS|CARD|AUSTRALIA|WESTERN|WA|DATE|BIRTH|EXPIRY|ADDRESS)$/i.test(v))
                    .sort((a, b) => b.length - a.length);
                if (ranked[0]) return ranked[0];
            }
        }
    }

    const broad = normalizeOcrText(text).match(/\b[A-Z0-9]{6,16}\b/g) || [];
    return broad
        .filter(v => /[0-9]/.test(v))
        .filter(v => !/^(AUSTRALIA|WESTERN|DRIVER|LICENCE|LICENSE|TRANSPORT)$/i.test(v))
        .sort((a, b) => b.length - a.length)[0] || null;
}

function extractUpperName(text) {
    const lines = normalizeOcrText(text).split('\n').map(s => s.trim()).filter(Boolean);
    for (const line of lines) {
        const cleaned = line.replace(/[^A-Z' -]/g, '').replace(/\s+/g, ' ').trim();
        if (!cleaned || cleaned.length < 4 || cleaned.length > 48) continue;
        if (!/^[A-Z][A-Z' -]+$/.test(cleaned)) continue;
        if (/\b(DRIVER|LICENCE|LICENSE|WESTERN|AUSTRALIA|DOB|EXP|ADDRESS|CLASS|TRANSPORT)\b/.test(cleaned)) continue;
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

function defaultRois() {
    // Baseline WA licence profile (relative coordinates).
    return {
        name: { key: 'name', label: 'Name', x: 0.03, y: 0.38, w: 0.47, h: 0.22 },
        licence_number: { key: 'licence_number', label: 'Licence No', x: 0.76, y: 0.19, w: 0.21, h: 0.17 },
        dob_expiry: { key: 'dob_expiry', label: 'DOB / Expiry', x: 0.03, y: 0.66, w: 0.62, h: 0.16 },
        address: { key: 'address', label: 'Address', x: 0.03, y: 0.54, w: 0.47, h: 0.28 }
    };
}

function roiToPixels(roi, width, height) {
    return {
        ...roi,
        px: clamp(Math.round(width * roi.x), 0, width - 1),
        py: clamp(Math.round(height * roi.y), 0, height - 1),
        pw: clamp(Math.round(width * roi.w), 1, width),
        ph: clamp(Math.round(height * roi.h), 1, height)
    };
}

function clampBox(box, width, height) {
    const px = clamp(Math.round(box.px), 0, width - 1);
    const py = clamp(Math.round(box.py), 0, height - 1);
    const pw = clamp(Math.round(box.pw), 1, width - px);
    const ph = clamp(Math.round(box.ph), 1, height - py);
    return { ...box, px, py, pw, ph };
}

function expandBox(box, padX, padY, width, height) {
    return clampBox({
        ...box,
        px: box.px - padX,
        py: box.py - padY,
        pw: box.pw + (padX * 2),
        ph: box.ph + (padY * 2)
    }, width, height);
}

function limitedAdjust(baseBox, proposedBox, width, height, maxDxRatio = 0.08, maxDyRatio = 0.08) {
    const maxDx = width * maxDxRatio;
    const maxDy = height * maxDyRatio;
    const dx = clamp(proposedBox.px - baseBox.px, -maxDx, maxDx);
    const dy = clamp(proposedBox.py - baseBox.py, -maxDy, maxDy);

    return clampBox({
        ...baseBox,
        px: baseBox.px + dx,
        py: baseBox.py + dy,
        pw: proposedBox.pw,
        ph: proposedBox.ph
    }, width, height);
}

function drawRoiOverlay(canvas, boxes = []) {
    const ctx = canvas?.getContext?.('2d');
    if (!ctx || !canvas) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (!boxes.length) return;

    ctx.strokeStyle = 'rgba(255, 59, 48, 0.95)';
    ctx.fillStyle = 'rgba(255, 59, 48, 0.16)';
    ctx.lineWidth = 2;
    ctx.font = 'bold 12px Arial';

    for (const box of boxes) {
        ctx.fillRect(box.px, box.py, box.pw, box.ph);
        ctx.strokeRect(box.px, box.py, box.pw, box.ph);
        const label = box.label || box.key;
        const labelW = Math.max(56, ctx.measureText(label).width + 10);
        const ly = Math.max(0, box.py - 18);
        ctx.fillRect(box.px, ly, labelW, 16);
        ctx.fillStyle = '#ffffff';
        ctx.fillText(label, box.px + 5, ly + 12);
        ctx.fillStyle = 'rgba(255, 59, 48, 0.16)';
    }
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

    const imageData = ctx.getImageData(0, 0, targetW, targetH);
    const d = imageData.data;
    for (let i = 0; i < d.length; i += 4) {
        const gray = clamp((0.299 * d[i]) + (0.587 * d[i + 1]) + (0.114 * d[i + 2]), 0, 255);
        const boosted = clamp((gray - 128) * 1.60 + 128, 0, 255);
        const bin = boosted > 170 ? 255 : boosted < 75 ? 0 : boosted;
        d[i] = bin;
        d[i + 1] = bin;
        d[i + 2] = bin;
    }
    ctx.putImageData(imageData, 0, 0);
    return { canvas, width: targetW, height: targetH };
}

function collectWordBoxes(result) {
    const words = result?.data?.words;
    if (!Array.isArray(words)) return [];

    return words
        .map((word) => {
            const text = normalizeToken(word?.text || '');
            if (!text) return null;
            const bbox = word?.bbox || {};
            const x0 = Number(bbox.x0 ?? 0);
            const y0 = Number(bbox.y0 ?? 0);
            const x1 = Number(bbox.x1 ?? x0);
            const y1 = Number(bbox.y1 ?? y0);
            if (x1 <= x0 || y1 <= y0) return null;
            return {
                rawText: word.text || '',
                text,
                confidence: Number(word?.confidence || 0),
                x0,
                y0,
                x1,
                y1,
                cx: (x0 + x1) / 2,
                cy: (y0 + y1) / 2,
                w: x1 - x0,
                h: y1 - y0
            };
        })
        .filter(Boolean);
}

function findWord(words, patterns) {
    return words.find((w) => patterns.some((p) => w.text.includes(p)));
}

function findNumericWord(words, minLen = 6) {
    return words
        .filter((w) => /\d/.test(w.text) && w.text.length >= minLen)
        .sort((a, b) => (b.text.length - a.text.length) || (b.confidence - a.confidence))[0] || null;
}

function adaptiveBoxes(base, words, width, height) {
    const boxes = {
        name: roiToPixels(base.name, width, height),
        licence_number: roiToPixels(base.licence_number, width, height),
        dob_expiry: roiToPixels(base.dob_expiry, width, height),
        address: roiToPixels(base.address, width, height)
    };

    const anchorLicence = findNumericWord(words, 7) || findWord(words, ['LICENCE', 'LICENSE', 'NO']);
    if (anchorLicence) {
        const proposed = expandBox({
            ...boxes.licence_number,
            px: anchorLicence.x0 - (anchorLicence.w * 0.9),
            py: anchorLicence.y0 - (anchorLicence.h * 1.2),
            pw: width * 0.24,
            ph: height * 0.17
        }, width * 0.008, height * 0.010, width, height);
        boxes.licence_number = limitedAdjust(boxes.licence_number, proposed, width, height, 0.06, 0.05);
    }

    const anchorDob = findWord(words, ['DATEOFBIRTH', 'DATE', 'BIRTH', 'DOB']);
    const anchorExp = findWord(words, ['EXPIRY', 'EXP', 'EXPIRYDATE']);
    if (anchorDob || anchorExp) {
        const ref = anchorDob || anchorExp;
        const proposed = expandBox({
            ...boxes.dob_expiry,
            px: Math.max((anchorExp?.x0 ?? ref.x0) - (width * 0.26), 0),
            py: ref.y0 - (ref.h * 1.4),
            pw: width * 0.66,
            ph: height * 0.16
        }, width * 0.008, height * 0.010, width, height);
        boxes.dob_expiry = limitedAdjust(boxes.dob_expiry, proposed, width, height, 0.06, 0.05);
    }

    const anchorAddress = findWord(words, ['ADDRESS']) || findWord(words, ['WAY', 'ST', 'STREET', 'ROAD', 'AVE']);
    if (anchorAddress) {
        const proposed = expandBox({
            ...boxes.address,
            px: Math.max(anchorAddress.x0 - (width * 0.04), 0),
            py: Math.max(anchorAddress.y0 - (height * 0.08), 0),
            pw: width * 0.52,
            ph: height * 0.26
        }, width * 0.008, height * 0.010, width, height);
        boxes.address = limitedAdjust(boxes.address, proposed, width, height, 0.06, 0.05);
    }

    return Object.values(boxes).map((b) => clampBox(b, width, height));
}

function getRoiConfig(key) {
    if (key === 'name') {
        return {
            tessedit_pageseg_mode: '7',
            tessedit_char_whitelist: "ABCDEFGHIJKLMNOPQRSTUVWXYZ '-"
        };
    }
    if (key === 'licence_number') {
        return {
            tessedit_pageseg_mode: '7',
            tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
        };
    }
    if (key === 'dob_expiry') {
        return {
            tessedit_pageseg_mode: '6',
            tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789/- '
        };
    }
    if (key === 'address') {
        return {
            tessedit_pageseg_mode: '6',
            tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 ,.-/'
        };
    }
    return { tessedit_pageseg_mode: '6' };
}

function cropFromBox(canvas, box) {
    const crop = document.createElement('canvas');
    crop.width = box.pw;
    crop.height = box.ph;
    const cctx = crop.getContext('2d', { willReadFrequently: true });
    cctx.drawImage(canvas, box.px, box.py, box.pw, box.ph, 0, 0, box.pw, box.ph);
    return crop;
}

export function createIdOcrEngine(options) {
    const { tesseract, roiCanvas, overlayCanvas, rois = defaultRois() } = options;
    if (!tesseract) throw new Error('Tesseract instance is required');
    if (!roiCanvas) throw new Error('ROI canvas is required');

    return {
        clearOverlay() {
            drawRoiOverlay(overlayCanvas, []);
        },

        async run(img, progressCb) {
            const { canvas, width, height } = preprocessIntoCanvas(img, roiCanvas);

            const full = await tesseract.recognize(canvas, 'eng', {
                tessedit_pageseg_mode: '6',
                logger: (m) => progressCb?.('full', m)
            });

            const words = collectWordBoxes(full);
            const pixelBoxes = adaptiveBoxes(rois, words, width, height);
            drawRoiOverlay(overlayCanvas, pixelBoxes);

            const perFieldText = {};
            const perFieldConfidence = {};

            for (const box of pixelBoxes) {
                const crop = cropFromBox(canvas, box);
                const res = await tesseract.recognize(crop, 'eng', {
                    ...getRoiConfig(box.key),
                    logger: (m) => progressCb?.(box.key, m)
                });

                perFieldText[box.key] = normalizeOcrText(res?.data?.text || '');
                perFieldConfidence[box.key] = Number((res?.data?.confidence || 0).toFixed(2));
            }

            const fullText = normalizeOcrText(full?.data?.text || '');
            const parsed = parseOcrFields(fullText, perFieldText);
            const confidence = Number((full?.data?.confidence || 0).toFixed(2));

            return {
                fullText,
                parsed,
                confidence,
                perFieldText,
                perFieldConfidence,
                boxes: pixelBoxes,
                wordCount: words.length
            };
        }
    };
}

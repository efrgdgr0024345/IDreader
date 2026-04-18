<?php
declare(strict_types=1);

date_default_timezone_set('Australia/Perth');

$saveDir = __DIR__ . '/face_scans';
$currentScanFile = __DIR__ . '/current_scan.json';

if (!is_dir($saveDir)) {
    @mkdir($saveDir, 0775, true);
}

if (!is_dir($saveDir) || !is_writable($saveDir)) {
    json_response([
        'ok' => false,
        'error' => 'Storage directory is not writable',
        'path' => $saveDir
    ], 500);
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function write_json_file(string $filePath, array $data): bool {
    return file_put_contents(
        $filePath,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

function read_json_file(string $filePath): ?array {
    if (!is_file($filePath)) {
        return null;
    }
    $raw = file_get_contents($filePath);
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'poll_current_scan') {
    $data = read_json_file($currentScanFile);
    if (!$data) {
        json_response([
            'ok' => true,
            'found' => false,
            'message' => 'No current scan file yet'
        ]);
    }

    json_response([
        'ok' => true,
        'found' => true,
        'current_scan' => $data
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_scan') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $landmarks = $payload['landmarks'] ?? null;
    $vector = $payload['face_map_vector'] ?? null;
    $meta = $payload['meta'] ?? [];
    $imageDataUrl = $payload['image_data_url'] ?? '';

    if (!is_array($landmarks) || count($landmarks) < 10) {
        json_response(['ok' => false, 'error' => 'No valid landmarks supplied'], 400);
    }

    if (!is_array($vector) || count($vector) < 10) {
        json_response(['ok' => false, 'error' => 'No valid face_map_vector supplied'], 400);
    }

    $stamp = date('Ymd_His');
    $id = $stamp . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

    $jsonPath = $saveDir . "/scan_{$id}.json";
    $imagePath = $saveDir . "/scan_{$id}.jpg";

    $savedImage = false;
    if (is_string($imageDataUrl) && preg_match('#^data:image/jpeg;base64,#', $imageDataUrl)) {
        $base64 = substr($imageDataUrl, strpos($imageDataUrl, ',') + 1);
        $bin = base64_decode($base64, true);
        if ($bin !== false) {
            if (file_put_contents($imagePath, $bin) !== false) {
                $savedImage = true;
            }
        }
    }

    $record = [
        'id' => $id,
        'created_at' => date('c'),
        'type' => 'browser_face_map_capture',
        'note' => 'Geometric face map for testing. Not the final production embedding.',
        'meta' => [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
            'client_meta' => $meta,
        ],
        'landmarks_count' => count($landmarks),
        'landmarks' => $landmarks,
        'face_map_vector_count' => count($vector),
        'face_map_vector' => $vector,
        'saved_image' => $savedImage,
        'saved_image_file' => $savedImage ? basename($imagePath) : null,
        'workflow' => [
            'face_captured' => true,
            'id_captured' => false,
            'status' => 'face_captured_waiting_for_id',
            'last_updated' => date('c'),
        ],
        'id_scan' => [
            'linked' => false,
            'captured_at' => null,
            'image_file' => null,
            'json_file' => null,
            'type' => null,
            'meta' => null,
        ],
    ];

    $ok = file_put_contents($jsonPath, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($ok === false) {
        json_response(['ok' => false, 'error' => 'Failed to save JSON'], 500);
    }

    $currentScanPayload = [
        'ok' => true,
        'scan_id' => $id,
        'json_file' => basename($jsonPath),
        'image_file' => $savedImage ? basename($imagePath) : null,
        'id_image_file' => null,
        'id_crop_image_file' => null,
        'id_full_image_file' => null,
        'updated_at' => date('c'),
        'timestamp' => time(),
        'status' => 'face_captured_waiting_for_id',
        'ready_for_next_scan' => false,
        'display_message' => 'Waiting for ID capture'
    ];

    if (!write_json_file($currentScanFile, $currentScanPayload)) {
        json_response(['ok' => false, 'error' => 'Failed to update current scan state'], 500);
    }

    json_response([
        'ok' => true,
        'id' => $id,
        'json_file' => basename($jsonPath),
        'image_file' => $savedImage ? basename($imagePath) : null,
        'saved_dir' => $saveDir,
    ]);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<meta http-equiv="Pragma" content="no-cache">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Face Map Capture Debug</title>
<style>
    :root{
        --bg:#ffffff;
        --panel:#101923;
        --panel2:#162332;
        --line:#294058;
        --text:#111111;
        --muted:#99aec2;
        --good:#39d98a;
        --warn:#ffc857;
        --bad:#ff6b6b;
        --accent:#4da3ff;
    }
    *{box-sizing:border-box}
    body{
        margin:0;
        background:#ffffff;
        color:var(--text);
        font-family:Arial,Helvetica,sans-serif;
    }
    .wrap{max-width:1400px;margin:0 auto;padding:20px}
    h1{margin:0 0 8px;font-size:30px}
    .sub{color:var(--muted);line-height:1.55;margin-bottom:18px}
    .grid{
        display:grid;
        grid-template-columns:1.2fr 0.8fr;
        gap:18px;
    }
    @media (max-width: 1050px){
        .grid{grid-template-columns:1fr}
    }
    .card{
        background:rgba(16,25,35,.96);
        border:1px solid var(--line);
        border-radius:18px;
        padding:16px;
        box-shadow:0 12px 30px rgba(0,0,0,.22);
    }
    .video-wrap{
        position:relative;
        overflow:hidden;
        border-radius:16px;
        background:#000;
        border:1px solid var(--line);
        aspect-ratio:16 / 9;
    }
    video, canvas{
        position:absolute;
        inset:0;
        width:100%;
        height:100%;
        object-fit:cover;
    }
    .controls{
        display:grid;
        grid-template-columns:repeat(4,1fr);
        gap:10px;
        margin-top:14px;
    }
    @media (max-width: 700px){
        .controls{grid-template-columns:1fr 1fr}
    }
    @media (max-width: 520px){
        .controls{grid-template-columns:1fr}
    }
    button, select{
        width:100%;
        padding:12px 14px;
        border-radius:12px;
        font-weight:700;
        color:white;
        border:none;
    }
    button{cursor:pointer;background:linear-gradient(180deg,#2a7fe0,#1e63b0)}
    button.secondary{
        background:#223446;
        border:1px solid var(--line);
    }
    button.danger{
        background:linear-gradient(180deg,#c24f4f,#933737);
    }
    select{
        background:#162332;
        border:1px solid var(--line);
    }
    .status{
        margin-top:12px;
        padding:12px;
        border-radius:12px;
        background:#0b141d;
        border:1px solid var(--line);
        min-height:70px;
        white-space:pre-wrap;
        font-size:14px;
        line-height:1.45;
    }
    .stats{margin-top:12px}
    .stat{
        display:inline-block;
        margin:6px 8px 0 0;
        padding:6px 10px;
        border-radius:999px;
        font-size:12px;
        background:#1a2a3a;
        border:1px solid var(--line);
    }
    pre{
        margin:0;
        background:#091018;
        border:1px solid var(--line);
        border-radius:14px;
        padding:12px;
        font-size:12px;
        line-height:1.45;
        color:#d7e5f3;
        max-height:420px;
        overflow:auto;
        white-space:pre-wrap;
        word-break:break-word;
    }
    .thumb{
        margin-top:10px;
        width:100%;
        border-radius:12px;
        border:1px solid var(--line);
        display:none;
        background:#091018;
    }
    .id-preview-wrap{
        position:relative;
        margin-top:10px;
        display:none;
    }
    .id-preview-wrap .thumb{
        margin-top:0;
        display:block;
    }
    #idOcrOverlay{
        position:absolute;
        inset:0;
        width:100%;
        height:100%;
        pointer-events:none;
    }
    .thumb-label{
        margin-top:14px;
        font-size:13px;
        color:var(--muted);
        font-weight:700;
    }
    .two{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:18px;
        margin-top:18px;
    }
    @media (max-width: 1050px){
        .two{grid-template-columns:1fr}
    }
    .mono{
        font-family:Consolas, Monaco, monospace;
    }
    .small{font-size:13px;color:var(--muted);line-height:1.55}
    .ocr-grid{
        display:grid;
        grid-template-columns:1fr;
        gap:12px;
        margin-top:12px;
    }
    .ocr-pill-row{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        margin-top:8px;
    }
    .ocr-pill{
        display:inline-block;
        padding:6px 10px;
        border-radius:999px;
        background:#142131;
        border:1px solid var(--line);
        font-size:12px;
        color:#dbe9f7;
    }
    .ocr-good{border-color:#2f8f66}
    .ocr-warn{border-color:#8d6a28}
    .ocr-bad{border-color:#904444}
    .field-box{
        background:#0b141d;
        border:1px solid var(--line);
        border-radius:14px;
        padding:12px;
        font-size:13px;
        line-height:1.5;
        color:#d7e5f3;
    }
    .field-label{
        color:var(--muted);
        font-size:12px;
        margin-bottom:4px;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.04em;
    }
    .field-value{
        font-size:14px;
        color:#eef5fb;
        word-break:break-word;
    }

    .roi-controls{
        margin-top:10px;
        display:flex;
        gap:8px;
        flex-wrap:wrap;
    }
    .roi-controls button{
        padding:8px 11px;
        border-radius:10px;
        border:1px solid var(--line);
        background:#122133;
        color:#eef5fb;
        font-weight:700;
        cursor:pointer;
    }
    .roi-controls button.active{
        border-color:var(--warn);
        background:#3a2d12;
    }
    .roi-hint{
        margin-top:8px;
        font-size:12px;
        color:var(--muted);
        min-height:18px;
    }
    .hidden-canvas{
        display:none;
    }
</style>
</head>
<body>
<div class="wrap">
    <h1>Face Map Capture Debug</h1>
    <div class="sub">
        This page uses the laptop camera to capture the face mesh and save the current active person for the iPhone ID scanner.
        It stays on this page and also shows the linked ID image when the phone finishes the capture.
    </div>

    <div class="grid">
        <div class="card">
            <div class="video-wrap">
                <video id="video" playsinline autoplay muted></video>
                <canvas id="overlay"></canvas>
            </div>

            <div style="margin-top:14px">
                <label for="cameraSelect" style="display:block;margin-bottom:8px;color:#d4e4f3;font-weight:700;">Camera device</label>
                <select id="cameraSelect"></select>
            </div>

            <div class="controls">
                <button id="scanBtn" class="secondary">Scan Cameras</button>
                <button id="startBtn">Start Camera</button>
                <button id="captureBtn" class="secondary">Capture Face Map</button>
                <button id="stopBtn" class="danger">Stop Camera</button>
            </div>

            <div id="status" class="status">Idle.</div>

            <div class="stats">
                <span class="stat" id="statFaces">Faces: 0</span>
                <span class="stat" id="statQuality">Quality: waiting</span>
                <span class="stat" id="statPoints">Points: 0</span>
                <span class="stat" id="statCamera">Camera: none</span>
                <span class="stat" id="statOcr">OCR: idle</span>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-top:0">Latest saved payload</h3>
            <div class="small" style="margin-bottom:10px">
                This stores a browser-side geometric face map for testing. The iPhone page reads the current active person from <span class="mono">current_scan.json</span>.
            </div>
            <pre id="output">No capture yet.</pre>

            <div class="thumb-label">Face capture image</div>
            <img id="thumb" class="thumb" alt="Captured face preview">

            <div class="thumb-label">Linked ID image</div>
            <div id="idPreviewWrap" class="id-preview-wrap">
                <img id="idThumb" class="thumb" alt="Captured ID preview">
                <canvas id="idOcrOverlay"></canvas>
            </div>
            <div class="roi-controls">
                <button id="setBoxesBtn" type="button">Set boxes</button>
                <button id="resetBoxesBtn" type="button">Reset boxes</button>
            </div>
            <div id="roiHint" class="roi-hint">Use the default WA template boxes for OCR.</div>

            <div class="thumb-label">OCR result</div>
            <pre id="ocrOutput" class="mono">No OCR run yet.</pre>

            <div class="ocr-grid">
                <div class="field-box">
                    <div class="field-label">Detected fields</div>
                    <div id="ocrFields" class="field-value">No parsed fields yet.</div>
                </div>
                <div class="field-box">
                    <div class="field-label">OCR status</div>
                    <div id="ocrMeta" class="field-value">Waiting for a linked ID image.</div>
                    <div id="ocrPills" class="ocr-pill-row"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="two">
        <div class="card">
            <h3 style="margin-top:0">Runtime diagnostics</h3>
            <pre id="diag" class="mono">Waiting for startup...</pre>
        </div>
        <div class="card">
            <h3 style="margin-top:0">Debug log</h3>
            <pre id="log" class="mono">Logger ready.</pre>
        </div>
    </div>
</div>

<canvas id="ocrCanvas" class="hidden-canvas"></canvas>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script type="module">
const { createIdOcrEngine, defaultRois, drawRoiOverlayNormalized, sanitizeRois } = await import(new URL('id-ocr.js', window.location.href).toString());

const MP_VERSION = "0.10.21";
const MP_IMPORT_URL = `https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@${MP_VERSION}/vision_bundle.mjs`;
const MP_WASM_URL   = `https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@${MP_VERSION}/wasm`;
const MP_MODEL_URL  = "https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task";

const video = document.getElementById('video');
const overlay = document.getElementById('overlay');
const ctx = overlay.getContext('2d');
const statusBox = document.getElementById('status');
const output = document.getElementById('output');
const thumb = document.getElementById('thumb');
const idPreviewWrap = document.getElementById('idPreviewWrap');
const idThumb = document.getElementById('idThumb');
const idOcrOverlay = document.getElementById('idOcrOverlay');
const logBox = document.getElementById('log');
const diagBox = document.getElementById('diag');
const cameraSelect = document.getElementById('cameraSelect');
const ocrOutput = document.getElementById('ocrOutput');
const ocrFields = document.getElementById('ocrFields');
const ocrMeta = document.getElementById('ocrMeta');
const ocrPills = document.getElementById('ocrPills');
const ocrCanvas = document.getElementById('ocrCanvas');
const setBoxesBtn = document.getElementById('setBoxesBtn');
const resetBoxesBtn = document.getElementById('resetBoxesBtn');
const roiHint = document.getElementById('roiHint');

const statFaces = document.getElementById('statFaces');
const statQuality = document.getElementById('statQuality');
const statPoints = document.getElementById('statPoints');
const statCamera = document.getElementById('statCamera');
const statOcr = document.getElementById('statOcr');

const scanBtn = document.getElementById('scanBtn');
const startBtn = document.getElementById('startBtn');
const captureBtn = document.getElementById('captureBtn');
const stopBtn = document.getElementById('stopBtn');

let stream = null;
let landmarker = null;
let DrawingUtils = null;
let drawingUtils = null;
let FilesetResolver = null;
let FaceLandmarker = null;
let running = false;
let lastVideoTime = -1;
let latestFace = null;
let latestCaptureJpeg = '';
let selectedDeviceId = '';
let lastResultSummary = null;
let latestSavedScanId = '';
let latestSavedImageFile = '';
let latestSavedIdImageFile = '';
let lastCurrentScanTimestamp = 0;
let lastCurrentScanStatus = '';

let ocrInFlight = false;
let ocrLastStartedFile = '';
let ocrLastCompletedFile = '';
let ocrLastRawText = '';
let ocrLastParsed = null;
let ocrLastConfidence = null;
let ocrEngine = null;
let editableRois = loadSavedRois();
let roiEditMode = false;
let roiInteraction = null;

function roisStorageKey() {
    return 'idreader_wa_rois_v1';
}

function loadSavedRois() {
    try {
        const raw = localStorage.getItem(roisStorageKey());
        if (!raw) return defaultRois();
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed) || !parsed.length) return defaultRois();
        return sanitizeRois(parsed.map((roi, idx) => ({
            key: roi.key || defaultRois()[idx]?.key || `field_${idx}`,
            label: roi.label || defaultRois()[idx]?.label || `Field ${idx + 1}`,
            x: roi.x,
            y: roi.y,
            w: roi.w,
            h: roi.h
        })));
    } catch (err) {
        return defaultRois();
    }
}

function saveRois() {
    try {
        localStorage.setItem(roisStorageKey(), JSON.stringify(editableRois));
    } catch (err) {
        warn('Failed to persist ROIs', describeError(err));
    }
}

function drawCurrentRois() {
    if (!idOcrOverlay.width || !idOcrOverlay.height) return;
    drawRoiOverlayNormalized(idOcrOverlay, editableRois, roiEditMode
        ? { accent: 'rgba(255, 209, 102, 0.98)', fill: 'rgba(255, 209, 102, 0.15)' }
        : {});
}

function getRoiHit(x, y) {
    const handles = ['nw', 'ne', 'sw', 'se'];
    for (let i = editableRois.length - 1; i >= 0; i--) {
        const roi = editableRois[i];
        const left = roi.x * idOcrOverlay.width;
        const top = roi.y * idOcrOverlay.height;
        const right = (roi.x + roi.w) * idOcrOverlay.width;
        const bottom = (roi.y + roi.h) * idOcrOverlay.height;
        const r = 11;
        const points = {
            nw: [left, top],
            ne: [right, top],
            sw: [left, bottom],
            se: [right, bottom]
        };
        for (const h of handles) {
            const [hx, hy] = points[h];
            if (Math.abs(x - hx) <= r && Math.abs(y - hy) <= r) {
                return { index: i, mode: h };
            }
        }
        if (x >= left && x <= right && y >= top && y <= bottom) {
            return { index: i, mode: 'move' };
        }
    }
    return null;
}

function applyRoiDrag(action, x, y) {
    const idx = action.index;
    const roi = { ...editableRois[idx] };
    const dx = (x - action.startX) / idOcrOverlay.width;
    const dy = (y - action.startY) / idOcrOverlay.height;

    if (action.mode === 'move') {
        roi.x = clamp(action.base.x + dx, 0, 1 - action.base.w);
        roi.y = clamp(action.base.y + dy, 0, 1 - action.base.h);
    } else {
        const left0 = action.base.x;
        const top0 = action.base.y;
        const right0 = action.base.x + action.base.w;
        const bottom0 = action.base.y + action.base.h;
        let left = left0;
        let right = right0;
        let top = top0;
        let bottom = bottom0;

        if (action.mode.includes('w')) left = clamp(left0 + dx, 0, right0 - 0.04);
        if (action.mode.includes('e')) right = clamp(right0 + dx, left + 0.04, 1);
        if (action.mode.includes('n')) top = clamp(top0 + dy, 0, bottom0 - 0.04);
        if (action.mode.includes('s')) bottom = clamp(bottom0 + dy, top + 0.04, 1);

        roi.x = left;
        roi.y = top;
        roi.w = right - left;
        roi.h = bottom - top;
    }

    editableRois[idx] = sanitizeRois([roi])[0];
}

function nowTime() {
    try {
        return new Date().toLocaleTimeString();
    } catch (e) {
        return new Date().toISOString();
    }
}

function safeJson(value) {
    try {
        return JSON.stringify(value, null, 2);
    } catch (e) {
        return String(value);
    }
}

function describeError(err) {
    if (!err) {
        return {
            type: 'unknown',
            message: 'No error object'
        };
    }

    if (err instanceof Error) {
        return {
            type: err.name || 'Error',
            message: err.message || '',
            stack: err.stack || ''
        };
    }

    if (typeof Event !== "undefined" && err instanceof Event) {
        return {
            type: 'Event',
            message: err.type || 'event',
            targetTag: err.target?.tagName || null,
            currentTargetTag: err.currentTarget?.tagName || null,
            isTrusted: err.isTrusted ?? null
        };
    }

    return {
        type: typeof err,
        message: String(err),
        raw: (() => {
            try {
                return JSON.stringify(err, Object.getOwnPropertyNames(err), 2);
            } catch (_) {
                return null;
            }
        })()
    };
}

function appendLogLine(text) {
    logBox.textContent = `[${nowTime()}] ${text}\n` + logBox.textContent;
}

function log(...args) {
    console.log('[FaceMap]', ...args);
    const text = args.map(arg => typeof arg === 'string' ? arg : safeJson(arg)).join(' ');
    appendLogLine(text);
}

function warn(...args) {
    console.warn('[FaceMap]', ...args);
    const text = args.map(arg => typeof arg === 'string' ? arg : safeJson(arg)).join(' ');
    appendLogLine('WARN: ' + text);
}

function errorLog(...args) {
    console.error('[FaceMap]', ...args);
    const text = args.map(arg => typeof arg === 'string' ? arg : safeJson(arg)).join(' ');
    appendLogLine('ERROR: ' + text);
}

function setStatus(msg) {
    statusBox.textContent = msg;
    appendLogLine('STATUS: ' + msg);
}

function setDiag(obj) {
    diagBox.textContent = JSON.stringify(obj, null, 2);
}

function setOcrStatus(text) {
    ocrMeta.textContent = text;
}

function setOcrOutput(text) {
    ocrOutput.textContent = text;
}

function clearOcrPills() {
    ocrPills.innerHTML = '';
}

function addOcrPill(label, variant = '') {
    const span = document.createElement('span');
    span.className = 'ocr-pill' + (variant ? ' ' + variant : '');
    span.textContent = label;
    ocrPills.appendChild(span);
}

function showFaceThumb(src) {
    if (!src) {
        thumb.style.display = 'none';
        thumb.removeAttribute('src');
        return;
    }
    thumb.src = src;
    thumb.style.display = 'block';
}

function showIdThumb(file) {
    if (!file) {
        idPreviewWrap.style.display = 'none';
        idThumb.style.display = 'none';
        idThumb.removeAttribute('src');
        if (ocrEngine) {
            ocrEngine.clearOverlay();
        }
        return;
    }
    idThumb.src = 'face_scans/' + encodeURIComponent(file) + '?t=' + Date.now();
    idThumb.style.display = 'block';
    idPreviewWrap.style.display = 'block';
    if (idThumb.complete && idThumb.naturalWidth) {
        drawCurrentRois();
    }
}

function syncIdOverlayToImage() {
    const w = idThumb.naturalWidth || idThumb.clientWidth || 0;
    const h = idThumb.naturalHeight || idThumb.clientHeight || 0;
    if (!w || !h) return;
    idOcrOverlay.width = w;
    idOcrOverlay.height = h;
    drawCurrentRois();
}

function resetOcrUi(reason = 'Waiting for a linked ID image.') {
    statOcr.textContent = 'OCR: idle';
    setOcrOutput('No OCR run yet.');
    ocrFields.textContent = 'No parsed fields yet.';
    setOcrStatus(reason);
    clearOcrPills();
    ocrLastRawText = '';
    ocrLastParsed = null;
    ocrLastConfidence = null;
}

window.addEventListener('error', (event) => {
    errorLog('window.error', {
        message: event.message,
        filename: event.filename,
        lineno: event.lineno,
        colno: event.colno,
        error: event.error ? String(event.error.stack || event.error.message || event.error) : null
    });
});

window.addEventListener('unhandledrejection', (event) => {
    errorLog('unhandledrejection', {
        reason: event.reason ? String(event.reason.stack || event.reason.message || event.reason) : null
    });
});

function resizeCanvasToVideo() {
    const rect = video.getBoundingClientRect();
    overlay.width = Math.max(1, Math.round(rect.width));
    overlay.height = Math.max(1, Math.round(rect.height));
}

function clamp(v, min, max) {
    return Math.max(min, Math.min(max, v));
}

function distance(a, b) {
    const dx = (a.x - b.x);
    const dy = (a.y - b.y);
    const dz = (a.z - b.z);
    return Math.sqrt(dx * dx + dy * dy + dz * dz);
}

function averagePoint(points) {
    let sx = 0, sy = 0, sz = 0;
    for (const p of points) {
        sx += p.x;
        sy += p.y;
        sz += (p.z || 0);
    }
    return {
        x: sx / points.length,
        y: sy / points.length,
        z: sz / points.length
    };
}

function buildNormalizedFaceMap(lm) {
    const leftEye = averagePoint([lm[33], lm[133]]);
    const rightEye = averagePoint([lm[362], lm[263]]);
    const nose = lm[1];
    const mouthLeft = lm[61];
    const mouthRight = lm[291];
    const chin = lm[152];
    const forehead = lm[10];

    const eyeDist = distance(leftEye, rightEye);
    if (!eyeDist || !isFinite(eyeDist)) {
        warn('buildNormalizedFaceMap invalid eye distance', eyeDist);
        return null;
    }

    const anchorX = (leftEye.x + rightEye.x) / 2;
    const anchorY = (leftEye.y + rightEye.y) / 2;
    const anchorZ = (leftEye.z + rightEye.z) / 2;

    const chosen = [
        lm[33], lm[133], lm[362], lm[263], lm[1], lm[61], lm[291],
        lm[152], lm[10], lm[78], lm[308], lm[13], lm[14], lm[168], lm[6]
    ];

    const vector = [];
    for (const p of chosen) {
        vector.push(Number(((p.x - anchorX) / eyeDist).toFixed(6)));
        vector.push(Number(((p.y - anchorY) / eyeDist).toFixed(6)));
        vector.push(Number((((p.z || 0) - anchorZ) / eyeDist).toFixed(6)));
    }

    const mouthWidth = distance(mouthLeft, mouthRight) / eyeDist;
    const noseToChin = distance(nose, chin) / eyeDist;
    const foreheadToNose = distance(forehead, nose) / eyeDist;

    vector.push(Number(mouthWidth.toFixed(6)));
    vector.push(Number(noseToChin.toFixed(6)));
    vector.push(Number(foreheadToNose.toFixed(6)));

    return {
        anchor: {
            x: Number(anchorX.toFixed(6)),
            y: Number(anchorY.toFixed(6)),
            z: Number(anchorZ.toFixed(6))
        },
        eye_distance: Number(eyeDist.toFixed(6)),
        selected_landmarks: chosen.map((p, idx) => ({
            slot: idx,
            x: Number(p.x.toFixed(6)),
            y: Number(p.y.toFixed(6)),
            z: Number((p.z || 0).toFixed(6))
        })),
        face_map_vector: vector
    };
}

function estimateQuality(lm) {
    const leftEye = averagePoint([lm[33], lm[133]]);
    const rightEye = averagePoint([lm[362], lm[263]]);
    const nose = lm[1];
    const mouthLeft = lm[61];
    const mouthRight = lm[291];
    const chin = lm[152];
    const forehead = lm[10];

    const eyeDist = distance(leftEye, rightEye);
    const mouthWidth = distance(mouthLeft, mouthRight);
    const faceHeight = distance(forehead, chin);
    const centeredX = Math.abs(((nose.x || 0.5) - 0.5));
    const centeredY = Math.abs(((nose.y || 0.5) - 0.5));

    let score = 100;
    if (eyeDist < 0.06) score -= 40;
    if (faceHeight < 0.12) score -= 20;
    if (mouthWidth < 0.03) score -= 10;
    if (centeredX > 0.18) score -= 15;
    if (centeredY > 0.20) score -= 15;

    score = clamp(score, 0, 100);

    let label = 'good';
    if (score < 70) label = 'fair';
    if (score < 45) label = 'poor';

    return {
        score,
        label,
        eyeDist: Number(eyeDist.toFixed(6)),
        faceHeight: Number(faceHeight.toFixed(6)),
        centeredX: Number(centeredX.toFixed(6)),
        centeredY: Number(centeredY.toFixed(6))
    };
}

async function loadMediaPipe() {
    if (landmarker) {
        log('MediaPipe already loaded');
        return;
    }

    log('Starting MediaPipe import sequence');
    log('Trying import', {
        version: MP_VERSION,
        importUrl: MP_IMPORT_URL,
        wasmUrl: MP_WASM_URL,
        modelUrl: MP_MODEL_URL
    });

    let visionModule;
    try {
        visionModule = await import(MP_IMPORT_URL);
        log('Import success keys', Object.keys(visionModule));
    } catch (err) {
        const info = describeError(err);
        errorLog('Import failed', info);
        throw new Error('Failed to import MediaPipe module: ' + (info.message || info.type));
    }

    FaceLandmarker = visionModule.FaceLandmarker || null;
    FilesetResolver = visionModule.FilesetResolver || null;
    DrawingUtils = visionModule.DrawingUtils || null;

    log('Resolved module keys', Object.keys(visionModule));
    log('MediaPipe module selected', {
        loadedVersion: `vision_bundle.mjs @ ${MP_VERSION}`,
        wasmBasePath: MP_WASM_URL,
        hasFaceLandmarker: !!FaceLandmarker,
        hasFilesetResolver: !!FilesetResolver,
        hasDrawingUtils: !!DrawingUtils
    });

    if (!FaceLandmarker || !FilesetResolver) {
        throw new Error('MediaPipe module loaded but required exports are missing');
    }

    let vision;
    try {
        log('Creating FilesetResolver', MP_WASM_URL);
        vision = await FilesetResolver.forVisionTasks(MP_WASM_URL);
        log('FilesetResolver created', vision);
    } catch (err) {
        const info = describeError(err);
        errorLog('FilesetResolver creation failed', info);
        throw new Error('FilesetResolver failed: ' + (info.message || info.type));
    }

    try {
        log('Creating FaceLandmarker with model', MP_MODEL_URL);
        landmarker = await FaceLandmarker.createFromOptions(vision, {
            baseOptions: {
                modelAssetPath: MP_MODEL_URL
            },
            runningMode: 'VIDEO',
            numFaces: 1,
            minFaceDetectionConfidence: 0.5,
            minFacePresenceConfidence: 0.5,
            minTrackingConfidence: 0.5,
            outputFaceBlendshapes: false,
            outputFacialTransformationMatrixes: false
        });
        log('FaceLandmarker created successfully', {
            ok: !!landmarker
        });
    } catch (err) {
        const info = describeError(err);
        errorLog('FaceLandmarker.createFromOptions failed', info);
        throw new Error('FaceLandmarker init failed: ' + (info.message || info.type));
    }

    try {
        drawingUtils = DrawingUtils ? new DrawingUtils(ctx) : null;
        log('DrawingUtils ready', { ready: !!drawingUtils });
    } catch (err) {
        const info = describeError(err);
        warn('DrawingUtils init failed, continuing without it', info);
        drawingUtils = null;
    }
}

async function scanDevices() {
    try {
        log('Refreshing devices');
        const devices = await navigator.mediaDevices.enumerateDevices();
        log('enumerateDevices result', devices);

        const videos = devices.filter(d => d.kind === 'videoinput');

        cameraSelect.innerHTML = '';
        if (!videos.length) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'No camera devices found';
            cameraSelect.appendChild(opt);
            statCamera.textContent = 'Camera: none';
            return;
        }

        videos.forEach((device, i) => {
            const opt = document.createElement('option');
            opt.value = device.deviceId;
            opt.textContent = device.label || `Camera ${i + 1}`;
            cameraSelect.appendChild(opt);
        });

        if (selectedDeviceId) {
            cameraSelect.value = selectedDeviceId;
        } else {
            selectedDeviceId = cameraSelect.value || '';
        }

        statCamera.textContent = 'Camera: ' + (cameraSelect.options[cameraSelect.selectedIndex]?.text || 'unknown');
    } catch (err) {
        const info = describeError(err);
        errorLog('scanDevices failed', info);
        setStatus('Failed to scan devices: ' + (info.message || info.type));
    }
}

function stopCamera() {
    log('stopCamera called');
    running = false;
    latestFace = null;
    latestCaptureJpeg = '';
    lastVideoTime = -1;
    lastResultSummary = null;

    if (stream) {
        for (const track of stream.getTracks()) {
            log('Stopping track', {
                kind: track.kind,
                label: track.label,
                readyState: track.readyState
            });
            track.stop();
        }
        stream = null;
    }

    video.srcObject = null;
    ctx.clearRect(0, 0, overlay.width, overlay.height);
    statFaces.textContent = 'Faces: 0';
    statQuality.textContent = 'Quality: stopped';
    statPoints.textContent = 'Points: 0';
    statCamera.textContent = 'Camera: none';
    setStatus('Camera stopped.');
    updateDiagnostics();
}

async function startCamera() {
    log('startCamera called');
    stopCamera();

    try {
        if (!window.isSecureContext) {
            warn('Not a secure context. HTTPS or localhost is usually required for camera access.');
        }

        setStatus('Loading MediaPipe module...');
        await loadMediaPipe();

        selectedDeviceId = cameraSelect.value || '';

        const constraints = {
            video: selectedDeviceId ? {
                deviceId: { exact: selectedDeviceId },
                width: { ideal: 1280 },
                height: { ideal: 720 },
                facingMode: 'user'
            } : {
                width: { ideal: 1280 },
                height: { ideal: 720 },
                facingMode: 'user'
            },
            audio: false
        };

        log('Requesting user media with constraints', constraints);

        stream = await navigator.mediaDevices.getUserMedia(constraints);
        log('getUserMedia success');

        const tracks = stream.getVideoTracks();
        if (tracks.length) {
            const t = tracks[0];
            log('Video track info', {
                label: t.label,
                settings: t.getSettings ? t.getSettings() : null,
                capabilities: t.getCapabilities ? t.getCapabilities() : null,
                constraints: t.getConstraints ? t.getConstraints() : null
            });
            statCamera.textContent = 'Camera: ' + (t.label || 'unknown');
        }

        video.srcObject = stream;

        await new Promise((resolve, reject) => {
            const timeout = setTimeout(() => reject(new Error('Timed out waiting for video metadata')), 10000);
            video.onloadedmetadata = () => {
                clearTimeout(timeout);
                resolve();
            };
        });

        await video.play();

        log('Video playback started', {
            videoWidth: video.videoWidth,
            videoHeight: video.videoHeight,
            readyState: video.readyState
        });

        resizeCanvasToVideo();
        window.addEventListener('resize', resizeCanvasToVideo);

        running = true;
        setStatus('Camera started. Looking for face...');
        requestAnimationFrame(loop);

        await scanDevices();
        updateDiagnostics();
    } catch (err) {
        const info = describeError(err);
        errorLog('startCamera failed', info);
        setStatus('Failed to start camera: ' + (info.message || info.type));
        updateDiagnostics();
    }
}

function drawLandmarks(landmarks) {
    ctx.clearRect(0, 0, overlay.width, overlay.height);

    const w = overlay.width;
    const h = overlay.height;

    ctx.lineWidth = 1;
    ctx.strokeStyle = 'rgba(80,180,255,0.65)';
    ctx.fillStyle = 'rgba(80,180,255,0.85)';

    for (const p of landmarks) {
        const x = p.x * w;
        const y = p.y * h;
        ctx.beginPath();
        ctx.arc(x, y, 1.5, 0, Math.PI * 2);
        ctx.fill();
    }

    const xs = landmarks.map(p => p.x);
    const ys = landmarks.map(p => p.y);
    const minX = Math.min(...xs) * w;
    const maxX = Math.max(...xs) * w;
    const minY = Math.min(...ys) * h;
    const maxY = Math.max(...ys) * h;

    ctx.strokeStyle = 'rgba(57,217,138,0.95)';
    ctx.lineWidth = 2;
    ctx.strokeRect(minX, minY, maxX - minX, maxY - minY);
}

function makeJpegSnapshot() {
    const snap = document.createElement('canvas');
    snap.width = video.videoWidth;
    snap.height = video.videoHeight;
    const sctx = snap.getContext('2d');
    sctx.drawImage(video, 0, 0, snap.width, snap.height);
    return snap.toDataURL('image/jpeg', 0.92);
}

function renderParsedFields(parsed) {
    const parts = [];

    if (parsed.name) parts.push(`Name: ${parsed.name}`);
    if (parsed.licence_number) parts.push(`Licence Number: ${parsed.licence_number}`);
    if (parsed.dob) parts.push(`DOB: ${parsed.dob}`);
    if (parsed.expiry) parts.push(`Expiry: ${parsed.expiry}`);
    if (parsed.address) parts.push(`Address: ${parsed.address}`);
    if (Array.isArray(parsed.date_candidates) && parsed.date_candidates.length) {
        parts.push(`Date Candidates: ${parsed.date_candidates.join(', ')}`);
    }

    ocrFields.textContent = parts.length ? parts.join('\n') : 'No strong fields parsed yet.';
}

async function runIdOcrFromCurrentImage(fileName) {
    try {
        if (!window.Tesseract) {
            throw new Error('Tesseract.js did not load');
        }
        if (!ocrEngine) {
            throw new Error('OCR engine not initialized');
        }

        if (!fileName) {
            warn('runIdOcrFromCurrentImage called without fileName');
            return;
        }

        if (ocrInFlight) {
            log('OCR already running, skipping', fileName);
            return;
        }

        if (ocrLastCompletedFile === fileName) {
            log('OCR already completed for this file, skipping', fileName);
            return;
        }

        if (!idThumb.src || idThumb.style.display !== 'block') {
            warn('ID image not available for OCR yet');
            return;
        }

        if (!idThumb.complete || !idThumb.naturalWidth) {
            await new Promise((resolve, reject) => {
                const cleanup = () => {
                    idThumb.onload = null;
                    idThumb.onerror = null;
                };
                idThumb.onload = () => {
                    cleanup();
                    resolve();
                };
                idThumb.onerror = () => {
                    cleanup();
                    reject(new Error('ID image failed to load for OCR'));
                };
            });
        }

        ocrInFlight = true;
        ocrLastStartedFile = fileName;
        statOcr.textContent = 'OCR: running';
        setOcrOutput('Running OCR...');
        setOcrStatus('Preparing image and starting browser OCR.');
        clearOcrPills();
        addOcrPill('Linked ID image detected', 'ocr-good');
        addOcrPill('Browser OCR started', 'ocr-warn');

        syncIdOverlayToImage();
        const result = await ocrEngine.run(idThumb, (stage, m) => {
            if (!m || !m.status) return;
            const pct = Math.max(0, Math.min(100, Math.round((m.progress || 0) * 100)));
            statOcr.textContent = `OCR: ${pct}%`;
            setOcrStatus(`OCR [${stage}] ${m.status} ${pct}%`);
        });

        const rawText = result.fullText;
        const confidence = result.confidence;
        const parsed = result.parsed;

        ocrLastCompletedFile = fileName;
        ocrLastRawText = rawText;
        ocrLastConfidence = confidence;
        ocrLastParsed = parsed;

        const fieldDetails = Object.entries(result.perFieldText || {})
            .map(([key, value]) => {
                const pct = result.perFieldConfidence?.[key] ?? 0;
                return `--- ${key} (${pct}%) ---\n${value || '(no text)'}`;
            })
            .join('\n\n');

        setOcrOutput(
            (rawText ? `FULL OCR\n${rawText}` : 'OCR finished, but no text was found.') +
            (fieldDetails ? '\n\n' + fieldDetails : '')
        );
        renderParsedFields(parsed);

        clearOcrPills();
        addOcrPill(`Confidence ${confidence}%`, confidence >= 75 ? 'ocr-good' : (confidence >= 50 ? 'ocr-warn' : 'ocr-bad'));
        addOcrPill(`Full pass: ${result.fullVariant}`, 'ocr-good');
        addOcrPill(parsed.name ? 'Name found' : 'Name unclear', parsed.name ? 'ocr-good' : 'ocr-warn');
        addOcrPill(parsed.licence_number ? 'Licence number found' : 'Licence number unclear', parsed.licence_number ? 'ocr-good' : 'ocr-warn');
        addOcrPill(parsed.dob ? 'DOB found' : 'DOB unclear', parsed.dob ? 'ocr-good' : 'ocr-warn');
        addOcrPill(parsed.expiry ? 'Expiry found' : 'Expiry unclear', parsed.expiry ? 'ocr-good' : 'ocr-warn');
        addOcrPill(parsed.address ? 'Address found' : 'Address unclear', parsed.address ? 'ocr-good' : 'ocr-warn');
        Object.entries(result.perFieldVariant || {}).forEach(([key, variant]) => {
            addOcrPill(`${key}: ${variant}`, 'ocr-good');
        });

        statOcr.textContent = confidence ? `OCR: done ${confidence}%` : 'OCR: done';
        setOcrStatus(
            'OCR complete for linked ID image.\n' +
            'File: ' + fileName + '\n' +
            'Confidence: ' + confidence + '%'
        );

        log('OCR finished', {
            fileName,
            confidence,
            parsed
        });

        updateDiagnostics();
    } catch (err) {
        const info = describeError(err);
        errorLog('runIdOcrFromCurrentImage failed', info);
        statOcr.textContent = 'OCR: failed';
        setOcrStatus('OCR failed.');
        setOcrOutput('OCR failed: ' + (info.message || info.type));
        clearOcrPills();
        addOcrPill('OCR failed', 'ocr-bad');
        updateDiagnostics();
    } finally {
        ocrInFlight = false;
    }
}

function triggerOcrForLinkedId(fileName) {
    if (!fileName) return;
    if (ocrLastCompletedFile === fileName || ocrLastStartedFile === fileName) return;

    const start = () => runIdOcrFromCurrentImage(fileName);

    if (idThumb.complete && idThumb.naturalWidth) {
        start();
        return;
    }

    const onLoad = () => {
        idThumb.removeEventListener('load', onLoad);
        idThumb.removeEventListener('error', onError);
        start();
    };

    const onError = () => {
        idThumb.removeEventListener('load', onLoad);
        idThumb.removeEventListener('error', onError);
        errorLog('Linked ID image failed to load before OCR');
    };

    idThumb.addEventListener('load', onLoad, { once: true });
    idThumb.addEventListener('error', onError, { once: true });
}

function updateDiagnostics() {
    const track = stream?.getVideoTracks?.()[0] || null;
    setDiag({
        page: location.href,
        secureContext: window.isSecureContext,
        userAgent: navigator.userAgent,
        mediaDevices: !!navigator.mediaDevices,
        getUserMedia: !!navigator.mediaDevices?.getUserMedia,
        enumerateDevices: !!navigator.mediaDevices?.enumerateDevices,
        mediapipe: {
            version: MP_VERSION,
            importUrl: MP_IMPORT_URL,
            wasmUrl: MP_WASM_URL,
            modelUrl: MP_MODEL_URL,
            loaded: !!landmarker
        },
        ocr: {
            tesseractLoaded: !!window.Tesseract,
            inFlight: ocrInFlight,
            lastStartedFile: ocrLastStartedFile,
            lastCompletedFile: ocrLastCompletedFile,
            confidence: ocrLastConfidence,
            parsed: ocrLastParsed
        },
        video: {
            readyState: video.readyState,
            currentTime: video.currentTime,
            videoWidth: video.videoWidth,
            videoHeight: video.videoHeight
        },
        stream: track ? {
            label: track.label,
            settings: track.getSettings ? track.getSettings() : null,
            capabilities: track.getCapabilities ? track.getCapabilities() : null
        } : null,
        latestSavedScanId,
        latestSavedImageFile,
        latestSavedIdImageFile,
        lastCurrentScanTimestamp,
        lastResultSummary
    });
}

async function pollCurrentScan() {
    try {
        const res = await fetch('?action=poll_current_scan&t=' + Date.now(), {
            cache: 'no-store'
        });

        const json = await res.json();
        if (!json.ok || !json.found || !json.current_scan) {
            return;
        }

        const current = json.current_scan;
        const scanId = current.scan_id || '';
        const timestamp = Number(current.timestamp || 0);
        const imageFile = current.image_file || '';
        const idImageFile = current.id_image_file || current.id_full_image_file || '';
        const status = current.status || '';

        if (!scanId) {
            return;
        }

        const changed =
            scanId !== latestSavedScanId ||
            timestamp !== lastCurrentScanTimestamp ||
            status !== lastCurrentScanStatus ||
            idImageFile !== latestSavedIdImageFile;

        if (!changed) {
            return;
        }

        latestSavedScanId = scanId;
        latestSavedImageFile = imageFile;
        const previousIdImageFile = latestSavedIdImageFile;
        latestSavedIdImageFile = idImageFile;
        lastCurrentScanTimestamp = timestamp;
        lastCurrentScanStatus = status;

        if (imageFile && thumb.style.display !== 'block') {
            showFaceThumb('face_scans/' + encodeURIComponent(imageFile) + '?t=' + Date.now());
        }

        if (!idImageFile) {
            showIdThumb(null);
            if (previousIdImageFile) {
                resetOcrUi('Linked ID image cleared. Waiting for the next one.');
            }
        } else {
            showIdThumb(idImageFile);
        }

        if (status === 'id_captured_complete' && idImageFile) {
            setStatus(
                'ID capture completed on iPhone.\n' +
                'Scan ID: ' + scanId + '\n' +
                'Face Image: ' + (imageFile || 'saved') + '\n' +
                'ID Image: ' + idImageFile
            );

            if (previousIdImageFile !== idImageFile) {
                resetOcrUi('New linked ID image received. Starting OCR...');
                triggerOcrForLinkedId(idImageFile);
            }
        }

        updateDiagnostics();
    } catch (err) {
        console.error('pollCurrentScan failed', err);
    }
}

async function loop() {
    if (!running || !landmarker) return;

    try {
        if (video.currentTime !== lastVideoTime) {
            lastVideoTime = video.currentTime;
            resizeCanvasToVideo();

            const result = landmarker.detectForVideo(video, performance.now());
            const faces = result?.faceLandmarks || [];

            statFaces.textContent = 'Faces: ' + faces.length;

            if (faces.length > 0) {
                const lm = faces[0];
                drawLandmarks(lm);
                statPoints.textContent = 'Points: ' + lm.length;

                const quality = estimateQuality(lm);
                statQuality.textContent = 'Quality: ' + quality.label + ' (' + quality.score + '/100)';

                const faceMap = buildNormalizedFaceMap(lm);
                latestCaptureJpeg = makeJpegSnapshot();

                latestFace = {
                    landmarks: lm.map(p => ({
                        x: Number(p.x.toFixed(6)),
                        y: Number(p.y.toFixed(6)),
                        z: Number((p.z || 0).toFixed(6))
                    })),
                    quality,
                    faceMap
                };

                lastResultSummary = {
                    faces: faces.length,
                    points: lm.length,
                    quality,
                    eye_distance: faceMap?.eye_distance || null
                };

                statusBox.textContent =
                    'Face detected.\n' +
                    'Quality score: ' + quality.score + '/100\n' +
                    'Eye distance: ' + quality.eyeDist + '\n' +
                    'Face height: ' + quality.faceHeight;
            } else {
                latestFace = null;
                ctx.clearRect(0, 0, overlay.width, overlay.height);
                statPoints.textContent = 'Points: 0';
                statQuality.textContent = 'Quality: waiting';
                lastResultSummary = { faces: 0 };
                statusBox.textContent = 'No face found. Move closer and face the camera.';
            }

            updateDiagnostics();
        }
    } catch (err) {
        const info = describeError(err);
        errorLog('Detection loop failed', info);
        setStatus('Detection loop failed: ' + (info.message || info.type));
    }

    requestAnimationFrame(loop);
}

async function saveCurrentFace() {
    try {
        if (!latestFace || !latestFace.faceMap) {
            setStatus('No usable face map available yet.');
            return;
        }

        const payload = {
            landmarks: latestFace.landmarks,
            face_map_vector: latestFace.faceMap.face_map_vector,
            image_data_url: latestCaptureJpeg,
            meta: {
                quality: latestFace.quality,
                eye_distance: latestFace.faceMap.eye_distance,
                anchor: latestFace.faceMap.anchor,
                selected_landmarks: latestFace.faceMap.selected_landmarks,
                viewport: {
                    width: window.innerWidth,
                    height: window.innerHeight
                },
                video: {
                    width: video.videoWidth,
                    height: video.videoHeight
                },
                debug: {
                    mediapipe_version: MP_VERSION,
                    camera_label: stream?.getVideoTracks?.()[0]?.label || null
                }
            }
        };

        output.textContent = JSON.stringify(payload, null, 2);
        showFaceThumb(latestCaptureJpeg);
        showIdThumb(null);
        resetOcrUi('Waiting for a linked ID image.');

        log('Saving capture payload', {
            landmarks: payload.landmarks.length,
            vectorLength: payload.face_map_vector.length,
            hasImage: !!payload.image_data_url
        });

        setStatus('Saving face map...');

        const res = await fetch('?action=save_scan', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const text = await res.text();
        log('Save response raw text', text);

        let json;
        try {
            json = JSON.parse(text);
        } catch (err) {
            throw new Error('Save endpoint did not return valid JSON: ' + text);
        }

        if (!res.ok || !json.ok) {
            throw new Error(json.error || ('HTTP ' + res.status));
        }

        latestSavedScanId = json.id || '';
        latestSavedImageFile = json.image_file || '';
        latestSavedIdImageFile = '';
        lastCurrentScanTimestamp = Math.floor(Date.now() / 1000);
        lastCurrentScanStatus = 'face_captured_waiting_for_id';
        ocrLastStartedFile = '';
        ocrLastCompletedFile = '';

        setStatus(
            'Saved successfully.\n' +
            'ID: ' + json.id + '\n' +
            'JSON: ' + json.json_file + '\n' +
            'Image: ' + (json.image_file || 'not saved') + '\n' +
            'The iPhone can now capture the matching ID.'
        );

        updateDiagnostics();
    } catch (err) {
        const info = describeError(err);
        errorLog('saveCurrentFace failed', info);
        setStatus('Save failed: ' + (info.message || info.type));
    }
}

scanBtn.addEventListener('click', scanDevices);
startBtn.addEventListener('click', startCamera);
captureBtn.addEventListener('click', saveCurrentFace);
stopBtn.addEventListener('click', stopCamera);

cameraSelect.addEventListener('change', () => {
    selectedDeviceId = cameraSelect.value || '';
    log('Camera selection changed', {
        deviceId: selectedDeviceId,
        label: cameraSelect.options[cameraSelect.selectedIndex]?.text || ''
    });
});
idThumb.addEventListener('load', syncIdOverlayToImage);
window.addEventListener('resize', syncIdOverlayToImage);

function pointerPos(evt) {
    const rect = idOcrOverlay.getBoundingClientRect();
    const e = evt.touches?.[0] || evt;
    return {
        x: clamp(e.clientX - rect.left, 0, rect.width),
        y: clamp(e.clientY - rect.top, 0, rect.height)
    };
}

function onRoiPointerDown(evt) {
    if (!roiEditMode || !idThumb.naturalWidth) return;
    const p = pointerPos(evt);
    const hit = getRoiHit(p.x, p.y);
    if (!hit) return;
    evt.preventDefault();
    const base = { ...editableRois[hit.index] };
    roiInteraction = {
        ...hit,
        startX: p.x,
        startY: p.y,
        base
    };
}

function onRoiPointerMove(evt) {
    if (!roiEditMode || !roiInteraction) return;
    evt.preventDefault();
    const p = pointerPos(evt);
    applyRoiDrag(roiInteraction, p.x, p.y);
    drawCurrentRois();
}

function onRoiPointerUp() {
    if (!roiInteraction) return;
    roiInteraction = null;
    saveRois();
}

setBoxesBtn.addEventListener('click', () => {
    roiEditMode = !roiEditMode;
    idOcrOverlay.style.pointerEvents = roiEditMode ? 'auto' : 'none';
    setBoxesBtn.classList.toggle('active', roiEditMode);
    setBoxesBtn.textContent = roiEditMode ? 'Lock boxes' : 'Set boxes';
    roiHint.textContent = roiEditMode
        ? 'Drag inside a box to move it. Drag corners to resize. Boxes are saved automatically.'
        : 'Using your saved WA template boxes for OCR.';
    drawCurrentRois();
});

resetBoxesBtn.addEventListener('click', () => {
    editableRois = defaultRois();
    saveRois();
    drawCurrentRois();
    roiHint.textContent = 'Boxes reset to default WA template.';
});

idOcrOverlay.addEventListener('mousedown', onRoiPointerDown);
idOcrOverlay.addEventListener('mousemove', onRoiPointerMove);
window.addEventListener('mouseup', onRoiPointerUp);
idOcrOverlay.addEventListener('touchstart', onRoiPointerDown, { passive: false });
idOcrOverlay.addEventListener('touchmove', onRoiPointerMove, { passive: false });
window.addEventListener('touchend', onRoiPointerUp);

(async function boot() {
    appendLogLine('Boot start');
    updateDiagnostics();
    resetOcrUi();
    ocrEngine = createIdOcrEngine({
        tesseract: window.Tesseract,
        roiCanvas: ocrCanvas,
        overlayCanvas: idOcrOverlay,
        getRois: () => editableRois
    });

    idOcrOverlay.style.pointerEvents = 'none';

    if (!navigator.mediaDevices?.getUserMedia) {
        setStatus('This browser does not support getUserMedia.');
        errorLog('getUserMedia missing');
        return;
    }

    try {
        await scanDevices();
    } catch (err) {
        const info = describeError(err);
        errorLog('Initial device scan failed', info);
    }

    setStatus('Page ready. Click Start Camera.');
    updateDiagnostics();

    pollCurrentScan();
    setInterval(pollCurrentScan, 1000);
})();
</script>
</body>
</html>

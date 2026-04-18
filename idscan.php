<?php
declare(strict_types=1);

date_default_timezone_set('Australia/Perth');

$saveDir = __DIR__ . '/face_scans';
$currentScanFile = __DIR__ . '/current_scan.json';

if (!is_dir($saveDir)) {
    @mkdir($saveDir, 0775, true);
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function read_json_file(string $filePath): ?array {
    if (!is_file($filePath)) {
        return null;
    }
    $raw = file_get_contents($filePath);
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : null;
}

function write_json_file(string $filePath, array $data): bool {
    return file_put_contents(
        $filePath,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

function find_scan_json(string $saveDir, string $scanId): ?string {
    $path = $saveDir . '/scan_' . $scanId . '.json';
    return is_file($path) ? $path : null;
}

function save_data_url_jpeg(string $dataUrl, string $outputPath): bool {
    if (!preg_match('#^data:image/jpeg;base64,#', $dataUrl)) {
        return false;
    }
    $base64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
    $bin = base64_decode($base64, true);
    if ($bin === false) {
        return false;
    }
    return file_put_contents($outputPath, $bin) !== false;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_id') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $scanId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($payload['scan_id'] ?? ''));
    $imageDataUrl = (string)($payload['image_data_url'] ?? '');
    $croppedImageDataUrl = (string)($payload['cropped_image_data_url'] ?? '');
    $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

    if ($scanId === '') {
        json_response(['ok' => false, 'error' => 'Missing scan_id'], 400);
    }

    $scanJsonPath = find_scan_json($saveDir, $scanId);
    if (!$scanJsonPath) {
        json_response(['ok' => false, 'error' => 'Original face scan record not found'], 404);
    }

    if (!preg_match('#^data:image/jpeg;base64,#', $imageDataUrl)) {
        json_response(['ok' => false, 'error' => 'Invalid or missing JPEG image data'], 400);
    }

    $base64 = substr($imageDataUrl, strpos($imageDataUrl, ',') + 1);
    $bin = base64_decode($base64, true);
    if ($bin === false) {
        json_response(['ok' => false, 'error' => 'Failed to decode image'], 400);
    }

    $stamp = date('Ymd_His');
    $idImageFile = 'idscan_' . $scanId . '_' . $stamp . '.jpg';
    $idJsonFile  = 'idscan_' . $scanId . '_' . $stamp . '.json';

    $idImagePath = $saveDir . '/' . $idImageFile;
    $idJsonPath  = $saveDir . '/' . $idJsonFile;

    if (file_put_contents($idImagePath, $bin) === false) {
        json_response(['ok' => false, 'error' => 'Failed to save ID image'], 500);
    }

    $croppedImageFile = null;
    if ($croppedImageDataUrl !== '') {
        $croppedImageFile = 'idcrop_' . $scanId . '_' . $stamp . '.jpg';
        $croppedImagePath = $saveDir . '/' . $croppedImageFile;
        if (!save_data_url_jpeg($croppedImageDataUrl, $croppedImagePath)) {
            $croppedImageFile = null;
        }
    }

    $primaryDisplayIdFile = $croppedImageFile ?: $idImageFile;

    $idRecord = [
        'scan_id' => $scanId,
        'created_at' => date('c'),
        'type' => 'rear_camera_id_capture',
        'meta' => [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
            'client_meta' => $meta,
        ],
        'image_file' => $idImageFile,
        'cropped_image_file' => $croppedImageFile,
        'primary_display_image_file' => $primaryDisplayIdFile,
    ];

    if (file_put_contents($idJsonPath, json_encode($idRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        json_response(['ok' => false, 'error' => 'Failed to save ID metadata'], 500);
    }

    $original = read_json_file($scanJsonPath);
    if (!is_array($original)) {
        $original = [];
    }

    $original['id_scan'] = [
        'linked' => true,
        'captured_at' => date('c'),
        'image_file' => $idImageFile,
        'cropped_image_file' => $croppedImageFile,
        'primary_display_image_file' => $primaryDisplayIdFile,
        'json_file' => $idJsonFile,
        'type' => 'rear_camera_id_capture',
        'meta' => $meta,
    ];

    $original['workflow'] = [
        'face_captured' => true,
        'id_captured' => true,
        'status' => 'id_captured_complete',
        'last_updated' => date('c'),
    ];

    if (file_put_contents($scanJsonPath, json_encode($original, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        json_response(['ok' => false, 'error' => 'Failed to update original face scan record'], 500);
    }

    $current = read_json_file($currentScanFile) ?: [];
    $current['ok'] = true;
    $current['scan_id'] = $scanId;

    // Clear the linked face so it is obvious the system is ready for the next person.
    $current['image_file'] = null;

    // Make the cropped image the main one index.php will pick up.
    $current['id_image_file'] = $primaryDisplayIdFile;
    $current['id_crop_image_file'] = $croppedImageFile;
    $current['id_full_image_file'] = $idImageFile;
    $current['updated_at'] = date('c');
    $current['timestamp'] = time();
    $current['status'] = 'id_captured_complete';
    $current['ready_for_next_scan'] = true;
    $current['display_message'] = 'Ready to scan next person';

    write_json_file($currentScanFile, $current);

    json_response([
        'ok' => true,
        'scan_id' => $scanId,
        'id_image_file' => $primaryDisplayIdFile,
        'id_crop_image_file' => $croppedImageFile,
        'id_full_image_file' => $idImageFile,
        'id_json_file' => $idJsonFile,
        'ready_for_next_scan' => true
    ]);
}

$initialCurrentScan = read_json_file($currentScanFile);
$initialFaceImage = null;
$initialCropImage = null;

if (is_array($initialCurrentScan) && !empty($initialCurrentScan['image_file'])) {
    $initialFaceImage = 'face_scans/' . basename((string)$initialCurrentScan['image_file']) . '?t=' . time();
}

if (is_array($initialCurrentScan) && !empty($initialCurrentScan['id_image_file'])) {
    $initialCropImage = 'face_scans/' . basename((string)$initialCurrentScan['id_image_file']) . '?t=' . time();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<meta http-equiv="Pragma" content="no-cache">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>ID Scan</title>
<style>
    :root{
        --bg:#05080d;
        --panel:rgba(10,16,24,.88);
        --line:#294058;
        --text:#e8f0f7;
        --muted:#9ab0c5;
        --accent:#4da3ff;
        --good:#39d98a;
        --warn:#ffd166;
        --bad:#ff6b6b;
        --safe-top: env(safe-area-inset-top, 0px);
        --safe-right: env(safe-area-inset-right, 0px);
        --safe-bottom: env(safe-area-inset-bottom, 0px);
        --safe-left: env(safe-area-inset-left, 0px);
    }

    *{box-sizing:border-box}

    html, body{
        margin:0;
        width:100%;
        height:100%;
        overflow:hidden;
        background:#000;
        font-family:Arial,Helvetica,sans-serif;
        color:var(--text);
    }

    body{
        min-height:100vh;
        min-height:100dvh;
    }

    .app{
        position:relative;
        width:100%;
        height:100vh;
        height:100dvh;
        overflow:hidden;
        background:#000;
    }

    .camera-stage{
        position:absolute;
        inset:0;
        background:#000;
    }

    video, canvas{
        position:absolute;
        inset:0;
        width:100%;
        height:100%;
        object-fit:cover;
    }

    .top-strip{
        position:absolute;
        inset:0;
        z-index:20;
        pointer-events:none;
    }

    .person-card{
        position:absolute;
        top:calc(var(--safe-top) + 6px);
        left:calc(var(--safe-left) + 6px);
        width:140px;
        background:rgba(10,16,24,.78);
        border:1px solid rgba(255,255,255,.10);
        border-radius:12px;
        box-shadow:0 8px 22px rgba(0,0,0,.34);
        padding:6px;
        pointer-events:auto;
        backdrop-filter:blur(8px);
    }

    .person-head{
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:6px;
        margin-bottom:5px;
    }

    .person-title{
        font-size:10px;
        font-weight:700;
        color:#fff;
        line-height:1.15;
    }

    .person-sub{
        display:none;
    }

    .scan-state{
        display:inline-block;
        padding:3px 6px;
        border-radius:999px;
        font-size:9px;
        font-weight:700;
        background:#1a2a3a;
        border:1px solid rgba(255,255,255,.10);
        color:#dcecff;
        white-space:nowrap;
        line-height:1.1;
    }

    .person-image-wrap{
        width:100%;
        aspect-ratio:1.85 / 1;
        border-radius:8px;
        overflow:hidden;
        border:1px solid rgba(255,255,255,.10);
        background:#091018;
    }

    .person-image{
        width:100%;
        height:100%;
        object-fit:cover;
        display:block;
    }

    .person-empty{
        display:flex;
        align-items:center;
        justify-content:center;
        width:100%;
        height:100%;
        font-size:10px;
        color:var(--muted);
        text-align:center;
        padding:8px;
    }

    .status-pill{
        position:absolute;
        left:50%;
        transform:translateX(-50%);
        bottom:calc(var(--safe-bottom) + 92px);
        z-index:24;
        width:min(calc(100% - 20px), 560px);
        padding:10px 14px;
        border-radius:16px;
        background:rgba(10,16,24,.82);
        border:1px solid rgba(255,255,255,.10);
        font-size:13px;
        line-height:1.35;
        white-space:pre-wrap;
        text-align:center;
        box-shadow:0 10px 30px rgba(0,0,0,.35);
        backdrop-filter:blur(8px);
    }

    .capture-bar{
        position:absolute;
        left:0;
        right:0;
        bottom:0;
        z-index:25;
        display:flex;
        justify-content:center;
        padding:
            10px
            calc(var(--safe-right) + 12px)
            calc(var(--safe-bottom) + 14px)
            calc(var(--safe-left) + 12px);
        background:linear-gradient(0deg, rgba(0,0,0,.70), rgba(0,0,0,.26), rgba(0,0,0,0));
    }

    .capture-btn{
        min-width:min(86vw, 340px);
        min-height:58px;
        padding:0 22px;
        border:none;
        border-radius:18px;
        font-weight:700;
        font-size:17px;
        color:#fff;
        cursor:pointer;
        box-shadow:0 10px 24px rgba(0,0,0,.25);
        background:linear-gradient(180deg,#35b56f,#218853);
    }

    .id-preview-card{
        position:absolute;
        right:calc(var(--safe-right) + 10px);
        bottom:calc(var(--safe-bottom) + 166px);
        z-index:22;
        width:min(28vw, 180px);
        background:var(--panel);
        border:1px solid rgba(255,255,255,.10);
        border-radius:16px;
        overflow:hidden;
        box-shadow:0 10px 28px rgba(0,0,0,.35);
        backdrop-filter:blur(8px);
    }

    .id-preview-label{
        font-size:11px;
        font-weight:700;
        letter-spacing:.02em;
        color:var(--muted);
        padding:8px 10px 0;
    }

    .id-preview{
        display:none;
        width:100%;
        aspect-ratio:1.586 / 1;
        object-fit:contain;
        background:#091018;
    }

    @media (max-width: 640px){
        .person-card{
            width:116px;
            top:calc(var(--safe-top) + 5px);
            left:calc(var(--safe-left) + 5px);
            padding:5px;
        }

        .person-title{
            font-size:9px;
        }

        .scan-state{
            font-size:8px;
            padding:3px 5px;
        }

        .person-empty{
            font-size:9px;
            padding:6px;
        }

        .status-pill{
            bottom:calc(var(--safe-bottom) + 88px);
            width:calc(100% - 16px);
            font-size:12px;
            padding:9px 12px;
        }

        .capture-btn{
            width:100%;
            min-width:0;
        }

        .id-preview-card{
            width:132px;
            bottom:calc(var(--safe-bottom) + 156px);
        }
    }

    @media (orientation: landscape){
        .person-card{
            width:112px;
            top:calc(var(--safe-top) + 4px);
            left:calc(var(--safe-left) + 4px);
            padding:5px;
        }

        .person-title{
            font-size:9px;
        }

        .scan-state{
            font-size:8px;
            padding:2px 5px;
        }

        .person-image-wrap{
            aspect-ratio:1.95 / 1;
        }

        .person-empty{
            font-size:8px;
            padding:6px;
        }

        .status-pill{
            width:min(58vw, 520px);
            bottom:calc(var(--safe-bottom) + 82px);
            font-size:12px;
        }

        .capture-btn{
            min-height:52px;
            font-size:15px;
            min-width:min(46vw, 280px);
        }

        .id-preview-card{
            width:min(20vw, 170px);
            bottom:calc(var(--safe-bottom) + 132px);
        }
    }
</style>
</head>
<body>
<div class="app">
    <div class="camera-stage">
        <video id="video" playsinline autoplay muted></video>
        <canvas id="overlay"></canvas>
    </div>

    <div class="top-strip">
        <div class="person-card">
            <div class="person-head">
                <div>
                    <div class="person-title">Linked face</div>
                    <div class="person-sub">Check this image before taking the ID photo.</div>
                </div>
                <div class="scan-state" id="scanStateTag">Waiting</div>
            </div>

            <div class="person-image-wrap">
                <?php if ($initialFaceImage): ?>
                    <img
                        id="facePreview"
                        class="person-image"
                        src="<?= h($initialFaceImage) ?>"
                        alt="Linked face preview"
                    >
                    <div id="facePreviewEmpty" class="person-empty" style="display:none;">Waiting for the next saved face scan…</div>
                <?php else: ?>
                    <img id="facePreview" class="person-image" src="" alt="Linked face preview" style="display:none;">
                    <div id="facePreviewEmpty" class="person-empty">Waiting for the next saved face scan…</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="id-preview-card">
        <div class="id-preview-label">Latest cropped ID preview</div>
        <?php if ($initialCropImage): ?>
            <img id="idPreview" class="id-preview" alt="ID preview" src="<?= h($initialCropImage) ?>" style="display:block;">
        <?php else: ?>
            <img id="idPreview" class="id-preview" alt="ID preview">
        <?php endif; ?>
    </div>

    <div id="status" class="status-pill">Ready.</div>

    <div class="capture-bar">
        <button id="captureBtn" class="capture-btn">Capture ID</button>
    </div>
</div>

<script async src="https://docs.opencv.org/4.9.0/opencv.js" onload="window.__opencvScriptLoaded = true;"></script>
<script>
let activeScanId = '';
let lastSeenTimestamp = 0;
let lastSeenStatus = '';
let lastSeenFaceImageFile = '';
let stream = null;
let captureLocked = false;
let activeStatus = '';
let animationHandle = null;
let opencvReady = false;
let detectionEnabled = true;
let latestDetection = null;
let lastDetectionAt = 0;
let stableDetectionSince = 0;
let latestCroppedDataUrl = '';
let lastAnalyzeAt = 0;
let guidePulse = 0;

const video = document.getElementById('video');
const overlay = document.getElementById('overlay');
const ctx = overlay.getContext('2d');
const statusBox = document.getElementById('status');
const idPreview = document.getElementById('idPreview');
const facePreview = document.getElementById('facePreview');
const facePreviewEmpty = document.getElementById('facePreviewEmpty');
const scanStateTag = document.getElementById('scanStateTag');
const captureBtn = document.getElementById('captureBtn');

const DETECT_INTERVAL_MS = 220;
const STABLE_HOLD_MS = 450;
const MIN_CARD_AREA_RATIO = 0.10;
const MAX_CARD_AREA_RATIO = 0.92;
const CARD_ASPECT_MIN = 1.35;
const CARD_ASPECT_MAX = 1.90;

function setStatus(msg) {
    statusBox.textContent = msg;
}

function setScanState(text) {
    scanStateTag.textContent = text;
}

function resizeCanvasToVideo() {
    const rect = video.getBoundingClientRect();
    overlay.width = Math.max(1, Math.round(rect.width));
    overlay.height = Math.max(1, Math.round(rect.height));
    drawOverlay();
}

function stopCamera() {
    if (animationHandle) {
        cancelAnimationFrame(animationHandle);
        animationHandle = null;
    }

    if (stream) {
        for (const track of stream.getTracks()) {
            track.stop();
        }
    }

    stream = null;
    video.srcObject = null;
    ctx.clearRect(0, 0, overlay.width, overlay.height);
    latestDetection = null;
    latestCroppedDataUrl = '';
    setStatus('Camera stopped.');
    setScanState('Camera stopped');
}

function waitForOpenCv(timeoutMs = 12000) {
    return new Promise((resolve, reject) => {
        const started = Date.now();

        function check() {
            if (window.cv && typeof cv.Mat === 'function') {
                try {
                    cv.getBuildInformation && cv.getBuildInformation();
                    opencvReady = true;
                    resolve();
                    return;
                } catch (e) {}
            }

            if (Date.now() - started > timeoutMs) {
                reject(new Error('OpenCV.js did not finish loading'));
                return;
            }

            setTimeout(check, 120);
        }

        check();
    });
}

async function startCamera() {
    try {
        if (stream) {
            setStatus('Rear camera already running.');
            return;
        }

        const constraints = {
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1920 },
                height: { ideal: 1080 }
            },
            audio: false
        };

        setStatus('Starting rear camera...');
        setScanState('Starting camera');

        stream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = stream;

        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Video metadata timeout')), 10000);
            video.onloadedmetadata = () => {
                clearTimeout(timer);
                resolve();
            };
        });

        await video.play();
        resizeCanvasToVideo();

        setStatus('Rear camera ready. Waiting for next face scan...');
        setScanState('Waiting for face');

        try {
            await waitForOpenCv(7000);
        } catch (e) {
            opencvReady = false;
            console.warn('OpenCV not ready, guide box only mode enabled.', e);
        }

        startOverlayLoop();
    } catch (err) {
        setStatus('Failed to start rear camera: ' + (err && err.message ? err.message : String(err)));
        setScanState('Camera error');
    }
}

function makeJpegSnapshot() {
    const snap = document.createElement('canvas');
    snap.width = video.videoWidth;
    snap.height = video.videoHeight;
    const sctx = snap.getContext('2d');
    sctx.drawImage(video, 0, 0, snap.width, snap.height);
    return snap.toDataURL('image/jpeg', 0.92);
}

function clearFacePreviewForNextPerson() {
    facePreview.removeAttribute('src');
    facePreview.style.display = 'none';
    facePreviewEmpty.style.display = 'flex';
}

function updateFacePreview(imageFile) {
    if (!imageFile) {
        clearFacePreviewForNextPerson();
        return;
    }

    facePreview.src = 'face_scans/' + encodeURIComponent(imageFile) + '?t=' + Date.now();
    facePreview.style.display = 'block';
    facePreviewEmpty.style.display = 'none';
}

function getGuideRect() {
    const w = overlay.width || 1;
    const h = overlay.height || 1;
    const targetAspect = 1.586;
    const maxW = w * 0.84;
    const maxH = h * 0.54;

    let boxW = maxW;
    let boxH = boxW / targetAspect;

    if (boxH > maxH) {
        boxH = maxH;
        boxW = boxH * targetAspect;
    }

    return {
        x: (w - boxW) / 2,
        y: (h - boxH) / 2,
        width: boxW,
        height: boxH
    };
}

function drawGuideRect(rect, color, lineWidth, label) {
    ctx.save();
    ctx.lineWidth = lineWidth;
    ctx.strokeStyle = color;
    ctx.setLineDash([10, 8]);
    ctx.strokeRect(rect.x, rect.y, rect.width, rect.height);

    const corner = 24;
    ctx.setLineDash([]);
    ctx.lineWidth = lineWidth + 1;

    function cornerLine(x1, y1, x2, y2) {
        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.stroke();
    }

    cornerLine(rect.x, rect.y, rect.x + corner, rect.y);
    cornerLine(rect.x, rect.y, rect.x, rect.y + corner);

    cornerLine(rect.x + rect.width, rect.y, rect.x + rect.width - corner, rect.y);
    cornerLine(rect.x + rect.width, rect.y, rect.x + rect.width, rect.y + corner);

    cornerLine(rect.x, rect.y + rect.height, rect.x + corner, rect.y + rect.height);
    cornerLine(rect.x, rect.y + rect.height, rect.x, rect.y + rect.height - corner);

    cornerLine(rect.x + rect.width, rect.y + rect.height, rect.x + rect.width - corner, rect.y + rect.height);
    cornerLine(rect.x + rect.width, rect.y + rect.height, rect.x + rect.width, rect.y + rect.height - corner);

    if (label) {
        ctx.font = '600 14px Arial';
        const padX = 10;
        const tw = ctx.measureText(label).width;
        const bx = rect.x;
        const by = Math.max(8, rect.y - 30);
        ctx.fillStyle = 'rgba(0,0,0,.72)';
        ctx.fillRect(bx, by, tw + padX * 2, 24);
        ctx.fillStyle = color;
        ctx.fillText(label, bx + padX, by + 16);
    }
    ctx.restore();
}

function drawDetectionQuad(points, color, label) {
    if (!points || points.length !== 4) return;

    ctx.save();
    ctx.strokeStyle = color;
    ctx.lineWidth = 4;
    ctx.setLineDash([]);
    ctx.beginPath();
    ctx.moveTo(points[0].x, points[0].y);
    for (let i = 1; i < points.length; i++) {
        ctx.lineTo(points[i].x, points[i].y);
    }
    ctx.closePath();
    ctx.stroke();

    ctx.fillStyle = color;
    for (const p of points) {
        ctx.beginPath();
        ctx.arc(p.x, p.y, 5, 0, Math.PI * 2);
        ctx.fill();
    }

    if (label) {
        ctx.font = '600 14px Arial';
        const padX = 10;
        const tw = ctx.measureText(label).width;
        const minX = Math.min(...points.map(p => p.x));
        const minY = Math.min(...points.map(p => p.y));
        const bx = minX;
        const by = Math.max(8, minY - 30);
        ctx.fillStyle = 'rgba(0,0,0,.72)';
        ctx.fillRect(bx, by, tw + padX * 2, 24);
        ctx.fillStyle = color;
        ctx.fillText(label, bx + padX, by + 16);
    }

    ctx.restore();
}

function drawOverlay() {
    ctx.clearRect(0, 0, overlay.width, overlay.height);
    if (!overlay.width || !overlay.height) return;

    const guideRect = getGuideRect();
    guidePulse += 0.03;

    if (latestDetection && Date.now() - lastDetectionAt < 1200) {
        drawDetectionQuad(latestDetection.overlayPoints, '#39d98a', 'Card detected');
    } else {
        const pulseAlpha = 0.35 + ((Math.sin(guidePulse) + 1) / 2) * 0.3;
        drawGuideRect(guideRect, `rgba(255,209,102,${pulseAlpha})`, 3, opencvReady ? 'Place licence inside box' : 'Guide box');
    }

    ctx.save();
    ctx.fillStyle = 'rgba(0,0,0,0.16)';
    ctx.fillRect(0, 0, overlay.width, overlay.height);
    ctx.clearRect(guideRect.x, guideRect.y, guideRect.width, guideRect.height);
    ctx.restore();
}

function getCoverDrawMetrics(videoW, videoH, canvasW, canvasH) {
    const videoAspect = videoW / videoH;
    const canvasAspect = canvasW / canvasH;

    let drawW, drawH, offsetX, offsetY;

    if (videoAspect > canvasAspect) {
        drawH = canvasH;
        drawW = drawH * videoAspect;
        offsetX = (canvasW - drawW) / 2;
        offsetY = 0;
    } else {
        drawW = canvasW;
        drawH = drawW / videoAspect;
        offsetX = 0;
        offsetY = (canvasH - drawH) / 2;
    }

    return { drawW, drawH, offsetX, offsetY };
}

function videoToOverlayPoint(px, py) {
    const m = getCoverDrawMetrics(video.videoWidth, video.videoHeight, overlay.width, overlay.height);
    return {
        x: m.offsetX + (px / video.videoWidth) * m.drawW,
        y: m.offsetY + (py / video.videoHeight) * m.drawH
    };
}

function orderQuadPoints(points) {
    const pts = points.map(p => ({ x: p.x, y: p.y }));
    const sumSorted = [...pts].sort((a, b) => (a.x + a.y) - (b.x + b.y));
    const diffSorted = [...pts].sort((a, b) => (a.y - a.x) - (b.y - b.x));

    const tl = sumSorted[0];
    const br = sumSorted[3];
    const tr = diffSorted[0];
    const bl = diffSorted[3];

    return [tl, tr, br, bl];
}

function distance(a, b) {
    const dx = a.x - b.x;
    const dy = a.y - b.y;
    return Math.sqrt(dx * dx + dy * dy);
}

function quadMetrics(points) {
    const ordered = orderQuadPoints(points);
    const [tl, tr, br, bl] = ordered;
    const widthTop = distance(tl, tr);
    const widthBottom = distance(bl, br);
    const heightLeft = distance(tl, bl);
    const heightRight = distance(tr, br);
    const avgW = (widthTop + widthBottom) / 2;
    const avgH = (heightLeft + heightRight) / 2;
    return {
        ordered,
        avgW,
        avgH,
        aspect: avgH > 0 ? avgW / avgH : 0
    };
}

function detectCardWithOpenCv() {
    if (!opencvReady || !stream || !video.videoWidth || !video.videoHeight) {
        return null;
    }

    const workCanvas = document.createElement('canvas');
    const maxSide = 960;
    const scale = Math.min(1, maxSide / Math.max(video.videoWidth, video.videoHeight));
    workCanvas.width = Math.max(1, Math.round(video.videoWidth * scale));
    workCanvas.height = Math.max(1, Math.round(video.videoHeight * scale));
    const workCtx = workCanvas.getContext('2d', { willReadFrequently: true });
    workCtx.drawImage(video, 0, 0, workCanvas.width, workCanvas.height);

    let src = null, gray = null, blur = null, edge = null, contours = null, hierarchy = null;
    try {
        src = cv.imread(workCanvas);
        gray = new cv.Mat();
        blur = new cv.Mat();
        edge = new cv.Mat();
        contours = new cv.MatVector();
        hierarchy = new cv.Mat();

        cv.cvtColor(src, gray, cv.COLOR_RGBA2GRAY, 0);
        cv.GaussianBlur(gray, blur, new cv.Size(5, 5), 0, 0, cv.BORDER_DEFAULT);
        cv.Canny(blur, edge, 70, 160);
        const kernel = cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(3, 3));
        cv.dilate(edge, edge, kernel);
        kernel.delete();

        cv.findContours(edge, contours, hierarchy, cv.RETR_LIST, cv.CHAIN_APPROX_SIMPLE);

        let best = null;
        const frameArea = workCanvas.width * workCanvas.height;

        for (let i = 0; i < contours.size(); i++) {
            const contour = contours.get(i);
            const peri = cv.arcLength(contour, true);
            const approx = new cv.Mat();
            cv.approxPolyDP(contour, approx, 0.02 * peri, true);

            if (approx.rows === 4) {
                const area = Math.abs(cv.contourArea(approx));
                const areaRatio = area / frameArea;

                if (areaRatio >= MIN_CARD_AREA_RATIO && areaRatio <= MAX_CARD_AREA_RATIO) {
                    const pts = [];
                    for (let j = 0; j < 4; j++) {
                        pts.push({
                            x: approx.data32S[j * 2],
                            y: approx.data32S[j * 2 + 1]
                        });
                    }

                    const metrics = quadMetrics(pts);
                    if (metrics.aspect >= CARD_ASPECT_MIN && metrics.aspect <= CARD_ASPECT_MAX) {
                        const centerX = metrics.ordered.reduce((s, p) => s + p.x, 0) / 4;
                        const centerY = metrics.ordered.reduce((s, p) => s + p.y, 0) / 4;
                        const cxNorm = Math.abs(centerX - workCanvas.width / 2) / (workCanvas.width / 2);
                        const cyNorm = Math.abs(centerY - workCanvas.height / 2) / (workCanvas.height / 2);
                        const centeredPenalty = (cxNorm + cyNorm) * 0.25;
                        const score = areaRatio - centeredPenalty;

                        if (!best || score > best.score) {
                            best = {
                                score,
                                area,
                                points: metrics.ordered.map(p => ({
                                    x: p.x / scale,
                                    y: p.y / scale
                                }))
                            };
                        }
                    }
                }
            }

            approx.delete();
            contour.delete();
        }

        if (!best) {
            return null;
        }

        const overlayPoints = best.points.map(p => videoToOverlayPoint(p.x, p.y));
        return {
            sourcePoints: best.points,
            overlayPoints
        };
    } catch (err) {
        console.warn('OpenCV detection failed', err);
        return null;
    } finally {
        if (src) src.delete();
        if (gray) gray.delete();
        if (blur) blur.delete();
        if (edge) edge.delete();
        if (contours) contours.delete();
        if (hierarchy) hierarchy.delete();
    }
}

function cropPerspectiveFromQuad(points) {
    if (!opencvReady || !points || points.length !== 4 || !video.videoWidth || !video.videoHeight) {
        return '';
    }

    let src = null, dst = null, dsize = null, srcTri = null, dstTri = null, M = null;
    try {
        const frameCanvas = document.createElement('canvas');
        frameCanvas.width = video.videoWidth;
        frameCanvas.height = video.videoHeight;
        const fctx = frameCanvas.getContext('2d');
        fctx.drawImage(video, 0, 0, frameCanvas.width, frameCanvas.height);

        src = cv.imread(frameCanvas);

        const ordered = orderQuadPoints(points);
        const widthA = distance(ordered[2], ordered[3]);
        const widthB = distance(ordered[1], ordered[0]);
        const maxWidth = Math.max(320, Math.round(Math.max(widthA, widthB)));

        const heightA = distance(ordered[1], ordered[2]);
        const heightB = distance(ordered[0], ordered[3]);
        const maxHeight = Math.max(200, Math.round(Math.max(heightA, heightB)));

        srcTri = cv.matFromArray(4, 1, cv.CV_32FC2, [
            ordered[0].x, ordered[0].y,
            ordered[1].x, ordered[1].y,
            ordered[2].x, ordered[2].y,
            ordered[3].x, ordered[3].y
        ]);

        dstTri = cv.matFromArray(4, 1, cv.CV_32FC2, [
            0, 0,
            maxWidth - 1, 0,
            maxWidth - 1, maxHeight - 1,
            0, maxHeight - 1
        ]);

        M = cv.getPerspectiveTransform(srcTri, dstTri);
        dsize = new cv.Size(maxWidth, maxHeight);
        dst = new cv.Mat();
        cv.warpPerspective(src, dst, M, dsize, cv.INTER_LINEAR, cv.BORDER_REPLICATE, new cv.Scalar());

        const outCanvas = document.createElement('canvas');
        cv.imshow(outCanvas, dst);
        return outCanvas.toDataURL('image/jpeg', 0.94);
    } catch (err) {
        console.warn('Perspective crop failed', err);
        return '';
    } finally {
        if (src) src.delete();
        if (dst) dst.delete();
        if (srcTri) srcTri.delete();
        if (dstTri) dstTri.delete();
        if (M) M.delete();
    }
}

function analyzeFrame() {
    if (!detectionEnabled || !stream || !video.videoWidth || !video.videoHeight) {
        return;
    }

    const now = Date.now();
    if (now - lastAnalyzeAt < DETECT_INTERVAL_MS) {
        return;
    }
    lastAnalyzeAt = now;

    const det = detectCardWithOpenCv();

    if (det) {
        latestDetection = det;
        lastDetectionAt = now;

        if (!stableDetectionSince) {
            stableDetectionSince = now;
        }

        if ((now - stableDetectionSince) >= STABLE_HOLD_MS) {
            const crop = cropPerspectiveFromQuad(det.sourcePoints);
            if (crop) {
                latestCroppedDataUrl = crop;
                idPreview.src = crop;
                idPreview.style.display = 'block';
            }
        }
    } else {
        latestDetection = null;
        stableDetectionSince = 0;
    }
}

function startOverlayLoop() {
    if (animationHandle) {
        cancelAnimationFrame(animationHandle);
        animationHandle = null;
    }

    const loop = () => {
        if (stream) {
            if (opencvReady) {
                analyzeFrame();
            }
            drawOverlay();
            animationHandle = requestAnimationFrame(loop);
        }
    };

    animationHandle = requestAnimationFrame(loop);
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

        const record = json.current_scan;
        const scanId = record.scan_id || '';
        const ts = Number(record.timestamp || 0);
        const status = record.status || '';
        const faceImageFile = record.image_file || '';
        const idImageFile = record.id_image_file || '';

        updateFacePreview(faceImageFile);

        if (idImageFile) {
            idPreview.src = 'face_scans/' + encodeURIComponent(idImageFile) + '?t=' + Date.now();
            idPreview.style.display = 'block';
        }

        if (!scanId) return;

        const changed =
            scanId !== activeScanId ||
            ts !== lastSeenTimestamp ||
            status !== lastSeenStatus ||
            faceImageFile !== lastSeenFaceImageFile;

        activeStatus = status;

        if (changed) {
            activeScanId = scanId;
            lastSeenTimestamp = ts;
            lastSeenStatus = status;
            lastSeenFaceImageFile = faceImageFile;
            captureLocked = false;
            latestCroppedDataUrl = '';
            latestDetection = null;
            stableDetectionSince = 0;

            if (status === 'face_captured_waiting_for_id') {
                if (idImageFile) {
                    idPreview.removeAttribute('src');
                    idPreview.style.display = 'none';
                }
                setStatus(
                    'New face scan detected.\n' +
                    'Line the licence up in the box, then tap Capture ID.'
                );
                setScanState('Ready for ID');
            } else if (status === 'id_captured_complete') {
                clearFacePreviewForNextPerson();
                setStatus('READY FOR NEXT PERSON');
                setScanState('Ready for next');
            } else {
                setStatus(
                    'Scan detected.\n' +
                    'Status: ' + status
                );
                setScanState(status || 'Detected');
            }
        }
    } catch (err) {
        console.error('pollCurrentScan failed', err);
    }
}

async function captureId() {
    try {
        if (!activeScanId) {
            setStatus('No active face scan detected yet.');
            setScanState('Waiting');
            return;
        }

        if (activeStatus === 'id_captured_complete') {
            clearFacePreviewForNextPerson();
            setStatus('READY FOR NEXT PERSON');
            setScanState('Ready for next');
            return;
        }

        if (captureLocked) {
            setStatus('A capture is already being processed.');
            return;
        }

        if (!stream || !video.videoWidth || !video.videoHeight) {
            setStatus('Rear camera is not running yet.');
            setScanState('Camera not ready');
            return;
        }

        captureLocked = true;

        const fullJpeg = makeJpegSnapshot();
        const cropJpeg = latestCroppedDataUrl || '';

        if (cropJpeg) {
            idPreview.src = cropJpeg;
        } else {
            idPreview.src = fullJpeg;
        }
        idPreview.style.display = 'block';

        setStatus('Saving cropped ID image...');
        setScanState('Saving ID');

        const payload = {
            scan_id: activeScanId,
            image_data_url: fullJpeg,
            cropped_image_data_url: cropJpeg,
            meta: {
                source: 'idscan.php',
                camera_mode: 'environment',
                orientation: window.matchMedia('(orientation: portrait)').matches ? 'portrait' : 'landscape',
                viewport: {
                    width: window.innerWidth,
                    height: window.innerHeight
                },
                video: {
                    width: video.videoWidth,
                    height: video.videoHeight
                },
                detection: {
                    opencv_ready: !!opencvReady,
                    card_detected: !!latestDetection,
                    crop_available: !!cropJpeg
                }
            }
        };

        const res = await fetch('?action=save_id', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const json = await res.json();
        if (!res.ok || !json.ok) {
            throw new Error(json.error || ('HTTP ' + res.status));
        }

        activeStatus = 'id_captured_complete';
        clearFacePreviewForNextPerson();

        if (json.id_image_file) {
            idPreview.src = 'face_scans/' + encodeURIComponent(json.id_image_file) + '?t=' + Date.now();
            idPreview.style.display = 'block';
        }

        setStatus('READY FOR NEXT PERSON');
        setScanState('Ready for next');
    } catch (err) {
        setStatus('Failed to save ID: ' + (err && err.message ? err.message : String(err)));
        setScanState('Save error');
        captureLocked = false;
        return;
    }

    captureLocked = false;
}

captureBtn.addEventListener('click', captureId);

window.addEventListener('resize', resizeCanvasToVideo);
window.addEventListener('orientationchange', () => {
    setTimeout(resizeCanvasToVideo, 200);
});

startCamera();
pollCurrentScan();
setInterval(pollCurrentScan, 1000);
</script>
</body>
</html>

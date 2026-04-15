<?php
// app/api/upload_temp_image.php
// Vastaanottaa kuvan HETI kun käyttäjä valitsee sen lomakkeessa
// Tallentaa väliaikaiseen kansioon ja palauttaa tiedostonimen

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/image_utils.php';

header('Content-Type: application/json; charset=utf-8');

// Väliaikainen kansio
$tempDir = __DIR__ . '/../../uploads/temp/';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0755, true);
}

// Garbage collector: 10% probability of running
if (rand(1, 100) <= 10) {
    $now = time();
    $maxAge = 24 * 60 * 60; // 24 hours in seconds
    
    $handle = @opendir($tempDir);
    if ($handle !== false) {
        try {
            while (($file = readdir($handle)) !== false) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                $filePath = $tempDir . $file;
                if (is_file($filePath)) {
                    $fileAge = $now - filemtime($filePath);
                    if ($fileAge > $maxAge) {
                        @unlink($filePath);
                    }
                }
            }
        } finally {
            closedir($handle);
        }
    }
}

// Validoi tiedosto
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Upload failed']);
    exit;
}

$file = $_FILES['image'];
$slot = isset($_POST['slot']) ? (int)$_POST['slot'] : 1;

// Tarkista tyyppi
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    echo json_encode(['ok' => false, 'error' => 'Failed to initialize file type checker']);
    exit;
}

$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if ($mimeType === false) {
    echo json_encode(['ok' => false, 'error' => 'Failed to detect file type']);
    exit;
}

if (!in_array($mimeType, $allowedTypes, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid file type']);
    exit;
}

// Luo uniikki tiedostonimi (session-based)
$sessionId = session_id() ?: 'anon';
$ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
$filename = 'temp_' . $sessionId . '_slot' . $slot . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

$destPath = $tempDir . $filename;

// Move uploaded file to temporary location first
$tempUploadPath = $destPath . '.tmp';
if (!move_uploaded_file($file['tmp_name'], $tempUploadPath)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
    exit;
}

// Process image: resize and compress (max 1920x1920, quality 80%)
if (!sf_resize_image($tempUploadPath, $destPath, 1920, 1920, 80)) {
    // If resize fails, use original file
    @rename($tempUploadPath, $destPath);
    error_log("Failed to resize image, using original: $filename");
} else {
    // Remove temporary file after successful processing
    @unlink($tempUploadPath);
}

// Palauta tiedostonimi ja URL
$basePath = rtrim($config['base_path'] ?? '', '/');
echo json_encode([
    'ok' => true,
    'filename' => $filename,
    'url' => $basePath . '/uploads/temp/' . $filename,
    'slot' => $slot
]);
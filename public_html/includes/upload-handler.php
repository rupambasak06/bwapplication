<?php
declare(strict_types=1);
if (!defined('BRAINWAVERS_BOOTSTRAPPED')) { exit('Direct access is not allowed.'); }

class UploadError extends RuntimeException {}

function process_image_upload(
    array $file,
    string $targetDir,
    int $maxBytes = 102400,
    array $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'],
    int $initialMaxDim = 1920
): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new UploadError('Invalid upload request.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new UploadError(upload_error_message((int)$file['error']));
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new UploadError('Upload was not processed correctly.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']) ?: '';
    if (!in_array($mime, $allowedMimes, true)) {
        throw new UploadError('Unsupported file type. Please upload a JPEG, PNG, or WebP image.');
    }
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext    = $extMap[$mime];

    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
        throw new UploadError('Image processing (PHP GD) is not available on this server.');
    }
    $imageData = @file_get_contents($file['tmp_name']);
    if ($imageData === false) throw new UploadError('Could not read the uploaded file.');
    $image = @imagecreatefromstring($imageData);
    if ($image === false) throw new UploadError('The uploaded file could not be decoded as an image.');

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        if (!empty($exif['Orientation'])) {
            switch ((int)$exif['Orientation']) {
                case 3: $image = imagerotate($image, 180, 0); break;
                case 6: $image = imagerotate($image, -90, 0); break;
                case 8: $image = imagerotate($image, 90, 0);  break;
            }
        }
    }

    ensure_dir($targetDir);
    $startSize = (int)($file['size'] ?? 0);

    try {
        $result = compress_image($image, $targetDir, $ext, $mime, $maxBytes, $initialMaxDim);
    } finally {
        imagedestroy($image);
    }

    clearstatcache(true, $result['path']);
    $finalSize = (int)filesize($result['path']);
    if ($finalSize <= 0 || $finalSize > $maxBytes) {
        @unlink($result['path']);
        throw new UploadError('Image exceeded the maximum size after compression.');
    }

    return [
        'stored_path'         => $result['path'],
        'stored_filename'     => basename($result['path']),
        'original_name'       => safe_basename((string)($file['name'] ?? ('image.' . $ext))),
        'mime'                => $mime,
        'size'                => $finalSize,
        'compression_applied' => $finalSize < $startSize ? 1 : 0,
    ];
}

function compress_image($image, string $targetDir, string $ext, string $mime, int $maxBytes, int $initialMaxDim): array
{
    $dimensions = [1920, 1400, 1024, 800, 600, 480];
    $qualities  = [85, 75, 65, 55, 45, 35];
    $origW = imagesx($image);
    $origH = imagesy($image);

    foreach ($dimensions as $maxDim) {
        if ($maxDim > $initialMaxDim) continue;
        $scale = min(1.0, $maxDim / max($origW, $origH));
        $newW  = max(1, (int)round($origW * $scale));
        $newH  = max(1, (int)round($origH * $scale));
        $resized = imagecreatetruecolor($newW, $newH);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        foreach ($qualities as $quality) {
            $tmp = $targetDir . '/_tmp_' . random_hex(8) . '.' . $ext;
            if (!write_image($resized, $tmp, $mime, $quality)) { @unlink($tmp); continue; }
            clearstatcache(true, $tmp);
            $size = (int)filesize($tmp);
            if ($size > 0 && $size <= $maxBytes) {
                imagedestroy($resized);
                $finalName = gmdate('Ymd_His') . '_' . random_hex(8) . '.' . $ext;
                $finalPath = $targetDir . '/' . $finalName;
                if (!@rename($tmp, $finalPath)) {
                    @unlink($tmp);
                    throw new UploadError('Could not finalize the uploaded image.');
                }
                return ['path' => $finalPath, 'size' => $size];
            }
            @unlink($tmp);
        }
        imagedestroy($resized);
    }
    throw new UploadError('Could not compress the image to ' . round($maxBytes / 1024) . ' KB or below.');
}

function write_image($image, string $path, string $mime, int $quality): bool
{
    switch ($mime) {
        case 'image/jpeg': return imagejpeg($image, $path, $quality);
        case 'image/png':
            imagesavealpha($image, true);
            return imagepng($image, $path, max(0, min(9, (int)round(9 - ($quality / 10)))));
        case 'image/webp':
            return function_exists('imagewebp') ? imagewebp($image, $path, $quality) : false;
    }
    return false;
}

/**
 * Upload a document (image or PDF). Images are compressed to $maxImageBytes.
 * PDFs are accepted up to $maxPdfBytes; if $strictPdf is true, PDFs must
 * also end up ≤ $maxImageBytes after optional Imagick+Ghostscript attempt.
 */
function process_document_upload(
    array $file,
    string $targetDir,
    int $maxImageBytes,
    int $maxPdfBytes,
    array $allowedExtensions,
    bool $strictPdf
): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new UploadError('Invalid upload request.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new UploadError(upload_error_message((int)$file['error']));
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new UploadError('Upload was not processed correctly.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']) ?: '';
    $size  = (int)($file['size'] ?? 0);
    $orig  = safe_basename((string)($file['name'] ?? 'document'));

    $mimeToExt = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];
    $ext = $mimeToExt[$mime] ?? '';
    $allowed = array_map('strtolower', $allowedExtensions);
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        throw new UploadError('Unsupported file type. Allowed: ' . implode(', ', $allowed) . '.');
    }

    ensure_dir($targetDir);

    if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return process_image_upload($file, $targetDir, $maxImageBytes, [$mime], 1600);
    }

    // PDF
    if ($size > $maxPdfBytes) {
        throw new UploadError(
            'The PDF is too large (max ' . round($maxPdfBytes / 1024 / 1024, 1) . ' MB).'
        );
    }
    $compressed = attempt_pdf_compression($file['tmp_name']);
    $sourcePath = $compressed ?: $file['tmp_name'];
    clearstatcache(true, $sourcePath);
    $finalSize = (int)filesize($sourcePath);

    if ($strictPdf && $finalSize > $maxImageBytes) {
        if ($compressed) @unlink($compressed);
        throw new UploadError(
            'This PDF cannot be reduced to ' . round($maxImageBytes / 1024) . ' KB or below. Please compress it before upload.'
        );
    }
    if ($finalSize > $maxPdfBytes) {
        if ($compressed) @unlink($compressed);
        throw new UploadError('The PDF is too large after processing.');
    }

    $finalName = gmdate('Ymd_His') . '_' . random_hex(8) . '.pdf';
    $finalPath = $targetDir . '/' . $finalName;
    if ($compressed) {
        if (!@rename($compressed, $finalPath)) {
            @unlink($compressed);
            throw new UploadError('Could not finalize the uploaded PDF.');
        }
    } else {
        if (!@copy($sourcePath, $finalPath)) {
            throw new UploadError('Could not save the uploaded PDF.');
        }
    }
    clearstatcache(true, $finalPath);
    $savedSize = (int)@filesize($finalPath);
    if ($savedSize <= 0 || $savedSize > $maxPdfBytes) {
        @unlink($finalPath);
        throw new UploadError('Saved PDF size could not be verified.');
    }

    return [
        'stored_path'         => $finalPath,
        'stored_filename'     => $finalName,
        'original_name'       => $orig,
        'mime'                => 'application/pdf',
        'size'                => $savedSize,
        'compression_applied' => $compressed ? 1 : 0,
    ];
}

function attempt_pdf_compression(string $sourcePath): ?string
{
    if (!class_exists('Imagick')) return null;
    try {
        $imagick = new Imagick();
        $imagick->setResolution(120, 120);
        $imagick->readImage($sourcePath);
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(70);
        $imagick->setImageBackgroundColor('white');
        $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $imagick->setImageFormat('pdf');

        ensure_dir(BW_STORAGE_DIR . '/tmp');
        $tmp = BW_STORAGE_DIR . '/tmp/' . random_hex(8) . '.pdf';
        $imagick->writeImages($tmp, true);
        $imagick->clear();
        $imagick->destroy();

        if (!is_file($tmp)) return null;
        clearstatcache(true, $tmp);
        $newSize = (int)filesize($tmp);
        $oldSize = (int)filesize($sourcePath);
        if ($newSize > 0 && $newSize < $oldSize) return $tmp;
        @unlink($tmp);
        return null;
    } catch (Throwable $e) {
        error_log('[pdf compression] ' . $e->getMessage());
        return null;
    }
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large.',
        UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Could not write the file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload was blocked by the server.',
        default               => 'An unknown upload error occurred.',
    };
}

function delete_stored_file(?string $path): void
{
    if (!$path) return;
    $real = realpath($path);
    $base = realpath(BW_STORAGE_DIR . '/uploads');
    if ($real && $base && str_starts_with($real, $base) && is_file($real)) {
        @unlink($real);
    }
}
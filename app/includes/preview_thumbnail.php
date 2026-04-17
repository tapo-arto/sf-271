<?php
declare(strict_types=1);

const SF_PREVIEW_THUMB_DEFAULT_MAX_WIDTH = 400;
const SF_PREVIEW_THUMB_MIN_WIDTH = 50;
const SF_PREVIEW_THUMB_DEFAULT_QUALITY = 78;
const SF_PREVIEW_THUMB_MIN_QUALITY = 30;
const SF_PREVIEW_THUMB_MAX_QUALITY = 95;
const SF_PREVIEW_THUMB_FILE_MODE = 0640;
const SF_PREVIEW_THUMB_IMAGICK_BLUR = 1.0;

function sf_preview_thumbnail_filename(string $filename): string
{
    $filename = basename($filename);
    $dotPos = strrpos($filename, '.');
    if ($dotPos === false) {
        return $filename . '_thumb.jpg';
    }
    return substr($filename, 0, $dotPos) . '_thumb' . substr($filename, $dotPos);
}

function sf_preview_thumbnail_path(string $originalPath): string
{
    $dir = dirname($originalPath);
    $filename = basename($originalPath);
    return $dir . '/' . sf_preview_thumbnail_filename($filename);
}

function sf_generate_preview_thumbnail(
    string $originalPath,
    int $maxWidth = SF_PREVIEW_THUMB_DEFAULT_MAX_WIDTH,
    int $quality = SF_PREVIEW_THUMB_DEFAULT_QUALITY
): bool
{
    if ($maxWidth < SF_PREVIEW_THUMB_MIN_WIDTH) {
        $maxWidth = SF_PREVIEW_THUMB_DEFAULT_MAX_WIDTH;
    }
    if ($quality < SF_PREVIEW_THUMB_MIN_QUALITY || $quality > SF_PREVIEW_THUMB_MAX_QUALITY) {
        $quality = SF_PREVIEW_THUMB_DEFAULT_QUALITY;
    }

    if (!is_file($originalPath)) {
        return false;
    }

    $thumbPath = sf_preview_thumbnail_path($originalPath);
    $thumbMtime = is_file($thumbPath) ? filemtime($thumbPath) : false;
    $originalMtime = filemtime($originalPath);
    if ($thumbMtime !== false && $originalMtime !== false && $thumbMtime >= $originalMtime) {
        return true;
    }

    try {
        if (class_exists('Imagick')) {
            $img = new Imagick($originalPath);
            $img->setImageBackgroundColor('white');
            $img = $img->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $width = $img->getImageWidth();
            if ($width > $maxWidth) {
                $img->resizeImage($maxWidth, 0, Imagick::FILTER_LANCZOS, SF_PREVIEW_THUMB_IMAGICK_BLUR, true);
            }
            $img->setImageFormat('jpeg');
            $img->setImageCompression(Imagick::COMPRESSION_JPEG);
            $img->setImageCompressionQuality($quality);
            $ok = $img->writeImage($thumbPath);
            $img->clear();
            $img->destroy();
            if ($ok) {
                if (!chmod($thumbPath, SF_PREVIEW_THUMB_FILE_MODE)) {
                    error_log('sf_generate_preview_thumbnail: chmod failed for ' . $thumbPath);
                }
            }
            return (bool)$ok;
        }

        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $originalBinary = file_get_contents($originalPath);
            if ($originalBinary === false) {
                return false;
            }
            $src = @imagecreatefromstring($originalBinary);
            if (!$src) {
                return false;
            }
            $srcW = imagesx($src);
            $srcH = imagesy($src);
            $ratio = min(1.0, $maxWidth / max(1, $srcW));
            $dstW = (int)max(1, round($srcW * $ratio));
            $dstH = (int)max(1, round($srcH * $ratio));
            $dst = imagecreatetruecolor($dstW, $dstH);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            $ok = imagejpeg($dst, $thumbPath, $quality);
            imagedestroy($src);
            imagedestroy($dst);
            if ($ok) {
                if (!chmod($thumbPath, SF_PREVIEW_THUMB_FILE_MODE)) {
                    error_log('sf_generate_preview_thumbnail: chmod failed for ' . $thumbPath);
                }
            }
            return (bool)$ok;
        }
    } catch (Throwable $e) {
        error_log('sf_generate_preview_thumbnail failed: ' . $e->getMessage());
        return false;
    }

    return false;
}

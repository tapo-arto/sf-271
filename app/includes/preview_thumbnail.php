<?php
declare(strict_types=1);

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

function sf_generate_preview_thumbnail(string $originalPath, int $maxWidth = 400, int $quality = 78): bool
{
    if ($maxWidth < 50) {
        $maxWidth = 400;
    }
    if ($quality < 30 || $quality > 95) {
        $quality = 78;
    }

    if (!is_file($originalPath)) {
        return false;
    }

    $thumbPath = sf_preview_thumbnail_path($originalPath);
    if (is_file($thumbPath) && @filemtime($thumbPath) >= @filemtime($originalPath)) {
        return true;
    }

    try {
        if (class_exists('Imagick')) {
            $img = new Imagick($originalPath);
            $img->setImageBackgroundColor('white');
            $img = $img->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $width = $img->getImageWidth();
            if ($width > $maxWidth) {
                $img->resizeImage($maxWidth, 0, Imagick::FILTER_LANCZOS, 1, true);
            }
            $img->setImageFormat('jpeg');
            $img->setImageCompression(Imagick::COMPRESSION_JPEG);
            $img->setImageCompressionQuality($quality);
            $ok = $img->writeImage($thumbPath);
            $img->clear();
            $img->destroy();
            if ($ok) {
                @chmod($thumbPath, 0644);
            }
            return (bool)$ok;
        }

        if (function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
            $src = @imagecreatefromjpeg($originalPath);
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
                @chmod($thumbPath, 0644);
            }
            return (bool)$ok;
        }
    } catch (Throwable $e) {
        error_log('sf_generate_preview_thumbnail failed: ' . $e->getMessage());
        return false;
    }

    return false;
}

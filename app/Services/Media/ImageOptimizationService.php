<?php

namespace App\Services\Media;

use GdImage;
use RuntimeException;

class ImageOptimizationService
{
    /** @return array{contents: string, mime_type: string, width: int, height: int} */
    public function toWebp(string $contents, string $label = '图片'): array
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new RuntimeException('服务器未安装支持 WebP 的 GD 图片扩展。');
        }

        $imageInfo = @getimagesizefromstring($contents);

        if (! is_array($imageInfo) || ! isset($imageInfo[0], $imageInfo[1], $imageInfo['mime'])) {
            throw new RuntimeException("{$label}不是有效图片。");
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        $mimeType = strtolower((string) $imageInfo['mime']);

        if (! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException("{$label}不是支持压缩的图片格式。");
        }

        if ($width < 1 || $height < 1 || ($width * $height) > $this->maxPixels()) {
            throw new RuntimeException("{$label}像素尺寸超过允许范围。");
        }

        $source = @imagecreatefromstring($contents);

        if (! $source instanceof GdImage) {
            throw new RuntimeException("{$label}解码失败。");
        }

        $scale = min(1, $this->maxDimension() / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if (! $target instanceof GdImage) {
            imagedestroy($source);

            throw new RuntimeException("{$label}压缩画布创建失败。");
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );

        ob_start();
        $encoded = imagewebp($target, null, $this->webpQuality());
        $webp = ob_get_clean();
        imagedestroy($target);
        imagedestroy($source);

        if (! $encoded || ! is_string($webp) || $webp === '') {
            throw new RuntimeException("{$label} WebP 压缩失败。");
        }

        return [
            'contents' => $webp,
            'mime_type' => 'image/webp',
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    private function maxDimension(): int
    {
        return max(320, min(4096, (int) config('services.feishu_table.campaign_image_max_dimension', 1920)));
    }

    private function maxPixels(): int
    {
        return max(1000000, (int) config('services.feishu_table.campaign_image_max_pixels', 80000000));
    }

    private function webpQuality(): int
    {
        return max(50, min(95, (int) config('services.feishu_table.campaign_image_webp_quality', 82)));
    }
}

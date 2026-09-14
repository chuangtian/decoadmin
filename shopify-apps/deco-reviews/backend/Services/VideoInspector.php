<?php

namespace DecoReviews\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class VideoInspector
{
    public function inspect(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());
        $expected = [
            'mp4' => ['mime' => 'video/mp4', 'formats' => ['mp4'], 'codecs' => ['h264', 'hevc', 'av1']],
            'webm' => ['mime' => 'video/webm', 'formats' => ['webm'], 'codecs' => ['vp8', 'vp9', 'av1']],
        ][$extension] ?? null;
        if (! $expected || $mime !== $expected['mime']) {
            $this->reject('视频文件类型与实际内容不一致。');
        }

        try {
            $process = new Process([
                (string) config('deco_reviews.video.ffprobe_binary', '/usr/bin/ffprobe'),
                '-v', 'error', '-show_entries', 'format=format_name,duration:stream=codec_type,codec_name,width,height',
                '-of', 'json', $file->getRealPath(),
            ]);
            $process->setTimeout((float) config('deco_reviews.video.probe_timeout_seconds', 10));
            $process->run();
            $result = $process->isSuccessful() ? json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            $result = null;
        }
        if (! is_array($result)) {
            $this->reject('视频文件无法安全解析。');
        }

        $formats = array_filter(explode(',', strtolower((string) data_get($result, 'format.format_name', ''))));
        if (! array_intersect($expected['formats'], $formats)) {
            $this->reject('视频容器与文件扩展名不一致。');
        }
        $streams = array_values(array_filter($result['streams'] ?? [], fn ($stream) => ($stream['codec_type'] ?? '') === 'video'));
        if (count($streams) !== 1) {
            $this->reject('视频必须包含且只能包含一个视频流。');
        }
        $stream = $streams[0];
        $codec = strtolower((string) ($stream['codec_name'] ?? ''));
        if (! in_array($codec, $expected['codecs'], true)) {
            $this->reject('视频编码不受支持。');
        }
        $duration = filter_var(data_get($result, 'format.duration'), FILTER_VALIDATE_FLOAT);
        $width = filter_var($stream['width'] ?? null, FILTER_VALIDATE_INT);
        $height = filter_var($stream['height'] ?? null, FILTER_VALIDATE_INT);
        if ($duration === false || ! is_finite((float) $duration) || $duration <= 0 || $duration > (float) config('deco_reviews.video.max_duration_seconds', 120)) {
            $this->reject('视频时长无效或超过限制。');
        }
        if ($width === false || $height === false || $width <= 0 || $height <= 0
            || $width > (int) config('deco_reviews.video.max_width', 3840)
            || $height > (int) config('deco_reviews.video.max_height', 2160)) {
            $this->reject('视频分辨率无效或超过限制。');
        }

        return ['mime' => $mime, 'container' => $extension, 'codec' => $codec,
            'duration' => (float) $duration, 'width' => $width, 'height' => $height];
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['media' => $message]);
    }
}

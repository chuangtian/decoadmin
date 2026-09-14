<?php

namespace Tests\DecoReviews;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use DecoReviews\Models\Settings;
use DecoReviews\Services\ReviewService;
use DecoReviews\Services\VideoInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VideoUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $config = require base_path('shopify-apps/deco-reviews/config/deco_reviews.php');
        config(['deco_reviews' => $config]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function mp4(string $name = 'review.mp4'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'deco-video-');
        file_put_contents($path, pack('N', 24).'ftypisom'.pack('N', 0).'isomiso2');
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, 'video/mp4', UPLOAD_ERR_OK, true);
    }

    private function webm(string $name = 'review.webm'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'deco-webm-');
        file_put_contents($path, "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01webm");
        $this->temporaryFiles[] = $path;

        return new class($path, $name, 'video/webm', UPLOAD_ERR_OK, true) extends UploadedFile
        {
            public function getMimeType(): ?string
            {
                return 'video/webm';
            }
        };
    }

    private function fakeProbe(array $payload, int $exit = 0): void
    {
        $path = tempnam(sys_get_temp_dir(), 'deco-ffprobe-');
        $script = "#!/bin/sh\nprintf '%s' ".escapeshellarg(json_encode($payload))."\nexit {$exit}\n";
        file_put_contents($path, $script);
        chmod($path, 0700);
        $this->temporaryFiles[] = $path;
        config(['deco_reviews.video.ffprobe_binary' => $path]);
    }

    private function validProbe(array $replace = []): array
    {
        return array_replace_recursive(['format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'duration' => '12.500000'],
            'streams' => [['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080]]], $replace);
    }

    private function assertRejected(UploadedFile $file): void
    {
        try {
            app(VideoInspector::class)->inspect($file);
            $this->fail('Unsafe video was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('media', $error->errors());
        }
    }

    public function test_valid_mp4_container_codec_duration_and_resolution_are_accepted(): void
    {
        $this->fakeProbe($this->validProbe());
        $result = app(VideoInspector::class)->inspect($this->mp4());
        $this->assertSame('video/mp4', $result['mime']);
        $this->assertSame('mp4', $result['container']);
        $this->assertSame('h264', $result['codec']);
        $this->assertSame(12.5, $result['duration']);
        $this->assertSame(1920, $result['width']);
        $this->assertSame(1080, $result['height']);
    }

    public function test_valid_webm_container_and_vp9_codec_are_accepted(): void
    {
        $this->fakeProbe(['format' => ['format_name' => 'matroska,webm', 'duration' => '8.25'],
            'streams' => [['codec_type' => 'video', 'codec_name' => 'vp9', 'width' => 1280, 'height' => 720]]]);
        $result = app(VideoInspector::class)->inspect($this->webm());
        $this->assertSame('video/webm', $result['mime']);
        $this->assertSame('webm', $result['container']);
        $this->assertSame('vp9', $result['codec']);
    }

    public function test_claimed_video_mime_with_non_video_content_is_rejected_before_probe(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'deco-fake-video-');
        file_put_contents($path, 'This is not a video.');
        $this->temporaryFiles[] = $path;
        $this->fakeProbe($this->validProbe());
        $this->assertRejected(new UploadedFile($path, 'forged.mp4', 'video/mp4', UPLOAD_ERR_OK, true));
    }

    public function test_unparseable_wrong_container_or_missing_video_stream_is_rejected(): void
    {
        foreach ([
            [$this->validProbe(['format' => ['format_name' => 'matroska,webm']]), 0],
            [['format' => ['format_name' => 'mp4', 'duration' => '10'], 'streams' => [['codec_type' => 'audio', 'codec_name' => 'aac']]], 0],
            [$this->validProbe(['streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080],
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 320, 'height' => 180],
            ]]), 0],
            [$this->validProbe(), 1],
        ] as [$payload, $exit]) {
            $this->fakeProbe($payload, $exit);
            $this->assertRejected($this->mp4());
        }
    }

    public function test_unsupported_codec_invalid_duration_and_oversized_resolution_are_rejected(): void
    {
        foreach ([
            $this->validProbe(['streams' => [['codec_type' => 'video', 'codec_name' => 'mpeg2video', 'width' => 1920, 'height' => 1080]]]),
            $this->validProbe(['format' => ['duration' => '0']]),
            $this->validProbe(['format' => ['duration' => '120.01']]),
            $this->validProbe(['streams' => [['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 4096, 'height' => 2160]]]),
            $this->validProbe(['streams' => [['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 2161]]]),
        ] as $payload) {
            $this->fakeProbe($payload);
            $this->assertRejected($this->mp4());
        }
    }

    public function test_review_service_uses_video_inspection_and_never_auto_publishes_video(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'metadata' => ['is_super_admin' => true]]);
        $organization = Organization::create(['name' => 'Video Security', 'code' => 'video-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $store = $organization->stores()->create(['name' => 'Video Security', 'shopify_domain' => 'video-'.Str::lower(Str::random(5)).'.myshopify.com', 'status' => 'active']);
        $product = Product::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_product_id' => '111',
            'title' => 'Video Bike', 'handle' => 'video-bike', 'status' => 'active', 'synced_at' => now()]);
        Settings::create(['organization_id' => $organization->id, 'store_id' => $store->id,
            'values' => array_replace(config('deco_reviews.defaults'), ['auto_publish_days' => 0])]);
        $this->fakeProbe($this->validProbe());
        $review = app(ReviewService::class)->create($store, ['kind' => 'product', 'product_id' => $product->id,
            'author_name' => 'Video Reviewer', 'rating' => 5, 'body' => 'A valid inspected video review.'], [$this->mp4()], $user);

        $this->assertSame('pending', $review->status);
        $this->assertNull($review->publish_at);
        $this->assertNull($review->published_at);
        $this->assertSame('video', $review->media()->firstOrFail()->type);
    }
}

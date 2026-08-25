<?php

namespace App\Console\Commands;

use App\Models\StudentDiscountClaim;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneStudentDiscountEvidence extends Command
{
    protected $signature = 'student-discounts:prune-evidence {--days=30}';

    protected $description = 'Delete reviewed student evidence after the retention period';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = 0;
        StudentDiscountClaim::query()
            ->whereIn('status', ['approved', 'rejected'])
            ->where('reviewed_at', '<=', now()->subDays($days))
            ->whereNotNull('evidence_path')
            ->orderBy('id')
            ->chunkById(100, function ($claims) use (&$deleted): void {
                foreach ($claims as $claim) {
                    Storage::disk((string) $claim->evidence_disk)->delete((string) $claim->evidence_path);
                    $claim->forceFill([
                        'evidence_path' => null,
                        'evidence_disk' => null,
                        'evidence_mime' => null,
                        'evidence_size' => null,
                        'evidence_deleted_at' => now(),
                    ])->save();
                    $deleted++;
                }
            });

        $this->info("已清理 {$deleted} 份学生证图片。");

        return self::SUCCESS;
    }
}

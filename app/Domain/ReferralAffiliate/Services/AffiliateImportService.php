<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AffiliateImportService
{
    public function import(Organization $org, Store $store, User $actor, string $programId, string $file): int
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.promoters.manage');
        $program = AffiliateProgram::query()->forOrganization($org)->forStore($store)->where('public_id', $programId)->firstOrFail();
        $handle = fopen($file, 'r');
        $rows = [];
        try {
            $header = fgetcsv($handle, escape: '');
            if (! $header) {
                throw ValidationException::withMessages(['file' => 'CSV 文件为空。']);
            }
            $header = array_map(fn ($v) => trim(ltrim((string) $v, "\xEF\xBB\xBF")), $header);
            if ($header !== ['name', 'email']) {
                throw ValidationException::withMessages(['file' => 'CSV 表头必须为 name,email。']);
            }
            $number = 1;
            while (($cells = fgetcsv($handle, escape: '')) !== false) {
                $number++;
                if ($cells === [null]) {
                    continue;
                }
                if ($number > 501 || count($cells) !== 2) {
                    throw ValidationException::withMessages(['file' => '最多 500 行，且每行必须包含 name,email 两列。']);
                }
                $row = ['display_name' => trim($cells[0]), 'email' => trim($cells[1])];
                if (Validator::make($row, ['display_name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:254']])->fails()) {
                    throw ValidationException::withMessages(['file' => '第 '.$number.' 行名称或邮箱无效。']);
                }
                $rows[mb_strtolower($row['email'])] = $row;
            }
        } finally {
            fclose($handle);
        }

        return DB::transaction(function () use ($org, $store, $actor, $program, $rows) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $before = $program->memberships()->count();
            foreach ($rows as $row) {
                app(AffiliateManagementService::class)->createPromoter($org, $store, $actor, $row + ['program_public_id' => $program->public_id, 'type' => $program->type->value]);
            }

            return $program->memberships()->count() - $before;
        });
    }
}

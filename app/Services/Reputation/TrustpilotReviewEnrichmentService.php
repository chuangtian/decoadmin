<?php

namespace App\Services\Reputation;

use App\Models\Product;
use App\Models\ReputationMention;
use App\Models\ReputationMentionProductMatch;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class TrustpilotReviewEnrichmentService
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, int|string|bool>
     */
    public function enrich(Store $store, array $snapshot, bool $dryRun = false): array
    {
        $domain = $this->validatedDomain($snapshot);
        $products = $this->currentBikeProducts($store);
        $this->assertSnapshotBelongsToStore($products, $domain);
        [$reviewsById, $reviewsByFingerprint, $snapshotRows] = $this->reviewIndexes($snapshot);

        $stats = [
            'store_id' => (int) $store->id,
            'business_domain' => $domain,
            'snapshot_rows' => $snapshotRows,
            'mentions_processed' => 0,
            'reviewer_matches' => 0,
            'reviewer_updates' => 0,
            'model_updates' => 0,
            'mentions_with_product_matches' => 0,
            'product_matches' => 0,
            'dry_run' => $dryRun,
        ];

        DB::beginTransaction();

        try {
            ReputationMention::query()
                ->forOrganization((int) $store->organization_id)
                ->forStore((int) $store->id)
                ->where('source', 'trustpilot')
                ->orderBy('id')
                ->chunkById(200, function (Collection $mentions) use (
                    $store,
                    $products,
                    $reviewsById,
                    $reviewsByFingerprint,
                    &$stats,
                ): void {
                    foreach ($mentions as $mention) {
                        $stats['mentions_processed']++;
                        $review = $this->matchedReview($mention, $reviewsById, $reviewsByFingerprint);
                        $title = trim((string) ($review['title'] ?? $mention->title));
                        $reviewerDirty = false;

                        if ($review !== null) {
                            $stats['reviewer_matches']++;
                            $mention->forceFill([
                                'reviewer_name' => $review['reviewer_name'],
                                'title' => filled($mention->title) ? $mention->title : ($review['title'] ?: null),
                                'url' => $review['url'],
                                'url_hash' => hash('sha256', $review['url']),
                            ]);
                            $reviewerDirty = $mention->isDirty();
                        }

                        $modelMentions = $this->extractModelMentions($title.' '.(string) $mention->content);
                        if ($modelMentions !== []) {
                            $modelName = implode(' | ', $modelMentions);
                            $mention->forceFill([
                                'model_name' => mb_strlen($modelName) <= 120 ? $modelName : $modelMentions[0],
                                'metrics' => [...($mention->metrics ?? []), 'detected_models' => $modelMentions],
                            ]);
                            if ($mention->isDirty('model_name') || $mention->isDirty('metrics')) {
                                $stats['model_updates']++;
                            }
                        }

                        if ($mention->isDirty()) {
                            $mention->save();
                            if ($reviewerDirty) {
                                $stats['reviewer_updates']++;
                            }
                        }

                        $matches = $this->matchProducts($products, $title, (string) $mention->content);
                        ReputationMentionProductMatch::query()
                            ->forOrganization((int) $store->organization_id)
                            ->forStore((int) $store->id)
                            ->where('reputation_mention_id', $mention->id)
                            ->delete();

                        foreach ($matches as $match) {
                            ReputationMentionProductMatch::query()->create([
                                'organization_id' => (int) $store->organization_id,
                                'store_id' => (int) $store->id,
                                'reputation_mention_id' => (int) $mention->id,
                                'product_id' => (int) $match['product']->id,
                                'match_role' => $match['role'],
                                'matched_alias' => $match['alias'],
                                'confidence' => $match['confidence'],
                            ]);
                        }

                        if ($matches !== []) {
                            $stats['mentions_with_product_matches']++;
                            $stats['product_matches'] += count($matches);
                        }
                    }
                });

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        return $stats;
    }

    /** @param array<string, mixed> $snapshot */
    private function validatedDomain(array $snapshot): string
    {
        if (($snapshot['schema'] ?? null) !== 'trustpilot-public-review-snapshot-v1') {
            throw new InvalidArgumentException('Trustpilot 快照格式不受支持。');
        }

        $domain = $this->normalizedDomain((string) ($snapshot['business_domain'] ?? ''));
        if ($domain === '' || ! is_array($snapshot['reviews'] ?? null)) {
            throw new InvalidArgumentException('Trustpilot 快照缺少站点或评论数据。');
        }

        return $domain;
    }

    /** @return Collection<int, Product> */
    private function currentBikeProducts(Store $store): Collection
    {
        $products = Product::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->where('status', 'active')
            ->whereHas('collections', function ($query) use ($store): void {
                $query->where('product_collections.organization_id', (int) $store->organization_id)
                    ->where('product_collections.store_id', (int) $store->id)
                    ->where(function ($collection): void {
                        $collection->where('product_collections.handle', 'electric-bike')
                            ->orWhereRaw('LOWER(TRIM(product_collections.title)) = ?', ['electric bikes']);
                    });
            })
            ->orderBy('id')
            ->get();

        if ($products->isEmpty()) {
            throw new InvalidArgumentException('当前店铺没有可用于评论匹配的 Electric Bikes 在售商品。');
        }

        return $products;
    }

    /** @param Collection<int, Product> $products */
    private function assertSnapshotBelongsToStore(Collection $products, string $snapshotDomain): void
    {
        $domains = $products
            ->map(fn (Product $product): string => $this->normalizedDomain((string) parse_url((string) $product->online_store_url, PHP_URL_HOST)))
            ->filter()
            ->unique()
            ->values();

        if (! $domains->contains($snapshotDomain)) {
            throw new InvalidArgumentException('Trustpilot 快照站点与当前店铺在售商品域名不一致。');
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, list<array<string, mixed>>>, 2: int}
     */
    private function reviewIndexes(array $snapshot): array
    {
        $byId = [];
        $byFingerprint = [];
        $rows = 0;

        foreach ($snapshot['reviews'] as $review) {
            if (! is_array($review)) {
                continue;
            }

            $id = trim((string) ($review['id'] ?? ''));
            $name = trim((string) ($review['reviewer_name'] ?? ''));
            $content = trim((string) ($review['content'] ?? ''));
            $rating = is_numeric($review['rating'] ?? null) ? (float) $review['rating'] : null;
            $url = trim((string) ($review['url'] ?? ''));

            if (! preg_match('/^[a-z0-9]{24}$/i', $id) || $name === '' || $content === '' || $rating === null) {
                continue;
            }

            $expectedUrl = "https://www.trustpilot.com/reviews/{$id}";
            if ($url !== '' && $url !== $expectedUrl) {
                continue;
            }

            $normalized = [
                'id' => $id,
                'reviewer_name' => Str::limit($name, 255, ''),
                'title' => Str::limit(trim((string) ($review['title'] ?? '')), 500, ''),
                'content' => $content,
                'rating' => $rating,
                'url' => $expectedUrl,
            ];
            $byId[$id] = $normalized;
            $byFingerprint[$this->reviewFingerprint($content, $rating)][] = $normalized;
            $rows++;
        }

        return [$byId, $byFingerprint, $rows];
    }

    /**
     * @param  array<string, array<string, mixed>>  $reviewsById
     * @param  array<string, list<array<string, mixed>>>  $reviewsByFingerprint
     * @return array<string, mixed>|null
     */
    private function matchedReview(
        ReputationMention $mention,
        array $reviewsById,
        array $reviewsByFingerprint,
    ): ?array {
        $reviewId = $this->reviewIdFromUrl((string) $mention->url);
        if ($reviewId !== null && isset($reviewsById[$reviewId])) {
            return $reviewsById[$reviewId];
        }

        if (! filled($mention->content) || $mention->rating === null) {
            return null;
        }

        $candidates = $reviewsByFingerprint[$this->reviewFingerprint((string) $mention->content, (float) $mention->rating)] ?? [];

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function reviewFingerprint(string $content, float $rating): string
    {
        return hash('sha256', $this->normalizedText($content).'|'.number_format($rating, 1, '.', ''));
    }

    private function reviewIdFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('#^/reviews/([a-z0-9]{24})/?$#i', $path, $matches) ? $matches[1] : null;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array{product: Product, role: string, alias: string, confidence: float}>
     */
    private function matchProducts(Collection $products, string $title, string $content): array
    {
        $combined = $this->matchingText($title.' '.$content);
        if ($combined === '') {
            return [];
        }

        $descriptors = $products->map(fn (Product $product): array => $this->productDescriptor($product))->all();
        $matches = [];

        foreach ($descriptors as $descriptor) {
            $best = null;
            foreach ($descriptor['aliases'] as $alias) {
                $candidate = $this->aliasMatch($combined, $alias);
                if ($candidate === null) {
                    continue;
                }
                if ($best === null
                    || $candidate['position'] < $best['position']
                    || ($candidate['position'] === $best['position'] && mb_strlen($candidate['alias']) > mb_strlen($best['alias']))) {
                    $best = $candidate;
                }
            }
            if ($best !== null) {
                $matches[] = [...$descriptor, ...$best];
            }
        }

        foreach ($matches as $index => $match) {
            if (! $match['collaboration']) {
                continue;
            }
            foreach ($matches as $baseIndex => $base) {
                if ($baseIndex !== $index && ! $base['collaboration'] && $base['model_code'] === $match['model_code'] && $base['position'] === $match['position']) {
                    unset($matches[$baseIndex]);
                }
            }
        }
        $matches = array_values($matches);

        preg_match_all('/(?<![A-Za-z0-9])[XxMm]\d+[A-Za-z]?(?![A-Za-z0-9])/u', $combined, $modelTokens, PREG_OFFSET_CAPTURE);
        $earliestModelPosition = collect($modelTokens[0] ?? [])->min(fn (array $token): int => (int) $token[1]);
        usort($matches, fn (array $left, array $right): int => $left['position'] <=> $right['position']);
        $primaryAssigned = false;

        return array_map(function (array $match) use (&$primaryAssigned, $earliestModelPosition): array {
            $isPrimary = ! $primaryAssigned
                && ($earliestModelPosition === null || (int) $match['position'] === (int) $earliestModelPosition);
            if ($isPrimary) {
                $primaryAssigned = true;
            }

            return [
                'product' => $match['product'],
                'role' => $isPrimary ? 'primary' : 'mentioned',
                'alias' => Str::limit($match['alias'], 120, ''),
                'confidence' => $match['collaboration'] ? 0.99 : ($isPrimary ? 0.98 : 0.92),
            ];
        }, $matches);
    }

    /** @return array{product: Product, aliases: list<string>, model_code: string, collaboration: bool} */
    private function productDescriptor(Product $product): array
    {
        $source = $this->matchingText((string) $product->title);
        preg_match('/(?<![A-Za-z0-9])([XxMm]\d+[A-Za-z]?)(?![A-Za-z0-9])/u', $source, $model);
        $modelCode = (string) ($model[1] ?? '');
        $collaboration = preg_match('/(?<![A-Za-z0-9])Bs(?:[.\s-]*)zay(?![A-Za-z0-9])/u', $source) === 1;
        $aliases = [];

        if ($collaboration && $modelCode !== '') {
            $aliases = ["{$modelCode} x Bs.zay", "{$modelCode} Bs.zay", 'Bs.zay'];
        } elseif ($modelCode !== '') {
            $aliases = [$modelCode, "Macfox {$modelCode}"];
            if ($modelCode === 'X2') {
                $aliases[] = 'X2 Pro';
            }
        }

        return [
            'product' => $product,
            'aliases' => collect($aliases)->filter()->unique()->values()->all(),
            'model_code' => $modelCode,
            'collaboration' => $collaboration,
        ];
    }

    /** @return array{alias: string, position: int}|null */
    private function aliasMatch(string $text, string $alias): ?array
    {
        if ($alias === '') {
            return null;
        }

        if (str_ends_with($alias, ' x Bs.zay')) {
            $model = preg_quote(substr($alias, 0, -strlen(' x Bs.zay')), '/');
            $pattern = '/(?<![A-Za-z0-9])'.$model.'\s*(?:x|×|\*)\s*Bs(?:[.\s-]*)zay(?![A-Za-z0-9])/u';
        } elseif ($alias === 'Bs.zay') {
            $pattern = '/(?<![A-Za-z0-9])Bs(?:[.\s-]*)zay(?![A-Za-z0-9])/u';
        } else {
            $quoted = preg_quote($alias, '/');
            $pattern = '/(?<![A-Za-z0-9])'.str_replace('\\ ', '\\s+', $quoted).'(?![A-Za-z0-9])/u';
        }

        if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return ['alias' => $matches[0][0], 'position' => (int) $matches[0][1]];
    }

    /** @return list<string> */
    private function extractModelMentions(string $value): array
    {
        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all(
            '/(?<![A-Za-z0-9])(?:\d+\s*[*×x]\s*)?[XxMm]\d+[A-Za-z]?(?:\s*(?:[*×x]\s*)Bs(?:[.\s-]*zay))?(?:欧版)?(?![A-Za-z0-9])/u',
            $text,
            $matches,
        );

        return collect($matches[0] ?? [])
            ->map(fn (string $match): string => trim($match))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function matchingText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(['’', '‘', '´', '`'], "'", $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function normalizedText(string $value): string
    {
        $value = Str::lower(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = str_replace(['’', '‘', '´', '`'], "'", $value);
        $value = preg_replace('/(?<![a-z0-9])([xm])\s*[- ]?\s*(\d+)\s*[- ]?\s*([a-z]?)(?![a-z0-9])/u', '$1$2$3', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function normalizedDomain(string $domain): string
    {
        return preg_replace('/^www\./i', '', Str::lower(trim($domain))) ?? '';
    }
}

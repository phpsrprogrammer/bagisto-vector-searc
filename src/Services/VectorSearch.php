<?php

namespace Rbk\VectorSearch\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VectorSearch
{
    protected function qdrant(): string { return rtrim(config('vectorsearch.qdrant_url'), '/'); }
    protected function embedUrl(): string { return rtrim(config('vectorsearch.embed_url'), '/'); }
    protected function collection(): string { return config('vectorsearch.collection'); }

    public function embed(array $texts, bool $isQuery = false): array
    {
        $resp = Http::timeout(20)->post($this->embedUrl().'/embed', [
            'texts'    => array_values($texts),
            'is_query' => $isQuery,
        ]);
        $resp->throw();

        return $resp->json('vectors', []);
    }

    public function upsert(array $points): void
    {
        Http::timeout(30)->put(
            $this->qdrant().'/collections/'.$this->collection().'/points?wait=true',
            ['points' => $points]
        )->throw();
    }

    public function productAttributes(int $id, string $locale = 'en'): array
    {
        $exclude = [
            'sku', 'name', 'url_key', 'price', 'status', 'visible_individually', 'new', 'featured',
            'guest_checkout', 'manage_stock', 'meta_title', 'meta_keywords', 'meta_description',
            'description', 'short_description', 'cost', 'special_price_from', 'special_price_to', 'tax_category_id',
        ];

        $rows = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->where('pav.product_id', $id)
            ->where(function ($q) use ($locale) {
                $q->whereNull('pav.locale')->orWhere('pav.locale', $locale);
            })
            ->select('a.code', 'a.type', 'pav.text_value', 'pav.integer_value', 'pav.float_value', 'pav.boolean_value')
            ->get();

        $attrs = [];
        foreach ($rows as $r) {
            if (in_array($r->code, $exclude, true)) continue;

            $val = null;
            switch ($r->type) {
                case 'boolean':
                    $val = is_null($r->boolean_value) ? null : ((int) $r->boolean_value ? 'Yes' : 'No');
                    break;
                case 'price':
                    $val = is_null($r->float_value) ? null : (float) $r->float_value;
                    break;
                case 'select':
                    if (! is_null($r->integer_value)) {
                        $val = DB::table('attribute_options')->where('id', $r->integer_value)->value('admin_name');
                    }
                    break;
                case 'multiselect':
                    if (! empty($r->text_value)) {
                        $ids = array_filter(explode(',', $r->text_value));
                        $val = DB::table('attribute_options')->whereIn('id', $ids)->pluck('admin_name')->implode(', ');
                    }
                    break;
                default:
                    $val = $r->text_value ?? $r->integer_value ?? $r->float_value;
            }

            if ($val !== null && $val !== '') $attrs[$r->code] = $val;
        }

        return $attrs;
    }

    public function productText(object $r, string $locale = 'en'): string
    {
        $cats = DB::table('product_categories as pc')
            ->join('category_translations as ct', function ($j) use ($locale) {
                $j->on('ct.category_id', '=', 'pc.category_id')->where('ct.locale', $locale);
            })
            ->where('pc.product_id', $r->product_id)
            ->pluck('ct.name')->implode(', ');

        $strip = fn ($h) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $h))));

        $attrStr = '';
        foreach ($this->productAttributes((int) $r->product_id, $locale) as $k => $v) {
            $attrStr .= ' '.$k.': '.$v.'.';
        }

        return trim(sprintf(
            '%s. SKU: %s. %s %s Categories: %s.%s',
            $r->name, $r->sku, $strip($r->short_description), $strip($r->description), $cats, $attrStr
        ));
    }

    public function productPayload(object $r, string $locale = 'en'): array
    {
        $category = DB::table('product_categories as pc')
            ->join('category_translations as ct', function ($j) use ($locale) {
                $j->on('ct.category_id', '=', 'pc.category_id')->where('ct.locale', $locale);
            })
            ->where('pc.product_id', $r->product_id)
            ->orderBy('pc.category_id')
            ->value('ct.name');

        $image = DB::table('product_images')->where('product_id', $r->product_id)
            ->orderBy('position')->value('path');

        return [
            'id'         => (int) $r->product_id,
            'name'       => $r->name,
            'sku'        => $r->sku,
            'url_key'    => $r->url_key,
            'price'      => (float) $r->price,
            'category'   => $category ?: 'Other',
            'image'      => $image,
            'attributes' => $this->productAttributes((int) $r->product_id, $locale),
        ];
    }

    public function indexProduct(int $id, string $locale = 'en'): void
    {
        $r = DB::table('product_flat')->where('product_id', $id)->where('locale', $locale)->first();

        if (! $r || (int) $r->status !== 1 || (int) $r->visible_individually !== 1) {
            $this->deleteProduct($id);
            return;
        }

        $vec = $this->embed([$this->productText($r, $locale)], false)[0] ?? null;
        if (! $vec) return;

        $this->upsert([[
            'id'      => $id,
            'vector'  => $vec,
            'payload' => $this->productPayload($r, $locale),
        ]]);
    }

    public function deleteProduct(int $id): void
    {
        Http::timeout(15)->post(
            $this->qdrant().'/collections/'.$this->collection().'/points/delete?wait=true',
            ['points' => [$id]]
        )->throw();
    }

    public function search(string $query, int $limit = 80): ?array
    {
        try {
            $vecs = $this->embed([$query], true);
            if (empty($vecs[0])) return null;

            $resp = Http::timeout(15)->post(
                $this->qdrant().'/collections/'.$this->collection().'/points/search',
                ['vector' => $vecs[0], 'limit' => $limit, 'with_payload' => false, 'score_threshold' => 0.0]
            );
            $resp->throw();

            return collect($resp->json('result', []))->pluck('id')->map(fn ($id) => (int) $id)->all();
        } catch (\Throwable $e) {
            Log::warning('[VectorSearch] search failed: '.$e->getMessage());
            return null;
        }
    }

    public function searchPayloads(string $query, int $limit = 40): ?array
    {
        try {
            $vecs = $this->embed([$query], true);
            if (empty($vecs[0])) return null;

            $resp = Http::timeout(15)->post(
                $this->qdrant().'/collections/'.$this->collection().'/points/search',
                ['vector' => $vecs[0], 'limit' => $limit, 'with_payload' => true]
            );
            $resp->throw();

            return collect($resp->json('result', []))->pluck('payload')->filter()->values()->all();
        } catch (\Throwable $e) {
            Log::warning('[VectorSearch] searchPayloads failed: '.$e->getMessage());
            return null;
        }
    }

    public function relatedByVector(int $productId, int $limit = 12): ?array
    {
        try {
            $resp = Http::timeout(15)->post(
                $this->qdrant().'/collections/'.$this->collection().'/points/recommend',
                ['positive' => [$productId], 'limit' => $limit, 'with_payload' => false]
            );
            $resp->throw();

            return collect($resp->json('result', []))
                ->pluck('id')->map(fn ($x) => (int) $x)
                ->reject(fn ($x) => $x === $productId)
                ->values()->all();
        } catch (\Throwable $e) {
            Log::warning('[VectorSearch] relatedByVector failed: '.$e->getMessage());
            return null;
        }
    }
}

<?php

namespace Rbk\VectorSearch\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Rbk\VectorSearch\Services\VectorSearch;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Http\Resources\ProductResource;

class ProductAssociationsController
{
    public function related($id, ProductRepository $repo, VectorSearch $vs)
    {
        $limit = (int) (core()->getConfigData('catalog.products.product_view_page.no_of_related_products') ?: 4);

        return $this->build((int) $id, 'product_relations', $limit, $repo, $vs);
    }

    public function upSell($id, ProductRepository $repo, VectorSearch $vs)
    {
        $limit = (int) (core()->getConfigData('catalog.products.product_view_page.no_of_up_sells') ?: 4);

        return $this->build((int) $id, 'product_up_sells', $limit, $repo, $vs);
    }

    /** Manual associations first (visible only), gap filled with vector nearest-neighbours. */
    protected function build(int $id, string $table, int $limit, ProductRepository $repo, VectorSearch $vs)
    {
        if (! $repo->find($id)) abort(404);

        $visibleSet = function (array $ids): array {
            if (empty($ids)) return [];
            return DB::table('product_flat')->whereIn('product_id', $ids)->where('locale', 'en')
                ->where('status', 1)->where('visible_individually', 1)->pluck('product_id')->all();
        };

        $manual    = DB::table($table)->where('parent_id', $id)->pluck('child_id')->map(fn ($x) => (int) $x)->all();
        $visManual = array_flip($visibleSet($manual));
        $ids = [];
        foreach ($manual as $m) {
            if (isset($visManual[$m]) && ! in_array($m, $ids, true)) $ids[] = $m;
        }

        if (count($ids) < $limit) {
            $neighbors = $vs->relatedByVector($id, $limit * 4) ?? [];
            $have      = array_flip(array_merge([$id], $ids));
            $cand      = array_values(array_filter($neighbors, fn ($n) => ! isset($have[$n])));
            $visCand   = array_flip($visibleSet($cand));
            foreach ($cand as $n) {
                if (isset($visCand[$n]) && ! isset($have[$n])) {
                    $ids[] = $n; $have[$n] = true;
                    if (count($ids) >= $limit) break;
                }
            }
        }

        $ids = array_slice($ids, 0, $limit);
        if (empty($ids)) return ProductResource::collection(collect());

        $products = Product::with([
            'attribute_family', 'images', 'videos', 'attribute_values',
            'price_indices', 'inventory_indices', 'reviews',
        ])->whereIn('id', $ids)->get()->keyBy('id');

        $ordered = collect($ids)->map(fn ($pid) => $products->get($pid))->filter()->values();

        return ProductResource::collection($ordered);
    }
}

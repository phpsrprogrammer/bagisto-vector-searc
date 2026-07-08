<?php

namespace Rbk\VectorSearch\Repositories;

use Rbk\VectorSearch\Services\VectorSearch;
use Webkul\Product\Repositories\ProductRepository;

class VectorProductRepository extends ProductRepository
{
    /** Route storefront text searches through the vector DB; everything else falls back to parent. */
    public function getAll(array $params = [])
    {
        $query   = trim($params['query'] ?? '');
        $adminUr = trim((string) config('app.admin_url', 'admin'), '/');
        $isAdmin = request()->is($adminUr) || request()->is($adminUr.'/*');

        if (
            config('vectorsearch.enabled')
            && ! $isAdmin
            && $this->searchEngine !== 'elastic'
            && $query !== ''
        ) {
            $ids = app(VectorSearch::class)->search($query, (int) config('vectorsearch.limit', 80));

            if (! empty($ids)) {
                return $this->searchByRankedIds($ids, $params);
            }
        }

        return parent::getAll($params);
    }

    /** Load products for the vector-ranked IDs, preserving relevance order + active filters. */
    protected function searchByRankedIds(array $ids, array $params)
    {
        $perPage = (int) ($params['limit'] ?? 12);
        if ($perPage <= 0 || $perPage > 100) $perPage = 12;

        $idList = implode(',', array_map('intval', $ids));

        return $this->with([
            'attribute_family', 'images', 'videos', 'attribute_values',
            'price_indices', 'inventory_indices', 'reviews', 'variants',
            'variants.attribute_family', 'variants.attribute_values',
            'variants.price_indices', 'variants.inventory_indices',
        ])->scopeQuery(function ($query) use ($ids, $idList, $params) {
            $qb = $query->distinct()
                ->select('products.*')
                ->leftJoin('product_price_indices', function ($join) {
                    $group = $this->customerRepository->getCurrentGroup();
                    $join->on('products.id', '=', 'product_price_indices.product_id')
                        ->where('product_price_indices.customer_group_id', $group->id);
                })
                ->whereIn('products.id', $ids);

            if (! empty($params['category_id'])) {
                $qb->leftJoin('product_categories', 'product_categories.product_id', '=', 'products.id')
                    ->whereIn('product_categories.category_id', explode(',', $params['category_id']));
            }

            if (! empty($params['price'])) {
                $range = explode(',', $params['price']);
                $qb->whereBetween('product_price_indices.min_price', [
                    core()->convertToBasePrice(current($range)),
                    core()->convertToBasePrice(end($range)),
                ]);
            }

            return $qb->orderByRaw('FIELD(products.id, '.$idList.')');
        })->paginate($perPage);
    }
}

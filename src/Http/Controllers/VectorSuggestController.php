<?php

namespace Rbk\VectorSearch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Rbk\VectorSearch\Services\VectorSearch;

class VectorSuggestController
{
    /** Category-grouped semantic autosuggest, served entirely from the Qdrant payload. */
    public function __invoke(VectorSearch $vs): JsonResponse
    {
        $q = trim((string) request('query', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }

        $payloads = $vs->searchPayloads($q, 40);
        if (empty($payloads)) {
            return response()->json(['groups' => []]);
        }

        $groups = [];
        $order  = [];

        foreach ($payloads as $p) {
            $cat = $p['category'] ?? 'Other';

            if (! isset($groups[$cat])) { $groups[$cat] = []; $order[] = $cat; }
            if (count($groups[$cat]) >= 5) continue;

            $parts = [];
            foreach (($p['attributes'] ?? []) as $k => $v) {
                $parts[] = ucfirst(str_replace('_', ' ', $k)).': '.$v;
            }

            $groups[$cat][] = [
                'name'       => $p['name'] ?? '',
                'url'        => url('/'.($p['url_key'] ?? '')),
                'price'      => '₹'.number_format((float) ($p['price'] ?? 0), 2),
                'image'      => ! empty($p['image']) ? url('cache/small/'.$p['image']) : null,
                'attributes' => implode('  ·  ', array_slice($parts, 0, 5)),
            ];
        }

        $out = [];
        foreach (array_slice($order, 0, 5) as $c) {
            $out[] = ['category' => $c, 'items' => $groups[$c]];
        }

        return response()->json(['groups' => $out]);
    }
}

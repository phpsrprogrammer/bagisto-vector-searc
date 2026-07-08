<?php

namespace Rbk\VectorSearch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Rbk\VectorSearch\Services\VectorSearch;

class VectorIndexProducts extends Command
{
    protected $signature = 'vector-search:index {--locale=en}';
    protected $description = 'Build product embeddings and upsert them into the vector DB (Qdrant)';

    public function handle(VectorSearch $vs): int
    {
        $locale = $this->option('locale');

        $rows = DB::table('product_flat')
            ->where('locale', $locale)
            ->where('status', 1)
            ->where('visible_individually', 1)
            ->select('product_id', 'sku', 'name', 'short_description', 'description', 'price', 'url_key')
            ->get();

        $this->info("Found {$rows->count()} products to index (locale={$locale}).");
        if ($rows->isEmpty()) return self::SUCCESS;

        $indexed = 0;
        foreach ($rows->chunk(32) as $chunk) {
            $texts = [];
            $meta  = [];
            foreach ($chunk as $r) {
                $texts[] = $vs->productText($r, $locale);
                $meta[]  = $vs->productPayload($r, $locale);
            }

            $vectors = $vs->embed($texts, false);

            $points = [];
            foreach ($meta as $i => $m) {
                $points[] = ['id' => $m['id'], 'vector' => $vectors[$i], 'payload' => $m];
            }
            $vs->upsert($points);
            $indexed += count($points);
            $this->line("  upserted {$indexed}/{$rows->count()}");
        }

        $this->info("Done. Indexed {$indexed} products into Qdrant.");
        return self::SUCCESS;
    }
}

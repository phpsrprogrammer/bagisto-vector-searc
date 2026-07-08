<?php

namespace Rbk\VectorSearch\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rbk\VectorSearch\Console\Commands\VectorIndexProducts;
use Rbk\VectorSearch\Http\Controllers\ProductAssociationsController;
use Rbk\VectorSearch\Http\Controllers\VectorSuggestController;
use Rbk\VectorSearch\Repositories\VectorProductRepository;
use Rbk\VectorSearch\Services\VectorSearch;
use Webkul\Product\Repositories\ProductRepository;

class VectorSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/vectorsearch.php', 'vectorsearch');

        // Swap Bagisto's ProductRepository for the vector-aware subclass.
        $this->app->bind(ProductRepository::class, VectorProductRepository::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../Config/vectorsearch.php' => config_path('vectorsearch.php'),
        ], 'vector-search-config');

        if ($this->app->runningInConsole()) {
            $this->commands([VectorIndexProducts::class]);
        }

        if (! config('vectorsearch.enabled')) {
            return;
        }

        // Category-grouped autosuggest (no route conflict).
        Route::get('/vector-suggest', VectorSuggestController::class)->name('vector.suggest');

        // Override native related + up-sell routes. Route collection is last-registered-wins for an
        // identical URI, so register AFTER all providers (incl. Webkul Shop) via app->booted(), and
        // KEEP the native route names so blade route('shop.api.products.*') calls still resolve.
        $this->app->booted(function () {
            Route::middleware('web')->get('api/products/{id}/related', [ProductAssociationsController::class, 'related'])->name('shop.api.products.related.index');
            Route::middleware('web')->get('api/products/{id}/up-sell', [ProductAssociationsController::class, 'upSell'])->name('shop.api.products.up-sell.index');
        });

        // Auto re-index a product in Qdrant on create/update/delete (never blocks a save).
        $reindex = function ($product) {
            try {
                $pid = is_object($product) ? (int) $product->id : (int) $product;
                app(VectorSearch::class)->indexProduct($pid);
            } catch (\Throwable $e) {
                Log::warning('[VectorSearch] auto re-index failed: '.$e->getMessage());
            }
        };

        Event::listen('catalog.product.create.after', $reindex);
        Event::listen('catalog.product.update.after', $reindex);

        Event::listen('catalog.product.delete.after', function ($id) {
            try {
                app(VectorSearch::class)->deleteProduct((int) $id);
            } catch (\Throwable $e) {
                Log::warning('[VectorSearch] auto delete failed: '.$e->getMessage());
            }
        });
    }
}

# Rbk Vector Search for Bagisto

Open-source semantic (vector) search for Bagisto — 100% self-hosted, no paid APIs.

**Stack:** [Qdrant](https://qdrant.tech) (vector DB, Apache-2.0) + [fastembed](https://github.com/qdrant/fastembed) running `BAAI/bge-small-en-v1.5` (ONNX, CPU) as a small local embedding service.

## Features
- **Semantic product search** — overrides `ProductRepository::getAll()` for the storefront search box (query is embedded → nearest vectors → results in relevance order, with category/price filters preserved). Falls back to Bagisto's DB search if the vector service is down.
- **Category-grouped autosuggest** — `GET /vector-suggest?query=` returns products grouped by category (name, price, image, attributes) served entirely from the Qdrant payload.
- **Vector-powered related & up-sell** — overrides `/api/products/{id}/related` and `/up-sell`: manual admin relations first, gap filled with vector nearest-neighbours (visibility-filtered).
- **Auto re-index** — listens to `catalog.product.create/update/delete.after`; keeps the index in sync on every product save.

## Requirements
- Qdrant reachable at `VECTOR_QDRANT_URL` (default `http://127.0.0.1:6333`).
- Embedding service (fastembed FastAPI) at `VECTOR_EMBED_URL` (default `http://127.0.0.1:8500`) exposing `POST /embed {texts, is_query}`.

## Install (local package)
1. Add to root `composer.json` autoload: `"Rbk\\VectorSearch\\": "packages/Rbk/VectorSearch/src"` then `composer dump-autoload`.
2. Register `Rbk\VectorSearch\Providers\VectorSearchServiceProvider::class` in `bootstrap/providers.php`.
3. Add env:
   ```
   VECTOR_SEARCH_ENABLED=true
   VECTOR_QDRANT_URL=http://127.0.0.1:6333
   VECTOR_EMBED_URL=http://127.0.0.1:8500
   VECTOR_COLLECTION=products
   VECTOR_DIM=384
   VECTOR_LIMIT=80
   ```
4. Create the Qdrant collection (size 384, Cosine), then build the index:
   ```
   php artisan vector-search:index
   ```

Set `VECTOR_SEARCH_ENABLED=false` to cleanly revert to Bagisto's native search/related.

## License
MIT

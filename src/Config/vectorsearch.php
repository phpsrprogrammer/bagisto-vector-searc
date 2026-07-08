<?php

return [
    'enabled'    => env('VECTOR_SEARCH_ENABLED', false),
    'qdrant_url' => env('VECTOR_QDRANT_URL', 'http://127.0.0.1:6333'),
    'embed_url'  => env('VECTOR_EMBED_URL', 'http://127.0.0.1:8500'),
    'collection' => env('VECTOR_COLLECTION', 'products'),
    'dim'        => (int) env('VECTOR_DIM', 384),
    'limit'      => (int) env('VECTOR_LIMIT', 80),
];

<?php

return [
    // Show an always-visible input in the top navbar (left side).
    'show_topbar' => true,

    // Rewire the existing magnifier dropdown search form to use smart search.
    // Default: disabled (we inject our own compact topbar search bar).
    'use_core_search_icon' => false,

    // Hide the core magnifier icon.
    'hide_core_search_icon' => true,

    // Minimum query length.
    'min_query_len' => 2,

    // Results per page.
    'per_page' => 50,

    // Cache minutes for table existence checks.
    'schema_cache_minutes' => 10,
];

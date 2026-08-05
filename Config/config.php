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

    // Allow deep searching of published customer messages, agent replies,
    // and internal notes. Deep search is isolated from the fast search path.
    'search_thread_body' => true,

    // Automatically try deep content search only when fast search has no matches.
    'search_thread_body_fallback' => false,

    // Minimum query length for deep message-content searching.
    'search_thread_body_min_query_len' => 4,

    // Bulk action safety limits.
    'bulk_max_selected' => 200,
    'bulk_note_max_length' => 50000,

    // Cache minutes for table existence checks.
    'schema_cache_minutes' => 10,
];

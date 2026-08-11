<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => \Helper::getSubdirectory(), 'middleware' => ['web', 'auth']], function () {
    Route::get('/smart-search', 'Modules\\AdamSmartSearchUI\\Http\\Controllers\\SmartSearchController@index')
        ->middleware('throttle:120,1')
        ->name('adamsmartsearchui.search');

    // Lightweight autosuggest for the topbar input.
    Route::get('/smart-search/suggest', 'Modules\\AdamSmartSearchUI\\Http\\Controllers\\SmartSearchController@suggest')
        ->middleware('throttle:180,1')
        ->name('adamsmartsearchui.suggest');

    // Dynamic mailbox-specific custom field list for the search form.
    Route::get('/smart-search/fields', 'Modules\\AdamSmartSearchUI\\Http\\Controllers\\SmartSearchController@fields')
        ->middleware('throttle:120,1')
        ->name('adamsmartsearchui.fields');
    // Refresh metadata for recent-search conversation shortcuts.
    Route::get('/smart-search/recent-meta', 'Modules\\AdamSmartSearchUI\\Http\\Controllers\\SmartSearchController@recentMeta')
        ->middleware('throttle:120,1')
        ->name('adamsmartsearchui.recent_meta');

    // Bulk actions for selected Smart Search results.
    Route::post('/smart-search/bulk', 'Modules\\AdamSmartSearchUI\\Http\\Controllers\\SmartSearchController@bulk')
        ->middleware('throttle:30,1')
        ->name('adamsmartsearchui.bulk');
});

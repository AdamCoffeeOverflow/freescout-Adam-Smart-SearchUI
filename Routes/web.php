<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => \Helper::getSubdirectory(), 'middleware' => ['web', 'auth']], function () {
    Route::get('/smart-search', 'Modules\\AdamSmartSearchUI\\Http\\Controllers\\SmartSearchController@index')
        ->name('adamsmartsearchui.search');

    // Lightweight autosuggest for the topbar input.
    Route::get('/smart-search/suggest', 'Modules\\AdamSmartSearchUI\\Http\\Controllers\\SmartSearchController@suggest')
        ->name('adamsmartsearchui.suggest');

    // Dynamic mailbox-specific custom field list for the search form.
    Route::get('/smart-search/fields', 'Modules\\AdamSmartSearchUI\\Http\\Controllers\\SmartSearchController@fields')
        ->name('adamsmartsearchui.fields');
    // Refresh metadata for recent-search conversation shortcuts.
    Route::get('/smart-search/recent-meta', 'Modules\AdamSmartSearchUI\Http\Controllers\SmartSearchController@recentMeta')
        ->name('adamsmartsearchui.recent_meta');
});

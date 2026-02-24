<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => \Helper::getSubdirectory(), 'middleware' => ['web', 'auth']], function () {
    Route::get('/smart-search', 'Modules\\AdamSmartSearchUI\\Http\\Controllers\\SmartSearchController@index')
        ->name('adamsmartsearchui.search');

    // Lightweight autosuggest for the topbar input.
    Route::get('/smart-search/suggest', 'Modules\\AdamSmartSearchUI\\Http\\Controllers\\SmartSearchController@suggest')
        ->name('adamsmartsearchui.suggest');
});

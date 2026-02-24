<?php

namespace Modules\AdamSmartSearchUI\Providers;

use Illuminate\Support\ServiceProvider;

class AdamSmartSearchUIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Ensure module config is available via config('adamsmartsearchui.*').
        try {
            $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'adamsmartsearchui');
        } catch (\Throwable $e) {
            // no-op
        }
    }

    public function boot(): void
    {
        $this->registerViews();
        $this->registerRoutes();
        $this->registerHooks();
    }

    protected function registerHooks(): void
    {
        // CSS.
        \Eventy::addFilter('stylesheets', function ($styles) {
            try {
                $styles[] = \Module::getPublicPath('adamsmartsearchui').'/css/module.css';
            } catch (\Throwable $e) {
                // no-op
            }
            return $styles;
        });

        // JS.
        \Eventy::addFilter('javascripts', function ($javascripts) {
            try {
                $javascripts[] = \Module::getPublicPath('adamsmartsearchui').'/js/module.js';
            } catch (\Throwable $e) {
                // no-op
            }
            return $javascripts;
        });

        // Inject a tiny config node near the bottom of <body>
        // so JS can be subdirectory-safe and config-aware.
        \Eventy::addAction('layout.body_bottom', function () {
            try {
                echo \View::make('adamsmartsearchui::partials.config')->render();
            } catch (\Throwable $e) {
                // no-op
            }
        }, 5);
    }

    protected function registerRoutes(): void
    {
        try {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        } catch (\Throwable $e) {
            // no-op
        }
    }

    protected function registerViews(): void
    {
        try {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'adamsmartsearchui');
        } catch (\Throwable $e) {
            // no-op
        }
    }
}

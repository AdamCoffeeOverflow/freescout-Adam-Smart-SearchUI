<?php

namespace Modules\AdamSmartSearchUI\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

if (!defined('ADAM_SMART_SEARCH_UI_MODULE')) {
    define('ADAM_SMART_SEARCH_UI_MODULE', 'adamsmartsearchui');
}

class AdamSmartSearchUIServiceProvider extends ServiceProvider
{
    public function register()
    {
        try {
            $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'adamsmartsearchui');
        } catch (\Throwable $e) {
            // no-op
        }
    }

    public function boot()
    {
        $this->registerViews();
        $this->registerTranslations();
        $this->registerRoutes();
        $this->registerHooks();
    }

    protected function registerTranslations()
    {
        try {
            $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'adamsmartsearchui');
        } catch (\Throwable $e) {
            // no-op
        }
    }

    protected function shouldInjectFrontendAssets()
    {
        try {
            if ($this->app->runningInConsole()) {
                return false;
            }
            if (!Auth::check()) {
                return false;
            }
            $request = $this->app['request'];
            if (!$request || $request->ajax() || $request->isXmlHttpRequest()) {
                return false;
            }

            // Navbar/topbar behavior must be available on normal authenticated pages,
            // not only on the Smart Search page itself. Keep the JS logic responsible
            // for deciding whether to actually render the inline control.
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function registerHooks()
    {
        \Eventy::addFilter('stylesheets', function ($styles) {
            try {
                if ($this->shouldInjectFrontendAssets()) {
                    $styles[] = \Module::getPublicPath(ADAM_SMART_SEARCH_UI_MODULE).'/css/module.css';
                }
            } catch (\Throwable $e) {
                // no-op
            }
            return $styles;
        });

        \Eventy::addFilter('javascripts', function ($javascripts) {
            try {
                if ($this->shouldInjectFrontendAssets()) {
                    $javascripts[] = \Module::getPublicPath(ADAM_SMART_SEARCH_UI_MODULE).'/js/module.js';
                }
            } catch (\Throwable $e) {
                // no-op
            }
            return $javascripts;
        });

        \Eventy::addAction('layout.body_bottom', function () {
            try {
                if ($this->shouldInjectFrontendAssets()) {
                    echo \View::make('adamsmartsearchui::partials.config')->render();
                }
            } catch (\Throwable $e) {
                // no-op
            }
        }, 5);
    }

    protected function registerRoutes()
    {
        try {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        } catch (\Throwable $e) {
            // no-op
        }
    }

    protected function registerViews()
    {
        try {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'adamsmartsearchui');
        } catch (\Throwable $e) {
            // no-op
        }
    }
}

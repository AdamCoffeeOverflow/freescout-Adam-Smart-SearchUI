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
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'adamsmartsearchui');
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'adamsmartsearchui');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'adamsmartsearchui');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        $this->registerHooks();
    }

    protected function shouldInjectFrontendAssets()
    {
        try {
            if ($this->app->runningInConsole() || !Auth::check()) {
                return false;
            }

            $request = $this->app['request'];
            if (!$request || $request->ajax() || $request->isXmlHttpRequest()) {
                return false;
            }

            // Navbar/topbar behavior is intentionally available on every normal
            // authenticated page. The JavaScript decides whether to render it.
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function registerHooks()
    {
        \Eventy::addFilter('stylesheets', function ($styles) {
            if ($this->shouldInjectFrontendAssets()) {
                $path = \Module::getPublicPath(ADAM_SMART_SEARCH_UI_MODULE).'/css/module.css';
                if (!in_array($path, $styles, true)) {
                    $styles[] = $path;
                }
            }

            return $styles;
        });

        \Eventy::addFilter('javascripts', function ($javascripts) {
            if ($this->shouldInjectFrontendAssets()) {
                $path = \Module::getPublicPath(ADAM_SMART_SEARCH_UI_MODULE).'/js/module.js';
                if (!in_array($path, $javascripts, true)) {
                    $javascripts[] = $path;
                }
            }

            return $javascripts;
        });

        \Eventy::addAction('layout.body_bottom', function () {
            if ($this->shouldInjectFrontendAssets()) {
                echo \View::make('adamsmartsearchui::partials.config')->render();
            }
        }, 5);
    }
}

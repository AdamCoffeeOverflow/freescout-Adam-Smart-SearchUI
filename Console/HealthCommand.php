<?php

namespace Modules\AdamSmartSearchUI\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

/**
 * Read-only health check for UpdateGuard.
 *
 *   php artisan adamsmartsearchui:health
 *   php artisan adamsmartsearchui:health --json
 *
 * Exit 0 means healthy. Any other exit code means unhealthy.
 * The command does not write, migrate, or use the network.
 */
class HealthCommand extends Command
{
    protected $signature = 'adamsmartsearchui:health {--json : Print a JSON object}';

    protected $description = 'Read-only AdamSmartSearchUI health check (UpdateGuard)';

    public function handle()
    {
        $root = dirname(__DIR__);
        $checks = array();
        $checks['files'] = $this->files($root);
        $checks['classes'] = $this->classes();
        $checks['views'] = $this->views();
        $checks['routes'] = $this->routes();
        $checks['database'] = $this->database();
        $checks['assets'] = $this->assets();

        $version = $this->version($root);
        if ($version === '') {
            $checks['version'] = 'module.json has no version';
        }
        $failed = 0;
        foreach ($checks as $result) {
            if ($result !== 'ok') {
                $failed++;
            }
        }

        $status = $failed === 0 ? 'ok' : 'fail';
        $payload = array(
            'status' => $status,
            'module' => 'AdamSmartSearchUI',
            'version' => $version,
            'checks' => $checks,
        );

        if ($this->option('json')) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                $this->line('{"status":"fail","module":"AdamSmartSearchUI","version":"","checks":{"json":"encode failed"}}');

                return 1;
            }
            $this->line($encoded);

            return $failed === 0 ? 0 : 1;
        }

        foreach ($checks as $name => $result) {
            $this->line(str_pad($name, 12).$result);
        }
        $this->line('AdamSmartSearchUI '.$version.': '.$status);

        return $failed === 0 ? 0 : 1;
    }

    protected function files($root)
    {
        $relative = array(
            'module.json',
            'start.php',
            'version.txt',
            'Config/config.php',
            'Providers/AdamSmartSearchUIServiceProvider.php',
            'Http/Controllers/SmartSearchController.php',
            'Routes/web.php',
            'Resources/views/search.blade.php',
            'Resources/views/partials/config.blade.php',
            'Resources/lang/en/messages.php',
            'Public/css/module.css',
            'Public/js/module.js',
            'Public/img/smartsearchui.png',
            'Database/Migrations/2026_02_18_000001_add_idx_conversations_mailbox_updated.php',
        );
        $missing = array();
        foreach ($relative as $path) {
            $full = $root.'/'.$path;
            if (!is_file($full) || !is_readable($full)) {
                $missing[] = $path;
            }
        }

        return $missing ? 'missing '.implode(', ', $missing) : 'ok';
    }

    protected function classes()
    {
        $classes = array(
            'Modules\\AdamSmartSearchUI\\Providers\\AdamSmartSearchUIServiceProvider',
            'Modules\\AdamSmartSearchUI\\Http\\Controllers\\SmartSearchController',
        );
        $missing = array();
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                $missing[] = $class;
            }
        }

        return $missing ? 'missing '.implode(', ', $missing) : 'ok';
    }

    protected function views()
    {
        $views = array(
            'adamsmartsearchui::search',
            'adamsmartsearchui::partials.config',
        );
        $missing = array();
        foreach ($views as $view) {
            try {
                $exists = View::exists($view);
            } catch (\Throwable $e) {
                $exists = false;
            }
            if (!$exists) {
                $missing[] = $view;
            }
        }

        return $missing ? 'missing '.implode(', ', $missing) : 'ok';
    }

    protected function routes()
    {
        $names = array(
            'adamsmartsearchui.search',
            'adamsmartsearchui.suggest',
            'adamsmartsearchui.fields',
            'adamsmartsearchui.recent_meta',
            'adamsmartsearchui.bulk',
        );
        $missing = array();
        foreach ($names as $name) {
            try {
                $exists = Route::has($name);
            } catch (\Throwable $e) {
                $exists = false;
            }
            if (!$exists) {
                $missing[] = $name;
            }
        }

        return $missing ? 'missing '.implode(', ', $missing) : 'ok';
    }

    /**
     * The optional (mailbox_id, updated_at) index is not required. Search still
     * works when FreeScout or another module already has an equivalent index.
     */
    protected function database()
    {
        try {
            if (!Schema::hasTable('conversations')) {
                return 'conversations table missing';
            }
            DB::table('conversations')->select(array('id', 'mailbox_id', 'updated_at'))->limit(1)->get();
        } catch (\Throwable $e) {
            return 'conversations query failed';
        }

        return 'ok';
    }

    protected function assets()
    {
        $alias = defined('ADAM_SMART_SEARCH_UI_MODULE') ? ADAM_SMART_SEARCH_UI_MODULE : 'adamsmartsearchui';
        $relative = array(
            'css/module.css',
            'js/module.js',
        );
        $missing = array();
        foreach ($relative as $path) {
            try {
                $full = public_path('modules/'.$alias.'/'.$path);
                $readable = is_file($full) && is_readable($full);
            } catch (\Throwable $e) {
                $readable = false;
            }
            if (!$readable) {
                $missing[] = 'modules/'.$alias.'/'.$path;
            }
        }

        return $missing ? 'missing '.implode(', ', $missing) : 'ok';
    }

    protected function version($root)
    {
        $manifest = json_decode((string) @file_get_contents($root.'/module.json'), true);
        if (!is_array($manifest) || !isset($manifest['version'])) {
            return '';
        }

        return (string) $manifest['version'];
    }
}

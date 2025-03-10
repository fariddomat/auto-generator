<?php

namespace Fariddomat\AutoGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CrudRouteGenerator
{
    public static function create($name, $controller, $group, $isDashboard, $command, $softDeletes = false, $middleware = [])
    {
        $routeFile = base_path("routes/{$group}.php");
        $pluralName = Str::plural(Str::snake($name));
        $controllerClass = $isDashboard ? "App\\Http\\Controllers\\Dashboard\\{$controller}" : "App\\Http\\Controllers\\{$controller}";
        
        // Prefix and name settings based on dashboard
        $routePrefix = $isDashboard ? 'dashboard' : '';
        $namePrefix = $isDashboard ? "'dashboard.'" : "''";

        // Middleware string for the group (if any)
        $middlewareString = empty($middleware) ? '' : "->middleware([" . implode(', ', array_map(fn($m) => "'$m'", $middleware)) . "])";

        // Route definitions
        $routes = "\nRoute::prefix('$routePrefix')->name($namePrefix)" . $middlewareString . "->group(function () {\n";
        $routes .= "    Route::resource('/$pluralName', \\{$controllerClass}::class);\n"; // Keep full namespace
        if ($softDeletes) {
            $routes .= "    Route::post('/$pluralName/{id}/restore', [\\{$controllerClass}::class, 'restore'])->name('$pluralName.restore');\n";
        }
        $routes .= "});\n";

        // Append to route file
        if (!File::exists($routeFile)) {
            $command->warn("\033[33m Route file '$routeFile' does not exist. Creating it. \033[0m");
            File::put($routeFile, "<?php\n\nuse Illuminate\Support\Facades\Route;\n");
        }

        $currentContent = File::get($routeFile);
        if (strpos($currentContent, "Route::resource('/$pluralName',") !== false && (!$isDashboard || strpos($currentContent, "prefix('$routePrefix')") !== false)) {
            $command->warn("\033[33m Routes for '/$pluralName' already exist in $routeFile. Skipping. \033[0m");
            return false;
        }

        $content = rtrim($currentContent, "\n") . $routes . "\n";
        File::put($routeFile, $content);
        $command->info("\033[32m CRUD routes added to: $routeFile \033[0m");

        return true;
    }
}
<?php

namespace Fariddomat\AutoGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ApiRouteGenerator
{
    public static function create($name, $controller, $version, $command, $softDeletes = false, $middleware = [])
    {
        $routeFile = base_path('routes/api.php');
        $pluralName = Str::plural(Str::snake($name));
        $controllerClass = "App\\Http\\Controllers\\{$controller}";
        $routePrefix = "/{$version}";

        // Middleware string
        $middlewareString = empty($middleware) ? '' : "->middleware([" . implode(', ', array_map(fn($m) => "'$m'", $middleware)) . "])";

        // Route definitions
        $routes = "\nRoute::prefix('$routePrefix')->group(function () {\n    Route::resource('$pluralName', \\{$controllerClass}::class)$middlewareString;";
        if ($softDeletes) {
            $routes .= "\n    Route::post('$pluralName/{id}/restore', [\\{$controllerClass}::class, 'restore'])$middlewareString;";
        }
        $routes .= "\n});\n";

        // Append to route file
        if (!File::exists($routeFile)) {
            $command->warn("\033[33m Route file '$routeFile' does not exist. Creating it. \033[0m");
            File::put($routeFile, "<?php\n\nuse Illuminate\Support\Facades\Route;\n");
        }

        $currentContent = File::get($routeFile);
        if (strpos($currentContent, "Route::resource('$pluralName',") !== false && strpos($currentContent, "prefix('$routePrefix')") !== false) {
            $command->warn("\033[33m Routes for '$pluralName' (version $version) already exist in $routeFile. Skipping. \033[0m");
            return false;
        }

        $content = rtrim($currentContent, "\n") . $routes . "\n";
        File::put($routeFile, $content);
        $command->info("\033[32m API routes added to: $routeFile \033[0m");

        return true;
    }
}
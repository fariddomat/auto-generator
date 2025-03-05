<?php

namespace Fariddomat\AutoCrud\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class RouteGenerator
{
    /**
     * Create routes for the given resource.
     *
     * @param string $modelName The name of the model for which the routes will be generated
     * @param string $controller The controller that should be used for the resource (e.g., PostController)
     * @param string $type Either 'api' or 'web' depending on the route type
     * @param bool $isDashboard Whether the route should be prefixed with 'dashboard'
     * @param object|null $command Optional console command instance for feedback
     * @param bool $withSoftDeletes Include restore route if true
     * @param array $middleware Optional middleware to apply to the routes
     * @return bool Success status
     */
    public function create(
        string $modelName,
        string $controller,
        string $type,
        bool $isDashboard = false,
        $command = null,
        bool $withSoftDeletes = false,
        array $middleware = []
    ): bool {
        $modelName = Str::snake(Str::plural($modelName));
        $isApi = $type === 'api';
        $routesPath = base_path($isApi ? 'routes/api.php' : 'routes/web.php');

        // Determine the controller namespace
        $controllerNamespace = $isDashboard ? 'App\Http\Controllers\Dashboard' : 'App\Http\Controllers';
        $controllerFullClass = "$controllerNamespace\\$controller";

        // Prepare middleware string if provided
        $middlewareString = !empty($middleware) ? "->middleware(['" . implode("', '", $middleware) . "'])" : '';

        // Base route code using short controller name
        $routeCode = $isApi
            ? "Route::apiResource('/{$modelName}', {$controller}::class)$middlewareString;"
            : "Route::resource('/{$modelName}', {$controller}::class)$middlewareString;";

        // Add restore route if soft deletes are enabled
        if ($withSoftDeletes) {
            $restoreRoute = "Route::post('/{$modelName}/{id}/restore', [{$controller}::class, 'restore'])$middlewareString"
                . "->name('{$modelName}.restore');";
            $routeCode .= "\n" . $restoreRoute;
        }

        // Wrap in dashboard group if applicable (only for web routes)
        if ($isDashboard && !$isApi) {
            $groupMiddleware = !empty($middleware) ? "->middleware(['" . implode("', '", $middleware) . "'])" : '';
            $routeCode = "Route::prefix('dashboard')"
                . "->name('dashboard.')"
                . $groupMiddleware
                . "->group(function () {"
                . "\n    Route::resource('/{$modelName}', {$controller}::class);"
                . ($withSoftDeletes ? "\n    Route::post('/{$modelName}/{id}/restore', [{$controller}::class, 'restore'])->name('{$modelName}.restore');" : '')
                . "\n});";
        }

        // Ensure the routes file exists and add the use statement
        if (!File::exists($routesPath)) {
            try {
                $initialContent = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\nuse $controllerFullClass;\n";
                File::put($routesPath, $initialContent);
                static::info($command, "Created routes file: $routesPath");
            } catch (\Exception $e) {
                static::error($command, "Failed to create routes file: " . $e->getMessage());
                return false;
            }
        }

        // Read and update the routes file
        try {
            $content = File::get($routesPath);

            // Check if the use statement for the controller already exists
            if (strpos($content, "use $controllerFullClass;") === false) {
                // Add the use statement after the Route facade
                $content = preg_replace(
                    "/(use Illuminate\\\\Support\\\\Facades\\\\Route;)/",
                    "$1\nuse $controllerFullClass;",
                    $content,
                    1
                );
                File::put($routesPath, $content);
            }

            // Check for existing resource route to avoid duplication
            $resourcePattern = $isApi
                ? "Route::apiResource\s*\(\s*['\"]\/{$modelName}['\"],\s*{$controller}::class\s*\)"
                : "Route::resource\s*\(\s*['\"]\/{$modelName}['\"],\s*{$controller}::class\s*\)";
            if (preg_match("/$resourcePattern/", $content)) {
                static::warn($command, "Route for '{$modelName}' already exists in $routesPath. Skipping.");
                return true; // Not an error, just skipping
            }

            // Append the new route code
            File::append($routesPath, "\n" . $routeCode . "\n");
            static::info($command, "Routes added to: $routesPath");
            return true;
        } catch (\Exception $e) {
            static::error($command, "Failed to update routes file: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Helper method to output info messages to the console.
     *
     * @param object|null $command Command instance
     * @param string $message Message to display
     */
    protected static function info($command, $message)
    {
        if ($command) {
            $command->info("\033[32m $message \033[0m");
        } else {
            echo "\033[32m $message \033[0m\n";
        }
    }

    /**
     * Helper method to output warning messages to the console.
     *
     * @param object|null $command Command instance
     * @param string $message Message to display
     */
    protected static function warn($command, $message)
    {
        if ($command) {
            $command->warn($message);
        } else {
            echo "\033[33m $message \033[0m\n";
        }
    }

    /**
     * Helper method to output error messages to the console.
     *
     * @param object|null $command Command instance
     * @param string $message Message to display
     */
    protected static function error($command, $message)
    {
        if ($command) {
            $command->error($message);
        } else {
            echo "\033[31m $message \033[0m\n";
        }
    }
}

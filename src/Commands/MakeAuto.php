<?php

namespace Fariddomat\AutoGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Fariddomat\AutoGenerator\Services\ModelGenerator;
use Fariddomat\AutoGenerator\Services\MigrationGenerator;
use Fariddomat\AutoGenerator\Services\CrudControllerGenerator;
use Fariddomat\AutoGenerator\Services\ApiControllerGenerator;
use Fariddomat\AutoGenerator\Services\ViewGenerator;
use Fariddomat\AutoGenerator\Services\CrudRouteGenerator;
use Fariddomat\AutoGenerator\Services\ApiRouteGenerator;

class MakeAuto extends Command
{
    protected $signature = 'make:auto';
    protected $description = 'Generate CRUD and/or API modules interactively with controller-based validation';

    public function handle()
    {
        $this->info("\033[34m Welcome to Auto-Generator! Let's create your module step-by-step. \033[0m");

        $name = $this->askModelName();
        $fields = $this->askFields($name);
        $type = $this->choice("\033[33m Generate CRUD, API, or both? \033[0m", ['crud', 'api', 'both'], 'both');
        $version = $type !== 'crud' ? $this->askVersion() : null;
        $isDashboard = $type !== 'api' ? $this->confirm("\033[33m Use dashboard prefix? (Default: No) \033[0m", false) : false;
        $softDeletes = $this->confirm("\033[33m Enable soft deletes? (Default: No) \033[0m", false);
        $searchEnabled = $type !== 'api' ? $this->confirm("\033[33m Enable search? (Default: No) \033[0m", false) : false;
        $searchableFields = $searchEnabled ? $this->askSearchableFields($fields) : [];
        $middleware = $this->askMiddleware();

        $this->displaySummary($name, $fields, $type, $version, $isDashboard, $softDeletes, $searchEnabled, $searchableFields, $middleware);
        if (!$this->confirm("\033[33m Proceed with these settings? \033[0m", true)) {
            $this->info("\033[31m Generation cancelled. \033[0m");
            return 0;
        }

        $this->info("\033[34m Generating for $name... \033[0m");

        // Parse fields once for reuse
        $parsedFields = $this->parseFields($fields);

        // Generate model and migration (shared for CRUD and API)
        ModelGenerator::generate($name, $parsedFields, $this, $softDeletes, $searchEnabled, $searchableFields);
        MigrationGenerator::generate($name, Str::snake(Str::plural($name)), $parsedFields, $this, $softDeletes);

        if (in_array($type, ['crud', 'both'])) {
            CrudControllerGenerator::generate($name, $isDashboard, $parsedFields, $this, $softDeletes, $middleware);
            ViewGenerator::generateBladeViews($name, $isDashboard, $parsedFields);
            CrudRouteGenerator::create($name, "{$name}Controller", 'web', $isDashboard, $this, $softDeletes, $middleware);
        }

        if (in_array($type, ['api', 'both'])) {
            ApiControllerGenerator::generate($name, $version, $parsedFields, $this, $softDeletes, $searchEnabled, $searchableFields, $middleware);
            ApiRouteGenerator::create($name, "{$name}ApiController", $version, $this, $softDeletes, $middleware);
            $this->generateOpenApiSpec($name, $version, $parsedFields, $softDeletes, $searchEnabled);
        }

        $this->info("\033[34m Generation for $name completed successfully! \033[0m");
    }

    protected function askModelName()
    {
        $name = $this->ask("\033[33m Model name? (e.g., Post; must start with a capital letter) \033[0m");
        if (empty($name) || !preg_match('/^[A-Z][a-zA-Z0-9_]*$/', $name)) {
            $this->error("\033[31m Invalid or empty model name. Aborting. \033[0m");
            exit(1);
        }
        return Str::studly($name);
    }

    protected function askFields($name)
    {
        $fields = [];
        $this->info("\033[36m Define fields for $name (e.g., title:string, user_id:belongsTo, roles:belongsToMany, photo:image, status:enum:active,inactive). Leave blank to finish. \033[0m");
        $this->info("\033[36m Use 'belongsTo' for one-to-many (e.g., user_id:belongsTo), 'belongsToMany' for many-to-many (e.g., roles:belongsToMany creates a pivot table), 'select' for UI dropdown hints, or new types like 'date', 'datetime', 'enum', 'json'. \033[0m");
        while (true) {
            $field = $this->ask("\033[33m Enter a field: \033[0m");
            if (empty($field)) break;
            $fields[] = $field;
        }
        return $fields;
    }

    protected function askSearchableFields($fields)
    {
        $parsedFields = array_map(fn($f) => explode(':', $f)[0], $fields);
        $this->info("\033[36m Select searchable fields (comma-separated, from: " . implode(', ', $parsedFields) . "). Leave blank for all string fields. \033[0m");
        $input = $this->ask("\033[33m Searchable fields: \033[0m");
        return !empty($input) ? array_filter(array_map('trim', explode(',', $input))) : [];
    }

    protected function askVersion()
    {
        return $this->ask("\033[33m API version? (e.g., v1, v2; default: v1) \033[0m", 'v1');
    }

    protected function askMiddleware()
    {
        $input = $this->ask("\033[33m Middleware (comma-separated, e.g., auth:api,throttle; leave blank for none)? \033[0m");
        return !empty($input) ? array_filter(array_map('trim', explode(',', $input))) : [];
    }

    protected function displaySummary($name, $fields, $type, $version, $isDashboard, $softDeletes, $searchEnabled, $searchableFields, $middleware)
    {
        $this->info("\033[36m Settings: \033[0m");
        $this->line("  \033[32m Model: \033[0m $name");
        $this->line("  \033[32m Fields: \033[0m " . (empty($fields) ? 'None' : implode(', ', $fields)));
        $this->line("  \033[32m Type: \033[0m " . ucfirst($type));
        if ($type !== 'crud') $this->line("  \033[32m Version: \033[0m $version");
        if ($type !== 'api') $this->line("  \033[32m Dashboard: \033[0m " . ($isDashboard ? 'Yes' : 'No'));
        $this->line("  \033[32m Soft Deletes: \033[0m " . ($softDeletes ? 'Yes' : 'No'));
        if ($type !== 'crud') {
            $this->line("  \033[32m Search Enabled: \033[0m " . ($searchEnabled ? 'Yes' : 'No'));
            if ($searchEnabled) $this->line("  \033[32m Searchable Fields: \033[0m " . (empty($searchableFields) ? 'All string fields' : implode(', ', $searchableFields)));
        }
        $this->line("  \033[32m Middleware: \033[0m " . (empty($middleware) ? 'None' : implode(', ', $middleware)));
    }

    protected function parseFields($fields)
    {
        $parsed = [];
        $validTypes = ['string', 'text', 'integer', 'decimal', 'select', 'belongsTo', 'belongsToMany', 'boolean', 'file', 'image', 'images', 'date', 'datetime', 'enum', 'json'];
        foreach ($fields as $field) {
            $parts = explode(':', $field);
            if (empty($parts[0])) {
                $this->warn("Skipping malformed field: '$field'. Expected format: 'name:type:modifiers'");
                continue;
            }

            $name = $parts[0];
            $type = $parts[1] ?? 'string';
            $modifiers = array_slice($parts, 2);

            if (!in_array($type, $validTypes)) {
                $this->warn("Invalid type '$type' for field '$name'. Defaulting to 'string'. Supported types: " . implode(', ', $validTypes));
                $type = 'string';
            }

            $migrationType = $type;
            if ($type === 'belongsTo' || $type === 'select') {
                $migrationType = 'unsignedBigInteger'; // For foreign keys
            } elseif (in_array($type, ['file', 'image', 'images'])) {
                $migrationType = 'string';
            } elseif ($type === 'belongsToMany') {
                $migrationType = null; // No column in main table for belongsToMany
            } elseif ($type === 'enum') {
                if (empty($modifiers)) {
                    $this->warn("Enum field '$name' requires values (e.g., status:enum:active,inactive). Skipping.");
                    continue;
                }
                $migrationType = ['enum', $modifiers]; // Pass enum values as an array
            }

            $parsed[] = [
                'name' => $name,
                'type' => $migrationType,
                'original_type' => $type,
                'modifiers' => $modifiers,
            ];
        }
        return $parsed;
    }

    protected function generateOpenApiSpec($name, $version, $parsedFields, $softDeletes, $searchEnabled)
    {
        $specPath = base_path("openapi/{$name}.json");
        $routePrefix = Str::plural(Str::snake($name));

        $properties = [];
        foreach ($parsedFields as $field) {
            if ($field['original_type'] === 'belongsToMany') {
                $properties[$field['name']] = [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => "Array of {$field['name']} IDs",
                ];
            } elseif ($field['original_type'] === 'enum') {
                $properties[$field['name']] = [
                    'type' => 'string',
                    'enum' => $field['modifiers'],
                    'description' => "One of: " . implode(', ', $field['modifiers']),
                ];
            } elseif ($field['original_type'] === 'json') {
                $properties[$field['name']] = [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'description' => "JSON data for {$field['name']}",
                ];
            } elseif (in_array($field['original_type'], ['date', 'datetime'])) {
                $properties[$field['name']] = [
                    'type' => 'string',
                    'format' => $field['original_type'] === 'date' ? 'date' : 'date-time',
                ];
            } else {
                $properties[$field['name']] = [
                    'type' => in_array($field['original_type'], ['file', 'image', 'images']) ? 'string' : $field['original_type'],
                ];
            }
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => "{$name} API (Version $version)",
                'version' => $version,
                'description' => "API for managing {$name} resources.",
            ],
            'paths' => [
                "/$version/$routePrefix" => [
                    'get' => [
                        'summary' => "List all {$name}s",
                        'parameters' => array_merge(
                            $searchEnabled ? [
                                ['name' => 'search', 'in' => 'query', 'description' => 'Search term', 'required' => false, 'schema' => ['type' => 'string']],
                            ] : [],
                            [
                                ['name' => 'per_page', 'in' => 'query', 'description' => 'Items per page', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 15]],
                                ['name' => 'page', 'in' => 'query', 'description' => 'Page number', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1]],
                            ]
                        ),
                        'responses' => ['200' => ['description' => 'Success']],
                    ],
                    'post' => [
                        'summary' => "Create a {$name}",
                        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$name}"]]]],
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
                "/$version/$routePrefix/{id}" => [
                    'get' => ['summary' => "Show a {$name}", 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]], 'responses' => ['200' => ['description' => 'Success']]],
                    'put' => ['summary' => "Update a {$name}", 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]], 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$name}"]]]], 'responses' => ['200' => ['description' => 'Updated']]],
                    'delete' => ['summary' => "Delete a {$name}", 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]], 'responses' => ['204' => ['description' => 'Deleted']]],
                ],
            ],
            'components' => [
                'schemas' => [
                    $name => [
                        'type' => 'object',
                        'properties' => $properties,
                    ],
                ],
            ],
        ];

        if ($softDeletes) {
            $spec['paths']["/$version/$routePrefix/{id}/restore"] = [
                'post' => [
                    'summary' => "Restore a {$name}",
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'Restored']],
                ],
            ];
        }

        File::ensureDirectoryExists(dirname($specPath));
        File::put($specPath, json_encode($spec, JSON_PRETTY_PRINT));
        $this->info("\033[32m OpenAPI spec created: $specPath \033[0m");
    }
}

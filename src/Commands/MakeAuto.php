<?php

namespace Fariddomat\AutoGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Fariddomat\AutoGenerator\Services\CrudGenerator;
use Fariddomat\AutoGenerator\Services\ApiGenerator;
use Fariddomat\AutoGenerator\Services\MigrationGenerator;

class MakeAuto extends Command
{
    protected $signature = 'make:auto';
    protected $description = 'Generate CRUD and/or API modules interactively.';

    public function handle()
    {
        $this->info("\033[34m Welcome to Auto-Generator! Let's create your module step-by-step. \033[0m");

        $name = $this->askModelName();
        $fields = $this->askFields($name);
        $type = $this->choice("\033[33m Generate CRUD, API, or both? \033[0m", ['crud', 'api', 'both'], 'both');
        $version = $type !== 'crud' ? $this->askVersion() : null;
        $isDashboard = $type !== 'api' ? $this->confirm("\033[33m Use dashboard prefix? (Default: No) \033[0m", false) : false;
        $softDeletes = $this->confirm("\033[33m Enable soft deletes? (Default: No) \033[0m", false);
        $searchEnabled = $type !== 'crud' ? $this->confirm("\033[33m Enable search? (Default: No) \033[0m", false) : false;
        $searchableFields = $searchEnabled ? $this->askSearchableFields($fields) : [];
        $middleware = $this->askMiddleware();

        $this->displaySummary($name, $fields, $type, $version, $isDashboard, $softDeletes, $searchEnabled, $searchableFields, $middleware);
        if (!$this->confirm("\033[33m Proceed with these settings? \033[0m", true)) {
            $this->info("\033[31m Generation cancelled. \033[0m");
            return 0;
        }

        $this->info("\033[34m Generating for $name... \033[0m");

        $parsedFields = (new CrudGenerator($name, $fields, $this))->parseFields();
        MigrationGenerator::generate($name, Str::snake(Str::plural($name)), $parsedFields, $this, $softDeletes);

        if (in_array($type, ['crud', 'both'])) {
            $crudGenerator = new CrudGenerator($name, $fields, $this);
            $crudGenerator->generateModel($softDeletes);
            $crudGenerator->generateController($isDashboard, $softDeletes, $middleware);
            $crudGenerator->generateViews($isDashboard);
            $crudGenerator->generateRoutes($isDashboard, $softDeletes, $middleware);
        }

        if (in_array($type, ['api', 'both'])) {
            $apiGenerator = new ApiGenerator($name, $fields, $this);
            if ($type !== 'both') $apiGenerator->generateModel($softDeletes, $searchEnabled, $searchableFields);
            $apiGenerator->generateController($version, $middleware, $softDeletes, $searchEnabled, $searchableFields);
            $apiGenerator->generateRoutes($version, $middleware, $softDeletes);
            $apiGenerator->generateOpenApiSpec($version, $softDeletes, $searchEnabled);
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
        $this->info("\033[36m Define fields for $name (e.g., title:string, user_id:select, photo:image). Leave blank to finish. \033[0m");
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
}
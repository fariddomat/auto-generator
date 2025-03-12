<?php

namespace Fariddomat\AutoGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CrudControllerGenerator
{
    public static function generate($name, $isDashboard, $parsedFields, $command, $softDeletes = false, $middleware = [])
    {
        $namespace = $isDashboard ? "App\\Http\\Controllers\\Dashboard" : "App\\Http\\Controllers";
        $path = app_path("Http/Controllers/" . ($isDashboard ? "Dashboard/" : "") . "{$name}Controller.php");
        $modelClassImport = "App\\Models\\{$name}";
        $modelClass = "\\App\\Models\\{$name}";
        $variableName = Str::camel($name);
        $pluralVariable = Str::plural($variableName);
        $viewPrefix = $isDashboard ? 'dashboard.' : '';

        $middlewareString = empty($middleware) ? '' : "    public function __construct()\n    {\n        \$this->middleware([" . implode(', ', array_map(fn($m) => "'$m'", $middleware)) . "]);\n    }\n";

        $validationRules = self::generateValidationRules($parsedFields);
        $fileHandlingStore = self::generateFileHandling($parsedFields, $variableName, 'store');
        $fileHandlingUpdate = self::generateFileHandling($parsedFields, $variableName, 'update');
        $fileCleanup = self::generateFileCleanup($parsedFields, $variableName);
        $relationshipsFetch = self::generateRelationshipsFetch($parsedFields);

        $compactVars = implode(', ', self::getCompactVars($parsedFields));

        $belongsToManyHandlingStore = self::generateBelongsToManyHandling($parsedFields, $variableName, 'store');
        $belongsToManyHandlingUpdate = self::generateBelongsToManyHandling($parsedFields, $variableName, 'update');

        $destroyMethod = $softDeletes
            ? "    public function destroy(\$id)\n    {\n        \${$variableName} = $modelClass::findOrFail(\$id);\n        \${$variableName}->delete();\n        return redirect()->route('{$viewPrefix}{$pluralVariable}.index')->with('success', '{$name} deleted successfully.');\n    }"
            : "    public function destroy(\$id)\n    {\n        \${$variableName} = $modelClass::findOrFail(\$id);\n        $fileCleanup\n        \${$variableName}->delete();\n        return redirect()->route('{$viewPrefix}{$pluralVariable}.index')->with('success', '{$name} deleted successfully.');\n    }";

        $content = <<<EOT
<?php

namespace $namespace;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use $modelClassImport;

class {$name}Controller extends Controller
{
$middlewareString
    public function index()
    {
        \${$pluralVariable} = $modelClass::all();
        return view('{$viewPrefix}{$pluralVariable}.index', compact('{$pluralVariable}'));
    }

    public function create()
    {
        $relationshipsFetch
        return view('{$viewPrefix}{$pluralVariable}.create', compact($compactVars));
    }

    public function store(Request \$request)
    {
        \$validated = \$request->validate([
            $validationRules
        ]);
        $fileHandlingStore
        \${$variableName} = $modelClass::create(\$validated);
        $belongsToManyHandlingStore
        return redirect()->route('{$viewPrefix}{$pluralVariable}.index')->with('success', '{$name} created successfully.');
    }

    public function show(\$id)
    {
        \${$variableName} = $modelClass::findOrFail(\$id);
        $relationshipsFetch
        return view('{$viewPrefix}{$pluralVariable}.show', compact('{$variableName}'));
    }

    public function edit(\$id)
    {
        \${$variableName} = $modelClass::findOrFail(\$id);
        $relationshipsFetch
        return view('{$viewPrefix}{$pluralVariable}.edit', compact('{$variableName}', $compactVars));
    }

    public function update(Request \$request, \$id)
    {
        \${$variableName} = $modelClass::findOrFail(\$id);
        \$validated = \$request->validate([
            $validationRules
        ]);
        $fileHandlingUpdate
        \${$variableName}->update(\$validated);
        $belongsToManyHandlingUpdate
        return redirect()->route('{$viewPrefix}{$pluralVariable}.index')->with('success', '{$name} updated successfully.');
    }

    $destroyMethod
EOT;

        if ($softDeletes) {
            $content .= <<<EOT

    public function restore(\$id)
    {
        \${$variableName} = $modelClass::withTrashed()->findOrFail(\$id);
        \${$variableName}->restore();
        return redirect()->route('{$viewPrefix}{$pluralVariable}.index')->with('success', '{$name} restored successfully.');
    }
EOT;
        }

        $content .= "\n}";

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
        $command->info("\033[32m CRUD Controller created: $path \033[0m");
    }

    protected static function generateValidationRules($parsedFields)
    {
        $rules = [];
        foreach ($parsedFields as $field) {
            $name = $field['name'];
            $type = $field['original_type'];
            $modifiers = $field['modifiers'];
            $rule = in_array('nullable', $modifiers) ? 'nullable' : 'required';

            switch ($type) {
                case 'string':
                    $rules[] = "'$name' => '$rule|string|max:255'";
                    break;
                case 'decimal':
                case 'integer':
                    $rules[] = "'$name' => '$rule|numeric'";
                    break;
                case 'select':
                    $table = Str::snake(Str::plural(Str::beforeLast($name, '_id')));
                    $rules[] = "'$name' => '$rule|exists:$table,id'";
                    break;
                case 'belongsToMany':
                    $table = Str::snake($field['name']);
                    $rules[] = "'$name' => '$rule|array'";
                    $rules[] = "'$name.*' => 'exists:$table,id'";
                    break;
                case 'boolean':
                    $rules[] = "'$name' => 'sometimes|boolean'";
                    break;
                case 'file':
                    $rules[] = "'$name' => '$rule|file|max:2048'";
                    break;
                case 'image':
                    $rules[] = "'$name' => '$rule|image|max:2048'";
                    break;
                case 'images':
                    $rules[] = "'$name' => '$rule|array'";
                    $rules[] = "'$name.*' => 'image|max:2048'";
                    break;
            }
        }
        return implode(",\n            ", $rules);
    }

    protected static function generateFileHandling($parsedFields, $variableName, $method)
    {
        $logic = '';
        foreach ($parsedFields as $field) {
            $name = $field['name'];
            if (in_array($field['original_type'], ['file', 'image'])) {
                $logic .= "        if (\$request->hasFile('$name')) {\n            \$validated['$name'] = \$request->file('$name')->store('public/{$name}s');\n";
                if ($method === 'update') {
                    $logic .= "            if (\${$variableName}->$name) Storage::delete(\${$variableName}->$name);\n";
                }
                $logic .= "        }\n";
            } elseif ($field['original_type'] === 'images') {
                $logic .= "        if (\$request->hasFile('$name')) {\n            \$validated['$name'] = array_map(fn(\$file) => \$file->store('public/{$name}'), \$request->file('$name'));\n";
                if ($method === 'update') {
                    $logic .= "            if (\${$variableName}->$name) Storage::delete(\${$variableName}->$name);\n";
                }
                $logic .= "        }\n";
            }
        }
        return $logic;
    }

    protected static function generateFileCleanup($parsedFields, $variableName)
    {
        $logic = '';
        foreach ($parsedFields as $field) {
            if (in_array($field['original_type'], ['file', 'image', 'images'])) {
                $logic .= "        if (\${$variableName}->{$field['name']}) Storage::delete(\${$variableName}->{$field['name']});\n";
            }
        }
        return $logic;
    }

    protected static function generateRelationshipsFetch($parsedFields)
    {
        $logic = '';
        foreach ($parsedFields as $field) {
            if ($field['original_type'] === 'select' || $field['original_type'] === 'belongsToMany') {
                $relatedModel =  Str::singular(Str::studly($field['original_type'] === 'select' ? Str::beforeLast($field['name'], '_id') : $field['name']));
                $varName = Str::plural(Str::camel($relatedModel));
                $logic .= "        \${$varName} = \\App\\Models\\{$relatedModel}::all();\n";
            }
        }
        return $logic;
    }


    protected static function generateBelongsToManyHandling($parsedFields, $variableName, $method)
    {
        $logic = '';
        foreach ($parsedFields as $field) {
            if ($field['original_type'] === 'belongsToMany') {
                $name = $field['name'];
                if ($method === 'store') {
                    $logic .= "        if (\$request->has('$name')) {\n            \${$variableName}->{$name}()->attach(\$request->input('$name'));\n        }\n";
                } elseif ($method === 'update') {
                    $logic .= "        if (\$request->has('$name')) {\n            \${$variableName}->{$name}()->sync(\$request->input('$name'));\n        }\n";
                }
            }
        }
        return $logic;
    }

    protected static function getCompactVars($parsedFields)
    {
        $vars = [];
        foreach ($parsedFields as $field) {
            if ($field['original_type'] === 'select' || $field['original_type'] === 'belongsToMany') {
                $vars[] = "'".Str::plural(Str::camel(Str::studly($field['original_type'] === 'select' ? Str::beforeLast($field['name'], '_id') : $field['name'])))."'";
            }
        }
        return $vars;
    }
}

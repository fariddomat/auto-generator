<?php

namespace Fariddomat\AutoGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ApiControllerGenerator
{
    public static function generate($name, $version, $parsedFields, $command, $softDeletes = false, $searchEnabled = false, $searchableFields = [], $middleware = [])
    {
        $controllerName = "{$name}ApiController";
        $path = app_path("Http/Controllers/{$controllerName}.php");
        $middlewareString = !empty($middleware) ? "['" . implode("', '", $middleware) . "']" : '[]';
        $routePrefix = Str::plural(Str::snake($name));

        // Generate relationship data for select and belongsToMany fields
        $relationData = self::generateRelationData($parsedFields);

        // File handling logic
        $fileHandling = self::generateFileHandling($parsedFields, $routePrefix, false);
        $fileUpdateHandling = self::generateFileHandling($parsedFields, $routePrefix, true);

        // BelongsToMany handling logic
        $belongsToManyHandlingStore = self::generateBelongsToManyHandling($parsedFields, 'store');
        $belongsToManyHandlingUpdate = self::generateBelongsToManyHandling($parsedFields, 'update');

        // Search logic
        $searchLogic = $searchEnabled
            ? "\$search = \$request->query('search');\n        \$perPage = \$request->query('per_page', 15);\n        \$query = {$name}::query();\n        if (\$search) {\n            \$searchable = property_exists({$name}::class, 'searchable') ? (new {$name})->searchable : [];\n            foreach (\$searchable as \$field) {\n                \$query->orWhere(\$field, 'like', \"%{\$search}%\");\n            }\n        }\n        \$records = \$query->paginate(\$perPage);"
            : "\$records = {$name}::paginate(15);";

        // Generate relationships for eager loading
        $relationships = array_filter(array_map(function ($field) {
            if ($field['original_type'] === 'select') {
                return Str::camel(Str::beforeLast($field['name'], '_id'));
            } elseif ($field['original_type'] === 'belongsToMany') {
                return $field['name'];
            }
            return null;
        }, $parsedFields));
        $withClause = empty($relationships) ? '' : "with(['" . implode("', '", $relationships) . "'])";

        $content = <<<EOT
<?php

namespace App\Http\Controllers;

use App\Models\\{$name};
use Illuminate\Http\Request;
use App\Helpers\ImageHelper;

class {$controllerName} extends Controller
{
    public function __construct()
    {
        \$this->middleware($middlewareString);
    }

    public function index(Request \$request)
    {
        $searchLogic
        return response()->json(['data' => \$records]);
    }

    public function create()
    {
        return response()->json([
            'data' => [
$relationData
            ],
            'message' => 'Use POST to store a new record'
        ]);
    }

    public function store(Request \$request)
    {
        \$validated = \$request->validate({$name}::rules());
$fileHandling
        \$record = {$name}::create(\$validated);
$belongsToManyHandlingStore
        return response()->json(['data' => \$record], 201);
    }

    public function show(\$id)
    {
        \$record = {$name}::{$withClause}->find(\$id);
        return \$record ? response()->json(['data' => \$record]) : response()->json(['message' => 'Not found'], 404);
    }

    public function edit(\$id)
    {
        \$record = {$name}::find(\$id);
        if (!\$record) return response()->json(['message' => 'Not found'], 404);
        return response()->json([
            'data' => [
                'record' => \$record,
$relationData
            ]
        ]);
    }

    public function update(Request \$request, \$id)
    {
        \$record = {$name}::find(\$id);
        if (!\$record) return response()->json(['message' => 'Not found'], 404);
        \$validated = \$request->validate({$name}::rules());
$fileUpdateHandling
        \$record->update(\$validated);
$belongsToManyHandlingUpdate
        return response()->json(['data' => \$record]);
    }

    public function destroy(\$id)
    {
        \$record = {$name}::find(\$id);
        if (!\$record) return response()->json(['message' => 'Not found'], 404);
        \$record->delete();
        return response()->json(['message' => 'Deleted'], 204);
    }
EOT;

        if ($softDeletes) {
            $content .= <<<EOT

    public function restore(\$id)
    {
        \$record = {$name}::withTrashed()->find(\$id);
        if (!\$record) return response()->json(['message' => 'Not found'], 404);
        \$record->restore();
        return response()->json(['data' => \$record]);
    }
EOT;
        }

        $content .= "\n}";

        File::put($path, $content);
        $command->info("\033[32m Controller created: $path \033[0m");
    }

    protected static function generateRelationData($parsedFields)
    {
        $relationData = "";
        foreach ($parsedFields as $field) {
            if ($field['original_type'] === 'select') {
                $relatedModel = Str::studly(Str::beforeLast($field['name'], '_id'));
                $varName = Str::plural(Str::camel($relatedModel));
                $relationData .= "            '$varName' => \\App\\Models\\{$relatedModel}::all(),\n";
            } elseif ($field['original_type'] === 'belongsToMany') {
                $relatedModel =  Str::singular(Str::studly($field['name']));
                $varName = Str::plural(Str::camel($field['name']));
                $relationData .= "            '$varName' => \\App\\Models\\{$relatedModel}::all(),\n";
            }
        }
        return $relationData;
    }

    protected static function generateFileHandling($parsedFields, $routePrefix, $isUpdate = false)
    {
        $fileHandling = "";
        foreach ($parsedFields as $field) {
            $name = $field['name'];
            if ($field['original_type'] === 'file') {
                $fileHandling .= "        if (\$request->hasFile('$name')) {\n            \$validated['$name'] = \$request->file('$name')->store('uploads/$routePrefix', 'public');\n        }\n";
            } elseif ($field['original_type'] === 'image') {
                $fileHandling .= "        if (\$request->hasFile('$name') && class_exists('App\\Helpers\\ImageHelper')) {\n";
                if ($isUpdate) {
                    $fileHandling .= "            if (\$record->$name) ImageHelper::removeImageInPublicDirectory(\$record->$name);\n";
                }
                $fileHandling .= "            \$validated['$name'] = ImageHelper::storeImageInPublicDirectory(\$request->file('$name'), 'uploads/$routePrefix');\n        } elseif (\$request->hasFile('$name')) {\n            \$validated['$name'] = \$request->file('$name')->store('uploads/$routePrefix', 'public');\n        }\n";
            } elseif ($field['original_type'] === 'images') {
                $fileHandling .= "        if (\$request->hasFile('$name') && class_exists('App\\Helpers\\ImageHelper')) {\n";
                if ($isUpdate) {
                    $fileHandling .= "            if (\$record->$name) {\n                foreach (json_decode(\$record->$name, true) as \$oldImage) {\n                    ImageHelper::removeImageInPublicDirectory(\$oldImage);\n                }\n            }\n";
                }
                $fileHandling .= "            \$validated['$name'] = [];\n            foreach (\$request->file('$name') as \$image) {\n                \$validated['$name'][] = ImageHelper::storeImageInPublicDirectory(\$image, 'uploads/$routePrefix');\n            }\n            \$validated['$name'] = json_encode(\$validated['$name']);\n        } elseif (\$request->hasFile('$name')) {\n            \$validated['$name'] = json_encode(array_map(fn(\$file) => \$file->store('uploads/$routePrefix', 'public'), \$request->file('$name')));\n        }\n";
            }
        }
        return $fileHandling;
    }

    protected static function generateBelongsToManyHandling($parsedFields, $method)
    {
        $logic = "";
        foreach ($parsedFields as $field) {
            if ($field['original_type'] === 'belongsToMany') {
                $name = $field['name'];
                if ($method === 'store') {
                    $logic .= "        if (\$request->has('$name')) {\n            \$record->$name()->attach(\$request->input('$name'));\n        }\n";
                } elseif ($method === 'update') {
                    $logic .= "        if (\$request->has('$name')) {\n            \$record->$name()->sync(\$request->input('$name'));\n        }\n";
                }
            }
        }
        return $logic;
    }
}

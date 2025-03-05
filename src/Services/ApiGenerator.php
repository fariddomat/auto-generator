<?php

namespace Fariddomat\AutoApi\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ApiGenerator
{
    protected $name;
    protected $fields;
    protected $command;

    public function __construct($name, $fields, $command)
    {
        $this->name = $name;
        $this->fields = $fields;
        $this->command = $command;
    }

    public function parseFields()
    {
        return array_map(function ($field) {
            $parts = explode(':', $field);
            return [
                'name' => $parts[0],
                'type' => $parts[1] ?? 'string',
                'modifiers' => array_slice($parts, 2),
            ];
        }, $this->fields);
    }

    public function generateRules($fields)
    {
        $rules = [];
        foreach ($fields as $field) {
            $name = $field['name'];
            $type = $field['type'];
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
        return implode(",\n        ", $rules);
    }

    public function generateModel($softDeletes, $searchEnabled, $searchableFields)
    {
        $path = app_path("Models/{$this->name}.php");
        $parsedFields = $this->parseFields();
        $fillable = implode("', '", array_column($parsedFields, 'name'));
        $rules = $this->generateRules($parsedFields);

        $uses = "use Illuminate\Database\Eloquent\Model;";
        $traits = "";
        if ($softDeletes) {
            $uses .= "\nuse Illuminate\Database\Eloquent\SoftDeletes;";
            $traits .= "\n    use SoftDeletes;";
        }

        $searchable = $searchEnabled ? "\n    protected \$searchable = ['" . implode("', '", $searchableFields ?: array_map(fn($f) => $f['name'], array_filter($parsedFields, fn($f) => in_array($f['type'], ['string', 'text'])))) . "'];" : "";

        $relationships = "";
        foreach ($parsedFields as $field) {
            if ($field['type'] === 'select') {
                $relatedModel = Str::studly(Str::beforeLast($field['name'], '_id'));
                $relationships .= <<<EOT

    public function {$relatedModel}()
    {
        return \$this->belongsTo(\App\Models\\{$relatedModel}::class, '{$field['name']}');
    }
EOT;
            }
        }

        $content = <<<EOT
<?php

namespace App\Models;

$uses

class {$this->name} extends Model
{
    $traits

    protected \$fillable = ['$fillable'];$searchable

    public static function rules()
    {
        return [
            $rules
        ];
    }
    $relationships
}
EOT;

        File::put($path, $content);
        $this->command->info("\033[32m Model created: $path \033[0m");
    }

    public function generateMigration($softDeletes)
    {
        $tableName = Str::snake(Str::plural($this->name));
        $timestamp = date('Y_m_d_His');
        $path = base_path("database/migrations/{$timestamp}_create_{$tableName}_table.php");
        $parsedFields = $this->parseFields();

        $columns = "";
        foreach ($parsedFields as $field) {
            $name = $field['name'];
            $type = $field['type'];
            $modifiers = $field['modifiers'];

            $columnDef = "\$table->$type('$name')";
            if ($type === 'select') {
                $relatedTable = Str::snake(Str::plural(Str::beforeLast($name, '_id')));
                $columnDef = "\$table->foreignId('$name')->constrained('$relatedTable')";
            } elseif (in_array($type, ['file', 'image', 'images'])) {
                $columnDef = "\$table->string('$name')" . (in_array('nullable', $modifiers) ? "->nullable()" : "");
            }
            if (in_array('nullable', $modifiers) && $type !== 'file' && $type !== 'image' && $type !== 'images') {
                $columnDef .= "->nullable()";
            }
            $columns .= "            $columnDef;\n";
        }

        $softDeletesColumn = $softDeletes ? "\n            \$table->softDeletes();" : "";

        $content = <<<EOT
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('$tableName', function (Blueprint \$table) {
            \$table->id();
$columns$softDeletesColumn
            \$table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('$tableName');
    }
};
EOT;

        File::put($path, $content);
        $this->command->info("\033[32m Migration created: $path \033[0m");
    }

    public function generateController($version, $middleware, $softDeletes = false, $searchEnabled = false, $searchableFields = [])
    {
        $controllerName = "{$this->name}ApiController";
        $path = app_path("Http/Controllers/{$controllerName}.php");
        $middlewareString = !empty($middleware) ? "['" . implode("', '", $middleware) . "']" : '[]';
        $parsedFields = $this->parseFields();
        $routePrefix = Str::plural(Str::snake($this->name));

        // Generate relationship data for select fields
        $relationData = "";
        foreach ($parsedFields as $field) {
            if ($field['type'] === 'select') {
                $relatedModel = Str::studly(Str::beforeLast($field['name'], '_id'));
                $varName = Str::plural(Str::camel($relatedModel));
                $relationData .= "            '$varName' => \\App\\Models\\{$relatedModel}::all(),\n";
            }
        }

        // File upload handling
        $fileHandling = "";
        foreach ($parsedFields as $field) {
            $name = $field['name'];
            if ($field['type'] === 'file') {
                $fileHandling .= <<<EOT
        if (\$request->hasFile('$name')) {
            \$validated['$name'] = \$request->file('$name')->store('uploads/$routePrefix', 'public');
        }
EOT;
            } elseif ($field['type'] === 'image') {
                $fileHandling .= <<<EOT
        if (\$request->hasFile('$name') && class_exists('App\Helpers\ImageHelper')) {
            \$validated['$name'] = ImageHelper::storeImageInPublicDirectory(\$request->file('$name'), 'uploads/$routePrefix');
        } elseif (\$request->hasFile('$name')) {
            \$validated['$name'] = \$request->file('$name')->store('uploads/$routePrefix', 'public');
        }
EOT;
            } elseif ($field['type'] === 'images') {
                $fileHandling .= <<<EOT
        if (\$request->hasFile('$name') && class_exists('App\Helpers\ImageHelper')) {
            \$validated['$name'] = [];
            foreach (\$request->file('$name') as \$image) {
                \$validated['$name'][] = ImageHelper::storeImageInPublicDirectory(\$image, 'uploads/$routePrefix');
            }
            \$validated['$name'] = json_encode(\$validated['$name']);
        } elseif (\$request->hasFile('$name')) {
            \$validated['$name'] = json_encode(array_map(fn(\$file) => \$file->store('uploads/$routePrefix', 'public'), \$request->file('$name')));
        }
EOT;
            }
        }

        $fileUpdateHandling = "";
        foreach ($parsedFields as $field) {
            $name = $field['name'];
            if ($field['type'] === 'file') {
                $fileUpdateHandling .= <<<EOT
        if (\$request->hasFile('$name')) {
            \$validated['$name'] = \$request->file('$name')->store('uploads/$routePrefix', 'public');
        }
EOT;
            } elseif ($field['type'] === 'image') {
                $fileUpdateHandling .= <<<EOT
        if (\$request->hasFile('$name') && class_exists('App\Helpers\ImageHelper')) {
            if (\$record->$name) ImageHelper::removeImageInPublicDirectory(\$record->$name);
            \$validated['$name'] = ImageHelper::storeImageInPublicDirectory(\$request->file('$name'), 'uploads/$routePrefix');
        } elseif (\$request->hasFile('$name')) {
            \$validated['$name'] = \$request->file('$name')->store('uploads/$routePrefix', 'public');
        }
EOT;
            } elseif ($field['type'] === 'images') {
                $fileUpdateHandling .= <<<EOT
        if (\$request->hasFile('$name') && class_exists('App\Helpers\ImageHelper')) {
            if (\$record->$name) {
                foreach (json_decode(\$record->$name, true) as \$oldImage) {
                    ImageHelper::removeImageInPublicDirectory(\$oldImage);
                }
            }
            \$validated['$name'] = [];
            foreach (\$request->file('$name') as \$image) {
                \$validated['$name'][] = ImageHelper::storeImageInPublicDirectory(\$image, 'uploads/$routePrefix');
            }
            \$validated['$name'] = json_encode(\$validated['$name']);
        } elseif (\$request->hasFile('$name')) {
            \$validated['$name'] = json_encode(array_map(fn(\$file) => \$file->store('uploads/$routePrefix', 'public'), \$request->file('$name')));
        }
EOT;
            }
        }

        $searchLogic = $searchEnabled ? <<<EOT
        \$search = \$request->query('search');
        \$perPage = \$request->query('per_page', 15);
        \$query = {$this->name}::query();
        if (\$search) {
            \$searchable = property_exists({$this->name}::class, 'searchable') ? (new {$this->name})->searchable : [];
            foreach (\$searchable as \$field) {
                \$query->orWhere(\$field, 'like', "%{\$search}%");
            }
        }
        \$records = \$query->paginate(\$perPage);
EOT : "\$records = {$this->name}::paginate(15);";

        $content = <<<EOT
<?php

namespace App\Http\Controllers;

use App\Models\\{$this->name};
use Illuminate\Http\Request;
use App\Helpers\ImageHelper;

class {$controllerName} extends Controller
{
    public function __construct()
    {
        \$this->middleware({$middlewareString});
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
        \$validated = \$request->validate({$this->name}::rules());
$fileHandling
        \$record = {$this->name}::create(\$validated);
        return response()->json(['data' => \$record], 201);
    }

    public function show(\$id)
    {
        \$record = {$this->name}::find(\$id);
        return \$record ? response()->json(['data' => \$record]) : response()->json(['message' => 'Not found'], 404);
    }

    public function edit(\$id)
    {
        \$record = {$this->name}::find(\$id);
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
        \$record = {$this->name}::find(\$id);
        if (!\$record) return response()->json(['message' => 'Not found'], 404);
        \$validated = \$request->validate({$this->name}::rules());
$fileUpdateHandling
        \$record->update(\$validated);
        return response()->json(['data' => \$record]);
    }

    public function destroy(\$id)
    {
        \$record = {$this->name}::find(\$id);
        if (!\$record) return response()->json(['message' => 'Not found'], 404);
        \$record->delete();
        return response()->json(['message' => 'Deleted'], 204);
    }
EOT;

        if ($softDeletes) {
            $content .= <<<EOT

    public function restore(\$id)
    {
        \$record = {$this->name}::withTrashed()->find(\$id);
        if (!\$record) return response()->json(['message' => 'Not found'], 404);
        \$record->restore();
        return response()->json(['data' => \$record]);
    }
EOT;
        }

        $content .= "\n}";

        File::put($path, $content);
        $this->command->info("\033[32m Controller created: $path \033[0m");
    }

    public function generateRoutes($version, $middleware, $softDeletes = false)
    {
        $routesPath = base_path("routes/api.php");
        $controller = "{$this->name}ApiController";
        $routePrefix = Str::plural(Str::snake($this->name));
        $middlewareString = !empty($middleware) ? "->middleware(['" . implode("', '", $middleware) . "'])" : '';

        $routeCode = <<<EOT
Route::prefix('$version')
    ->group(function () {
        Route::apiResource('/$routePrefix', \\App\\Http\\Controllers\\{$controller}::class)$middlewareString;
EOT;

if ($softDeletes) {

    $restoreRoute = "Route::post('/{$routePrefix}/{id}/restore', [\\App\\Http\\Controllers\\{$controller}::class, 'restore'])$middlewareString"
        . "->name('{$routePrefix}.restore');";
    $routeCode .= "\n" . $restoreRoute;
}

        $routeCode .= "\n    });";

        File::append($routesPath, "\n" . $routeCode . "\n");
        $this->command->info("\033[32m Routes added to: $routesPath \033[0m");
    }

    public function generateOpenApiSpec($version, $softDeletes = false, $searchEnabled = false)
    {
        $specPath = base_path("openapi/{$this->name}.json");
        $routePrefix = Str::plural(Str::snake($this->name));
        $parsedFields = $this->parseFields();

        $spec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => "{$this->name} API (Version $version)",
                'version' => $version,
                'description' => "API for managing {$this->name} resources.",
            ],
            'paths' => [
                "/$version/$routePrefix" => [
                    'get' => [
                        'summary' => "List all {$this->name}s",
                        'parameters' => array_merge(
                            $searchEnabled ? [
                                [
                                    'name' => 'search',
                                    'in' => 'query',
                                    'description' => 'Search term for string fields',
                                    'required' => false,
                                    'schema' => ['type' => 'string'],
                                ],
                            ] : [],
                            [
                                [
                                    'name' => 'per_page',
                                    'in' => 'query',
                                    'description' => 'Number of items per page',
                                    'required' => false,
                                    'schema' => ['type' => 'integer', 'default' => 15],
                                ],
                                [
                                    'name' => 'page',
                                    'in' => 'query',
                                    'description' => 'Page number',
                                    'required' => false,
                                    'schema' => ['type' => 'integer', 'default' => 1],
                                ],
                            ]
                        ),
                        'responses' => [
                            '200' => [
                                'description' => 'Successful response',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => [
                                                    'type' => 'array',
                                                    'items' => ['$ref' => "#/components/schemas/{$this->name}"],
                                                ],
                                                'meta' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'current_page' => ['type' => 'integer'],
                                                        'per_page' => ['type' => 'integer'],
                                                        'total' => ['type' => 'integer'],
                                                        'last_page' => ['type' => 'integer'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'post' => [
                        'summary' => "Create a new {$this->name}",
                        'requestBody' => [
                            'required' => true,
                            'content' => array_merge(
                                [
                                    'application/json' => [
                                        'schema' => ['$ref' => "#/components/schemas/{$this->name}"],
                                    ],
                                ],
                                array_reduce($parsedFields, function ($carry, $field) {
                                    if (in_array($field['type'], ['file', 'image', 'images'])) {
                                        $carry['multipart/form-data'] = [
                                            'schema' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    $field['name'] => [
                                                        'type' => $field['type'] === 'images' ? 'array' : 'string',
                                                        'format' => $field['type'] === 'images' ? null : 'binary',
                                                        'items' => $field['type'] === 'images' ? ['type' => 'string', 'format' => 'binary'] : null,
                                                    ],
                                                ],
                                            ],
                                        ];
                                    }
                                    return $carry;
                                }, [])
                            ),
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Created',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => ['$ref' => "#/components/schemas/{$this->name}"],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                "/$version/$routePrefix/create" => [
                    'get' => [
                        'summary' => "Get data for creating a {$this->name}",
                        'responses' => [
                            '200' => [
                                'description' => 'Successful response',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => [
                                                    'type' => 'object',
                                                    'properties' => array_merge(
                                                        [],
                                                        array_map(function ($field) use ($version) {
                                                            if ($field['type'] === 'select') {
                                                                $relatedModel = Str::studly(Str::beforeLast($field['name'], '_id'));
                                                                $varName = Str::plural(Str::camel($relatedModel));
                                                                return [
                                                                    $varName => [
                                                                        'type' => 'array',
                                                                        'items' => ['$ref' => "#/components/schemas/{$relatedModel}"],
                                                                    ],
                                                                ];
                                                            }
                                                            return [];
                                                        }, $parsedFields)
                                                    ),
                                                    'message' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                "/$version/$routePrefix/{id}" => [
                    'get' => [
                        'summary' => "Show a {$this->name}",
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'integer'],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Successful response',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => ['$ref' => "#/components/schemas/{$this->name}"],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '404' => ['description' => 'Not found'],
                        ],
                    ],
                    'put' => [
                        'summary' => "Update a {$this->name}",
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'integer'],
                            ],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => array_merge(
                                [
                                    'application/json' => [
                                        'schema' => ['$ref' => "#/components/schemas/{$this->name}"],
                                    ],
                                ],
                                array_reduce($parsedFields, function ($carry, $field) {
                                    if (in_array($field['type'], ['file', 'image', 'images'])) {
                                        $carry['multipart/form-data'] = [
                                            'schema' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    $field['name'] => [
                                                        'type' => $field['type'] === 'images' ? 'array' : 'string',
                                                        'format' => $field['type'] === 'images' ? null : 'binary',
                                                        'items' => $field['type'] === 'images' ? ['type' => 'string', 'format' => 'binary'] : null,
                                                    ],
                                                ],
                                            ],
                                        ];
                                    }
                                    return $carry;
                                }, [])
                            ),
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Updated',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => ['$ref' => "#/components/schemas/{$this->name}"],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '404' => ['description' => 'Not found'],
                        ],
                    ],
                    'delete' => [
                        'summary' => "Delete a {$this->name}",
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'integer'],
                            ],
                        ],
                        'responses' => [
                            '204' => ['description' => 'Deleted'],
                            '404' => ['description' => 'Not found'],
                        ],
                    ],
                ],
                "/$version/$routePrefix/{id}/edit" => [
                    'get' => [
                        'summary' => "Get data for editing a {$this->name}",
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'integer'],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Successful response',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => [
                                                    'type' => 'object',
                                                    'properties' => array_merge(
                                                        [
                                                            'record' => ['$ref' => "#/components/schemas/{$this->name}"],
                                                        ],
                                                        array_map(function ($field) use ($version) {
                                                            if ($field['type'] === 'select') {
                                                                $relatedModel = Str::studly(Str::beforeLast($field['name'], '_id'));
                                                                $varName = Str::plural(Str::camel($relatedModel));
                                                                return [
                                                                    $varName => [
                                                                        'type' => 'array',
                                                                        'items' => ['$ref' => "#/components/schemas/{$relatedModel}"],
                                                                    ],
                                                                ];
                                                            }
                                                            return [];
                                                        }, $parsedFields)
                                                    ),
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '404' => ['description' => 'Not found'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => array_merge(
                    [
                        $this->name => [
                            'type' => 'object',
                            'properties' => array_combine(
                                array_column($parsedFields, 'name'),
                                array_map(fn($f) => ['type' => in_array($f['type'], ['file', 'image', 'images']) ? 'string' : $f['type']], $parsedFields)
                            ),
                        ],
                    ],
                    array_reduce($parsedFields, function ($carry, $field) {
                        if ($field['type'] === 'select') {
                            $relatedModel = Str::studly(Str::beforeLast($field['name'], '_id'));
                            $carry[$relatedModel] = [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                    'name' => ['type' => 'string'],
                                ],
                            ];
                        }
                        return $carry;
                    }, [])
                ),
            ],

        ];

        if ($softDeletes) {
            $spec['paths']["/$version/$routePrefix/{id}/restore"] = [
                'post' => [
                    'summary' => "Restore a soft-deleted {$this->name}",
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'integer'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Restored',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'data' => ['$ref' => "#/components/schemas/{$this->name}"],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '404' => ['description' => 'Not found'],
                    ],
                ],
            ];
        }

        File::ensureDirectoryExists(dirname($specPath));
        File::put($specPath, json_encode($spec, JSON_PRETTY_PRINT));
        $this->command->info("\033[32m OpenAPI spec created: $specPath \033[0m");
    }
}

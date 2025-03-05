<?php

namespace Fariddomat\AutoCrud\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ControllerGenerator
{
    /**
     * Generate a controller file for the given model.
     *
     * @param string $name Model name
     * @param bool $isApi Generate API controller if true
     * @param bool $isDashboard Use dashboard namespace and routes if true
     * @param array $fields Fields array to check for relationships or special handling
     * @param object|null $command Optional console command instance for feedback
     * @param bool $withSoftDeletes Include soft delete support if true
     * @param array $middleware Optional middleware to apply to the controller
     * @return bool Success status
     */
    public static function generate($name, $isApi, $isDashboard, $fields = [], $command = null, $withSoftDeletes = false, $middleware = [])
    {
        $namespace = $isDashboard ? 'App\Http\Controllers\Dashboard' : 'App\Http\Controllers';
        $controllerPath = app_path($isDashboard ? "Http/Controllers/Dashboard/{$name}Controller.php" : "Http/Controllers/{$name}" . ($isApi ? 'ApiController' : 'Controller') . ".php");
        $routePrefix = $isDashboard ? 'dashboard.' . Str::plural(Str::snake($name)) : Str::plural(Str::snake($name));

        $controllerClassName = $name . ($isApi ? 'Api' : '') . 'Controller';

        $controllerContent = <<<EOT
<?php

namespace $namespace;

use App\Models\\$name;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Helpers\ImageHelper;

class $controllerClassName extends Controller
{
EOT;

        if (!empty($middleware)) {
            $middlewareString = implode("', '", $middleware);
            $controllerContent .= <<<EOT

    public function __construct()
    {
        \$this->middleware(['$middlewareString']);
    }
EOT;
        }

        if ($isApi) {
            $controllerContent .= self::generateApiMethods($name, $routePrefix, $fields, $withSoftDeletes);
        } else {
            $controllerContent .= self::generateWebMethods($name, $routePrefix, $fields, $withSoftDeletes);
        }

        $controllerContent .= "\n}\n";

        try {
            File::ensureDirectoryExists(dirname($controllerPath));
            File::put($controllerPath, $controllerContent);
            static::info($command, "Controller created: {$controllerPath}");
            return true;
        } catch (\Exception $e) {
            static::error($command, "Failed to create controller file: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate API controller methods with JSON error responses.
     *
     * @param string $name Model name
     * @param string $routePrefix Route prefix
     * @param array $fields Fields for relationships or special handling
     * @param bool $withSoftDeletes Include soft delete support
     * @return string
     */
    private static function generateApiMethods($name, $routePrefix, $fields, $withSoftDeletes)
    {
        $relationships = '';
        $relationshipData = '';
        foreach ($fields as $field) {
            if ($field['type'] === 'unsignedBigInteger' && Str::endsWith($field['name'], '_id')) {
                $relatedModel = Str::studly(Str::beforeLast($field['name'], '_id'));
                $varName = Str::camel($relatedModel) . 's';
                $relationships .= "\$$varName = \\App\\Models\\{$relatedModel}::all();\n        ";
                $relationshipData .= "'$varName' => \$$varName,\n            ";
            }
        }

        $content = <<<EOT

    public function index()
    {
        \$records = $name::all();
        return response()->json([
            'data' => \$records,
            'message' => 'Records retrieved successfully',
            'status' => 'success'
        ]);
    }

    public function create()
    {
        $relationships
        return response()->json([
            'data' => [
                $relationshipData
            ],
            'message' => 'Create form data retrieved successfully',
            'status' => 'success'
        ]);
    }

    public function store(Request \$request)
    {
        \$validated = \$request->validate($name::rules());

        if (\$request->hasFile('image') && class_exists('App\Helpers\ImageHelper')) {
            \$validated['image'] = ImageHelper::storeImageInPublicDirectory(\$request->file('image'), 'uploads/{$routePrefix}');
        } elseif (\$request->hasFile('image')) {
            \$validated['image'] = \$request->file('image')->store('uploads/{$routePrefix}', 'public');
        }

        if (\$request->hasFile('file')) {
            \$validated['file'] = \$request->file('file')->store('uploads/{$routePrefix}', 'public');
        }

        if (\$request->hasFile('images') && class_exists('App\Helpers\ImageHelper')) {
            \$validated['images'] = [];
            foreach (\$request->file('images') as \$image) {
                \$validated['images'][] = ImageHelper::storeImageInPublicDirectory(\$image, 'uploads/{$routePrefix}');
            }
            \$validated['images'] = json_encode(\$validated['images']);
        } elseif (\$request->hasFile('images')) {
            \$validated['images'] = json_encode(array_map(fn(\$file) => \$file->store('uploads/{$routePrefix}', 'public'), \$request->file('images')));
        }

        \$record = $name::create(\$validated);
        return response()->json([
            'data' => \$record,
            'message' => 'Record created successfully',
            'status' => 'success'
        ], 201);
    }

    public function show(\$id)
    {
        \$record = $name::find(\$id);
        if (!\$record) {
            return response()->json([
                'data' => null,
                'message' => 'Record not found',
                'status' => 'error'
            ], 404);
        }
        return response()->json([
            'data' => \$record,
            'message' => 'Record retrieved successfully',
            'status' => 'success'
        ]);
    }

    public function edit(\$id)
    {
        \$record = $name::find(\$id);
        if (!\$record) {
            return response()->json([
                'data' => null,
                'message' => 'Record not found',
                'status' => 'error'
            ], 404);
        }
        $relationships
        return response()->json([
            'data' => [
                'record' => \$record,
                $relationshipData
            ],
            'message' => 'Edit form data retrieved successfully',
            'status' => 'success'
        ]);
    }

    public function update(Request \$request, \$id)
    {
        \$record = $name::find(\$id);
        if (!\$record) {
            return response()->json([
                'data' => null,
                'message' => 'Record not found',
                'status' => 'error'
            ], 404);
        }
        \$validated = \$request->validate($name::rules());

        if (\$request->hasFile('image') && class_exists('App\Helpers\ImageHelper')) {
            if (\$record->image) ImageHelper::removeImageInPublicDirectory(\$record->image);
            \$validated['image'] = ImageHelper::storeImageInPublicDirectory(\$request->file('image'), 'uploads/{$routePrefix}');
        } elseif (\$request->hasFile('image')) {
            \$validated['image'] = \$request->file('image')->store('uploads/{$routePrefix}', 'public');
        }

        if (\$request->hasFile('file')) {
            \$validated['file'] = \$request->file('file')->store('uploads/{$routePrefix}', 'public');
        }

        if (\$request->hasFile('images') && class_exists('App\Helpers\ImageHelper')) {
            if (\$record->images) {
                foreach (json_decode(\$record->images, true) as \$oldImage) {
                    ImageHelper::removeImageInPublicDirectory(\$oldImage);
                }
            }
            \$validated['images'] = [];
            foreach (\$request->file('images') as \$image) {
                \$validated['images'][] = ImageHelper::storeImageInPublicDirectory(\$image, 'uploads/{$routePrefix}');
            }
            \$validated['images'] = json_encode(\$validated['images']);
        } elseif (\$request->hasFile('images')) {
            \$validated['images'] = json_encode(array_map(fn(\$file) => \$file->store('uploads/{$routePrefix}', 'public'), \$request->file('images')));
        }

        \$record->update(\$validated);
        return response()->json([
            'data' => \$record,
            'message' => 'Record updated successfully',
            'status' => 'success'
        ]);
    }

    public function destroy(\$id)
    {
        \$record = $name::find(\$id);
        if (!\$record) {
            return response()->json([
                'data' => null,
                'message' => 'Record not found',
                'status' => 'error'
            ], 404);
        }
        if (\$record->image && class_exists('App\Helpers\ImageHelper')) {
            ImageHelper::removeImageInPublicDirectory(\$record->image);
        }
        if (\$record->images && class_exists('App\Helpers\ImageHelper')) {
            foreach (json_decode(\$record->images, true) as \$image) {
                ImageHelper::removeImageInPublicDirectory(\$image);
            }
        }
EOT;

        if ($withSoftDeletes) {
            $content .= <<<EOT
        \$record->delete();
        return response()->json([
            'data' => null,
            'message' => 'Record soft-deleted successfully',
            'status' => 'success'
        ]);
    }

    public function restore(\$id)
    {
        \$record = $name::withTrashed()->find(\$id);
        if (!\$record) {
            return response()->json([
                'data' => null,
                'message' => 'Record not found',
                'status' => 'error'
            ], 404);
        }
        \$record->restore();
        return response()->json([
            'data' => \$record,
            'message' => 'Record restored successfully',
            'status' => 'success'
        ]);
        }
EOT;
        } else {
            $content .= <<<EOT
        \$record->delete();
        return response()->json([
            'data' => null,
            'message' => 'Record deleted successfully',
            'status' => 'success'
        ]);
        }
EOT;
        }

        return $content;
    }

    /**
     * Generate web controller methods (unchanged).
     *
     * @param string $name Model name
     * @param string $routePrefix Route prefix
     * @param array $fields Fields for relationships or special handling
     * @param bool $withSoftDeletes Include soft delete support
     * @return string
     */
    private static function generateWebMethods($name, $routePrefix, $fields, $withSoftDeletes)
    {
        $relationships = '';
        $createCompactArgs = [];
        $editCompactArgs = ['record'];
        foreach ($fields as $field) {
            if ($field['type'] === 'unsignedBigInteger' && Str::endsWith($field['name'], '_id')) {
                $relatedModel = Str::studly(Str::beforeLast($field['name'], '_id'));
                $varName = Str::camel($relatedModel) . 's';
                $relationships .= "\$$varName = \\App\\Models\\{$relatedModel}::all();\n        ";
                $createCompactArgs[] = $varName;
                $editCompactArgs[] = $varName;
            }
        }
        $createCompactString = !empty($createCompactArgs) ? "compact('" . implode("', '", $createCompactArgs) . "')" : '[]';
        $editCompactString = !empty($editCompactArgs) ? "compact('" . implode("', '", $editCompactArgs) . "')" : "compact('record')";

        $content = <<<EOT

    public function index()
    {
        \$records = $name::all();
        return view('{$routePrefix}.index', compact('records'));
    }

    public function create()
    {
        $relationships
        return view('{$routePrefix}.create', $createCompactString);
    }

    public function store(Request \$request)
    {
        \$validated = \$request->validate($name::rules());

        if (\$request->hasFile('image') && class_exists('App\Helpers\ImageHelper')) {
            \$validated['image'] = ImageHelper::storeImageInPublicDirectory(\$request->file('image'), 'uploads/{$routePrefix}');
        } elseif (\$request->hasFile('image')) {
            \$validated['image'] = \$request->file('image')->store('uploads/{$routePrefix}', 'public');
        }

        if (\$request->hasFile('file')) {
            \$validated['file'] = \$request->file('file')->store('uploads/{$routePrefix}', 'public');
        }

        if (\$request->hasFile('images') && class_exists('App\Helpers\ImageHelper')) {
            \$validated['images'] = [];
            foreach (\$request->file('images') as \$image) {
                \$validated['images'][] = ImageHelper::storeImageInPublicDirectory(\$image, 'uploads/{$routePrefix}');
            }
            \$validated['images'] = json_encode(\$validated['images']);
        } elseif (\$request->hasFile('images')) {
            \$validated['images'] = json_encode(array_map(fn(\$file) => \$file->store('uploads/{$routePrefix}', 'public'), \$request->file('images')));
        }

        $name::create(\$validated);
        return redirect()->route('{$routePrefix}.index')->with('success', 'تم الإضافة بنجاح');
    }

    public function edit(\$id)
    {
        \$record = $name::findOrFail(\$id);
        $relationships
        return view('{$routePrefix}.edit', $editCompactString);
    }

    public function update(Request \$request, \$id)
    {
        \$record = $name::findOrFail(\$id);
        \$validated = \$request->validate($name::rules());

        if (\$request->hasFile('image') && class_exists('App\Helpers\ImageHelper')) {
            if (\$record->image) ImageHelper::removeImageInPublicDirectory(\$record->image);
            \$validated['image'] = ImageHelper::storeImageInPublicDirectory(\$request->file('image'), 'uploads/{$routePrefix}');
        } elseif (\$request->hasFile('image')) {
            \$validated['image'] = \$request->file('image')->store('uploads/{$routePrefix}', 'public');
        }

        if (\$request->hasFile('file')) {
            \$validated['file'] = \$request->file('file')->store('uploads/{$routePrefix}', 'public');
        }

        if (\$request->hasFile('images') && class_exists('App\Helpers\ImageHelper')) {
            if (\$record->images) {
                foreach (json_decode(\$record->images, true) as \$oldImage) {
                    ImageHelper::removeImageInPublicDirectory(\$oldImage);
                }
            }
            \$validated['images'] = [];
            foreach (\$request->file('images') as \$image) {
                \$validated['images'][] = ImageHelper::storeImageInPublicDirectory(\$image, 'uploads/{$routePrefix}');
            }
            \$validated['images'] = json_encode(\$validated['images']);
        } elseif (\$request->hasFile('images')) {
            \$validated['images'] = json_encode(array_map(fn(\$file) => \$file->store('uploads/{$routePrefix}', 'public'), \$request->file('images')));
        }

        \$record->update(\$validated);
        return redirect()->route('{$routePrefix}.index')->with('success', 'تم التحديث بنجاح');
    }

    public function destroy(\$id)
    {
        \$record = $name::findOrFail(\$id);
        if (\$record->image && class_exists('App\Helpers\ImageHelper')) {
            ImageHelper::removeImageInPublicDirectory(\$record->image);
        }
        if (\$record->images && class_exists('App\Helpers\ImageHelper')) {
            foreach (json_decode(\$record->images, true) as \$image) {
                ImageHelper::removeImageInPublicDirectory(\$image);
            }
        }
EOT;

        if ($withSoftDeletes) {
            $content .= <<<EOT
        \$record->delete();
        return redirect()->route('{$routePrefix}.index')->with('success', 'تم الحذف بنجاح');
    }

    public function restore(\$id)
    {
        \$record = $name::withTrashed()->findOrFail(\$id);
        \$record->restore();
        return redirect()->route('{$routePrefix}.index')->with('success', 'تم الاستعادة بنجاح');
        }
EOT;
        } else {
            $content .= <<<EOT
        \$record->delete();
        return redirect()->route('{$routePrefix}.index')->with('success', 'تم الحذف بنجاح');
        }
EOT;
        }

        return $content;
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

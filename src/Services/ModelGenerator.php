<?php

namespace Fariddomat\AutoGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ModelGenerator
{
    public static function generate($name, $parsedFields, $command, $softDeletes = false, $searchEnabled = false, $searchableFields = [])
    {
        $path = app_path("Models/{$name}.php");

        if (File::exists($path)) {
            $command->warn("\033[33m Model '{$name}' already exists at $path. Skipping. \033[0m");
            return false;
        }

        $uses = "use Illuminate\Database\Eloquent\Model;";
        $traits = "";
        if ($softDeletes) {
            $uses .= "\nuse Illuminate\Database\Eloquent\SoftDeletes;";
            $traits .= "\n    use SoftDeletes;";
        }

        // Fillable fields (exclude belongsToMany)
        $fillable = [];
        foreach ($parsedFields as $field) {
            if ($field['original_type'] !== 'belongsToMany') {
                $fillable[] = "'{$field['name']}'";
            }
        }
        $fillableString = implode(', ', $fillable);

        // Validation rules
        $rules = self::generateRules($parsedFields);

        // Searchable fields
        $searchable = "";
        if ($searchEnabled) {
            $searchFields = !empty($searchableFields)
                ? $searchableFields
                : array_map(fn($f) => $f['name'], array_filter($parsedFields, fn($f) => in_array($f['original_type'], ['string', 'text'])));
            $searchable = "\n    protected \$searchable = ['" . implode("', '", $searchFields) . "'];";
        }

        // Relationships
        $relationships = "";
        foreach ($parsedFields as $field) {
            if (in_array($field['original_type'], ['belongsTo', 'select'])) {
                $relatedModel = Str::studly(Str::beforeLast($field['name'], '_id'));
                $relationships .= <<<EOT

    public function {$relatedModel}()
    {
        return \$this->belongsTo(\\App\\Models\\{$relatedModel}::class, '{$field['name']}');
    }
EOT;
            } elseif ($field['original_type'] === 'belongsToMany') {
                $relatedModel = Str::studly($field['name']); // e.g., 'Roles'
                $relatedModelSingular = Str::singular($relatedModel); // e.g., 'Role'
                $pivotTable = Str::snake($name) . '_' . Str::snake($field['name']);
                $nameSnake = Str::snake($name);
                $fieldSingular = Str::singular($field['name']);
                $relationships .= <<<EOT

    public function {$field['name']}()
    {
        return \$this->belongsToMany(\\App\\Models\\{$relatedModelSingular}::class, '{$pivotTable}', '{$nameSnake}_id', '{$fieldSingular}_id');
    }
EOT;
            }
        }

        // Model content
        $content = <<<EOT
<?php

namespace App\Models;

$uses

class {$name} extends Model
{
    $traits

    protected \$fillable = [$fillableString];

    public static function rules()
    {
        return [
            $rules
        ];
    }
$searchable$relationships
}
EOT;

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
        $command->info("\033[32m Model created: $path \033[0m");

        return true;
    }

    protected static function generateRules($parsedFields)
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
                case 'text':
                    $rules[] = "'$name' => '$rule|string'";
                    break;
                case 'select':
                case 'belongsTo':
                    $tableName = Str::snake(Str::plural(Str::beforeLast($name, '_id')));
                    $rules[] = "'$name' => '$rule|exists:$tableName,id'";
                    break;
                case 'belongsToMany':
                    $tableName = Str::snake($field['name']); // e.g., 'roles'
                    $rules[] = "'$name' => '$rule|array'";
                    $rules[] = "'$name.*' => 'exists:$tableName,id'";
                    break;
                case 'boolean':
                    $rules[] = "'$name' => 'sometimes|boolean'";
                    break;
                case 'file':
                    $rules[] = "'$name' => '$rule|file|max:2048'";
                    break;
                case 'image':
                    $rules[] = "'$name' => '$rule|image|mimes:jpeg,png,jpg,gif|max:2048'";
                    break;
                case 'images':
                    $rules[] = "'$name' => '$rule|array'";
                    $rules[] = "'$name.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048'";
                    break;
                case 'date':
                case 'datetime':
                    $rules[] = "'$name' => '$rule|date'";
                    break;
                case 'enum':
                    $values = implode(',', $field['modifiers']);
                    $rules[] = "'$name' => '$rule|in:$values'";
                    break;
                case 'json':
                    $rules[] = "'$name' => '$rule|json'";
                    break;
            }
        }
        return implode(",\n            ", $rules);
    }
}

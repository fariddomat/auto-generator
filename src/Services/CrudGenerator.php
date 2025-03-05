<?php

namespace Fariddomat\AutoCrud\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CrudGenerator
{
    protected $name;
    protected $fields;
    protected $command; // To allow interaction with the console command

    public function __construct($name, $fields, $command = null)
    {
        $this->name = Str::studly($name);
        $this->fields = $fields;
        $this->command = $command; // Optional command instance for feedback
    }

    /**
     * Parse the fields argument and generate the validation rules.
     *
     * @return string
     */
    public function generateRules()
    {
        $rules = [];

        foreach ($this->fields as $field) {
            $parts = explode(':', $field);
            if (empty($parts[0])) {
                $this->warn("Skipping malformed field: '$field'. Expected format: 'name:type:modifiers'");
                continue;
            }

            $name = $parts[0];
            $type = $parts[1] ?? 'string';
            $modifiers = array_slice($parts, 2); // Additional modifiers like 'nullable', 'unique'

            // Generate validation rules based on field types
            $rule = "'$name' => '";
            $isNullable = in_array('nullable', $modifiers) ? 'nullable' : 'required';

            switch ($type) {
                case 'string':
                    $rule .= "$isNullable|string|max:255";
                    break;

                case 'decimal':
                case 'integer':
                    $rule .= "$isNullable|numeric";
                    break;

                case 'text':
                    $rule .= "nullable|string";
                    break;

                case 'select':
                    $tableName = Str::snake(Str::plural(Str::beforeLast($name, '_id')));
                    $rule .= "$isNullable|exists:$tableName,id";
                    break;

                case 'boolean':
                    $rule .= "sometimes|boolean";
                    break;

                case 'file':
                    $rule .= "sometimes|file|max:2048";
                    break;

                case 'image':
                    $rule .= "sometimes|image|mimes:jpeg,png,jpg,gif|max:2048";
                    break;

                case 'images':
                    $rules[] = "'$name' => 'sometimes|array',";
                    $rules[] = "'$name.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',";
                    continue 2; // Skip adding to $rules directly

                default:
                    $this->warn("Unknown field type '$type' for '$name'. Defaulting to string.");
                    $rule .= "$isNullable|string";
                    break;
            }

            // Add custom modifiers like 'unique'
            if (in_array('unique', $modifiers)) {
                $tableName = $this->getTableName();
                $rule .= "|unique:$tableName,$name";
            }

            $rules[] = "$rule',";
        }

        return implode("\n            ", $rules);
    }

    /**
     * Generate the model file along with the rules method and optional relationships.
     *
     * @param bool $force Overwrite existing model file if true
     * @param bool $withSoftDeletes Include soft deletes if true
     * @return bool Success status
     */
    public function generateModel($force = false, $withSoftDeletes = false)
    {
        $modelPath = app_path("Models/{$this->name}.php");

        if (file_exists($modelPath) && !$force) {
            $this->warn("Model '{$this->name}' already exists at $modelPath. Use --force to overwrite.");
            return false;
        }

        $modelContent = "<?php\n\nnamespace App\Models;\n\nuse Illuminate\Database\Eloquent\Model;\n";
        if ($withSoftDeletes) {
            $modelContent .= "use Illuminate\Database\Eloquent\SoftDeletes;\n";
        }
        $modelContent .= "\nclass {$this->name} extends Model\n{\n";
        if ($withSoftDeletes) {
            $modelContent .= "    use SoftDeletes;\n\n";
        }

        // Generate fillable property
        $fillableFields = array_map(fn($field) => "'{$field['name']}'", $this->parseFields());
        $modelContent .= "    protected \$fillable = [" . implode(", ", $fillableFields) . "];\n\n";

        // Add rules method
        $modelContent .= "    public static function rules()\n    {\n";
        $modelContent .= "        return [\n            " . $this->generateRules() . "\n        ];\n";
        $modelContent .= "    }\n\n";

        // Add relationships for 'select' fields
        $relationships = $this->generateRelationships();
        if ($relationships) {
            $modelContent .= $relationships;
        }

        $modelContent .= "}\n";

        // Write to the model file with error handling
        try {
            File::ensureDirectoryExists(dirname($modelPath));
            File::put($modelPath, $modelContent);
            $this->info("Model created: $modelPath");
            return true;
        } catch (\Exception $e) {
            $this->error("Failed to create model file: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Parse fields into an array of name, type, and modifiers.
     *
     * @return array
     */
    public function parseFields()
    {
        $parsed = [];
        foreach ($this->fields as $field) {
            $parts = explode(':', $field);
            if (empty($parts[0])) {
                $this->warn("Skipping malformed field: '$field'. Expected format: 'name:type:modifiers'");
                continue;
            }

            $name = $parts[0];
            $type = $parts[1] ?? 'string';
            $modifiers = array_slice($parts, 2);

            // Adjust type for migration
            $migrationType = $type;
            if ($type === 'select') {
                $migrationType = 'unsignedBigInteger';
            } elseif (in_array($type, ['file', 'image', 'images'])) {
                $migrationType = 'string';
            }

            $parsed[] = [
                'name' => $name,
                'type' => $migrationType,
                'modifiers' => $modifiers,
            ];
        }
        return $parsed;
    }

    /**
     * Parse fields for view generation (no type adjustment needed).
     *
     * @return array
     */
    public function viewParseFields()
    {
        $parsed = [];
        foreach ($this->fields as $field) {
            $parts = explode(':', $field);
            if (empty($parts[0])) {
                $this->warn("Skipping malformed field: '$field'. Expected format: 'name:type:modifiers'");
                continue;
            }

            $name = $parts[0];
            $type = $parts[1] ?? 'string';
            $parsed[] = ['name' => $name, 'type' => $type];
        }
        return $parsed;
    }

    /**
     * Generate relationship methods for 'select' fields.
     *
     * @return string
     */
    protected function generateRelationships()
    {
        $relationships = '';
        foreach ($this->parseFields() as $field) {
            if ($field['type'] === 'unsignedBigInteger' && Str::endsWith($field['name'], '_id')) {
                $relatedModel = Str::studly(Str::beforeLast($field['name'], '_id'));
                $relationships .= "    public function " . Str::camel($relatedModel) . "()\n    {\n";
                $relationships .= "        return \$this->belongsTo(" . $relatedModel . "::class);\n";
                $relationships .= "    }\n\n";
            }
        }
        return $relationships;
    }

    /**
     * Get the table name for the model.
     *
     * @return string
     */
    public function getTableName()
    {
        return Str::snake(Str::plural($this->name));
    }

    /**
     * Helper method to output info messages to the console.
     *
     * @param string $message
     */
    protected function info($message)
    {
        if ($this->command) {
            $this->command->info($message);
        }
    }

    /**
     * Helper method to output warning messages to the console.
     *
     * @param string $message
     */
    protected function warn($message)
    {
        if ($this->command) {
            $this->command->warn($message);
        }
    }

    /**
     * Helper method to output error messages to the console.
     *
     * @param string $message
     */
    protected function error($message)
    {
        if ($this->command) {
            $this->command->error($message);
        }
    }
}

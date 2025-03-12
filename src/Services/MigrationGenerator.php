<?php

namespace Fariddomat\AutoGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MigrationGenerator
{
    public static function generate($name, $tableName, $parsedFields, $command = null, $withSoftDeletes = false)
    {
        $migrationFileName = database_path('migrations/' . date('Y_m_d_His') . "_create_{$tableName}_table.php");
        $migrationContent = self::getTemplate($tableName, $parsedFields, $withSoftDeletes);

        try {
            File::ensureDirectoryExists(dirname($migrationFileName));
            File::put($migrationFileName, $migrationContent);
            static::info($command, "Migration created: {$migrationFileName}");

            // Generate pivot table migrations for belongsToMany fields
            self::generatePivotTableMigrations($name, $tableName, $parsedFields, $command);

            return true;
        } catch (\Exception $e) {
            static::error($command, "Failed to create migration file: " . $e->getMessage());
            return false;
        }
    }

    private static function getTemplate($tableName, $parsedFields, $withSoftDeletes)
    {
        $fields = "";
        foreach ($parsedFields as $field) {
            // Skip belongsToMany fields in the main table as they use a pivot table
            if ($field['original_type'] === 'belongsToMany') {
                continue;
            }

            $fieldDefinition = "\$table->{$field['type']}('{$field['name']}')";

            if (in_array('nullable', $field['modifiers'])) {
                $fieldDefinition .= "->nullable()";
            }
            if (in_array('unique', $field['modifiers'])) {
                $fieldDefinition .= "->unique()";
            }
            if ($field['type'] === 'unsignedBigInteger' && Str::endsWith($field['name'], '_id')) {
                $relatedTable = Str::snake(Str::plural(Str::beforeLast($field['name'], '_id')));
                $fieldDefinition = "\$table->foreignId('{$field['name']}')->constrained('$relatedTable')->onDelete('cascade')";
            }

            $fields .= "            $fieldDefinition;\n";
        }

        $softDeletes = $withSoftDeletes ? "            \$table->softDeletes();\n" : "";

        return <<<EOT
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('$tableName', function (Blueprint \$table) {
            \$table->id();
$fields$softDeletes            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('$tableName');
    }
};
EOT;
    }

    private static function generatePivotTableMigrations($name, $tableName, $parsedFields, $command)
    {
        foreach ($parsedFields as $field) {
            if ($field['original_type'] === 'belongsToMany') {
                $relatedModel = Str::studly($field['name']); // e.g., 'Roles'
                $relatedTable = Str::snake($field['name']); // e.g., 'roles'
                $relatedTableSingular =  Str::singular($relatedTable); // e.g., 'roles'
                $tableNameSingular = Str::singular($tableName); // e.g., 'roles'
                $pivotTable = Str::snake($name) . '_' . $relatedTable; // e.g., 'raed_roles'
                $timestamp = date('Y_m_d_His', time() + count($parsedFields)); // Offset timestamp to avoid conflicts
                $pivotMigrationFileName = database_path("migrations/{$timestamp}_create_{$pivotTable}_table.php");

                $pivotContent = <<<EOT
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('$pivotTable', function (Blueprint \$table) {
            \$table->id();
            \$table->foreignId('{$tableNameSingular}_id')->constrained('$tableName')->onDelete('cascade');
            \$table->foreignId('{$relatedTableSingular}_id')->constrained('$relatedTable')->onDelete('cascade');
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('$pivotTable');
    }
};
EOT;

                try {
                    File::put($pivotMigrationFileName, $pivotContent);
                    static::info($command, "Pivot table migration created: {$pivotMigrationFileName}");
                } catch (\Exception $e) {
                    static::error($command, "Failed to create pivot table migration: " . $e->getMessage());
                }
            }
        }
    }

    protected static function info($command, $message)
    {
        if ($command) {
            $command->info("\033[32m $message \033[0m");
        } else {
            echo "\033[32m $message \033[0m\n";
        }
    }

    protected static function error($command, $message)
    {
        if ($command) {
            $command->error($message);
        } else {
            echo "\033[31m $message \033[0m\n";
        }
    }
}

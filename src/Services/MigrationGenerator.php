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
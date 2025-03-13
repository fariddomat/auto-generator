<?php

namespace Fariddomat\AutoGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ViewGenerator
{
    public static function generateBladeViews($name, $isDashboard, $parsedFields)
    {
        $folderName = $isDashboard
            ? "dashboard." . Str::plural(Str::snake($name))
            : Str::plural(Str::snake($name));
        $basePath = resource_path(
            "views/" .
            ($isDashboard
                ? "dashboard/" . Str::plural(Str::snake($name))
                : Str::plural(Str::snake($name)))
        );
        $pluralVariable = Str::plural(Str::camel($name));
        $variableName = Str::camel($name);

        File::ensureDirectoryExists($basePath);

        File::put("$basePath/create.blade.php", self::generateCreateBlade($folderName, $parsedFields));
        File::put("$basePath/edit.blade.php", self::generateEditBlade($folderName, $parsedFields, $variableName));
        File::put("$basePath/show.blade.php", self::generateShowBlade($folderName, $parsedFields, $variableName));

        $columns = implode("', '", array_map(fn($f) => $f['name'], $parsedFields));
        $indexView = <<<EOT
<x-app-layout>
    <div class="container mx-auto p-6">
        <h1 class="text-2xl font-bold mb-4">$name</h1>
        <a href="{{ route('{$folderName}.create') }}" class="px-4 py-2 bg-blue-500 text-white rounded shadow" wire:navigate>➕ @lang('site.add') $name</a>

        <div class="overflow-x-auto mt-4">
            <x-autocrud::table
                :columns="['id', '$columns']"
                :data="\${$pluralVariable}"
                routePrefix="{$folderName}"
                :show="true"
                :edit="true"
                :delete="true"
                :restore="true"
            />
        </div>
    </div>
</x-app-layout>
EOT;

        File::put("$basePath/index.blade.php", $indexView);
        echo "\033[32m Views created in: {$basePath} \033[0m\n";
    }

    public static function generateCreateBlade($folderName, $parsedFields)
    {
        $formFields = self::generateFormFields($parsedFields);
        return <<<EOT
<x-app-layout>
    <div class="container mx-auto p-6">
        <h1 class="text-2xl font-bold mb-4">
            @lang('site.create') @lang('site.{$folderName}')
        </h1>

        <form action="{{ route('{$folderName}.store') }}" method="POST" class="bg-white p-6 rounded-lg shadow-md" enctype="multipart/form-data">
            @csrf
            $formFields
            <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded shadow hover:bg-blue-700">
                @lang('site.create')
            </button>
        </form>
    </div>
</x-app-layout>
EOT;
    }

    public static function generateEditBlade($folderName, $parsedFields, $variableName)
    {
        $formFields = self::generateFormFields($parsedFields, true, $variableName);
        return <<<EOT
<x-app-layout>
    <div class="container mx-auto p-6">
        <h1 class="text-2xl font-bold mb-4">
            @lang('site.edit') @lang('site.{$folderName}')
        </h1>

        <form action="{{ route('{$folderName}.update', \${$variableName}->id) }}" method="POST" class="bg-white p-6 rounded-lg shadow-md" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            $formFields
            <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded shadow hover:bg-blue-700">
                @lang('site.update')
            </button>
        </form>
    </div>
</x-app-layout>
EOT;
    }

    public static function generateFormFields($parsedFields, $isEdit = false, $variableName = null)
    {
        $output = "";
        foreach ($parsedFields as $field) {
            $name = $field['name'];
            $type = $field['original_type'];
            $value = $isEdit ? "{{ old('$name', \${$variableName}->$name) }}" : "{{ old('$name') }}";

            switch ($type) {
                case 'string':
                case 'text':
                    $inputType = $type === 'string' ? 'text' : 'textarea';
                    $inputElement = $inputType === 'text'
                        ? "<input type=\"text\" name=\"$name\" value=\"$value\" class=\"w-full border border-gray-300 rounded p-2\">"
                        : "<textarea name=\"$name\" class=\"w-full border border-gray-300 rounded p-2\">$value</textarea>";
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                $inputElement
                @error('$name')
                    <span class="text-red-500 text-sm">{{ \$message }}</span>
                @enderror
            </div>
EOT;
                    break;
                case 'decimal':
                case 'integer':
                    $step = $type === 'decimal' ? 'step="0.01"' : '';
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <input type="number" name="$name" value="$value" class="w-full border border-gray-300 rounded p-2" $step>
                @error('$name')
                    <span class="text-red-500 text-sm">{{ \$message }}</span>
                @enderror
            </div>
EOT;
                    break;
                case 'select':
                case 'belongsTo':
                    $relatedModelVar = Str::plural(Str::camel(Str::beforeLast($name, '_id')));
                    $selected = $isEdit
                        ? "{{ \${$variableName}->$name == \$option->id ? 'selected' : '' }}"
                        : "{{ old('$name') == \$option->id ? 'selected' : '' }}";
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <select name="$name" class="w-full border border-gray-300 rounded p-2">
                    <option value="">@lang('site.select_$name')</option>
                    @foreach (\$$relatedModelVar as \$option)
                        <option value="{{ \$option->id }}" $selected>{{ \$option->name }}</option>
                    @endforeach
                </select>
                @error('$name')
                    <span class="text-red-500 text-sm">{{ \$message }}</span>
                @enderror
            </div>
EOT;
                    break;
                case 'belongsToMany':
                    $relatedModelVar = Str::plural(Str::camel($name)); // e.g., 'roles'
                    $selected = $isEdit
                        ? "{{ \${$variableName}->$name instanceof \\Illuminate\\Database\\Eloquent\\Collection && \${$variableName}->{$name}->pluck('id')->contains(\$option->id) ? 'selected' : '' }}"
                        : "{{ in_array(\$option->id, old('$name', [])) ? 'selected' : '' }}";
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <select name="{$name}[]" multiple class="w-full border border-gray-300 rounded p-2">
                    @foreach (\$$relatedModelVar as \$option)
                        <option value="{{ \$option->id }}" $selected>{{ \$option->name }}</option>
                    @endforeach
                </select>
                @error('$name')
                    <span class="text-red-500 text-sm">{{ \$message }}</span>
                @enderror
            </div>
EOT;
                    break;
                case 'boolean':
                    $checked = $isEdit ? "{{ \${$variableName}->$name ? 'checked' : '' }}" : "";
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="flex items-center">
                    <input type="checkbox" name="$name" value="1" class="mr-2" $checked>
                    @lang('site.$name')
                </label>
                @error('$name')
                    <span class="text-red-500 text-sm">{{ \$message }}</span>
                @enderror
            </div>
EOT;
                    break;
                case 'file':
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <input type="file" name="$name" class="w-full border border-gray-300 rounded p-2">
EOT;
                    if ($isEdit) {
                        $output .= <<<EOT
                @isset(\${$variableName}->$name)
                    <p class="mt-2">
                        <a href="{{ Storage::url(\${$variableName}->$name) }}" target="_blank" class="text-blue-500">@lang('site.view_file')</a>
                    </p>
                @endisset
EOT;
                    }
                    $output .= <<<EOT
                @error('$name')
                    <span class="text-red-500 text-sm">{{ \$message }}</span>
                @enderror
            </div>
EOT;
                    break;
                case 'image':
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <input type="file" name="$name" accept="image/*" class="w-full border border-gray-300 rounded p-2">
EOT;
                    if ($isEdit) {
                        $output .= <<<EOT
                @isset(\${$variableName}->$name)
                    <img src="{{ Storage::url(\${$variableName}->$name) }}" alt="$name" class="mt-2 w-32 h-32 rounded">
                @endisset
EOT;
                    }
                    $output .= <<<EOT
                @error('$name')
                    <span class="text-red-500 text-sm">{{ \$message }}</span>
                @enderror
            </div>
EOT;
                    break;
                case 'images':
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <input type="file" name="{$name}[]" accept="image/*" multiple class="w-full border border-gray-300 rounded p-2">
EOT;
                    if ($isEdit) {
                        $output .= <<<EOT
                @if(!empty(\${$variableName}->$name))
                    <div class="mt-2 flex flex-wrap">
                        @foreach(json_decode(\${$variableName}->$name, true) ?? [] as \$image)
                            <img src="{{ Storage::url(\$image) }}" alt="$name" class="w-16 h-16 mr-2 rounded">
                        @endforeach
                    </div>
                @endif
EOT;
                    }
                    $output .= <<<EOT
                @error('$name')
                    <span class="text-red-500 text-sm">{{ \$message }}</span>
                @enderror
            </div>
EOT;
                    break;
                case 'date':
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <input type="date" name="$name" value="$value" class="w-full border border-gray-300 rounded p-2">
                @error('$name')
                    <span class="text-red-500 text-sm">{{ \$message }}</span>
                @enderror
            </div>
EOT;
                    break;
                case 'datetime':
                    $value = $isEdit ? "{{ old('$name', \${$variableName}->$name ? \${$variableName}->{$name}->format('Y-m-d\\TH:i') : '') }}" : "{{ old('$name') }}";
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <input type="datetime-local" name="$name" value="$value" class="w-full border border-gray-300 rounded p-2">
                @error('$name')
                    <span class="text-red-500 text-sm">{{ \$message }}</span>
                @enderror
            </div>
EOT;
                    break;
                case 'enum':
                    // Handle $field['modifiers'] as either an array or a comma-separated string
                    $enumValues = implode(',', $field['modifiers']);
                    $enumValues = explode(',', $enumValues);
                    $options = '';
                    foreach ($enumValues as $option) {
                        $option = trim($option); // Clean up any whitespace
                        $selected = $isEdit
                            ? "{{ old('$name', \${$variableName}->$name) == '$option' ? 'selected' : '' }}"
                            : "{{ old('$name') == '$option' ? 'selected' : '' }}";
                        $options .= "                    <option value=\"$option\" $selected>$option</option>\n";
                    }
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <select name="$name" class="w-full border border-gray-300 rounded p-2">
                    <option value="">@lang('site.select_$name')</option>
$options
                </select>
                @error('$name')
                    <span class="text-red-500 text-sm">{{ \$message }}</span>
                @enderror
            </div>
EOT;
                    break;
                case 'json':
                    $value = $isEdit ? "{{ old('$name', json_encode(\${$variableName}->$name, JSON_PRETTY_PRINT)) }}" : "{{ old('$name') }}";
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <textarea name="$name" class="w-full border border-gray-300 rounded p-2" placeholder="Enter JSON data">$value</textarea>
                @error('$name')
                    <span class="text-red-500 text-sm">{{ \$message }}</span>
                @enderror
            </div>
EOT;
                    break;
            }
        }
        return $output;
    }

    public static function generateShowBlade($folderName, $parsedFields, $variableName)
    {
        $displayFields = self::generateDisplayFields($parsedFields, $variableName);
        return <<<EOT
<x-app-layout>
    <div class="container mx-auto p-6">
        <h1 class="text-2xl font-bold mb-4">
            @lang('site.show') @lang('site.{$folderName}')
        </h1>

        <div class="bg-white p-6 rounded-lg shadow-md">
            $displayFields
            <a href="{{ route('{$folderName}.index') }}" class="mt-4 inline-block px-4 py-2 bg-gray-500 text-white rounded shadow hover:bg-gray-700">
                @lang('site.back')
            </a>
        </div>
    </div>
</x-app-layout>
EOT;
    }

    public static function generateDisplayFields($parsedFields, $variableName)
    {
        $output = "";
        foreach ($parsedFields as $field) {
            $name = $field['name'];
            $type = $field['original_type'];

            switch ($type) {
                case 'string':
                case 'text':
                case 'decimal':
                case 'integer':
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <p class="text-gray-900">{{ \${$variableName}->$name ?? '—' }}</p>
            </div>
EOT;
                    break;
                case 'select':
                case 'belongsTo':
                    $relatedModel = Str::camel(Str::beforeLast($name, '_id'));
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <p class="text-gray-900">
                    @isset(\${$variableName}->$relatedModel)
                        {{ \${$variableName}->{$relatedModel}->name ?? '—' }}
                    @else
                        {{ \${$variableName}->$name ?? '—' }}
                    @endisset
                </p>
            </div>
EOT;
                    break;
                case 'belongsToMany':
                    $relatedModel = Str::camel($name);
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <p class="text-gray-900">
                    @if(\${$variableName}->$name instanceof \\Illuminate\\Database\\Eloquent\\Collection && \${$variableName}->{$name}->isNotEmpty())
                        {{ \${$variableName}->{$name}->pluck('name')->implode(', ') }}
                    @else
                        —
                    @endif
                </p>
            </div>
EOT;
                    break;
                case 'boolean':
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <p class="text-gray-900">{{ \${$variableName}->$name ? 'Yes' : 'No' }}</p>
            </div>
EOT;
                    break;
                case 'file':
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                @isset(\${$variableName}->$name)
                    <p class="text-gray-900">
                        <a href="{{ Storage::url(\${$variableName}->$name) }}" target="_blank" class="text-blue-500 hover:underline">@lang('site.view_file')</a>
                    </p>
                @else
                    <p class="text-gray-900">—</p>
                @endisset
            </div>
EOT;
                    break;
                case 'image':
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                @isset(\${$variableName}->$name)
                    <img src="{{ Storage::url(\${$variableName}->$name) }}" alt="$name" class="mt-2 w-48 h-48 rounded">
                @else
                    <p class="text-gray-900">—</p>
                @endisset
            </div>
EOT;
                    break;
                case 'images':
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                @if(!empty(\${$variableName}->$name))
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach(json_decode(\${$variableName}->$name, true) ?? [] as \$image)
                            <img src="{{ Storage::url(\$image) }}" alt="$name" class="w-24 h-24 rounded">
                        @endforeach
                    </div>
                @else
                    <p class="text-gray-900">—</p>
                @endif
            </div>
EOT;
                    break;
                case 'date':
                case 'datetime':
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <p class="text-gray-900">{{ \${$variableName}->$name ? \${$variableName}->{$name}->format('Y-m-d" . ($type === 'datetime' ? ' H:i' : '') . "') : '—' }}</p>
            </div>
EOT;
                    break;
                case 'enum':
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <p class="text-gray-900">{{ \${$variableName}->$name ?? '—' }}</p>
            </div>
EOT;
                    break;
                case 'json':
                    $output .= <<<EOT
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">@lang('site.$name')</label>
                <pre class="text-gray-900 bg-gray-100 p-2 rounded">{{ json_encode(\${$variableName}->$name, JSON_PRETTY_PRINT) ?? '—' }}</pre>
            </div>
EOT;
                    break;
            }
        }
        return $output;
    }
}

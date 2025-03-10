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
        $variableName = Str::camel($name); // e.g., 'alii' for 'Alii'

        File::ensureDirectoryExists($basePath);

        File::put("$basePath/create.blade.php", self::generateCreateBlade($folderName, $parsedFields));
        File::put("$basePath/edit.blade.php", self::generateEditBlade($folderName, $parsedFields, $variableName));

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
            }
        }
        return $output;
    }
}
<?php

namespace Fariddomat\AutoGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CrudGenerator
{
    protected $name;
    protected $fields;
    protected $command;

    public function __construct($name, $fields, $command = null)
    {
        $this->name = Str::studly($name);
        $this->fields = $fields;
        $this->command = $command;
    }

    // ... (generateRules, generateModel, parseFields, viewParseFields, generateRelationships, etc.)
}
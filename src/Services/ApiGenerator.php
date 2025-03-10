<?php

namespace Fariddomat\AutoGenerator\Services;

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

    // ... (parseFields, generateRules, generateModel, generateMigration, generateController, generateRoutes, generateOpenApiSpec methods)
}
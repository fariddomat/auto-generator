# AutoGenerator Package for Laravel

The `Fariddomat\AutoGenerator` package is a powerful tool designed to streamline the creation of CRUD (Create, Read, Update, Delete) and API modules in Laravel applications. It provides an interactive command-line interface to generate models, migrations, controllers, views, routes, and OpenAPI specifications with minimal effort.

# Features
- *Interactive CLI* to define models, fields, and settings.
- Generates **CRUD controllers**, Blade views, and routes for web applications.
- Generates **API controllers**, routes, and OpenAPI specs for RESTful APIs.
- Supports relationships (*select* for `belongsTo`, *belongsToMany*).
- Optional features: **soft deletes**, *search functionality*, middleware, and dashboard prefix.
- Validation rules generated in models.
- File handling for uploads (files, images, multiple images).

# Requirements
- PHP >= 8.1
- Laravel >= 9.x
- Composer

# Installation

Install the package via Composer:

``` bash
composer require fariddomat/auto-generator
```

### Publish Configuration (Optional)

If the package includes a configuration file, publish it:

``` bash
php artisan vendor:publish --provider="Fariddomat\AutoGenerator\AutoGeneratorServiceProvider"
```

*Note:* If no service provider exists yet, you’ll need to register it manually in `config/app.php`:

``` php
'providers' => [
    // ...
    Fariddomat\AutoGenerator\AutoGeneratorServiceProvider::class,
],
```

# Usage

Run the interactive generator command:

``` bash
php artisan make:auto
```

### Interactive Prompts
- **Model Name**: Enter the model name (e.g., `Post`, must start with a capital letter).
- **Fields**: Define fields (e.g., `title:string`, `user_id:select`, `roles:belongsToMany`). Leave blank to finish.
  - *Supported types*: `string`, `text`, `integer`, `decimal`, `boolean`, `select`, `belongsToMany`, `file`, `image`, `images`.
  - *Modifiers*: `nullable`, `unique` (e.g., `title:string:nullable`).
- **Type**: Choose `crud`, `api`, or `both`.
- **API Version**: Specify version (e.g., `v1`) if API is selected.
- **Dashboard Prefix**: Use `dashboard.` prefix for views and routes (yes/no).
- **Soft Deletes**: Enable soft deletes (yes/no).
- **Search**: Enable search for API (yes/no), with optional searchable fields.
- **Middleware**: Add middleware (e.g., `auth:api,throttle`).

### Example Command

Generate a `Post` model with CRUD and API:

``` bash
php artisan make:auto 
Post 
title:string
user_id:select
images:images:nullable
2 
v1
yes 
yes 
yes
auth:api
```

### Generated Output
- *Model*: `app/Models/Post.php` with `$fillable`, rules, relationships, and optional `$searchable`.
- *Migration*: `database/migrations/*_create_posts_table.php`.
- *CRUD Controller*: `app/Http/Controllers/Dashboard/PostController.php`.
- *Views*: `resources/views/dashboard/posts/{index,create,edit,show}.blade.php`.
- *API Controller*: `app/Http/Controllers/PostApiController.php`.
- *Routes*: Added to `routes/web.php` (CRUD) and `routes/api.php` (API).
- *OpenAPI Spec*: `openapi/Post.json`.

For `belongsToMany` (e.g., `roles:belongsToMany`):
- *Pivot table migration* (e.g., `post_roles`).
- *Relationship method* in the model (e.g., `public function roles()`).

# Example Generated Model

``` php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    protected $fillable = ['title', 'user_id', 'images'];

    public static function rules()
    {
        return [
            'title' => 'required|string|max:255',
            'user_id' => 'required|exists:users,id',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
        ];
    }

    protected $searchable = ['title'];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
```

# Notes on Relationships
- *`select`*: Generates a `belongsTo` relationship (e.g., `user_id:select` → `belongsTo(User)`).
- *`belongsToMany`*: Generates a many-to-many relationship with a pivot table (e.g., `users:belongsToMany` → `belongsToMany(User, 'gara_user')`).
  - *Ensure* the related model (e.g., `User`) exists before migrating.

# Post-Generation Steps
- **Run Migrations**:
  ``` bash
  php artisan migrate
  ```
- **Test Routes**:
  - *CRUD*: Visit `/dashboard/posts` (if dashboard enabled).
  - *API*: Test `/api/v1/posts` with a tool like Postman.

# Customization
- *Edit Generated Files*: Modify controllers, views, or migrations as needed.
- *Extend the Generator*: Update `src/Services/*Generator.php` files to add custom logic.

# Troubleshooting
- *Class Not Found*: Ensure related models (e.g., `User`) exist. Generate them with `php artisan make:model User -m` if missing.
- *Pivot Table Errors*: Verify pivot table names match the model’s relationship definition (e.g., `gara_user` vs. `gara_users`).
- *Validation Issues*: Check `rules()` in the model for correct syntax.

# Contributing

Feel free to fork the repository, submit pull requests, or report issues on [GitHub](https://github.com/fariddomat/auto-generator).

# License

This package is open-source software licensed under the [MIT License](https://opensource.org/licenses/MIT).
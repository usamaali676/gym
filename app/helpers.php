<?php

use Illuminate\Support\Facades\Route;

if (! function_exists('route_if')) {
    /** URL for a named route, or null when it is not registered (lets views link to optional sections). */
    function route_if(string $name, array $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }
}

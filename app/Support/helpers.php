<?php

use App\Facades\Feature;

if (! function_exists('feature')) {
    /** Is this feature on for this install? Same answer as the `feature:` route middleware and `@feature`. */
    function feature(string $key): bool
    {
        return Feature::enabled($key);
    }
}

<?php

use App\Facades\Feature;

if (! function_exists('ui_scale')) {
    /** The root font size, in percent, for the page being rendered: the signed-in user's own choice; 100 for a guest and in a demo build. */
    function ui_scale(): int
    {
        return \App\Support\Demo::enabled() ? 100 : (auth()->user()?->uiScale() ?? 100);
    }
}

if (! function_exists('feature')) {
    /** Is this feature on for this install? Same answer as the `feature:` route middleware and `@feature`. */
    function feature(string $key): bool
    {
        return Feature::enabled($key);
    }
}

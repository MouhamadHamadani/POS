<?php

/** English source for lang/ar/errors.php — keep both in sync (LangParityTest enforces it). */
return [
    'error' => 'Error',
    'go_home' => 'Go home',
    'go_back' => 'Go back',
    'session_expired' => 'Your session expired, so the page was refreshed. Please sign in again.',
    'session_expired_json' => 'Your session expired. Reload the page and sign in again.',
    'codes' => [
        '401' => ['title' => 'Sign-in required', 'message' => 'Please sign in to continue.'],
        '403' => ['title' => 'Access denied', 'message' => "You don't have permission to open this page."],
        '404' => ['title' => 'Page not found', 'message' => 'The page you are looking for does not exist or has moved.'],
        '405' => ['title' => 'Action not allowed', 'message' => 'This page does not support that action.'],
        '419' => ['title' => 'Page expired', 'message' => 'This page was open for too long and its security token expired. Go home and try again.'],
        '429' => ['title' => 'Too many requests', 'message' => 'Please wait a moment, then try again.'],
        '500' => ['title' => 'Something went wrong', 'message' => 'The program hit an unexpected problem. Go home and try again. If it keeps happening, contact support.'],
        '503' => ['title' => 'Temporarily unavailable', 'message' => 'The program is busy or being updated. Try again in a moment.'],
    ],
    'generic' => ['title' => 'Something went wrong', 'message' => 'This page could not be shown. Go home and try again.'],
];

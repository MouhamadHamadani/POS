<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A feature change that would leave a feature switched on without something it
 * requires, or switch off something other enabled features need. Thrown by
 * FeatureService before anything is written; the message is already localised and
 * names the features involved, so a controller can hand it straight to the user.
 */
class FeatureDependencyException extends RuntimeException
{
    /**
     * @param  array<string,list<string>>  $unmet   switched-on feature => the requirements that are off
     * @param  list<string>  $broken   features that would stop working, and the change that causes it
     */
    public function __construct(string $message, public readonly array $unmet = [], public readonly array $broken = [])
    {
        parent::__construct($message);
    }
}

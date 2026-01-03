<?php

namespace App\Exceptions;

use Exception;

class SlugRedirectException extends Exception
{
    public $slug;
    public $oldSlug;

    public function __construct($slug, $oldSlug = null)
    {
        parent::__construct("Moved Permanently");
        $this->slug = $slug;
        $this->oldSlug = $oldSlug;
    }
}

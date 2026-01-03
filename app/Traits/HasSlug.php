<?php

namespace App\Traits;

use App\Models\SlugRedirect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Exceptions\SlugRedirectException;

trait HasSlug
{
    protected static function bootHasSlug()
    {
        static::saving(function (Model $model) {
            $model->generateSlug();
        });

        static::updating(function (Model $model) {
            $model->recordSlugRedirect();
        });
    }

    public function getSlugSourceColumn(): string
    {
        return 'name';
    }

    public function generateSlug()
    {
        $sourceColumn = $this->getSlugSourceColumn();
        $source = $this->getAttribute($sourceColumn);

        // If source is empty, we can't generate a slug.
        if (!$source) {
            return;
        }

        $proposedSlug = Str::slug($source);

        // Check if the user has manually set the slug
        if ($this->isDirty('slug') && !empty($this->slug)) {
            $proposedSlug = $this->slug;
        }

        // If the slug hasn't changed from what it is currently (and presumably valid), skip
        if ($this->slug === $proposedSlug) {
            return;
        }

        // Ensure uniqueness
        $originalSlug = $proposedSlug;
        $count = 1;

        while ($this->slugExists($proposedSlug)) {
            $proposedSlug = $originalSlug . '-' . $count++;
        }

        $this->slug = $proposedSlug;
    }

    protected function slugExists($slug)
    {
        $query = static::where('slug', $slug);

        if ($this->exists) {
            $query->where($this->getKeyName(), '!=', $this->getKey());
        }

        return $query->exists();
    }

    public function recordSlugRedirect()
    {
        // Only record redirect if we are updating an existing model and the slug has changed
        if ($this->exists && $this->isDirty('slug')) {
            $originalSlug = $this->getOriginal('slug');

            // Only if there was an original slug
            if ($originalSlug && $originalSlug !== $this->slug) {
                SlugRedirect::create([
                    'slug' => $originalSlug,
                    'redirectable_type' => static::class,
                    'redirectable_id' => $this->getKey(),
                ]);
            }
        }
    }

    /**
     * Retrieve the model for a bound value.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // If a specific field is provided, use it strictly
        if ($field) {
            return parent::resolveRouteBinding($value, $field);
        }

        // 1. Try ID if numeric
        if (is_numeric($value)) {
            $model = $this->where($this->getKeyName(), $value)->first();
            if ($model) {
                return $model;
            }
        }

        // 2. Try Slug
        $model = $this->where('slug', $value)->first();
        if ($model) {
            return $model;
        }

        // 3. Check Redirects
        $redirect = SlugRedirect::where('slug', $value)
            ->where('redirectable_type', static::class)
            ->first();

        if ($redirect) {
            $model = $redirect->redirectable;
            if ($model) {
                // Throw exception to be caught by global handler
                throw new SlugRedirectException($model->slug, $value);
            }
        }

        return null;
    }
}

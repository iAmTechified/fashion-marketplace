<?php

namespace App\Http\Controllers;

use App\Models\ShowcaseSet;
use App\Models\Product;
use App\Models\ShowcasePlaceholder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShowcaseSetController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(ShowcaseSet::withCount('products')->with('placeholders')->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:showcase_sets,name',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'type' => 'nullable|in:standard,with_placeholders',
            'products' => 'nullable|array|required_if:type,standard',
            'products.*' => 'exists:products,id',
            'placeholders' => 'nullable|array|required_if:type,with_placeholders',
            'placeholders.*.title' => 'required_with:placeholders',
            'placeholders.*.products' => 'nullable|array',
            'placeholders.*.products.*' => 'exists:products,id',
        ]);

        $showcaseSet = ShowcaseSet::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'type' => $validated['type'] ?? 'standard',
        ]);

        if ($showcaseSet->type === 'standard' && !empty($validated['products'])) {
            $showcaseSet->products()->sync($validated['products']);
        } elseif ($showcaseSet->type === 'with_placeholders' && !empty($validated['placeholders'])) {
            foreach ($validated['placeholders'] as $placeholderData) {
                $placeholder = $showcaseSet->placeholders()->create([
                    'title' => $placeholderData['title'],
                    'description' => $placeholderData['description'] ?? null,
                    'cta_text' => $placeholderData['cta_text'] ?? null,
                    'cta_url' => $placeholderData['cta_url'] ?? null,
                ]);

                if (!empty($placeholderData['products'])) {
                    $placeholder->products()->sync($placeholderData['products']);
                }
            }
        }

        return response()->json($showcaseSet->load(['products', 'placeholders.products']), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(ShowcaseSet $showcaseSet)
    {
        return response()->json($showcaseSet->load(['products', 'placeholders.products']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ShowcaseSet $showcaseSet)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|unique:showcase_sets,name,' . $showcaseSet->id,
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'type' => 'nullable|in:standard,with_placeholders',
            'products' => 'nullable|array',
            'products.*' => 'exists:products,id',
            'placeholders' => 'nullable|array',
            'placeholders.*.id' => 'nullable|exists:showcase_placeholders,id',
            'placeholders.*.title' => 'required_with:placeholders',
            'placeholders.*.products' => 'nullable|array',
            'placeholders.*.products.*' => 'exists:products,id',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $showcaseSet->update($validated);

        // Handle Standard Type Updates
        if ($showcaseSet->type === 'standard') {
            if (isset($validated['products'])) {
                $showcaseSet->products()->sync($validated['products']);
            }
            // Ensure no placeholders exist effectively? Or just ignore them. 
            // For cleanliness, we might want to delete them if switching types, 
            // but let's assume the user handles that or we just leave them/ignore them.
        }

        // Handle With Placeholders Type Updates
        elseif ($showcaseSet->type === 'with_placeholders' && isset($validated['placeholders'])) {
            $currentPlaceholderIds = [];

            foreach ($validated['placeholders'] as $placeholderData) {
                if (isset($placeholderData['id'])) {
                    // Update existing
                    $placeholder = ShowcasePlaceholder::where('id', $placeholderData['id'])
                        ->where('showcase_set_id', $showcaseSet->id)
                        ->first();

                    if ($placeholder) {
                        $placeholder->update([
                            'title' => $placeholderData['title'],
                            'description' => $placeholderData['description'] ?? $placeholder->description,
                            'cta_text' => $placeholderData['cta_text'] ?? $placeholder->cta_text,
                            'cta_url' => $placeholderData['cta_url'] ?? $placeholder->cta_url,
                        ]);
                        $currentPlaceholderIds[] = $placeholder->id;
                    }
                } else {
                    // Create new
                    $placeholder = $showcaseSet->placeholders()->create([
                        'title' => $placeholderData['title'],
                        'description' => $placeholderData['description'] ?? null,
                        'cta_text' => $placeholderData['cta_text'] ?? null,
                        'cta_url' => $placeholderData['cta_url'] ?? null,
                    ]);
                    $currentPlaceholderIds[] = $placeholder->id;
                }

                if (isset($placeholderData['products'])) {
                    $placeholder->products()->sync($placeholderData['products']);
                }
            }

            // Optional: Delete placeholders not present in the update request?
            // If the user sends a list, it's usually a full replace or merge. 
            // Let's assume merge for now unless the user asked for full replace. 
            // Actually, usually in these APIs, if you send a list, you expect the list to be the state.
            // But implementing full delete of missing ones involves more logic.
            // I'll leave it as additive/update for now to be safe, unless requested otherwise.
        }

        return response()->json($showcaseSet->load(['products', 'placeholders.products']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ShowcaseSet $showcaseSet)
    {
        $showcaseSet->delete();
        return response()->json(['message' => 'Showcase Set deleted']);
    }

    /**
     * Add a product to the showcase set.
     */
    public function addProduct(Request $request, ShowcaseSet $showcaseSet, Product $product)
    {
        $showcaseSet->products()->syncWithoutDetaching($product->id);
        return response()->json(['message' => 'Product added to showcase set']);
    }

    /**
     * Remove a product from the showcase set.
     */
    public function removeProduct(ShowcaseSet $showcaseSet, Product $product)
    {
        $showcaseSet->products()->detach($product->id);
        return response()->json(['message' => 'Product removed from showcase set']);
    }

    /**
     * Get products that are NOT in the specified showcase set.
     */
    public function getProductsNotInSet(ShowcaseSet $showcaseSet)
    {
        $products = Product::whereDoesntHave('showcaseSets', function ($query) use ($showcaseSet) {
            $query->where('showcase_sets.id', $showcaseSet->id);
        })->get();
        return response()->json($products);
    }
}

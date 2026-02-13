<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Get all categories (default + custom for household)
     */
    public function index(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'type' => 'nullable|in:income,expense,both',
            'parent_category_id' => 'nullable|exists:parent_categories,id',
        ]);

        $type = $validated['type'] ?? 'expense';

        $query = Category::query()
            ->with('parentCategory') // ← ADD THIS
            ->where(function($q) use ($household) {
                $q->whereNull('household_id')
                ->orWhere('household_id', $household->id);
            });

        // Filter by type
        if ($type !== 'both') {
            $query->where(function($q) use ($type) {
                $q->where('type', $type)->orWhere('type', 'both');
            });
        }

        // Filter by parent category
        if (isset($validated['parent_category_id'])) {
            $query->where('parent_category_id', $validated['parent_category_id']);
        }

        $categories = $query->orderBy('sort_order')
                            ->orderBy('name')
                            ->get()
                            ->map(function ($category) {
                                return [
                                    'id' => $category->id,
                                    'name' => $category->name,
                                    'icon' => $category->icon,
                                    'color' => $category->color,
                                    'type' => $category->type,
                                    'parent_category' => $category->parentCategory ? [
                                        'id' => $category->parentCategory->id,
                                        'name' => $category->parentCategory->name,
                                        'slug' => $category->parentCategory->slug,
                                    ] : null,
                                    'is_default' => $category->is_default,
                                    'is_custom' => $category->isCustom(),
                                ];
                            });

        return response()->json([
            'categories' => $categories,
        ]);
    }


    /**
     * Get single category
     */
    public function show(Request $request, Category $category)
    {
        $household = $request->user()->household;

        // Check if category is accessible by household
        if ($category->household_id && $category->household_id !== $household->id) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        return response()->json([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'icon' => $category->icon,
                'color' => $category->color,
                'type' => $category->type,  // ← Add this
                'is_default' => $category->is_default,
                'is_custom' => $category->isCustom(),
                'total_transactions' => $category->getTotalTransactions(),
                'total_amount' => $category->getTotalAmount(),
            ],
        ]);
    }

    /**
     * Create custom category
     */
    public function store(Request $request)
    {
        $household = $request->user()->household;

        if (!$household->canAccessFeature('custom_categories')) {
            return response()->json([
                'message' => 'Kategori kustom tidak tersedia pada paket Anda saat ini. Silakan tingkatkan paket Anda untuk mengakses fitur ini.'
            ], 403);
        }

        $validated = $request->validate([
            'type' => 'required|in:income,expense,both',
            'name' => 'required|string|max:255',
            'icon' => 'required|string|max:10',
            'color' => 'required|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'parent_category_id' => 'nullable|exists:parent_categories,id', // ← ADD THIS
        ]);

        $category = Category::create([
            'household_id' => $household->id,
            ...$validated,
            'is_default' => false,
            'sort_order' => Category::where('household_id', $household->id)->max('sort_order') + 1,
        ]);

        return response()->json([
            'message' => 'Category created successfully',
            'category' => $category,
        ], 201);
    }


    /**
     * Update custom category
     */
    public function update(Request $request, Category $category)
    {
        $household = $request->user()->household;

        // Check ownership
        if ($category->household_id !== $household->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Cannot edit default categories
        if ($category->is_default) {
            return response()->json(['message' => 'Cannot edit default categories'], 400);
        }

        $validated = $request->validate([
            'type' => 'sometimes|in:income,expense,both',  // ← Add this
            'name' => 'sometimes|string|max:255',
            'icon' => 'sometimes|string|max:10',
            'color' => 'sometimes|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $category->update($validated);

        return response()->json([
            'message' => 'Category updated successfully',
            'category' => [
                'id' => $category->id,
                'type' => $category->type,  // ← Add this
                'name' => $category->name,
                'icon' => $category->icon,
                'color' => $category->color,
            ],
        ]);
    }

    /**
     * Delete custom category
     */
    public function destroy(Request $request, Category $category)
    {
        $household = $request->user()->household;

        // Check ownership
        if ($category->household_id !== $household->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Cannot delete default categories
        if ($category->is_default) {
            return response()->json(['message' => 'Cannot delete default categories'], 400);
        }

        // Check if category has transactions
        if ($category->transactions()->exists()) {
            return response()->json([
                'message' => 'Cannot delete category with existing transactions',
            ], 400);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Reorder categories
     */
    public function reorder(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|exists:categories,id',
            'categories.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($validated['categories'] as $categoryData) {
            $category = Category::find($categoryData['id']);
            
            // Only reorder custom categories
            if ($category->household_id === $household->id) {
                $category->update(['sort_order' => $categoryData['sort_order']]);
            }
        }

        return response()->json([
            'message' => 'Categories reordered successfully',
        ]);
    }
}
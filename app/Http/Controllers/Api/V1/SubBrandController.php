<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SubBrand;
use Illuminate\Http\Request;

class SubBrandController extends Controller
{
    public function index()
    {
        // Currently, users are not tied directly to sub_brands in DB.
        // We simulate a usersCount of 0, but use withCount if the relation existed on the users table.
        // E.g., return SubBrand::withCount('users')->get();
        // Since we don't have sub_brand_id on users yet, we'll return 0 manually or use withCount if we mapped it.
        // The relation is set up to return 0 because no users have sub_brand_id.
        $brands = SubBrand::all()->map(function ($brand) {
            $brand->usersCount = 0; // Fixed for now until users schema changes
            return $brand;
        });

        return response()->json($brands);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:sub_brands',
            'status' => 'in:active,inactive',
        ]);

        $brand = SubBrand::create($validated);
        $brand->usersCount = 0;

        return response()->json($brand, 201);
    }

    public function show(SubBrand $subBrand)
    {
        $subBrand->usersCount = 0;
        return response()->json($subBrand);
    }

    public function update(Request $request, SubBrand $subBrand)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:sub_brands,name,' . $subBrand->id,
            'status' => 'in:active,inactive',
        ]);

        $subBrand->update($validated);
        $subBrand->usersCount = 0;

        return response()->json($subBrand);
    }

    public function destroy(SubBrand $subBrand)
    {
        // Add check later: if ($subBrand->users()->count() > 0) return error
        $subBrand->delete();
        return response()->json(null, 204);
    }
}

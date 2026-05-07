<?php

namespace App\Http\Controllers;

use App\Models\Specialization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SpecializationController extends Controller
{
    /**
     * List all specializations (accessible by admin, doctor, assistant).
     */
    public function index()
    {
        $specializations = Specialization::orderBy('name')->get();
        return response()->json($specializations);
    }

    /**
     * Create a new specialization (admin only).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'    => 'required|string|max:255|unique:specializations,name',
            'name_en' => 'nullable|string|max:255',
            'color'   => 'nullable|string|max:50',
            'features' => 'nullable|array',
            'features.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $specialization = Specialization::create([
            'name'     => $request->name,
            'name_en'  => $request->name_en,
            'color'    => $request->color ?? 'primary',
            'features' => $request->features ?? [],
        ]);

        return response()->json($specialization, 201);
    }

    /**
     * Show a single specialization.
     */
    public function show(Specialization $specialization)
    {
        return response()->json($specialization);
    }

    /**
     * Update a specialization (admin only).
     */
    public function update(Request $request, Specialization $specialization)
    {
        $validator = Validator::make($request->all(), [
            'name'    => 'sometimes|required|string|max:255|unique:specializations,name,' . $specialization->id,
            'name_en' => 'nullable|string|max:255',
            'color'   => 'nullable|string|max:50',
            'features' => 'nullable|array',
            'features.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $specialization->update($request->only(['name', 'name_en', 'color', 'features']));

        return response()->json($specialization);
    }

    /**
     * Delete a specialization (admin only).
     */
    public function destroy(Specialization $specialization)
    {
        // Nullify the specialization_id on doctors using it
        $specialization->doctors()->update(['specialization_id' => null]);
        $specialization->delete();

        return response()->json(['message' => 'Specialization deleted successfully']);
    }
}

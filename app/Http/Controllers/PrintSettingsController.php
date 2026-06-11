<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PrintSettingsController extends Controller
{
    private const FIELDS = [
        'print_clinic_name',
        'print_clinic_phone',
        'print_clinic_address',
        'print_header_text',
        'print_footer_text',
        'print_primary_color',
        'print_clinic_name_position',
        'print_patient_info_position',
    ];

    public function show(Request $request)
    {
        return response()->json($request->user()->only(self::FIELDS));
    }

    public function update(Request $request)
    {
        if (!in_array($request->user()->role, ['doctor', 'sub-doctor'], true)) {
            return response()->json(['message' => 'Only doctors can update print settings.'], 403);
        }

        $data = $request->validate([
            'print_clinic_name' => 'nullable|string|max:255',
            'print_clinic_phone' => 'nullable|string|max:255',
            'print_clinic_address' => 'nullable|string|max:255',
            'print_header_text' => 'nullable|string|max:2000',
            'print_footer_text' => 'nullable|string|max:2000',
            'print_primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'print_clinic_name_position' => 'nullable|in:left,center,right',
            'print_patient_info_position' => 'nullable|in:top,after_doctor,bottom',
        ]);

        $request->user()->update($data);

        return response()->json([
            'message' => 'Print settings updated successfully.',
            'settings' => $request->user()->fresh()->only(self::FIELDS),
        ]);
    }
}

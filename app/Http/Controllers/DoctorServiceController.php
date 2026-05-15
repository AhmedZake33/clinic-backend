<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\DoctorService;
use Illuminate\Http\Request;

class DoctorServiceController extends Controller
{
    use ResolvesDoctor;

    public function index(Request $request)
    {
        // Each doctor/sub-doctor sees only their own services
        $doctorId = $this->requireDoctorId($request);
        $services = DoctorService::where('doctor_id', $doctorId)
            ->orderBy('name')
            ->get();
        return response()->json($services);
    }

    public function store(Request $request)
    {
        $doctorId = $this->requireDoctorId($request);

        $request->validate([
            'name'        => 'required|string|max:255',
            'name_en'     => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'is_active'   => 'nullable|boolean',
        ]);

        $service = DoctorService::create([
            'doctor_id'   => $doctorId,
            'name'        => $request->name,
            'name_en'     => $request->name_en,
            'description' => $request->description,
            'price'       => $request->price,
            'is_active'   => $request->input('is_active', true),
        ]);

        return response()->json($service, 201);
    }

    public function update(Request $request, DoctorService $doctorService)
    {
        $doctorId = $this->requireDoctorId($request);

        if ($doctorService->doctor_id !== $doctorId) {
            abort(403);
        }

        $request->validate([
            'name'        => 'required|string|max:255',
            'name_en'     => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'is_active'   => 'nullable|boolean',
        ]);

        $doctorService->update($request->only('name', 'name_en', 'description', 'price', 'is_active'));

        return response()->json($doctorService);
    }

    public function destroy(Request $request, DoctorService $doctorService)
    {
        $doctorId = $this->requireDoctorId($request);

        if ($doctorService->doctor_id !== $doctorId) {
            abort(403);
        }

        $doctorService->delete();

        return response()->json(['message' => 'Deleted']);
    }
}

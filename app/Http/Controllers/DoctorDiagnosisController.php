<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\DoctorDiagnosis;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;

class DoctorDiagnosisController extends Controller
{
    use ResolvesDoctor;

    public function index(Request $request)
    {
        $doctorId = $this->requireDoctorId($request);

        return response()->json(
            DoctorDiagnosis::where('doctor_id', $doctorId)
                ->orderBy('name')
                ->get()
        );
    }

    public function forReservation(Request $request, Reservation $reservation)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($reservation->doctor_id, $doctorIds)) {
            abort(403);
        }

        return response()->json(
            DoctorDiagnosis::where('doctor_id', $reservation->doctor_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }

    public function forDoctor(Request $request, User $doctor)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($doctor->id, $doctorIds) || !in_array($doctor->role, ['doctor', 'sub-doctor'])) {
            abort(403);
        }

        return response()->json(
            DoctorDiagnosis::where('doctor_id', $doctor->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $doctorId = $this->requireDoctorId($request);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $diagnosis = DoctorDiagnosis::create([
            ...$data,
            'doctor_id' => $doctorId,
            'is_active' => $request->input('is_active', true),
        ]);

        return response()->json($diagnosis, 201);
    }

    public function update(Request $request, DoctorDiagnosis $doctorDiagnosis)
    {
        $doctorId = $this->requireDoctorId($request);

        if ($doctorDiagnosis->doctor_id !== $doctorId) {
            abort(403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $doctorDiagnosis->update($data);

        return response()->json($doctorDiagnosis);
    }

    public function destroy(Request $request, DoctorDiagnosis $doctorDiagnosis)
    {
        $doctorId = $this->requireDoctorId($request);

        if ($doctorDiagnosis->doctor_id !== $doctorId) {
            abort(403);
        }

        $doctorDiagnosis->delete();

        return response()->json(['message' => 'Deleted']);
    }
}

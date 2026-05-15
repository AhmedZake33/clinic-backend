<?php

namespace App\Http\Traits;

use Illuminate\Http\Request;

/**
 * Trait ResolvesDoctor
 *
 * Provides a helper method for controllers to resolve the doctor_id
 * based on the authenticated user's role:
 * - If the user is a doctor, returns their own ID.
 * - If the user is an assistant, returns their assigned doctor_id.
 *
 * This ensures all data queries are scoped to the correct doctor (tenant).
 */
trait ResolvesDoctor
{
    /**
     * Get the doctor_id for tenant scoping.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int|null
     */
    protected function resolveDoctorId(Request $request): ?int
    {
        $user = $request->user();

        if (!$user) {
            return null;
        }

        if ($user->role === 'doctor') {
            return $user->id;
        }

        if ($user->role === 'assistant') {
            return $user->doctor_id;
        }

        if ($user->role === 'sub-doctor') {
            // Sub-doctors see only their own reservations (doctor_id = their own id)
            return $user->id;
        }

        return null;
    }

    /**
     * Abort with 403 if the doctor context cannot be resolved.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int
     */
    protected function requireDoctorId(Request $request): int
    {
        $doctorId = $this->resolveDoctorId($request);

        if (!$doctorId) {
            abort(403, 'No doctor context available. Assistant must be assigned to a doctor.');
        }

        return $doctorId;
    }

    /**
     * Returns the doctor's own ID plus all sub-doctors' IDs.
     *
     * - doctor   → [doctor.id, ...sub_doctor ids]
     * - assistant → [doctor_id, ...sub_doctor ids of that doctor]
     * - sub-doctor → [sub_doctor.id]  (only their own records)
     */
    protected function getDoctorIds(Request $request): array
    {
        $doctorId = $this->requireDoctorId($request);
        $user = $request->user();

        if (in_array($user->role, ['doctor', 'assistant'])) {
            $subIds = \App\Models\User::where('role', 'sub-doctor')
                ->where('parent_doctor_id', $doctorId)
                ->pluck('id')
                ->toArray();
            return array_merge([$doctorId], $subIds);
        }

        return [$doctorId];
    }
}

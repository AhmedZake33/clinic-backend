<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\DoctorService;
use App\Models\Reservation;
use App\Models\ReservationService;
use Illuminate\Http\Request;
use App\Models\Financial;
use Illuminate\Support\Facades\DB;

class ReservationServiceController extends Controller
{
    use ResolvesDoctor;

    /** List services for a reservation */
    public function index(Request $request, $reservationId)
    {
        $doctorIds = $this->getDoctorIds($request);

        $reservation = Reservation::where('id', $reservationId)
            ->whereIn('doctor_id', $doctorIds)
            ->firstOrFail();

        $services = ReservationService::with(['creator'])
            ->where('reservation_id', $reservation->id)
            ->orderBy('created_at')
            ->get();

        return response()->json($services);
    }

    /** Add a service to a reservation */
    public function store(Request $request, $reservationId)
    {
        $doctorIds = $this->getDoctorIds($request);

        $reservation = Reservation::where('id', $reservationId)
            ->whereIn('doctor_id', $doctorIds)
            ->firstOrFail();

        // The service record owner is the reservation's actual doctor
        $doctorId = $reservation->doctor_id;

        $request->validate([
            'doctor_service_id' => 'nullable|exists:doctor_services,id',
            'service_name'      => 'required|string|max:255',
            'quantity'          => 'required|integer|min:1',
            'unit_price'        => 'required|numeric|min:0',
            'with_invoice'      => 'required|boolean',
            'notes'             => 'nullable|string',
        ]);

        $quantity   = $request->quantity;
        $unitPrice  = $request->unit_price;
        $totalPrice = $quantity * $unitPrice;

        $rs = null;
        DB::transaction(function () use ($request, $reservation, $doctorId, $quantity, $unitPrice, $totalPrice, &$rs) {
            $rs = ReservationService::create([
                'reservation_id'    => $reservation->id,
                'doctor_service_id' => $request->doctor_service_id,
                'doctor_id'         => $doctorId,
                'client_id'         => $reservation->client_id,
                'created_by'        => $request->user()->id,
                'service_name'      => $request->service_name,
                'quantity'          => $quantity,
                'unit_price'        => $unitPrice,
                'total_price'       => $totalPrice,
                'with_invoice'      => $request->with_invoice,
                'notes'             => $request->notes,
            ]);

            // If this service should generate an invoice, create a Financial record linked to this reservation service
            if ($rs->with_invoice) {
                $amount = $rs->total_price;
                $financial = Financial::create([
                    'reservation_id' => $reservation->id,
                    'reservation_service_id' => $rs->id,
                    'client_id' => $reservation->client_id,
                    'doctor_id' => $doctorId,
                    'created_by' => $request->user()->id,
                    'amount' => $amount,
                    'paid' => 0,
                    'remaining' => $amount,
                    'payment_status' => 'unpaid',
                    'payment_method' => 'other',
                    'notes' => "ReservationService: {$rs->id} - {$rs->service_name}",
                ]);
            }
        });

        $rs->load('creator');

        return response()->json($rs, 201);
    }

    /** Update a reservation service */
    public function update(Request $request, $reservationId, ReservationService $reservationService)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($reservationService->doctor_id, $doctorIds) || (int) $reservationService->reservation_id !== (int) $reservationId) {
            abort(403);
        }

        $request->validate([
            'service_name' => 'required|string|max:255',
            'quantity'     => 'required|integer|min:1',
            'unit_price'   => 'required|numeric|min:0',
            'with_invoice' => 'required|boolean',
            'notes'        => 'nullable|string',
        ]);

        DB::transaction(function () use ($request, $reservationService, $reservationId) {
            $reservationService->update([
                'service_name' => $request->service_name,
                'quantity'     => $request->quantity,
                'unit_price'   => $request->unit_price,
                'total_price'  => $request->quantity * $request->unit_price,
                'with_invoice' => $request->with_invoice,
                'notes'        => $request->notes,
            ]);

            // Lookup financial by explicit FK
            $financial = Financial::where('reservation_service_id', $reservationService->id)->first();

            if ($reservationService->with_invoice) {
                $amount = $reservationService->quantity * $reservationService->unit_price;
                if ($financial) {
                    $financial->update([
                        'amount' => $amount,
                        'remaining' => $amount - $financial->paid,
                        'payment_status' => $financial->paid >= $amount ? 'paid' : ($financial->paid > 0 ? 'partial' : 'unpaid'),
                        'notes' => "ReservationService: {$reservationService->id} - {$reservationService->service_name}",
                        'voided' => false,
                    ]);
                } else {
                    Financial::create([
                        'reservation_id' => $reservationId,
                        'reservation_service_id' => $reservationService->id,
                        'client_id' => $reservationService->client_id,
                        'doctor_id' => $reservationService->doctor_id,
                        'created_by' => $request->user()->id,
                        'amount' => $amount,
                        'paid' => 0,
                        'remaining' => $amount,
                        'payment_status' => 'unpaid',
                        'payment_method' => 'other',
                        'notes' => "ReservationService: {$reservationService->id} - {$reservationService->service_name}",
                    ]);
                }
            } elseif ($financial) {
                // mark financial as voided instead of deleting
                $financial->update(['voided' => true]);
            }
        });

        return response()->json($reservationService);
    }

    /** Remove a service from a reservation */
    public function destroy(Request $request, $reservationId, ReservationService $reservationService)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($reservationService->doctor_id, $doctorIds)) {
            abort(403);
        }

        DB::transaction(function () use ($reservationService, $reservationId) {
            // If there is a linked financial record, mark it voided
            $financial = Financial::where('reservation_service_id', $reservationService->id)->first();
            if ($financial) {
                $financial->update(['voided' => true]);
            }

            $reservationService->delete();
        });

        return response()->json(['message' => 'Deleted']);
    }
}

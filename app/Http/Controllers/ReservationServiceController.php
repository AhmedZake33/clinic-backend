<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\DoctorService;
use App\Models\Reservation;
use App\Models\ReservationLog;
use App\Models\ReservationService;
use Illuminate\Http\Request;
use App\Models\Financial;
use Illuminate\Support\Facades\DB;

class ReservationServiceController extends Controller
{
    use ResolvesDoctor;

    private const ADDITIONAL_SERVICES_INVOICE_NOTE = 'Additional services invoice';

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

        if (in_array($reservation->status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot add services to a completed or cancelled reservation'], 422);
        }

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

            $this->syncAdditionalServicesInvoice($reservation, $request);

            ReservationLog::create([
                'reservation_id' => $reservation->id,
                'user_id' => $request->user()?->id,
                'action' => 'service_added',
                'description' => "Added service: {$rs->service_name}",
                'meta' => [
                    'service_name' => $rs->service_name,
                    'quantity' => $rs->quantity,
                    'total_price' => $rs->total_price,
                ],
            ]);
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

            $reservation = Reservation::findOrFail($reservationId);
            $this->syncAdditionalServicesInvoice($reservation, $request);

            Financial::where('reservation_service_id', $reservationService->id)->update(['voided' => true]);

            ReservationLog::create([
                'reservation_id' => $reservation->id,
                'user_id' => $request->user()?->id,
                'action' => 'service_updated',
                'description' => "Updated service: {$reservationService->service_name}",
                'meta' => [
                    'service_name' => $reservationService->service_name,
                    'quantity' => $reservationService->quantity,
                    'total_price' => $reservationService->total_price,
                ],
            ]);
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

        DB::transaction(function () use ($reservationService, $reservationId, $request) {
            $serviceName = $reservationService->service_name;
            Financial::where('reservation_service_id', $reservationService->id)->update(['voided' => true]);
            $reservationService->delete();

            $reservation = Reservation::findOrFail($reservationId);
            $this->syncAdditionalServicesInvoice($reservation, $request);

            ReservationLog::create([
                'reservation_id' => $reservation->id,
                'user_id' => $request->user()?->id,
                'action' => 'service_deleted',
                'description' => "Deleted service: {$serviceName}",
                'meta' => ['service_name' => $serviceName],
            ]);
        });

        return response()->json(['message' => 'Deleted']);
    }

    private function syncAdditionalServicesInvoice(Reservation $reservation, Request $request): void
    {
        $servicesTotal = (float) ReservationService::where('reservation_id', $reservation->id)
            ->where('with_invoice', true)
            ->sum('total_price');
        $serviceNames = ReservationService::where('reservation_id', $reservation->id)
            ->where('with_invoice', true)
            ->orderBy('created_at')
            ->pluck('service_name')
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');
        $invoiceNote = trim(self::ADDITIONAL_SERVICES_INVOICE_NOTE . ($serviceNames ? ': ' . $serviceNames : ''));

        $serviceFinancial = Financial::where('reservation_id', $reservation->id)
            ->whereNull('reservation_service_id')
            ->where('voided', false)
            ->where('notes', 'like', self::ADDITIONAL_SERVICES_INVOICE_NOTE . '%')
            ->oldest()
            ->first();

        if ($servicesTotal <= 0) {
            if ($serviceFinancial) {
                $serviceFinancial->update(['voided' => true]);
            }
            return;
        }

        $baseFinancial = Financial::where('reservation_id', $reservation->id)
            ->whereNull('reservation_service_id')
            ->where('voided', false)
            ->where(function ($query) {
                $query->whereNull('notes')
                    ->orWhere('notes', 'not like', self::ADDITIONAL_SERVICES_INVOICE_NOTE . '%');
            })
            ->oldest()
            ->first();

        $hasReservationInvoiceValue = $baseFinancial && (float) $baseFinancial->amount > 0;
        $financial = $hasReservationInvoiceValue ? $serviceFinancial : ($serviceFinancial ?: $baseFinancial);

        if (!$financial) {
            $financial = Financial::create([
                'reservation_id' => $reservation->id,
                'client_id' => $reservation->client_id,
                'doctor_id' => $reservation->doctor_id,
                'created_by' => $request->user()->id,
                'amount' => 0,
                'paid' => 0,
                'remaining' => 0,
                'payment_status' => 'unpaid',
                'payment_method' => 'other',
                'notes' => $invoiceNote,
            ]);
        }

        if (!$hasReservationInvoiceValue && $serviceFinancial && $baseFinancial && $serviceFinancial->id !== $baseFinancial->id) {
            $serviceFinancial->update(['voided' => true]);
            $financial = $baseFinancial;
        }

        $amount = $servicesTotal;
        $paid = (float) $financial->paid;
        $remaining = max(0, $amount - $paid);

        if ($paid <= 0) {
            $paymentStatus = 'unpaid';
        } elseif ($remaining <= 0) {
            $paymentStatus = 'paid';
        } else {
            $paymentStatus = 'partial';
        }

        $financial->update([
            'amount' => $amount,
            'remaining' => $remaining,
            'payment_status' => $paymentStatus,
            'notes' => $invoiceNote,
        ]);
    }
}

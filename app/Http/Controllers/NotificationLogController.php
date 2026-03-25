<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\NotificationLog;
use Illuminate\Http\Request;

class NotificationLogController extends Controller
{
    use ResolvesDoctor;

    public function index(Request $request)
    {
        $doctorId = $this->requireDoctorId($request);

        $query = NotificationLog::with(['client:id,name,phone', 'reservation:id,appointment_date'])
            ->where('doctor_id', $doctorId)
            ->orderBy('created_at', 'desc');

        if ($channel = $request->query('channel')) {
            $query->where('channel', $channel);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        return response()->json($query->paginate(15));
    }
}

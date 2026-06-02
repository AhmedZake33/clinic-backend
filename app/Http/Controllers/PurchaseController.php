<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\Purchase;
use App\Enums\PurchaseCategory;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PurchaseController extends Controller
{
    use ResolvesDoctor;

    private const PAYMENT_METHODS = 'Cash,Card,Bank Transfer,Other,InstaPay';

    public function index(Request $request)
    {
        $query = Purchase::query();

        if (Auth::check() && in_array(Auth::user()->role, ['doctor', 'assistant', 'sub-doctor'])) {
            $doctorIds = $this->getDoctorIds($request);
            $query->whereIn('doctor_id', $doctorIds);
        } else {
            if ($request->filled('doctor_id')) {
                $query->where('doctor_id', $request->input('doctor_id'));
            }
        }

        if ($request->filled('from')) {
            $query->where('purchase_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('purchase_date', '<=', $request->to);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        // Allow frontend to request a custom per-page value via query param
        $perPage = (int) $request->input('per_page', 25);
        $perPage = $perPage > 0 ? $perPage : 25;

        $purchases = $query->with('doctor')->orderBy('purchase_date', 'desc')->paginate($perPage);

        // Attach bilingual labels to each item for frontend display
        $labelsEn = PurchaseCategory::labels('en');
        $labelsAr = PurchaseCategory::labels('ar');
        $purchases->getCollection()->transform(function ($p) use ($labelsEn, $labelsAr) {
            $cat = $p->category;
            $p->category_labels = [
                'en' => $labelsEn[$cat] ?? $cat,
                'ar' => $labelsAr[$cat] ?? $cat,
            ];
            return $p;
        });

        return response()->json($purchases);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'item_name' => 'required|string|max:255',
            'category' => ['required', new EnumRule(PurchaseCategory::class)],
            'quantity' => 'nullable|integer|min:1',
            'amount_paid' => 'required|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
            'payment_method' => 'required|string|in:' . self::PAYMENT_METHODS,
            'purchase_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $data['created_by'] = Auth::id();
        $data['doctor_id'] = $this->requireDoctorId($request);

        $purchase = Purchase::create($data);

        // eager load doctor relation
        $purchase->load('doctor');

        // attach bilingual labels for frontend
        $labelsEn = PurchaseCategory::labels('en');
        $labelsAr = PurchaseCategory::labels('ar');
        $purchase->category_labels = [
            'en' => $labelsEn[$purchase->category] ?? $purchase->category,
            'ar' => $labelsAr[$purchase->category] ?? $purchase->category,
        ];

        return response()->json($purchase, 201);
    }

    public function show(Purchase $purchase)
    {
        $this->authorizePurchaseScope(request(), $purchase);

        $labelsEn = PurchaseCategory::labels('en');
        $labelsAr = PurchaseCategory::labels('ar');
        $purchase->category_labels = [
            'en' => $labelsEn[$purchase->category] ?? $purchase->category,
            'ar' => $labelsAr[$purchase->category] ?? $purchase->category,
        ];
        $purchase->load('doctor');
        return response()->json($purchase);
    }

    public function update(Request $request, Purchase $purchase)
    {
        $this->authorizePurchaseScope($request, $purchase);

        $data = $request->validate([
            'item_name' => 'sometimes|required|string|max:255',
            'category' => ['sometimes', 'required', new EnumRule(PurchaseCategory::class)],
            'quantity' => 'nullable|integer|min:1',
            'amount_paid' => 'sometimes|required|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
            'payment_method' => 'sometimes|required|string|in:' . self::PAYMENT_METHODS,
            'purchase_date' => 'sometimes|required|date',
            'notes' => 'nullable|string',
        ]);

        $purchase->update($data);

        $purchase->load('doctor');

        $labelsEn = PurchaseCategory::labels('en');
        $labelsAr = PurchaseCategory::labels('ar');
        $purchase->category_labels = [
            'en' => $labelsEn[$purchase->category] ?? $purchase->category,
            'ar' => $labelsAr[$purchase->category] ?? $purchase->category,
        ];
        return response()->json($purchase);
    }

    public function destroy(Request $request, Purchase $purchase)
    {
        $this->authorizePurchaseScope($request, $purchase);

        $purchase->delete();
        return response()->json(['message' => 'Purchase deleted']);
    }

    private function authorizePurchaseScope(Request $request, Purchase $purchase): void
    {
        if (Auth::check() && in_array(Auth::user()->role, ['doctor', 'assistant', 'sub-doctor'])) {
            abort_unless(in_array($purchase->doctor_id, $this->getDoctorIds($request)), 403, 'Unauthorized');
        }
    }

    // (Removed daily/monthly/category helper endpoints — use unified /purchases/stats only)

    // GET /purchases/stats?date=YYYY-MM-DD&from=YYYY-MM-DD&to=YYYY-MM-DD
    // Return only the period total (sum of amount_paid over requested range or date).
    public function stats(Request $request)
    {
        $date = $request->input('date');
        $from = $request->input('from');
        $to = $request->input('to');
        $category = $request->input('category');

        // Determine doctor scoping using getDoctorIds for all applicable roles.
        $doctorIds = null;
        if (Auth::check() && in_array(Auth::user()->role, ['doctor', 'assistant', 'sub-doctor'])) {
            $doctorIds = $this->getDoctorIds($request);
        } elseif ($request->filled('doctor_id')) {
            $doctorIds = [$request->input('doctor_id')];
        }

        $today = date('Y-m-d');
        $date = $date ?: $from ?: $today;

        $query = Purchase::query();
        if ($category) {
            $query->where('category', $category);
        }
        if ($doctorIds) {
            $query->whereIn('doctor_id', $doctorIds);
        }

        if ($from && $to) {
            $query->whereBetween('purchase_date', [$from, $to]);
            $returnedDate = null;
        } else {
            $query->where('purchase_date', $date);
            $returnedDate = $date;
        }

        $periodTotal = (float) $query->sum('amount_paid');

        return response()->json([
            'date' => $returnedDate,
            'period_total' => $periodTotal,
        ]);
    }

    // GET /purchases/categories?lang=en|ar
    public function categories(Request $request)
    {
        $lang = $request->input('lang', $request->header('Accept-Language') ? substr($request->header('Accept-Language'), 0, 2) : 'en');
        $lang = in_array($lang, ['ar', 'en']) ? $lang : 'en';
        $options = PurchaseCategory::localizedOptions($lang);
        return response()->json(['data' => $options]);
    }
}

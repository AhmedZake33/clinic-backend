<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Enums\PurchaseCategory;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $query = Purchase::query();

        // If authenticated and role is doctor/assistant, always scope to their doctor.
        // Only honor an explicit doctor_id in the request for non-doctor/assistant roles (e.g., admin).
        if (Auth::check() && in_array(Auth::user()->role, ['doctor', 'assistant'])) {
            if (Auth::user()->role === 'doctor') {
                $query->where('doctor_id', Auth::id());
            } else {
                $query->where('doctor_id', Auth::user()->doctor_id);
            }
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
            'payment_method' => 'required|string|in:Cash,Card,Bank Transfer',
            'purchase_date' => 'required|date',
            'notes' => 'nullable|string',
            'doctor_id' => 'nullable|exists:users,id',
        ]);

        $data['created_by'] = Auth::id();

        // If doctor_id not provided, associate based on authenticated user's role
        if (empty($data['doctor_id']) && Auth::user()) {
            if (Auth::user()->role === 'doctor') {
                $data['doctor_id'] = Auth::id();
            } elseif (Auth::user()->role === 'assistant') {
                // assistants belong to a doctor via doctor_id on user
                $data['doctor_id'] = Auth::user()->doctor_id ?? null;
            }
        }

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
        $data = $request->validate([
            'item_name' => 'sometimes|required|string|max:255',
            'category' => ['sometimes', 'required', new EnumRule(PurchaseCategory::class)],
            'quantity' => 'nullable|integer|min:1',
            'amount_paid' => 'sometimes|required|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
            'payment_method' => 'sometimes|required|string|in:Cash,Card,Bank Transfer',
            'purchase_date' => 'sometimes|required|date',
            'notes' => 'nullable|string',
            'doctor_id' => 'nullable|exists:users,id',
        ]);

        $purchase->update($data);

        // if doctor_id not provided, set based on current user's role
        if (empty($data['doctor_id']) && Auth::user()) {
            if (Auth::user()->role === 'doctor') {
                $purchase->doctor_id = Auth::id();
                $purchase->save();
            } elseif (Auth::user()->role === 'assistant') {
                $purchase->doctor_id = Auth::user()->doctor_id ?? $purchase->doctor_id;
                $purchase->save();
            }
        }

        $purchase->load('doctor');

        $labelsEn = PurchaseCategory::labels('en');
        $labelsAr = PurchaseCategory::labels('ar');
        $purchase->category_labels = [
            'en' => $labelsEn[$purchase->category] ?? $purchase->category,
            'ar' => $labelsAr[$purchase->category] ?? $purchase->category,
        ];
        return response()->json($purchase);
    }

    public function destroy(Purchase $purchase)
    {
        $purchase->delete();
        return response()->json(['message' => 'Purchase deleted']);
    }

    // GET /purchases/stats/daily?date=YYYY-MM-DD
    public function dailyTotal(Request $request)
    {
        $date = $request->input('date', date('Y-m-d'));
        $total = Purchase::where('purchase_date', $date)->sum('amount_paid');
        return response()->json(['date' => $date, 'total' => (float) $total]);
    }

    // GET /purchases/stats/monthly?year=YYYY&month=MM
    public function monthlyTotal(Request $request)
    {
        $year = $request->input('year', date('Y'));
        $month = $request->input('month', date('m'));
        $total = Purchase::whereYear('purchase_date', $year)
            ->whereMonth('purchase_date', $month)
            ->sum('amount_paid');
        return response()->json(['year' => (int) $year, 'month' => (int) $month, 'total' => (float) $total]);
    }

    // GET /purchases/stats/category?from=YYYY-MM-DD&to=YYYY-MM-DD
    public function totalByCategory(Request $request)
    {
        $query = Purchase::query();
        // Scope by authenticated doctor/assistant by default; otherwise allow doctor_id filter
        if (Auth::check() && in_array(Auth::user()->role, ['doctor', 'assistant'])) {
            if (Auth::user()->role === 'doctor') {
                $query->where('doctor_id', Auth::id());
            } else {
                $query->where('doctor_id', Auth::user()->doctor_id);
            }
        } elseif ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->input('doctor_id'));
        }
        if ($request->filled('from')) {
            $query->where('purchase_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('purchase_date', '<=', $request->to);
        }

        $results = $query->selectRaw('category, SUM(amount_paid) as total')
            ->groupBy('category')
            ->get()
            ->map(function ($r) {
                return ['category' => $r->category, 'total' => (float) $r->total];
            });

        // attach bilingual labels to each result
        $labelsEn = PurchaseCategory::labels('en');
        $labelsAr = PurchaseCategory::labels('ar');
        $results = $results->map(function ($r) use ($labelsEn, $labelsAr) {
            $r['labels'] = [
                'en' => $labelsEn[$r['category']] ?? $r['category'],
                'ar' => $labelsAr[$r['category']] ?? $r['category'],
            ];
            return $r;
        });

        return response()->json($results);
    }

    // GET /purchases/stats?date=YYYY-MM-DD&from=YYYY-MM-DD&to=YYYY-MM-DD&year=YYYY&month=MM
    public function stats(Request $request)
    {
        // return $request->all();
        // Determine date range and month/year
        $date = $request->input('date');
        $from = $request->input('from');
        $to = $request->input('to');
        $category = $request->input('category');

        // Determine doctor scoping: for doctor/assistant, use authenticated context.
        $doctorId = null;
        if (Auth::check() && in_array(Auth::user()->role, ['doctor', 'assistant'])) {
            $doctorId = Auth::user()->role === 'doctor' ? Auth::id() : Auth::user()->doctor_id;
        } elseif ($request->filled('doctor_id')) {
            // allow explicit filter for admins or other roles
            $doctorId = $request->input('doctor_id');
        }
        $year = $request->input('year');
        $month = $request->input('month');

        // Default date to today if not provided
        $today = date('Y-m-d');
        $date = $date ?: $from ?: $today;

        // Daily total (apply category / range if provided)
        $dailyQuery = Purchase::query();
        if ($category) {
            $dailyQuery->where('category', $category);
        }
        if ($doctorId) {
            $dailyQuery->where('doctor_id', $doctorId);
        }
        // if explicit from/to provided and date is equal to from, handle as date filter
        if ($from && $to) {
            $dailyQuery->whereBetween('purchase_date', [$from, $to]);
        } else {
            $dailyQuery->where('purchase_date', $date);
        }
        $dailyTotal = (float) $dailyQuery->sum('amount_paid');

        // Monthly: if year/month provided use them, otherwise derive from date
        if (!$year || !$month) {
            $parts = explode('-', $date);
            $year = $parts[0] ?? date('Y');
            $month = $parts[1] ?? date('m');
        }
        $monthlyQuery = Purchase::query();
        if ($category) {
            $monthlyQuery->where('category', $category);
        }
        if ($doctorId) {
            $monthlyQuery->where('doctor_id', $doctorId);
        }
        $monthlyTotal = (float) $monthlyQuery->whereYear('purchase_date', $year)
            ->whereMonth('purchase_date', $month)
            ->sum('amount_paid');

        // Totals by category for given range (from/to) or for the month if not provided
        $catQuery = Purchase::query();
        if ($category) {
            $catQuery->where('category', $category);
        }
        if ($doctorId) {
            $catQuery->where('doctor_id', $doctorId);
        }
        if ($from) {
            $catQuery->where('purchase_date', '>=', $from);
        }
        if ($to) {
            $catQuery->where('purchase_date', '<=', $to);
        }
        if (!$from && !$to) {
            // default to month
            $catQuery->whereYear('purchase_date', $year)->whereMonth('purchase_date', $month);
        }

        $byCategory = $catQuery->selectRaw('category, SUM(amount_paid) as total')
            ->groupBy('category')
            ->get()
            ->map(function ($r) {
                return ['category' => $r->category, 'total' => (float) $r->total];
            });

        // attach bilingual labels
        $labelsEn = PurchaseCategory::labels('en');
        $labelsAr = PurchaseCategory::labels('ar');
        $byCategory = $byCategory->map(function ($r) use ($labelsEn, $labelsAr) {
            $r['labels'] = [
                'en' => $labelsEn[$r['category']] ?? $r['category'],
                'ar' => $labelsAr[$r['category']] ?? $r['category'],
            ];
            return $r;
        });

        return response()->json([
            'date' => $date,
            'daily' => $dailyTotal,
            'year' => (int) $year,
            'month' => (int) $month,
            'monthly' => $monthlyTotal,
            'byCategory' => $byCategory,
            // period_total: sum over the selected period (from..to) or the single date if only date provided
            'period_total' => (float) Purchase::when($from && $to, function ($q) use ($from, $to, $category, $doctorId) {
                    if ($category) $q->where('category', $category);
                    if ($doctorId) $q->where('doctor_id', $doctorId);
                    $q->whereBetween('purchase_date', [$from, $to]);
                }, function ($q) use ($date, $category, $doctorId) {
                    if ($category) $q->where('category', $category);
                    if ($doctorId) $q->where('doctor_id', $doctorId);
                    $q->where('purchase_date', $date);
                })->sum('amount_paid'),
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

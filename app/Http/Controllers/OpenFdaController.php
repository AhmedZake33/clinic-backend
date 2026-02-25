<?php

namespace App\Http\Controllers;

use App\Models\EgyptDrug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class OpenFdaController extends Controller
{
    private const BASE_URL = 'https://api.fda.gov/drug';

    /**
     * Search Egyptian drugs from local database.
     */
    public function searchEgyptDrugs(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:1|max:100',
            'category' => 'nullable|string|max:50',
            'form' => 'nullable|string|max:50',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $query = $request->input('query');
        $limit = $request->input('limit', 15);

        $builder = EgyptDrug::where('name', 'LIKE', "%{$query}%");

        if ($category = $request->input('category')) {
            $builder->where('category', $category);
        }
        if ($form = $request->input('form')) {
            $builder->where('form', $form);
        }

        $results = $builder->orderByRaw("CASE WHEN name LIKE ? THEN 0 ELSE 1 END", ["{$query}%"])
            ->limit($limit)
            ->get();

        return response()->json([
            'results' => $results,
            'total'   => $results->count(),
        ]);
    }

    /**
     * Get distinct categories for filter dropdowns.
     */
    public function egyptDrugFilters()
    {
        $categories = EgyptDrug::select('category')
            ->distinct()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->orderBy('category')
            ->pluck('category');

        $forms = EgyptDrug::select('form')
            ->distinct()
            ->whereNotNull('form')
            ->where('form', '!=', '')
            ->orderBy('form')
            ->pluck('form');

        return response()->json([
            'categories' => $categories,
            'forms'      => $forms,
        ]);
    }

    /**
     * Search drugs by name using OpenFDA drug label endpoint.
     */
    public function searchDrugs(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:2|max:100',
            'limit' => 'nullable|integer|min:1|max:25',
        ]);

        $query = $request->input('query');
        $limit = $request->input('limit', 10);

        try {
            $searchTerm = urlencode($query);
            $response = Http::timeout(10)->get(self::BASE_URL . '/label.json', [
                'search' => "openfda.brand_name:\"{$query}\"+openfda.generic_name:\"{$query}\"",
                'limit'  => $limit,
            ]);

            if ($response->failed()) {
                // OpenFDA returns 404 when no results found
                if ($response->status() === 404) {
                    return response()->json(['results' => [], 'total' => 0]);
                }
                return response()->json(['error' => 'Failed to fetch data from OpenFDA'], 502);
            }

            $data = $response->json();
            $results = collect($data['results'] ?? [])->map(function ($item) {
                return [
                    'brand_name'       => $item['openfda']['brand_name'][0] ?? null,
                    'generic_name'     => $item['openfda']['generic_name'][0] ?? null,
                    'manufacturer'     => $item['openfda']['manufacturer_name'][0] ?? null,
                    'route'            => $item['openfda']['route'][0] ?? null,
                    'substance_name'   => $item['openfda']['substance_name'][0] ?? null,
                    'product_type'     => $item['openfda']['product_type'][0] ?? null,
                    'dosage_form'      => $item['openfda']['dosage_form'][0] ?? null,
                    'indications'      => $item['indications_and_usage'][0] ?? null,
                    'dosage'           => $item['dosage_and_administration'][0] ?? null,
                    'warnings'         => $item['warnings'][0] ?? null,
                    'adverse_reactions' => $item['adverse_reactions'][0] ?? null,
                    'drug_interactions' => $item['drug_interactions'][0] ?? null,
                ];
            })->filter(function ($item) {
                return $item['brand_name'] || $item['generic_name'];
            })->values();

            return response()->json([
                'results' => $results,
                'total'   => $data['meta']['results']['total'] ?? count($results),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to connect to OpenFDA API'], 503);
        }
    }

    /**
     * Get detailed drug information by brand or generic name.
     */
    public function drugDetails(Request $request)
    {
        $request->validate([
            'name' => 'required|string|min:2|max:100',
        ]);

        $name = $request->input('name');

        try {
            $response = Http::timeout(10)->get(self::BASE_URL . '/label.json', [
                'search' => "openfda.brand_name:\"{$name}\"",
                'limit'  => 1,
            ]);

            if ($response->failed()) {
                if ($response->status() === 404) {
                    return response()->json(['error' => 'Drug not found'], 404);
                }
                return response()->json(['error' => 'Failed to fetch data from OpenFDA'], 502);
            }

            $data = $response->json();
            $item = $data['results'][0] ?? null;

            if (!$item) {
                return response()->json(['error' => 'Drug not found'], 404);
            }

            return response()->json([
                'brand_name'            => $item['openfda']['brand_name'][0] ?? null,
                'generic_name'          => $item['openfda']['generic_name'][0] ?? null,
                'manufacturer'          => $item['openfda']['manufacturer_name'][0] ?? null,
                'route'                 => $item['openfda']['route'][0] ?? null,
                'substance_name'        => $item['openfda']['substance_name'][0] ?? null,
                'product_type'          => $item['openfda']['product_type'][0] ?? null,
                'dosage_form'           => $item['openfda']['dosage_form'][0] ?? null,
                'indications'           => $item['indications_and_usage'][0] ?? null,
                'dosage'                => $item['dosage_and_administration'][0] ?? null,
                'warnings'              => $item['warnings'][0] ?? null,
                'precautions'           => $item['precautions'][0] ?? null,
                'adverse_reactions'     => $item['adverse_reactions'][0] ?? null,
                'drug_interactions'     => $item['drug_interactions'][0] ?? null,
                'contraindications'     => $item['contraindications'][0] ?? null,
                'how_supplied'          => $item['how_supplied'][0] ?? null,
                'storage_and_handling'  => $item['storage_and_handling'][0] ?? null,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to connect to OpenFDA API'], 503);
        }
    }
}

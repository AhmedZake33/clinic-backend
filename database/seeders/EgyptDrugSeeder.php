<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EgyptDrugSeeder extends Seeder
{
    public function run(): void
    {
        // Truncate table first
        DB::table('egypt_drugs')->truncate();

        // Import main dataset
        $this->importFile('egypt_drugs.json', 'main dataset');

        // Import supplementary common Egyptian drugs
        $this->importFile('egypt_drugs_supplement.json', 'supplementary common drugs');

        $total = DB::table('egypt_drugs')->count();
        $this->command->info("Total Egyptian drugs in database: {$total}");
    }

    private function importFile(string $filename, string $label): void
    {
        $jsonPath = database_path("seeders/{$filename}");

        if (!file_exists($jsonPath)) {
            $this->command->warn("{$filename} not found at: {$jsonPath} — skipping {$label}.");
            return;
        }

        $drugs = json_decode(file_get_contents($jsonPath), true);

        if (empty($drugs)) {
            $this->command->warn("No drugs found in {$filename} — skipping {$label}.");
            return;
        }

        $this->command->info("Importing " . count($drugs) . " {$label}...");

        $chunks = array_chunk($drugs, 500);
        $bar = $this->command->getOutput()->createProgressBar(count($chunks));

        foreach ($chunks as $chunk) {
            $records = array_map(function ($drug) {
                return [
                    'name'       => $drug['name'],
                    'price'      => $drug['price'] ?? 0,
                    'form'       => $drug['form'] ?: null,
                    'company'    => $drug['company'] ?: null,
                    'category'   => $drug['category'] ?: null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }, $chunk);

            DB::table('egypt_drugs')->insert($records);
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
    }
}

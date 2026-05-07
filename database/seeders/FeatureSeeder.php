<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $features = [
            ['key' => 'reservations',  'name' => 'الحجوزات',        'name_en' => 'Reservations',  'icon' => 'ClipboardIcon',    'sort_order' => 1],
            ['key' => 'clients',       'name' => 'المرضى',           'name_en' => 'Clients',       'icon' => 'UsersIcon',        'sort_order' => 2],
            ['key' => 'schedule',      'name' => 'الجدول الزمني',    'name_en' => 'Schedule',      'icon' => 'ClockIcon',        'sort_order' => 3],
            ['key' => 'waiting-queue', 'name' => 'قائمة الانتظار',   'name_en' => 'Waiting Queue', 'icon' => 'ListIcon',         'sort_order' => 4],
            ['key' => 'financials',    'name' => 'الحسابات',         'name_en' => 'Financials',    'icon' => 'DollarSignIcon',   'sort_order' => 5],
            ['key' => 'purchases',     'name' => 'المشتريات',        'name_en' => 'Purchases',     'icon' => 'ShoppingCartIcon', 'sort_order' => 6],
            ['key' => 'assistants',    'name' => 'المساعدون',        'name_en' => 'Assistants',    'icon' => 'UserPlusIcon',     'sort_order' => 7],
            ['key' => 'reports',       'name' => 'التقارير',         'name_en' => 'Reports',       'icon' => 'BarChart2Icon',    'sort_order' => 8],
            ['key' => 'drugs',         'name' => 'الأدوية',          'name_en' => 'Drugs',         'icon' => 'ActivityIcon',     'sort_order' => 9],
        ];

        foreach ($features as $feature) {
            \App\Models\Feature::updateOrCreate(['key' => $feature['key']], $feature);
        }
    }
}

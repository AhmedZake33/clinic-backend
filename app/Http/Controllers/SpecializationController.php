<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SpecializationController extends Controller
{
    public function index()
    {
        $specializations = [
            ['value' => 'Allergy and Immunology', 'labels' => ['en' => 'Allergy and Immunology', 'ar' => 'الحساسية والمناعة']],
            ['value' => 'Anesthesiology', 'labels' => ['en' => 'Anesthesiology', 'ar' => 'التخدير']],
            ['value' => 'Audiology', 'labels' => ['en' => 'Audiology', 'ar' => 'السمعيات']],
            ['value' => 'Bariatric Surgery', 'labels' => ['en' => 'Bariatric Surgery', 'ar' => 'جراحة السمنة']],
            ['value' => 'Cardiology', 'labels' => ['en' => 'Cardiology', 'ar' => 'أمراض القلب']],
            ['value' => 'Cardiothoracic Surgery', 'labels' => ['en' => 'Cardiothoracic Surgery', 'ar' => 'جراحة القلب والصدر']],
            ['value' => 'Dentistry', 'labels' => ['en' => 'Dentistry', 'ar' => 'طب الأسنان']],
            ['value' => 'Dermatology', 'labels' => ['en' => 'Dermatology', 'ar' => 'الأمراض الجلدية']],
            ['value' => 'Emergency Medicine', 'labels' => ['en' => 'Emergency Medicine', 'ar' => 'طب الطوارئ']],
            ['value' => 'Endocrinology', 'labels' => ['en' => 'Endocrinology', 'ar' => 'الغدد الصماء']],
            ['value' => 'ENT', 'labels' => ['en' => 'ENT', 'ar' => 'الأنف والأذن والحنجرة']],
            ['value' => 'Family Medicine', 'labels' => ['en' => 'Family Medicine', 'ar' => 'طب الأسرة']],
            ['value' => 'Gastroenterology', 'labels' => ['en' => 'Gastroenterology', 'ar' => 'الجهاز الهضمي']],
            ['value' => 'General Practice', 'labels' => ['en' => 'General Practice', 'ar' => 'طب عام']],
            ['value' => 'General Surgery', 'labels' => ['en' => 'General Surgery', 'ar' => 'جراحة عامة']],
            ['value' => 'Geriatrics', 'labels' => ['en' => 'Geriatrics', 'ar' => 'طب المسنين']],
            ['value' => 'Gynecology', 'labels' => ['en' => 'Gynecology', 'ar' => 'أمراض النساء']],
            ['value' => 'Hematology', 'labels' => ['en' => 'Hematology', 'ar' => 'أمراض الدم']],
            ['value' => 'Hepatology', 'labels' => ['en' => 'Hepatology', 'ar' => 'أمراض الكبد']],
            ['value' => 'Internal Medicine', 'labels' => ['en' => 'Internal Medicine', 'ar' => 'الباطنة']],
            ['value' => 'Nephrology', 'labels' => ['en' => 'Nephrology', 'ar' => 'أمراض الكلى']],
            ['value' => 'Neurology', 'labels' => ['en' => 'Neurology', 'ar' => 'المخ والأعصاب']],
            ['value' => 'Neurosurgery', 'labels' => ['en' => 'Neurosurgery', 'ar' => 'جراحة المخ والأعصاب']],
            ['value' => 'Nutrition', 'labels' => ['en' => 'Nutrition', 'ar' => 'التغذية العلاجية']],
            ['value' => 'Obstetrics and Gynecology', 'labels' => ['en' => 'Obstetrics and Gynecology', 'ar' => 'النساء والتوليد']],
            ['value' => 'Oncology', 'labels' => ['en' => 'Oncology', 'ar' => 'الأورام']],
            ['value' => 'Ophthalmology', 'labels' => ['en' => 'Ophthalmology', 'ar' => 'طب العيون']],
            ['value' => 'Oral and Maxillofacial Surgery', 'labels' => ['en' => 'Oral and Maxillofacial Surgery', 'ar' => 'جراحة الفم والوجه والفكين']],
            ['value' => 'Orthodontics', 'labels' => ['en' => 'Orthodontics', 'ar' => 'تقويم الأسنان']],
            ['value' => 'Orthopedics', 'labels' => ['en' => 'Orthopedics', 'ar' => 'العظام']],
            ['value' => 'Pediatrics', 'labels' => ['en' => 'Pediatrics', 'ar' => 'الأطفال']],
            ['value' => 'Pediatric Surgery', 'labels' => ['en' => 'Pediatric Surgery', 'ar' => 'جراحة الأطفال']],
            ['value' => 'Physical Therapy', 'labels' => ['en' => 'Physical Therapy', 'ar' => 'العلاج الطبيعي']],
            ['value' => 'Plastic Surgery', 'labels' => ['en' => 'Plastic Surgery', 'ar' => 'جراحة التجميل']],
            ['value' => 'Psychiatry', 'labels' => ['en' => 'Psychiatry', 'ar' => 'الطب النفسي']],
            ['value' => 'Pulmonology', 'labels' => ['en' => 'Pulmonology', 'ar' => 'الصدر والجهاز التنفسي']],
            ['value' => 'Radiology', 'labels' => ['en' => 'Radiology', 'ar' => 'الأشعة']],
            ['value' => 'Rheumatology', 'labels' => ['en' => 'Rheumatology', 'ar' => 'الروماتيزم والمناعة']],
            ['value' => 'Urology', 'labels' => ['en' => 'Urology', 'ar' => 'المسالك البولية']],
            ['value' => 'Vascular Surgery', 'labels' => ['en' => 'Vascular Surgery', 'ar' => 'جراحة الأوعية الدموية']],
        ];

        return response()->json(array_map(function (array $specialization) {
            $specialization['anatomy_map_key'] = $this->anatomyMapKey($specialization['value']);

            return $specialization;
        }, $specializations));
    }

    private function anatomyMapKey(string $specialization): string
    {
        return [
            'Allergy and Immunology' => 'allergy_immunology',
            'Anesthesiology' => 'anesthesiology',
            'Audiology' => 'audiology',
            'Bariatric Surgery' => 'bariatric_surgery',
            'Cardiology' => 'cardiology',
            'Cardiothoracic Surgery' => 'cardiothoracic_surgery',
            'Dentistry' => 'dentistry',
            'Dermatology' => 'dermatology',
            'Emergency Medicine' => 'emergency',
            'Endocrinology' => 'endocrinology',
            'ENT' => 'ent',
            'Family Medicine' => 'family_medicine',
            'Gastroenterology' => 'gastroenterology',
            'General Practice' => 'general_practice',
            'General Surgery' => 'general_surgery',
            'Geriatrics' => 'geriatrics',
            'Gynecology' => 'gynecology',
            'Hematology' => 'hematology',
            'Hepatology' => 'hepatology',
            'Internal Medicine' => 'internal_medicine',
            'Nephrology' => 'nephrology',
            'Neurology' => 'neurology',
            'Neurosurgery' => 'neurosurgery',
            'Nutrition' => 'nutrition',
            'Obstetrics and Gynecology' => 'gynecology',
            'Oncology' => 'oncology',
            'Ophthalmology' => 'ophthalmology',
            'Oral and Maxillofacial Surgery' => 'dentistry',
            'Orthodontics' => 'dentistry',
            'Orthopedics' => 'orthopedics',
            'Pediatrics' => 'pediatrics',
            'Pediatric Surgery' => 'pediatric_surgery',
            'Physical Therapy' => 'physical_therapy',
            'Plastic Surgery' => 'plastic_surgery',
            'Psychiatry' => 'psychiatry',
            'Pulmonology' => 'pulmonology',
            'Radiology' => 'radiology',
            'Rheumatology' => 'rheumatology',
            'Urology' => 'urology',
            'Vascular Surgery' => 'vascular_surgery',
        ][$specialization] ?? 'general_practice';
    }
}

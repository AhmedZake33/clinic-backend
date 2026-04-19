<?php

namespace App\Enums;

enum PurchaseCategory: string
{
    case MedicalSupplies = 'Medical Supplies';
    case Equipment = 'Equipment';
    case Services = 'Services';
    case Maintenance = 'Maintenance';
    case Other = 'Other';

    public static function values(): array
    {
        return array_map(fn($c) => $c->value, self::cases());
    }

    public static function labels(string $locale = 'en'): array
    {
        $map = [
            'en' => [
                'Medical Supplies' => 'Medical Supplies',
                'Equipment' => 'Equipment',
                'Services' => 'Services',
                'Maintenance' => 'Maintenance',
                'Other' => 'Other',
            ],
            'ar' => [
                'Medical Supplies' => 'مستلزمات طبية',
                'Equipment' => 'معدات',
                'Services' => 'خدمات',
                'Maintenance' => 'صيانة',
                'Other' => 'أخرى',
            ],
        ];

        return $map[$locale] ?? $map['en'];
    }

    public static function localizedOptions(string $locale = 'en'): array
    {
        $labels = self::labels($locale);
        return array_map(fn($value) => ['value' => $value, 'label' => $labels[$value] ?? $value], self::values());
    }
}

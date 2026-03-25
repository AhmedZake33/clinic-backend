<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoctorNotificationSetting extends Model
{
    protected $fillable = [
        'doctor_id',
        'sms_enabled',
        'whatsapp_enabled',
        'reminder_hours_before',
        'sms_template',
        'whatsapp_template_name',
    ];

    protected $casts = [
        'sms_enabled' => 'boolean',
        'whatsapp_enabled' => 'boolean',
        'reminder_hours_before' => 'integer',
    ];

    public const DEFAULT_SMS_TEMPLATE = 'مرحباً {client_name}، نذكرك بموعدك غداً في عيادة د. {doctor_name} الساعة {time}. للإلغاء أو التعديل يرجى التواصل معنا.';

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function getEffectiveSmsTemplate(): string
    {
        return $this->sms_template ?: self::DEFAULT_SMS_TEMPLATE;
    }
}

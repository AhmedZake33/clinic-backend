<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\DoctorNotificationSetting;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

class NotificationSettingsController extends Controller
{
    use ResolvesDoctor;

    public function show(Request $request)
    {
        $doctorId = $this->requireDoctorId($request);

        $settings = DoctorNotificationSetting::firstOrCreate(
            ['doctor_id' => $doctorId],
            [
                'sms_enabled' => false,
                'whatsapp_enabled' => false,
                'reminder_hours_before' => 24,
                'sms_template' => DoctorNotificationSetting::DEFAULT_SMS_TEMPLATE,
                'whatsapp_template_name' => 'appointment_reminder',
            ]
        );

        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $doctorId = $this->requireDoctorId($request);

        $request->validate([
            'sms_enabled' => 'required|boolean',
            'whatsapp_enabled' => 'required|boolean',
            'sms_template' => 'nullable|string|max:500',
            'whatsapp_template_name' => 'nullable|string|max:255',
        ]);

        $settings = DoctorNotificationSetting::updateOrCreate(
            ['doctor_id' => $doctorId],
            $request->only(['sms_enabled', 'whatsapp_enabled', 'sms_template', 'whatsapp_template_name'])
        );

        return response()->json($settings);
    }

    public function testSms(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        $doctorName = $request->user()->name;
        $template = DoctorNotificationSetting::DEFAULT_SMS_TEMPLATE;
        $message = str_replace(
            ['{client_name}', '{doctor_name}', '{time}'],
            ['مريض تجريبي', $doctorName, '10:00 AM'],
            $template,
        );

        $service = app(SmsService::class);
        $result = $service->send($request->phone, $message);

        return response()->json($result);
    }

    public function testWhatsapp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        $doctorName = $request->user()->name;
        $doctorId = $this->requireDoctorId($request);
        $settings = DoctorNotificationSetting::where('doctor_id', $doctorId)->first();
        $templateName = $settings?->whatsapp_template_name ?? 'appointment_reminder';

        $service = app(WhatsAppService::class);
        $result = $service->sendTemplateMessage(
            $request->phone,
            $templateName,
            [
                'client_name' => 'مريض تجريبي',
                'doctor_name' => $doctorName,
                'time' => '10:00 AM',
            ],
        );

        return response()->json($result);
    }
}

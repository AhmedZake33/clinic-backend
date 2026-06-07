<?php

namespace App\Http\Controllers;

use App\Exceptions\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\Contracts\WhatsAppMessageSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WhatsAppTestController extends Controller
{
    public function sendFromWeb(Request $request, WhatsAppMessageSender $sender): JsonResponse
    {
        if (! $request->filled('chat_id') || ! $request->filled('message')) {
            return response()->json([
                'message' => 'Add chat_id and message query parameters to send a WhatsApp test message.',
                'example' => url('/test-whatsapp') . '?chat_id=%2B201234567890&message=Test%20message',
            ]);
        }

        $validator = Validator::make($request->query(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid WhatsApp test message payload.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->sendValidated($validator->validated(), $sender);
    }

    public function send(Request $request, WhatsAppMessageSender $sender): JsonResponse
    {
        $validated = $request->validate($this->rules());

        return $this->sendValidated($validated, $sender);
    }

    public function sendImage(Request $request, WhatsAppMessageSender $sender): JsonResponse
    {
        $validated = $request->validate([
            'chat_id' => ['required', 'string', 'max:50'],
            'image_url' => ['required_without:imageUrl', 'url', 'max:2048'],
            'imageUrl' => ['required_without:image_url', 'url', 'max:2048'],
            'caption' => ['nullable', 'string', 'max:1024'],
        ]);

        try {
            $result = $sender->sendImage(
                $validated['chat_id'],
                $validated['image_url'] ?? $validated['imageUrl'],
                $validated['caption'] ?? null,
            );
        } catch (WhatsAppException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 500);
        } catch (ConnectionException) {
            return response()->json([
                'message' => 'Failed to connect to WhatsApp provider.',
            ], 503);
        }

        return response()->json([
            'message' => $result['successful']
                ? 'WhatsApp image sent.'
                : 'WhatsApp provider rejected the image.',
            'provider' => config('services.whatsapp.driver'),
            'result' => $result,
        ], $result['successful'] ? 200 : 502);
    }

    private function sendValidated(array $validated, WhatsAppMessageSender $sender): JsonResponse
    {
        try {
            $result = $sender->sendText(
                $validated['chat_id'],
                $validated['message'],
                [
                    'priority' => $validated['priority'] ?? null,
                    'send_at' => $validated['send_at'] ?? null,
                ],
            );
        } catch (WhatsAppException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 500);
        } catch (ConnectionException) {
            return response()->json([
                'message' => 'Failed to connect to WhatsApp provider.',
            ], 503);
        }

        return response()->json([
            'message' => $result['successful']
                ? 'WhatsApp message sent.'
                : 'WhatsApp provider rejected the message.',
            'provider' => config('services.whatsapp.driver'),
            'result' => $result,
        ], $result['successful'] ? 200 : 502);
    }

    private function rules(): array
    {
        return [
            'chat_id' => ['required', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:4096'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'send_at' => ['nullable', 'date'],
        ];
    }
}

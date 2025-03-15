<?php

namespace App\Http\Controllers;

use App\Models\Recipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PostfixController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (count($request->all()) === 0) {
            return response()->json();
        }

        $validator = Validator::make($request->all(), [
            '*.id' => ['required', 'string'],
            '*.status' => ['required', 'string'],
            '*.response' => ['required', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $existingRecipients = Recipient::whereIn('postfix_id', collect($request->all())->pluck('id'))
            ->get(['postfix_id', 'message_id', 'batch_id', 'email', 'replacements', 'headers'])
            ->keyBy('postfix_id');

        $data = [];
        foreach ($request->all() as $item) {
            if (!isset($existingRecipients[$item['id']])) {
                continue;
            }

            $time = now()->format('Y-m-d H:i:s.u');
            $data[] = [
                'postfix_id' => $item['id'],
                'postfix_status' => $item['status'],
                'postfix_response' => $item['response'],
                'message_id' => $existingRecipients[$item['id']]->message_id,
                'batch_id' => $existingRecipients[$item['id']]->batch_id,
                'email' => $existingRecipients[$item['id']]->email,
                'status' => $item['status'] === 'sent' ? 'delivered' : 'failed',
                'replacements' => json_encode($existingRecipients[$item['id']]->replacements),
                'headers' => json_encode($existingRecipients[$item['id']]->headers),
                'updated_at' => $time
            ];
        }

        if (empty($data)) {
            return response()->json();
        }

        Recipient::upsert($data, ['postfix_id'], ['status', 'postfix_status', 'postfix_response', 'updated_at']);

        return response()->json();
    }
}

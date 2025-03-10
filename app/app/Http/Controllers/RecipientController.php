<?php

namespace App\Http\Controllers;

use App\Jobs\Send;
use App\Models\Message;
use App\Models\Recipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RecipientController extends Controller
{
    public function index($messageId, Request $request): Response|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['in:created,delivered,failed'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $status = $request->input('status');
        $bindings = [$messageId];

        $sql = <<<SQL
SELECT
    JSON_OBJECT(
        "id", id,
        "email", email,
        "status", status,
        "postfix_status", postfix_status,
        "postfix_response", postfix_response,
        "updated_at", updated_at
    ) as json
FROM
    recipients
WHERE
    message_id = ?
SQL;

        if ($status) {
            $sql .= " AND status = ?";
            $bindings[] = $status;
        }

        $recipients = DB::select($sql, $bindings);
        $recipients = array_column($recipients, 'json');

        $recipients = '{"recipients":[' . implode(",", $recipients) . ']}';

        return response($recipients, 200)->header('Content-Type', 'application/json');
    }

    public function store(Message $message, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'delay' => ['date_format:Y-m-d\TH:i:s.u\Z'],
            'recipients' => ['required', 'array', 'min:1', 'max:2000'],
            'recipients.*.email' => ['required', 'email'],
            'recipients.*.replacements' => ['array', 'max:10'],
            'recipients.*.replacements.*.search' => ['required', 'string'],
            'recipients.*.replacements.*.replace' => ['present', 'nullable'],
            'recipients.*.headers' => ['array', 'max:10'],
            'recipients.*.headers.*.key' => ['required', 'string'],
            'recipients.*.headers.*.value' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $recipients = [];
        $batchId = Str::uuid();
        foreach ($request->recipients as $recipient) {
            $time = now()->format('Y-m-d H:i:s.u');
            $recipients[] = [
                'message_id' => $message->id,
                'batch_id' => $batchId,
                'email' => $recipient['email'],
                'status' => 'created',
                'replacements' => json_encode($recipient['replacements'] ?? []),
                'headers' => json_encode($recipient['headers'] ?? []),
                'created_at' => $time,
                'updated_at' => $time
            ];
        }

        $delay = null;
        if ($request->delay) {
            $delay = Carbon::parse($request->delay);
        }

        DB::transaction(function () use ($recipients, $batchId, $delay) {
            Recipient::insert($recipients);
            Send::dispatch($batchId)->onQueue('send')->delay($delay);
        });

        return response()->json([
            'recipients' => $message->recipients()->where('batch_id', $batchId)->get()
        ], 200);
    }

    public function progress($messageId): JsonResponse
    {
        $counts = Recipient::where('message_id', $messageId)
            ->selectRaw("SUM(CASE WHEN status = 'created' THEN 1 ELSE 0 END) as created_count, COUNT(*) as total_count")
            ->first();

        $progress = $counts->total_count == 0
            ? 0
            : ($counts->total_count - $counts->created_count) / $counts->total_count * 100;

        return response()->json([
            'progress' => number_format($progress, 2, '.', '')
        ]);
    }
}

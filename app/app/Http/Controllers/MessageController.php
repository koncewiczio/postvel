<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dkim_signer_domain' => ['required', 'string', 'max:200', function ($attribute, $value, $fail) {
                if (!Storage::disk('dkim')->exists($value . '.private')) {
                    $fail('DKIM key file not found for the specified domain.');
                }
            }],
            'dkim_signer_sector' => ['required', 'string', 'max:200'],
            'from_title' => ['required', 'string', 'max:200'],
            'from_email' => ['required', 'email', 'max:200'],
            'subject' => ['required', 'string', 'max:200'],
            'replay_to' => ['required', 'email', 'max:200'],
            'body' => ['required', 'string', 'max:1000000'], // ~1MB
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $message = new Message;
        $message->dkim_signer_domain = $request->dkim_signer_domain;
        $message->dkim_signer_sector = $request->dkim_signer_sector;
        $message->from_title = $request->from_title;
        $message->from_email = $request->from_email;
        $message->subject = $request->subject;
        $message->replay_to = $request->replay_to;
        $message->body = $request->body;
        $message->save();

        return response()->json([
            'message' => Message::find($message->id),
        ]);
    }
}

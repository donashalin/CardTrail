<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InboundMessage;
use Inertia\Inertia;
use Inertia\Response;

class InboundMessageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/inbound/Index', [
            'address' => config('cardtrail.receipts.address'),
            'messages' => InboundMessage::query()
                ->with('user:id,name')
                ->latest('id')
                ->paginate(50)
                ->through(fn (InboundMessage $message) => [
                    'id' => $message->id,
                    'from_email' => $message->from_email,
                    'subject' => $message->subject,
                    'user' => $message->user?->name,
                    'outcome' => $message->outcome->value,
                    'attachment_count' => $message->attachment_count,
                    'accepted_count' => $message->accepted_count,
                    'received_at' => ($message->received_at ?? $message->created_at)->toIso8601String(),
                ]),
        ]);
    }
}

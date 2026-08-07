<?php

namespace App\Http\Controllers;

use App\Jobs\HandleStripeWebhook;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $sig     = $request->header('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sig,
                config('services.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException) {
            return response('Invalid signature', 400);
        }

        try {
            $id = DB::table('webhook_events')->insertGetId([
                'stripe_event_id' => $event->id,
                'type'            => $event->type,
                'payload'         => $payload,
                'status'          => 'received',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response('ok', 200);
        }

        HandleStripeWebhook::dispatch($id)->afterCommit();

        return response('ok', 200);
    }
}

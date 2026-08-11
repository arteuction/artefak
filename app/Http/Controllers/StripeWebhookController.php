<?php

declare(strict_types=1);

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
        $secret  = config('services.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return response('Webhook secret not configured', 500);
        }

        try {
            $event = Webhook::constructEvent($payload, $sig, $secret);
        } catch (SignatureVerificationException) {
            return response('Invalid signature', 400);
        }

        try {
            DB::transaction(function () use ($payload, $event): void {
                $id = DB::table('webhook_events')->insertGetId([
                    'stripe_event_id' => $event->id,
                    'type'            => $event->type,
                    'payload'         => $payload,
                    'status'          => 'received',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                HandleStripeWebhook::dispatch($id)->afterCommit();
            });
        } catch (UniqueConstraintViolationException) {
            return response('ok', 200);
        }

        return response('ok', 200);
    }
}

<?php

namespace App\Contracts;

use App\Models\Payment;
use Illuminate\Http\Request;

interface PaymentProvider
{
    public function createCheckout(Payment $payment): array;

    public function verifyWebhook(Request $request): array;
}

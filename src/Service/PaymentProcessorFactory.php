<?php

namespace A2nt\UserFormsPayments\Service;

use Omnipay\Stripe\CheckoutGateway;

final class PaymentProcessorFactory
{
    public static function create(string $gateway): PaymentProcessorInterface
    {
        $normalized = ltrim($gateway, '\\');

        if ($normalized === CheckoutGateway::class) {
            return new StripeCheckoutPaymentProcessor();
        }

        return new OmnipayPaymentProcessor();
    }
}

<?php

namespace A2nt\UserFormsPayments\Service;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Parser\DecimalMoneyParser;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

/**
 * Builds Stripe Checkout `line_items` from a submitted form (UserForms payment items) or a single total.
 */
final class CheckoutLineItemsBuilder
{
    /**
     * @param array<string, mixed> $data Extra initiate data (e.g. description override)
     * @return list<array<string, mixed>>
     */
    public static function forSubmittedForm(SubmittedForm $obj, array $data = []): array
    {
        $currencyCode = strtoupper((string) ($obj->CurrencyCode ?: 'USD'));
        $items = $obj->getPaymentItems();

        if (is_array($items) && $items !== []) {
            $lineItems = [];
            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['price'])) {
                    continue;
                }
                $qty = max(1, (int) ($item['quantity'] ?? 1));
                $name = (string) ($item['name'] ?? $item['description'] ?? 'Item');
                $lineItems[] = [
                    'quantity' => $qty,
                    'price_data' => [
                        'currency' => strtolower($currencyCode),
                        'unit_amount' => self::amountToMinorUnits((float) $item['price'], $currencyCode),
                        'product_data' => [
                            'name' => $name,
                        ],
                    ],
                ];
            }
            if ($lineItems !== []) {
                return $lineItems;
            }
        }

        $amount = (float) $obj->Amount;
        $description = (string) ($data['description'] ?? $obj->Title ?? 'Payment');

        return [
            [
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currencyCode),
                    'unit_amount' => self::amountToMinorUnits($amount, $currencyCode),
                    'product_data' => [
                        'name' => $description,
                    ],
                ],
            ],
        ];
    }

    private static function amountToMinorUnits(float $amount, string $currencyCode): int
    {
        $currencies = new ISOCurrencies();
        $currency = new Currency($currencyCode);
        $parser = new DecimalMoneyParser($currencies);
        $subunit = $currencies->subunitFor($currency);
        $formatted = number_format($amount, $subunit, '.', '');

        return (int) $parser->parse($formatted, $currency)->getAmount();
    }
}

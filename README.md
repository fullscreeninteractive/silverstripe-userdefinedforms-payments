# silverstripe-userdefinedforms-payments

User Defined Forms Conditional Payments
Let's you add conditions to calculate amount and require payment using omnipay module

+ Install it using composer
`composer require a2nt/userdefinedforms-payments`

+ Define Payment configuration
app/_config/api-payment.yml

```yaml
---
Name: 'webapp-api-payment'
---
SilverStripe\Omnipay\Model\Payment:
  allowed_gateways:
    - 'PayPal_Express'

SilverStripe\Omnipay\GatewayInfo:
  PayPal_Express:
    parameters:
      username: ''
      password: ''
      signature: ''
      testMode: true # Make sure to override this to false
```

+ Rebuild the database and flush the cache.

+ Define Payment Rules of a specific User Form at the CMS

![Screenshot from 2021-07-07 00-04-03](https://user-images.githubusercontent.com/672794/124674513-2ec75c80-debb-11eb-86c6-28fc4733ef1e.png)

## Stripe Checkout

When the form’s **Payment gateway** (or site default) is `Omnipay\Stripe\CheckoutGateway`, checkout is handled with the **Stripe PHP SDK** directly (`\Stripe\Checkout\Session::create()`), not via Omnipay’s `ServiceFactory`. Implementation: `A2nt\UserFormsPayments\Service\StripeCheckoutPaymentProcessor`.

### Versions

| Piece | Version / note |
|--------|----------------|
| **Composer package** | [`stripe/stripe-php`](https://github.com/stripe/stripe-php) `^13` or `^14` (see this package’s `composer.json`) |
| **Stripe API version** | Each `stripe/stripe-php` release pins a **dated** Stripe API version via `Stripe\Util\ApiVersion::CURRENT` (e.g. `2023-10-16` in many v13/v14 releases). Your project’s exact value is in `vendor/stripe/stripe-php/lib/Util/ApiVersion.php` after `composer install`. Override per request with the `Stripe-Version` header if you need to (see Stripe’s docs). |
| **Product** | [Stripe Checkout](https://stripe.com/docs/payments/checkout) (hosted Checkout page; `mode: payment` with `line_items` built from the submission). |

The Composer constraint is `"stripe/stripe-php": "^13 || ^14"` (either major line satisfies the requirement).

Configure the secret key the same way as Omnipay Stripe: `SilverStripe\Omnipay\GatewayInfo` parameters for `\Omnipay\Stripe\CheckoutGateway` (e.g. `apiKey`), or fall back to the `STRIPE_SK_KEY` environment variable (see `StripeCheckoutPaymentProcessor::getStripeSecretKey()`).

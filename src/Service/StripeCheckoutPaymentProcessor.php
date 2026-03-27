<?php

namespace A2nt\UserFormsPayments\Service;

use A2nt\UserFormsPayments\Controllers\UserFormsPaymentController;
use Omnipay\Stripe\CheckoutGateway;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\Omnipay\GatewayInfo;
use SilverStripe\Omnipay\Helper\ErrorHandling;
use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\Omnipay\Service\ServiceResponse;
use SilverStripe\UserForms\Model\EditableFormField\EditableEmailField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;

final class StripeCheckoutPaymentProcessor implements PaymentProcessorInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function process(SubmittedForm $obj, UserFormsPaymentController $controller, array $data = []): HTTPResponse
    {
        $gateway = CheckoutGateway::class;
        $secret = self::getStripeSecretKey($gateway);

        if ($secret === '') {
            return HTTPResponse::create('Stripe API key is not configured for Checkout.', 500);
        }

        $payment = Payment::create()
            ->init($gateway, $obj->Amount, $obj->CurrencyCode ?? $controller->config()->get('default_currency_code'))
            ->setSuccessUrl($controller->Link('complete') . '/' . $controller->getShortPayableObjectName($obj::class) . '/' . $obj->ID)
            ->setFailureUrl($controller->Link('canceled') . '/' . $controller->getShortPayableObjectName($obj::class) . '/' . $obj->ID);

        $payment->setField('SubmittedFormID', $obj->ID);
        $payment->write();

        $data['rp_invoice_id'] = $obj->OrderID;
        $data['custom'] = $obj->OrderID;
        $data['invoice'] = $obj->OrderID;
        $controller->extend('updateProcessPaymentData', $data, $obj);

        $successUrl = Director::absoluteURL(
            $controller->Link('complete') . '/' . $controller->getShortPayableObjectName($obj::class) . '/' . $obj->ID
        );
        if (!str_contains($successUrl, '{CHECKOUT_SESSION_ID}')) {
            $successUrl .= (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';
        }

        $cancelUrl = Director::absoluteURL(
            $controller->Link('canceled') . '/' . $controller->getShortPayableObjectName($obj::class) . '/' . $obj->ID
        );

        $lineItems = CheckoutLineItemsBuilder::forSubmittedForm($obj, $data);

        $customerEmail = self::resolveCustomerEmailFromSubmission($obj);

        $sessionPayload = [
            'mode' => 'payment',
            'client_reference_id' => $payment->Identifier,
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'submitted_form_id' => (string) $obj->ID,
                'order_id' => (string) $obj->OrderID,
            ],
        ];

        if ($customerEmail !== null) {
            $sessionPayload['customer_email'] = $customerEmail;
        }

        if (self::gatewayAllowsPromotionCodes($gateway)) {
            $sessionPayload['allow_promotion_codes'] = true;
        }

        try {
            $session = Session::create(
                $sessionPayload,
                ['api_key' => $secret]
            );
        } catch (ApiErrorException $e) {
            return HTTPResponse::create(
                htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
                502
            );
        }

        $payment->TransactionReference = $session->id;
        $payment->Status = 'PendingPurchase';
        $payment->write();

        return HTTPResponse::create()->redirect($session->url, 303);
    }

    public static function completeCheckoutSession(
        UserFormsPaymentController $controller,
        SubmittedForm $form,
        string $sessionId
    ): HTTPResponse {
        $gateway = CheckoutGateway::class;
        $secret = self::getStripeSecretKey($gateway);

        if ($secret === '') {
            return HTTPResponse::create('Stripe API key is not configured.', 500);
        }

        try {
            $session = Session::retrieve($sessionId, ['api_key' => $secret]);
        } catch (ApiErrorException $e) {
            return $controller->redirect($controller->Link('canceled') . '/' . $controller->getShortPayableObjectName($form::class) . '/' . $form->ID);
        }

        if ($session->payment_status !== 'paid') {
            return $controller->redirect($controller->Link('canceled') . '/' . $controller->getShortPayableObjectName($form::class) . '/' . $form->ID);
        }

        $payment = Payment::get()->filter('TransactionReference', $sessionId)->first();

        if (!$payment || (int) $payment->SubmittedFormID !== (int) $form->ID) {
            return $controller->httpError(403);
        }

        if ($payment->Status === 'Captured') {
            $page = $form->Parent();

            return $controller->redirect($page->Link('finished'));
        }

        $payment->Status = 'Captured';
        $payment->write();

        $serviceResponse = new ServiceResponse($payment, 0);
        ErrorHandling::safeExtend($payment, 'onCaptured', $serviceResponse);

        $page = $form->Parent();

        return $controller->redirect($page->Link('finished'));
    }

    public static function getStripeSecretKey(string $gateway): string
    {
        $params = self::getGatewayParameters($gateway);

        if (is_array($params)) {
            if (!empty($params['apiKey'])) {
                return (string) $params['apiKey'];
            }
            if (!empty($params['stripe_secret_key'])) {
                return (string) $params['stripe_secret_key'];
            }
        }

        return (string) Environment::getEnv('STRIPE_SK_KEY');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getGatewayParameters(string $gateway): ?array
    {
        foreach (self::gatewayConfigKeyCandidates($gateway) as $key) {
            $params = GatewayInfo::getParameters($key);
            if (is_array($params)) {
                return $params;
            }
        }

        return null;
    }

    /**
     * Reads {@link GatewayInfo} for this gateway (supports keys with or without a leading backslash).
     *
     * @return mixed null if unset
     */
    private static function getGatewayConfigSetting(string $gateway, string $key): mixed
    {
        foreach (self::gatewayConfigKeyCandidates($gateway) as $gw) {
            $value = GatewayInfo::getConfigSetting($gw, $key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function gatewayConfigKeyCandidates(string $gateway): array
    {
        return [
            $gateway,
            '\\' . ltrim($gateway, '\\'),
            ltrim($gateway, '\\'),
        ];
    }

    /**
     * @see https://stripe.com/docs/api/checkout/sessions/create#create_checkout_session-allow_promotion_codes
     */
    private static function gatewayAllowsPromotionCodes(string $gateway): bool
    {
        $value = self::getGatewayConfigSetting($gateway, 'allow_promotion_codes');

        return $value === true
            || $value === 1
            || $value === '1'
            || strtolower((string) $value) === 'true';
    }

    /**
     * If the parent form defines an {@link EditableEmailField} and the submission includes a valid value
     * for that field, return it so Stripe Checkout can pre-fill the customer email field.
     */
    private static function resolveCustomerEmailFromSubmission(SubmittedForm $obj): ?string
    {
        foreach ($obj->Values() as $field) {
            $isEmail = EditableEmailField::get()->filter([
                'Name' => $field->Name,
                'ParentID' => $obj->ParentID,
            ])->exists();

            if ($isEmail && trim((string) $field->Value) !== '') {
                return trim((string) $field->Value);
            }
        }

        return null;
    }
}

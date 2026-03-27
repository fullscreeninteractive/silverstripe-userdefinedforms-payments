<?php

namespace A2nt\UserFormsPayments\Controllers;

use A2nt\UserFormsPayments\Extensions\FormHasPaymentsExtension;
use A2nt\UserFormsPayments\Service\PaymentProcessorFactory;
use A2nt\UserFormsPayments\Service\StripeCheckoutPaymentProcessor;
use Exception;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;
use SilverStripe\Omnipay\GatewayFieldsFactory;
use SilverStripe\Omnipay\GatewayInfo;
use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

class UserFormsPaymentController extends ContentController
{
    private static $url_segment = '/userpayment';

    private static string $default_currency_code = 'USD';

    private static string $payment_intent = 'purchase';

    private static $allowed_actions = [
        'pay',
        'complete',
        'canceled',
        'PaymentForm',
    ];

    /**
     * @var array<string, class-string>
     */
    private static $allowed_objects = [
        'SubmittedForm' => SubmittedForm::class,
    ];

    private ?SubmittedForm $object = null;

    public function getShortPayableObjectName(string $class): string|false
    {
        /** @var array<string, class-string> $allowed */
        $allowed = static::config()->get('allowed_objects');

        return array_search($class, $allowed);
    }


    protected function getPayableObjectClass(string $shortName): string
    {
        /** @var array<string, class-string> $allowed */
        $allowed = static::config()->get('allowed_objects');

        if (!array_key_exists($shortName, $allowed)) {
            throw new Exception('Invalid short name: ' . $shortName);
        }

        return $allowed[$shortName];
    }


    /**
     * Get the payable object from the request either in the URL or in the
     * request params.
     *
     * @param array<string, mixed> $newParams
     */
    public function getPayableObject(array $newParams = []): SubmittedForm|false
    {
        if ($this->object) {
            return $this->object;
        }

        /** @var array<string, class-string> $allowed */
        $allowed = static::config()->get('allowed_objects');

        $request = $this->getRequest();
        $params = array_merge($request->allParams(), $newParams);
        $class = $params['ID'] ?? null;
        $id = $params['OtherID'] ?? null;

        $requestParams = $request->requestVars();

        if (!$id || !$class || !array_key_exists($class, $allowed)) {
            // look for the token and id in the request
            $token = $requestParams['PayableObjectToken'] ?? null;
            $id = $requestParams['PayableObjectID'] ?? null;

            if (!$token || !$id) {
                return false;
            }

            $obj = SubmittedForm::get()->filter('OrderID', $token)->first();

            if (!$obj) {
                return false;
            }

            $this->object = $obj;

            return $this->object;
        }

        $class = $this->getPayableObjectClass($class);
        $obj = $class::get()->byID($id);

        if (!$obj) {
            return false;
        }

        $this->object = $obj;

        return $this->object;
    }


    public function complete(): HTTPResponse
    {
        /** @var SubmittedForm $obj */
        $obj = $this->getPayableObject();

        if (!$obj) {
            return $this->httpError(404);
        }

        $sessionId = $this->request->getVar('session_id');
        if (is_string($sessionId) && $sessionId !== '') {
            return StripeCheckoutPaymentProcessor::completeCheckoutSession($this, $obj, $sessionId);
        }

        $payment = Payment::get()->filter([
            'SubmittedFormID' => $obj->ID,
            'Status' => 'Captured',
        ])->first();

        if (!$payment) {
            return $this->redirect($this->Link('canceled') . '/' . $this->getShortPayableObjectName($obj::class) . '/' . $obj->ID);
        }

        $page = $obj->Parent();

        return $this->redirect($page->Link('finished'));
    }


    public function canceled()
    {
        /** @var SubmittedForm $obj */
        $obj = $this->getPayableObject();

        if (!$obj) {
            return $this->httpError(404);
        }

        $page = $obj->Parent();

        return [
            'Title' => _t('UserFormsPaymentController.CANCELED_TITLE', 'Payment Canceled'),
            'Content' => _t('UserFormsPaymentController.CANCELED_CONTENT', 'Your payment has been canceled.'),
            'BackButton' => _t('UserFormsPaymentController.CANCELED_BACK_BUTTON', 'Back to the form'),
            'BackButtonLink' => $page->Link(),
        ];
    }


    public function pay()
    {
        $obj = $this->getPayableObject();

        if (!$obj) {
            return $this->httpError(404);
        }

        $gateway = $this->getGateway($obj);

        if (GatewayInfo::isOffsite($gateway)) {
            if ((float) $obj->Amount <= 0) {
                return $this->httpError(400);
            }

            return $this->processPayment($obj);
        }

        return [
            'Form' => $this->PaymentForm(),
            'Title' => _t('UserFormsPaymentController.PAY_TITLE', 'Pay Now'),
        ];
    }


    public function getGateway(?SubmittedForm $obj = null): string
    {
        if ($obj) {
            return FormHasPaymentsExtension::getEffectiveGatewayFor($obj->Parent());
        }

        $gateways = GatewayInfo::getSupportedGateways(false);

        return array_key_first($gateways);
    }


    public function PaymentForm(): Form|HTTPResponse
    {
        $obj = $this->getPayableObject();

        if (!$obj) {
            return $this->httpError(404);
        }

        $gateway = $this->getGateway($obj);
        switch ($gateway) {
            case 'PayPal_Express':
                if (!$obj) {
                    return $this->httpError(404);
                }

                return $this->processPayment($obj);
        }

        $factory = GatewayFieldsFactory::create($gateway);
        $factory->setPaymentAmount((float) $obj->Amount);
        $factory->setPaymentCurrency($obj->CurrencyCode);

        $fields = $factory->getFields();

        $this->extend('updateGatewayFieldsFactory', $factory, $obj);

        $fields->push(HiddenField::create('PayableObjectToken', '', $obj ? $obj->OrderID : null));
        $fields->push(HiddenField::create('PayableObjectID', '', $obj ? $obj->ID : null));

        return Form::create(
            $this,
            'PaymentForm',
            $fields,
            FieldList::create(FormAction::create(
                'doSubmit',
                _t('Checkout.PayNow', 'Pay Now')
            )),
            RequiredFieldsValidator::create(['ID', 'OtherID'])
        );
    }


    /**
     * @param array<string, mixed> $data
     */
    protected function processPayment(SubmittedForm $obj, array $data = [])
    {
        $gateway = $this->getGateway($obj);

        return PaymentProcessorFactory::create($gateway)->process($obj, $this, $data);
    }


    /**
     * @param array<string, mixed> $data
     */
    public function doSubmit(array $data)
    {
        $obj = $this->getPayableObject($data);

        if (!$obj) {
            return $this->httpError(404);
        }

        if ($obj->Amount > 0) {
            return $this->processPayment($obj, $data);
        }

        return HTTPResponse::create('ERROR 00-' . __CLASS__ . '_' . __FUNCTION__ . ': wrong amount', 500);
    }


    public function Link($action = null): string
    {
        return Controller::join_links(static::config()->get('url_segment'), $action);
    }
}

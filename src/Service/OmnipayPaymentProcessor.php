<?php

namespace A2nt\UserFormsPayments\Service;

use A2nt\UserFormsPayments\Controllers\UserFormsPaymentController;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\Omnipay\Service\ServiceFactory;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

final class OmnipayPaymentProcessor implements PaymentProcessorInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function process(SubmittedForm $obj, UserFormsPaymentController $controller, array $data = []): HTTPResponse
    {
        $gateway = $controller->getGateway($obj);

        $payment = Payment::create()
            ->init($gateway, $obj->Amount, $obj->CurrencyCode ?? $controller->config()->get('default_currency_code'))
            ->setSuccessUrl($controller->Link('complete') . '/' . $controller->getShortPayableObjectName($obj::class) . '/' . $obj->ID)
            ->setFailureUrl($controller->Link('canceled') . '/' . $controller->getShortPayableObjectName($obj::class) . '/' . $obj->ID);

        $payment->setField('SubmittedFormID', $obj->ID);
        $payment->write();

        $items = $obj->getPaymentItems();

        if ($items) {
            $data['items'] = $items;
        } else {
            $data['description'] = $obj->Title;
            $data['statement_descriptor'] = $obj->Title;
        }

        $data['rp_invoice_id'] = $obj->OrderID;
        $data['custom'] = $obj->OrderID;
        $data['invoice'] = $obj->OrderID;

        $controller->extend('updateProcessPaymentData', $data, $obj);

        $service = ServiceFactory::create()
            ->getService($payment, $controller->config()->get('payment_intent') ?? ServiceFactory::INTENT_PURCHASE);

        $response = $service->initiate($data);

        return $response->redirectOrRespond();
    }
}

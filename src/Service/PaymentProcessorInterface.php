<?php

namespace A2nt\UserFormsPayments\Service;

use A2nt\UserFormsPayments\Controllers\UserFormsPaymentController;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

interface PaymentProcessorInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function process(SubmittedForm $obj, UserFormsPaymentController $controller, array $data = []): HTTPResponse;
}

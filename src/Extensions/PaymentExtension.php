<?php

namespace A2nt\UserFormsPayments\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

/**
 * @extends Extension<Payment&static>
 */
class PaymentExtension extends Extension
{
    private static array $has_one = [
        'SubmittedForm' => SubmittedForm::class,
    ];

    protected function onCaptured($response): void
    {
        /** @var Payment $obj */
        $obj = $this->owner;

        /** @var SubmittedForm $form */
        $form = $obj->SubmittedForm();

        if (!$form->exists()) {
            return;
        }

        if ((float) $form->Amount === (float) $form->TotalPaidOrAuthorized()) {
            $form->setField('PaymentStatus', 'Paid');
            $form->write();
        }
    }
}

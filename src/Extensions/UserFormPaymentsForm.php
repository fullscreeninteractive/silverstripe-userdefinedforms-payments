<?php

namespace A2nt\UserFormsPayments\Extensions;

use A2nt\UserFormsPayments\Controllers\UserFormsPaymentController;
use DNADesign\ElementalUserForms\Model\ElementForm;
use SilverStripe\Core\Extension;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\FieldList;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\UserForms\Model\EditableFormField\EditableNumericField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

/**
 * @extends Extension<SubmittedForm&static>
 */
class UserFormPaymentsForm extends Extension
{
    private static $db = [
        'OrderID' => 'Varchar',
        'Amount' => 'Currency',
        'PaymentStatus' => 'Enum("Not Required,Unpaid,Paid","Not Required")',
    ];

    /**
     * @return array<string, mixed>
     */
    private function collectData(): array
    {
        /** @var SubmittedForm $obj */
        $obj = $this->owner;
        $vals = $obj->Values();

        $data = [];
        foreach ($vals as $valField) {
            $data[$valField->Name] = $valField->Value;
        }

        return $data;
    }

    protected function updateAfterProcess(array &$emailData, array &$attachments): void
    {
        /** @var SubmittedForm $obj */
        $obj = $this->owner;
        $data = $this->collectData();

        /** @var ElementForm $userForm */
        $userForm = $obj->Parent();
        $paymentRules = $userForm->PaymentRules();

        $once = ($userForm->PaymentRulesCondition === 'Or');
        $amount = 0;
        foreach ($paymentRules as $rule) {
            $field = $rule->ConditionField();

            if (
                $field->ClassName === EditableNumericField::class
                && $rule->ConditionOption === 'Summarize'
            ) {
                $amount += $data[$field->Name];
            } elseif ($rule->matches($data)) {
                $amount += $rule->Amount;
            }

            if ($once && $amount > 0) {
                break;
            }
        }

        if ($amount <= 0 || $userForm->PaymentRulesCondition === 'Never') {
            $obj->PaymentStatus = 'Not Required';
            $obj->Amount = 0;
        } else {
            $obj->PaymentStatus = 'Unpaid';
            $obj->Amount = $amount;
        }

        if ($obj->Amount > 0) {
            $obj->OrderID = 'O-' . $obj->ID . '-' . strtoupper(substr(uniqid('', true), 0, 4));
            $obj->write();

            $link = singleton(UserFormsPaymentController::class)->Link('/pay/SubmittedForm/' . $obj->ID);

            $response = HTTPResponse::create()->redirect($link);
            $response->output();
            exit();
        }
    }

    /**
     * @return list<array{name: string, price: float|int|string, quantity: int}>
     */
    public function getPaymentItems(): array
    {
        /** @var SubmittedForm $obj */
        $obj = $this->owner;
        $data = $this->collectData();

        /** @var ElementForm $userForm */
        $userForm = $obj->Parent();
        $paymentRules = $userForm->PaymentRules();

        $items = [];
        $once = ($userForm->PaymentRulesCondition === 'Or');

        $totalAmount = 0;
        foreach ($paymentRules as $rule) {
            /** @var EditableFormField $field */
            $field = $rule->ConditionField();

            if (
                $field->ClassName === EditableNumericField::class
                && $rule->ConditionOption === 'Summarize'
            ) {
                $amount = $data[$field->Name];
                if ((float) $amount > 0) {
                    $items[] = [
                        'name' => $field->Title,
                        'price' => $amount,
                        'quantity' => 1,
                    ];
                    $totalAmount += $data[$field->Name];
                }
            } elseif ($rule->matches($data)) {
                $amount = $rule->Amount;
                if ((float) $amount > 0) {
                    $items[] = [
                        'name' => $field->Title,
                        'price' => $amount,
                        'quantity' => 1,
                    ];
                    $totalAmount += $rule->Amount;
                }
            }

            if ($once && $totalAmount > 0) {
                break;
            }
        }

        return $items;
    }

    protected function updateCMSFields(FieldList $fields)
    {
        $readOnlyFields = ['OrderID', 'Amount', 'PaymentStatus'];

        foreach ($readOnlyFields as $key) {
            $fields
                ->dataFieldByName($key)
                ->setReadonly(true);
        }

        return $fields;
    }
}

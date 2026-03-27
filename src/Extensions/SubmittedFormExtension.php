<?php

namespace A2nt\UserFormsPayments\Extensions;

use A2nt\UserFormsPayments\Controllers\UserFormsPaymentController;
use DNADesign\ElementalUserForms\Model\ElementForm;
use SilverStripe\Core\Extension;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\UserForms\Model\EditableFormField\EditableNumericField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

/**
 * @extends Extension<SubmittedForm&static>
 */
class SubmittedFormExtension extends Extension
{
    private static array $db = [
        'OrderID' => 'Varchar(200)',
        'Amount' => 'Currency',
        'CurrencyCode' => 'Varchar(3)',
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

    public function onBeforeWrite()
    {
        if (!$this->owner->CurrencyCode) {
            $obj = $this->owner;
            $obj->CurrencyCode = $obj->Parent()->CurrencyCode ?? 'USD';
        }
    }


    protected function updateAfterProcess(array &$emailData, array &$attachments): void
    {
        /** @var SubmittedForm $obj */
        $obj = $this->owner;
        $data = $this->collectData();

        /** @var ElementForm|UserDefinedForm $userForm */
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

        $obj->CurrencyCode = $userForm->CurrencyCode ?? 'USD';

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

            $description = $rule->StatementDescriptor ?? $field->Title;

            if (
                $field->ClassName === EditableNumericField::class
                && $rule->ConditionOption === 'Summarize'
            ) {
                $amount = $data[$field->Name];
                if ((float) $amount > 0) {
                    $items[] = [
                        'name' => $description,
                        'description' => $description,
                        'price' => $amount,
                        'quantity' => 1,
                    ];
                    $totalAmount += $data[$field->Name];
                }
            } elseif ($rule->matches($data)) {
                $amount = $rule->Amount;
                if ((float) $amount > 0) {
                    $items[] = [
                        'name' => $description,
                        'description' => $description,
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
        $readOnlyFields = ['OrderID', 'Amount', 'PaymentStatus', 'CurrencyCode'];

        foreach ($readOnlyFields as $key) {
            $fields->makeFieldReadonly($key);
        }

        $fields->insertAfter(
            'PaymentStatus',
            LiteralField::create(
                'PaymentLink',
                sprintf('<p><a target="_blank" href="%s">Pay for this order</a></p>', $this->getPaymentLink())
            ),
        );

        return $fields;
    }


    public function getPaymentLink(): string
    {
        return singleton(UserFormsPaymentController::class)->Link(sprintf(
            '/pay/SubmittedForm/%s?token=%s',
            $this->owner->ID,
            $this->owner->OrderID
        ));
    }
}

<?php

namespace A2nt\UserFormsPayments\Models;

use DNADesign\ElementalUserForms\Model\ElementForm;
use LogicException;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\UserForms\Model\EditableFormField;

class PaymentConditionRule extends DataObject
{
    /**
     * List of options
     *
     * @config
     * @var array<string, string>
     */
    private static $condition_options = [
        'IsBlank' => 'Is blank',
        'IsNotBlank' => 'Is not blank',
        'Equals' => 'Equals',
        'NotEquals' => "Doesn't equal",
        'ValueLessThan' => 'Less than',
        'ValueLessThanEqual' => 'Less than or equal',
        'ValueGreaterThan' => 'Greater than',
        'ValueGreaterThanEqual' => 'Greater than or equal',
        'Summarize' => 'Add amount entered to the field',
    ];

    private static $db = [
        'ConditionOption' => 'Enum("IsBlank,IsNotBlank,Equals,NotEquals,ValueLessThan,ValueLessThanEqual,ValueGreaterThan,ValueGreaterThanEqual,Summarize")',
        'ConditionValue' => 'Varchar',
        'Amount' => 'Currency',
    ];

    private static $has_one = [
        'Parent' => ElementForm::class,
        'ConditionField' => EditableFormField::class,
    ];

    private static $table_name = 'UserDefinedForm_PaymentConditionRule';

    /**
     * Determine if this rule matches the given condition
     *
     * @param array<string, mixed> $data
     */
    public function matches(array $data): bool
    {
        $fieldName = $this->ConditionField()->Name;
        $fieldValue = $data[$fieldName] ?? null;
        $conditionValue = $this->ConditionValue;
        $result = null;
        switch ($this->ConditionOption) {
            case 'Summarize':
                $result = true;
                break;
            case 'IsBlank':
                $result = empty($fieldValue);
                break;
            case 'IsNotBlank':
                $result = !empty($fieldValue);
                break;
            case 'ValueLessThan':
                $result = ($fieldValue < $conditionValue);
                break;
            case 'ValueLessThanEqual':
                $result = ($fieldValue <= $conditionValue);
                break;
            case 'ValueGreaterThan':
                $result = ($fieldValue > $conditionValue);
                break;
            case 'ValueGreaterThanEqual':
                $result = ($fieldValue >= $conditionValue);
                break;
            case 'NotEquals':
            case 'Equals':
                $result = is_array($fieldValue)
                    ? in_array($conditionValue, $fieldValue)
                    : $fieldValue == $conditionValue;

                if ($this->ConditionOption === 'NotEquals') {
                    $result = !$result;
                }
                break;
            default:
                throw new LogicException("Unhandled rule {$this->ConditionOption}");
        }

        return $result;
    }

    /**
     * Return whether a user can create an object of this type
     *
     * @param Member|null $member
     * @param array<string, mixed> $context Virtual parameter to allow context to be passed in to check
     */
    public function canCreate($member = null, $context = [])
    {
        $parent = $this->getCanCreateContext(func_get_args());
        if ($parent) {
            return $parent->canEdit($member);
        }

        return parent::canCreate($member);
    }

    /**
     * Helper method to check the parent for this object
     *
     * @param array<int, mixed> $args List of arguments passed to canCreate
     * @return ElementForm|null
     */
    protected function getCanCreateContext($args)
    {
        if (isset($args[1]['Parent'])) {
            return $args[1]['Parent'];
        }
        $curr = Controller::curr();
        if ($curr instanceof CMSMain) {
            $record = $curr->currentRecord();

            return $record instanceof ElementForm ? $record : null;
        }

        return null;
    }

    public function canView($member = null)
    {
        return $this->Parent()->canView($member);
    }

    public function canEdit($member = null)
    {
        return $this->Parent()->canEdit($member);
    }

    public function canDelete($member = null)
    {
        return $this->canEdit($member);
    }
}

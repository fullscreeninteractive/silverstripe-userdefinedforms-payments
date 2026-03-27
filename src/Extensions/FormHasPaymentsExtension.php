<?php

namespace A2nt\UserFormsPayments\Extensions;

use A2nt\UserFormsPayments\Models\PaymentConditionRule;
use DNADesign\ElementalUserForms\Model\ElementForm;
use SilverStripe\Core\Extension;
use SilverStripe\Omnipay\GatewayInfo;
use SilverStripe\Forms\CurrencyField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextField;
use Symbiote\GridFieldExtensions\GridFieldAddNewInlineButton;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;

/**
 * @extends Extension<ElementForm&static>
 */
class FormHasPaymentsExtension extends Extension
{
    private static array $db = [
        'CurrencyCode' => 'Varchar(3)',
        'PaymentRulesCondition' => 'Enum("Never,And,Or","Never")',
        /** @see GatewayInfo::getSupportedGateways() keys (Omnipay gateway class names) */
        'Gateway' => 'Varchar(128)',
    ];

    private static array $has_many = [
        'PaymentRules' => PaymentConditionRule::class,
    ];

    private static array $cascade_duplicates = [
        'PaymentRules',
    ];

    private static array $cascade_deletes = [
        'PaymentRules',
    ];

    /**
     * When `Gateway` is empty, use the first entry from configured allowed gateways.
     */
    public function getEffectiveGateway(): string
    {
        return static::getEffectiveGatewayFor($this->owner);
    }

    /**
     * @param \SilverStripe\ORM\DataObject|null $parent Usually the form block (e.g. ElementForm).
     */
    public static function getEffectiveGatewayFor($parent): string
    {
        if (!$parent) {
            $supported = GatewayInfo::getSupportedGateways(false);

            return array_key_first($supported);
        }

        $configured = $parent->Gateway ?? '';
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $supported = GatewayInfo::getSupportedGateways(false);

        return array_key_first($supported);
    }

    /**
     * Generate a {@link GridFieldConfig} config for editing filter rules
     */
    protected function getRulesConfig(): GridFieldConfig
    {
        $formFields = $this->owner->Fields();

        $config = GridFieldConfig::create()
            ->addComponents(
                GridFieldButtonRow::create('before'),
                GridFieldToolbarHeader::create(),
                GridFieldAddNewInlineButton::create(),
                GridFieldDeleteAction::create(),
                $columns = GridFieldEditableColumns::create()
            );

        $columns->setDisplayFields([
            'ConditionFieldID' => function ($record, $column, $grid) use ($formFields) {
                return DropdownField::create($column, false, $formFields->map('ID', 'Title'));
            },
            'ConditionOption' => function ($record, $column, $grid) {
                /** @var array<string, string> $options */
                $options = PaymentConditionRule::config()->get('condition_options');

                return DropdownField::create($column, false, $options);
            },
            'ConditionValue' => function ($record, $column, $grid) {
                return TextField::create($column);
            },
            'Amount' => function ($record, $column, $grid) {
                return CurrencyField::create($column);
            },
            'StatementDescriptor' => function ($record, $column, $grid) {
                return TextField::create($column)->setAttribute('placeholder', 'Statement Descriptor');
            },
        ]);

        return $config;
    }

    protected function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName('PaymentRules');

        $grid = GridField::create(
            'PaymentRules',
            _t(__CLASS__ . '.PaymentRules', 'Payment Rules'),
            $this->owner->PaymentRules(),
            $this->getRulesConfig()
        );
        $grid->setDescription(_t(
            __CLASS__ . '.PaymentsDescription',
            'Payment will be required if the custom rules are met. If no rules are defined, '
            . 'payment will not be required.'
        ));

        $fields->addFieldsToTab('Root.PaymentRules', [
            LiteralField::create(
                'PaymentsNote',
                '<div class="alert alert-info">'
                . _t(__CLASS__ . '.PaymentsNote', 'Add conditional logic to require payment. Note Amount fields must be Numeric.')
                . '</div>'
            ),
            DropdownField::create(
                'PaymentRulesCondition',
                _t(__CLASS__ . '.RequireCondition', 'Require Condition'),
                [
                    'Never' => _t(__CLASS__ . '.RequireIfNever', 'Never'),
                    'Or' => _t(
                        'SilverStripe\\UserForms\\Model\\UserDefinedForm.SENDIFOR',
                        'Any conditions are true'
                    ),
                    'And' => _t(
                        'SilverStripe\\UserForms\\Model\\UserDefinedForm.SENDIFAND',
                        'All conditions are true'
                    ),
                ]
            ),
            DropdownField::create(
                'Gateway',
                _t(__CLASS__ . '.Gateway', 'Payment gateway'),
                $this->getGatewayDropdownSource()
            )->setDescription(_t(
                __CLASS__ . '.GatewayDescription',
                'Leave as default to use the first gateway configured under Payment.allowed_gateways.'
            )),
            TextField::create('CurrencyCode', 'Currency Code')->setDescription('ISO 4217 currency code, e.g. USD, EUR, GBP, etc.')
                ->setAttribute('placeholder', 'USD'),
            $grid,
        ]);

        $fields
            ->fieldByName('Root.PaymentRules')
            ->setTitle(_t(__CLASS__ . '.PaymentsTab', 'Payment Rules'));

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private function getGatewayDropdownSource(): array
    {
        $gateways = GatewayInfo::getSupportedGateways(false);
        $label = _t(__CLASS__ . '.GatewayDefault', 'Default (first configured gateway)');

        return ['' => $label] + $gateways;
    }
}

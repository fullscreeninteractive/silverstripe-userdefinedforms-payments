<?php

namespace A2nt\UserFormsPayments\Admins;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Model\List\SS_List;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

class UserFormOrders extends ModelAdmin
{
    private static array $managed_models = [
        SubmittedForm::class,
    ];

    private static string $url_segment = 'orders';

    private static string $menu_title = 'Form Orders';

    public function getList(): SS_List
    {
        $list = parent::getList();

        if ($list->dataClass() === SubmittedForm::class) {
            return $list->filter(['Amount:GreaterThan' => 0]);
        }

        return $list;
    }
}

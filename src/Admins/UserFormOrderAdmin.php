<?php

namespace A2nt\UserFormsPayments\Admins;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Model\List\SS_List;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

class UserFormOrderAdmin extends ModelAdmin
{
    private static array $managed_models = [
        SubmittedForm::class,
    ];

    private static string $url_segment = 'orders';

    private static string $menu_title = 'Form Orders';

    private static string $menu_icon_class = 'font-icon-p-cart';

    public function getList(): SS_List
    {
        $list = parent::getList();

        if ($list->dataClass() === SubmittedForm::class) {
            return $list->filter(['Amount:GreaterThan' => 0]);
        }

        return $list;
    }
}

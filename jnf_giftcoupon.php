<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use DoctrineExtensions\Query\Mysql\Now;
use PrestaShop\PrestaShop\Core\MailTemplate\Layout\Layout;
use PrestaShop\PrestaShop\Core\MailTemplate\ThemeCatalogInterface;
use PrestaShop\PrestaShop\Core\MailTemplate\ThemeCollectionInterface;
use PrestaShop\PrestaShop\Core\MailTemplate\ThemeInterface;
use PrestaShop\PrestaShop\Core\Localization\Locale;

class Jnf_Giftcoupon extends Module
{
    
    /**
     * @var string $database
     */
    private $database;
    
    /**
     * @var array $order_status
     */
    private $order_status;

    public function __construct()
    {
        $this->name = 'jnf_giftcoupon';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'JnfDev';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Gift Coupon', [], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon');
        $this->description = $this->trans('This provide coupons to regular customers. This plugin is an "admission test" for Interfell.');

        $this->database     = _DB_PREFIX_ . 'giftcoupon';
        $this->order_status =  OrderState::getOrderStates((int) Configuration::get('PS_LANG_DEFAULT'));

        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall?', [], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon');
    }

    public function install()
    {
        return parent::install() &&
            $this->createDatabase() &&
            $this->registerHook('actionOrderStatusPostUpdate') &&
            Configuration::updateValue('JNF_GIFTCOUPON_TRIGGER_ORDER_STATUS', 2) &&
            Configuration::updateValue('JNF_GIFTCOUPON_TRIGGER_AMOUNT', 50) &&
            $this->registerHook(ThemeCatalogInterface::LIST_MAIL_THEMES_HOOK);
        ;
    }

    public function uninstall() {
        return parent::uninstall() && 
            $this->removeDatabase() &&
            Configuration::deleteByName('JNF_GIFTCOUPON_TRIGGER_ORDER_STATUS') &&
            Configuration::deleteByName('JNF_GIFTCOUPON_TRIGGER_AMOUNT') &&
            $this->unregisterHook(ThemeCatalogInterface::LIST_MAIL_THEMES_HOOK);
        ;
    }

    public function enable($force_all = false) {
        return parent::enable()
            && $this->registerHook(ThemeCatalogInterface::LIST_MAIL_THEMES_HOOK)
        ;
    }

    public function disable($force_all = false) {
        return parent::disable()
            && $this->unregisterHook(ThemeCatalogInterface::LIST_MAIL_THEMES_HOOK)
        ;
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    public function createMultiLangField($field) 
    {
        $res = array();
        foreach (Language::getIDs(false) as $id_lang) {
            $res[$id_lang] = $field;
        }
        return $res;
    }

    public function createDatabase()
    {
        $db = Db::getInstance();

        $sql = "CREATE TABLE IF NOT EXISTS $this->database (
            `id_giftcoupon`   INT(10) UNSIGNED AUTO_INCREMENT,
            `id_customer`     int(10) unsigned NOT NULL UNIQUE,
            `giftcoupon_code` varchar(254) NOT NULL,
            `date_add`        datetime NOT NULL,
            PRIMARY KEY (`id_giftcoupon`, `id_customer`)
        ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=UTF8;";

        return $db->execute($sql);
    }

    public function removeDatabase()
    {
    
        $db  = Db::getInstance();
        $sql = "DROP TABLE IF EXISTS `$this->database`";
       
        return $db->execute($sql);
    }

    public function registerGiftCoupon($idCustomer, $couponCode)
    {
        $db   = Db::getInstance();
        $data = array(
            'id_customer' => (int) $idCustomer,
            'giftcoupon_code' => pSQL($couponCode),
            'date_add' => date('Y-m-d H:i:s'),
        );
        
        try {
            return $db->insert($this->database, $data, false, true, Db::INSERT, false);
        } catch (PrestaShopDatabaseException  $e) {
            return false;
        }
    }

    public function createCustomerCartRule($idCustomer)
    {
        $cartRule = new CartRule();
        $cartRule->name = $this->createMultiLangField($this->trans('Thanks Coupon 🙏'));
        $cartRule->code = Tools::passwdGen(16);
        $cartRule->quantity = 1;
        $cartRule->quantity_per_user = 1;
        $cartRule->id_customer = (int) $idCustomer;
        $cartRule->date_from = date('Y-m-d H:i:s', time());
        $cartRule->date_to = date('Y-m-d H:i:s', strtotime('+3 month'));
        $cartRule->partial_use = 0;
        $cartRule->active = 1;
        $cartRule->reduction_percent = 30;

        return $cartRule->add() ? $cartRule : false;
    }

    public function getCustomersCoupon()
    {
        $db  = Db::getInstance();
        $sql = "SELECT gc.`id_giftcoupon`, gc.`id_customer`, c.`firstname`, c.`lastname`, gc.`giftcoupon_code`, gc.`date_add` 
            FROM `$this->database` gc
            INNER JOIN `". _DB_PREFIX_ ."_customer` c ON gc.`id_customer` = c.`id_customer`;
        ";

        return (array) $db->executeS($sql);
    }

    public function doesCustomerHaveCoupon($idCustomer)
    {
        $db  = Db::getInstance();
        $sql = "SELECT `id_giftcoupon` FROM `$this->database` WHERE `id_customer` = " . (int) $idCustomer;

        return $db->executeS($sql) ? true : false;
    }

    /** Hooks */

    public function hookActionOrderStatusPostUpdate($params)
    {
        $orderObj = new Order((int) $params['id_order']);

        if ( !isset($orderObj->current_state) || $orderObj->current_state !== Configuration::get('JNF_GIFTCOUPON_TRIGGER_ORDER_STATUS') ) {
            return;
        }

        if (!isset($orderObj->id_customer) || !Customer::customerIdExistsStatic($orderObj->id_customer)) {
            return;
        }

        // Customer on the order existe?
        $customer = new Customer($orderObj->id_customer);
        if (!Customer::customerExists($customer->email)) {
            return;
        }

        $customerOrders   = Order::getCustomerOrders($customer->id);        
        $totalAmountSpent = 0; // This amount should be on the default currency.

        foreach ($customerOrders as $order) {
            /**
             * ToDo: replace "covertPrice" with "convertPriceToCurrency"
             * Note: The method "covertPrice" is depecrated, however the suggested method "convertPriceToCurrency" is not avaible yet.
             */
            $totalAmountSpent += Tools::convertPrice((float) $order['total_paid_tax_incl'], (int) $order['id_currency'], false);
        }

        //Send email
        if ($totalAmountSpent > 0) {

            // Total amount spent but expresed 
            // on the customer last order's currency.
            $customerTotalAmountSpent = Tools::convertPrice($totalAmountSpent, $orderObj->id_currency);

            $var_list = [
                // ToDo: Replace displayPrice for formatPrice, for some reason.
                // the currentLocale object is null on the context, figure it out later...
                '{customerTotalAmountSpent}' => Tools::displayPrice($customerTotalAmountSpent, (int) $orderObj->id_currency),
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
            ];

            Mail::send(
                $customer->id_lang,
                'customer_total_amount_spent',
                $this->trans('Expense report for %customer%', ['%customer%' => $customer->firstname], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon'),
                $var_list,
                $customer->email,
                null,
                null,
                null,
                null,
                null,
                __DIR__ . '/mails/'
            );

            if ($totalAmountSpent >= (float) Configuration::get('JNF_GIFTCOUPON_TRIGGER_AMOUNT')) {

                // Does customer have a coupon?
                // If yes, lets return.
                if ($this->doesCustomerHaveCoupon($customer->id) === true) {
                    return;
                }
 
                /**
                 * @var CartRule $cartRule
                 */
                $cartRule = $this->createCustomerCartRule($customer->id);

                if (!$cartRule) {
                    throw new PrestaShopException("Unexpected error, contact site's administrator.");
                    return;
                }

                $cartRuleCode    = $cartRule->code;   
                $reductionAmount = $cartRule->reduction_percent;

                // Set customer coupon in database.
                if (!$this->registerGiftCoupon($customer->id, $cartRuleCode)) {
                    throw new PrestaShopException("Unexpected error, contact site's administrator.");
                    return;
                }

                $var_list = [
                    // ToDo: Replace displayPrice for formatPrice, for some reason.
                    // the currentLocale object is null on the context, figure it out later...
                    '{customerGiftCouponAmount}' => $reductionAmount . '% off',
                    '{customerGiftCouponCode}'   => $cartRuleCode,
                    '{firstname}'                => $customer->firstname,
                    '{lastname}'                 => $customer->lastname,
                ];

                Mail::send(
                    $customer->id_lang,
                    'customer_gift_coupon',
                    $this->trans('A gift for %customer%', ['%customer%' => $customer->firstname], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon'),
                    $var_list,
                    $customer->email,
                    null,
                    null,
                    null,
                    null,
                    null,
                    __DIR__ . '/mails/'
                );
            }
        }

    }

    /**
     * @param array $hookParams
     */
    public function hookActionListMailThemes(array $hookParams)
    {
        if (!isset($hookParams['mailThemes'])) {
            return;
        }

        /** @var ThemeCollectionInterface $themes */
        $themes = $hookParams['mailThemes'];

        /** @var ThemeInterface $theme */
        foreach ($themes as $theme) {
            if (!in_array($theme->getName(), ['classic', 'modern'])) {
                continue;
            }

            // Add a layout to each theme (don't forget to specify the module name)
            $theme->getLayouts()->add(new Layout(
                'customer_total_amount_spent',
                __DIR__ . '/mails/layouts/customer_total_amount_spent_' . $theme->getName() . '_layout.html.twig',
                '',
                $this->name
            ));

            $theme->getLayouts()->add(new Layout(
                'customer_gift_coupon',
                __DIR__ . '/mails/layouts/customer_gift_coupon_' . $theme->getName() . '_layout.html.twig',
                '',
                $this->name
            ));
        }
    }

    /** Admin Configuration Page */

    public function getContent()
    {
        $output = '';
        $errors = array();

        $output .= $this->displayTable();

        if (Tools::isSubmit('submit'.$this->name)) {

            $triggerAmount      = Tools::getValue('JNF_GIFTCOUPON_TRIGGER_AMOUNT');
            $triggerOrderStatus = (int) Tools::getValue('JNF_GIFTCOUPON_TRIGGER_ORDER_STATUS');

            if (!in_array($triggerOrderStatus, array_column($this->order_status, 'id_order_state'))) {
                $errors[] = $this->trans('Invalid Order Status.', [], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon');
            }

            if (! is_numeric($triggerAmount) && !((float) $triggerAmount > 0)) {
                $errors[] = $this->trans('Trigger amount should be a valid number greater than 0.', [], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon');
            }

            if (! count($errors)) {
                Configuration::updateValue('JNF_GIFTCOUPON_TRIGGER_AMOUNT', $triggerAmount);
                Configuration::updateValue('JNF_GIFTCOUPON_TRIGGER_ORDER_STATUS', $triggerOrderStatus);
            } else {
                $output .= $this->displayError(implode('<br />', $errors));
            }
        }

        return $output . $this->displayForm();
    }

    public function displayTable()
    {
        $query_result = $this->getCustomersCoupon();

        $fields_list = array(
            'id_giftcoupon' => array(
                'title' => $this->trans('ID'),
            ),
            'id_customer' => array(
                'title' => $this->trans('ID Customer'),
            ),
            'firstname' => array(
                'title' => $this->l('Customer Firstname')
            ),
            'lastname' => array(
                'title' => $this->l('Customer Lastname')
            ),
            'giftcoupon_code' => array(
                'title' => $this->trans('Coupon Code'),
            ),
            'date_add' => array(
                'title' => $this->trans('Creation Date'),
                'type' => 'date'
            )
        );
        $helper = new HelperList();
         
        $helper->shopLinkType = '';
         
        $helper->simple_header = true;
         
        // Actions to be displayed in the "Actions" column
        $helper->actions = array();
         
        $helper->identifier = 'giftcoupon_table';
        $helper->show_toolbar = false;
        $helper->title = $this->trans('Gift Coupons', [], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon');
        $helper->table = $this->database;
         
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        
        return $helper->generateList( $query_result, $fields_list );
    }

    public function displayForm()
    {
        // Get default language
        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');

        $fieldsForm[0]['form'] = [
            'legend' => [
                'title' => $this->trans('Gift Coupon', [], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon'),
            ],
            'input' => [
                [
                    'type'  => 'text',
                    'label' => $this->trans('Amount to trigger the Gift Coupon', [], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon'),
                    'desc'  => $this->trans('The amount must be a valid price.', [], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon'),
                    'name'  => 'JNF_GIFTCOUPON_TRIGGER_AMOUNT',
                    'col'  => 2
                ],
                [
                    'type' => 'select',
                    'label' => $this->trans('Order status to trigger the Gift Coupon functionalities', [], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon'),
                    'name' => 'JNF_GIFTCOUPON_TRIGGER_ORDER_STATUS',
                    'options' => [
                        'query' => $this->order_status,
                        'id'    => 'id_order_state',
                        'name'  => 'name',
                    ]
                ],
            ],
            'submit' => [
                'title' => $this->trans('Save', [], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon'),
                'class' => 'btn btn-default pull-right'
            ],
        ];

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        // Language
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->trans('Save', [], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon'),
                'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                '&token='.Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->trans('Back to list', [], 'Modules.Jnfgiftcoupon.Jnfgiftcoupon')
            ]
        ];

        // Load current value
        $helper->fields_value['JNF_GIFTCOUPON_TRIGGER_AMOUNT']   = Tools::getValue('JNF_GIFTCOUPON_TRIGGER_AMOUNT', Configuration::get('JNF_GIFTCOUPON_TRIGGER_AMOUNT'));
        $helper->fields_value['JNF_GIFTCOUPON_TRIGGER_ORDER_STATUS']   = Tools::getValue('JNF_GIFTCOUPON_TRIGGER_ORDER_STATUS', Configuration::get('JNF_GIFTCOUPON_TRIGGER_ORDER_STATUS'));

        return $helper->generateForm($fieldsForm);

    }

}
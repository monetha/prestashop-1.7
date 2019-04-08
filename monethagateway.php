<?php

require __DIR__ . '/vendor/autoload.php';


use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Monetha\Config;
use Monetha\Services\GatewayService;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Monethagateway extends PaymentModule
{
    const DISPLAY_NAME = 'Monetha Gateway';
    const COLOR = '#00e882';

    public function __construct()
    {
        $this->name = 'monethagateway';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Monetha';
        $this->need_instance = 1;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Monetha Gateway');
        $this->description = $this->l('Monetha payment gateway');

        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        $configuration = Config::get_predefined_configuration();

        Configuration::updateValue($this->name, json_encode($configuration));

        $this->create_order_state();
        $this->copy_email_templates();

        return parent::install() &&
            $this->registerHook('paymentOptions') &&
            $this->registerHook('paymentReturn') &&
            $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        if ($params['newOrderStatus']->id == Configuration::get('PS_OS_CANCELED')) {
            try {
                $order = new Order($params['id_order']);
                if ($order->module != 'monethagateway') {
                    return;
                }

                $query = "SELECT * FROM `" . _DB_PREFIX_ . "monetha_gateway` WHERE order_id='" . pSQL($params['id_order']) . "' LIMIT 1";
                $data = Db::getInstance()->executeS($query);
                if (!$data) {
                    error_log('Monetha gateway order id = ' . $params['id_order'] . ' not found.');
                }

                $configAdapter = new \Monetha\PS16\Adapter\ConfigAdapter(true);
                $gateway = new \Monetha\Services\GatewayService($configAdapter);

                $row = reset($data);

                $gateway->cancelExternalOrder($row['monetha_id']);
            } catch (\Monetha\Response\Exception\ApiException $e) {
                $message = sprintf(
                    'Status code: %s, error: %s, message: %s',
                    $e->getApiStatusCode(),
                    $e->getApiErrorCode(),
                    $e->getMessage()
                );
                error_log($message);

            }  catch (\Exception $ex) {
                error_log($ex->getMessage());
            }

            return;
        }

        // the rest always happens during validation

        /** @var Cart $cart */
        $cart = $params['cart'];
        $orderId = $params['id_order'];

        $this->updateOrderByCartId($cart, $orderId);
    }

    public function uninstall()
    {
        $this->delete_order_state();
        $this->delete_email_templates();

        Configuration::deleteByName($this->name);
        Configuration::deleteByName(Config::ORDER_STATUS);

        return parent::uninstall();
    }

    /**
     * @throws PrestaShopDatabaseException
     */
    private function create_order_state()
    {
        $db = Db::getInstance();

        $db->insert('order_state', array(
            'send_email' => 1,
            'module_name' => $this->name,
            'color' => self::COLOR,
            'logable' => 1,
        ));

        $order_state_id = $db->Insert_ID();
        Configuration::updateValue(Config::ORDER_STATUS, $order_state_id);

        $db->insert('order_state_lang', array(
            'id_order_state' => $order_state_id,
            'id_lang' => 1,
            'name' => 'Awaiting Monetha payment',
            'template' => $this->name,
        ));

        $tableName = _DB_PREFIX_ . 'monetha_gateway';

        Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `$tableName` (
          `id` int(11) NOT NULL,
          `order_id` int(11) NOT NULL,
          `monetha_id` int(11) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;

        ALTER TABLE `$tableName`
          ADD PRIMARY KEY (`id`);

        ALTER TABLE `$tableName`
          MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;");

        if (!$this->columnExists(_DB_NAME_, $tableName, 'cart_id')) {
            $query = "ALTER TABLE `$tableName` ADD COLUMN `cart_id` INT(11) NOT NULL";
            Db::getInstance()->execute($query);
        }

        if (!$this->columnExists(_DB_NAME_, $tableName, 'payment_url')) {
            $query = "ALTER TABLE `$tableName` ADD COLUMN `payment_url` VARCHAR(512) NOT NULL";
            Db::getInstance()->execute($query);
        }
    }

    private function delete_order_state()
    {
        $db = Db::getInstance();
        $order_state_id = Configuration::get(Config::ORDER_STATUS);

        $db->delete('order_state_lang', "id_order_state = $order_state_id", 1);
        $db->delete('order_state', "id_order_state = $order_state_id", 1);
    }

    private function copy_email_templates()
    {
        $source = _PS_MODULE_DIR_ . $this->name . '/mails/en/' . $this->name;
        $destination = _PS_MAIL_DIR_ . 'en/' . $this->name;

        $txt_template_source_path = $source . '.txt';
        $txt_template_destination_path = $destination . '.txt';
        if (file_exists($txt_template_source_path)) {
            copy($txt_template_source_path, $txt_template_destination_path);
        }

        $html_template_source_path = $source . '.html';
        $txt_template_destination_path = $destination . '.html';
        if (file_exists($html_template_source_path)) {
            copy($html_template_source_path, $txt_template_destination_path);
        }
    }

    private function delete_email_templates()
    {
        $path = _PS_MAIL_DIR_ . 'en/' . $this->name;

        $txt_template_path = $path . '.txt';
        if (file_exists($txt_template_path)) {
            unlink($txt_template_path);
        }

        $html_template_path = $path . '.html';
        if (file_exists($html_template_path)) {
            unlink($html_template_path);
        }
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return;
        }

        try {
            $conf = Config::get_configuration();
        } catch (\Exception $e) {
            return;
        }

        $enabled = $conf[Config::PARAM_ENABLED];
        if (!$enabled) {
            return;
        }

        $payment_options = [
            $this->getExternalPaymentOption(),
        ];

        return $payment_options;
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getExternalPaymentOption()
    {
        $externalOption = new PaymentOption();
        $externalOption->setCallToActionText($this->l(self::DISPLAY_NAME))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
            ->setInputs([
                'token' => [
                    'name' =>'token',
                    'type' =>'hidden',
                    'value' =>'12345689',
                ],
            ])
            ->setAdditionalInformation($this->context->smarty->fetch('module:' . $this->name . '/views/templates/front/payment_infos.tpl'))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/payment.jpg'));

        return $externalOption;
    }

    protected function generateForm()
    {
        $months = [];
        for ($i = 1; $i <= 12; $i++) {
            $months[] = sprintf("%02d", $i);
        }

        $years = [];
        for ($i = 0; $i <= 10; $i++) {
            $years[] = date('Y', strtotime('+'.$i.' years'));
        }

        $this->context->smarty->assign([
            'action' => $this->context->link->getModuleLink($this->name, 'validation', array(), true),
            'months' => $months,
            'years' => $years,
        ]);

        return $this->context->smarty->fetch('module:' . $this->name . '/views/templates/front/payment_form.tpl');
    }

    public function displayForm()
    {
        $output = null;
        try {
            $conf = Config::get_configuration();
        } catch (\Exception $e) {
            $output .= $this->displayError('Current configuration error: ' . $this->l($e->getMessage()));
        }

        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = array(
            'save' =>
                array(
                    'desc' => $this->l('Save'),
                    'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                        '&token='.Tools::getAdminTokenLite('AdminModules'),
                ),
            'back' => array(
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        $yes_no_options = [
            'query' => [
                [
                    'id_option' => '1',
                    'name' => 'Yes',
                ],
                [
                    'id_option' => '0',
                    'name' => 'No',
                ],
            ],
            'id' => 'id_option',
            'name' => 'name',
        ];

        $labels = Config::get_labels();

        $fields_form[0]['form'] = [
            'legend' => [
                'title' => $this->l(self::DISPLAY_NAME .' Settings'),
            ],
            'input' => [
                [
                    'type' => 'select',
                    'name' => Config::PARAM_ENABLED,
                    'label' => $this->l($labels[Config::PARAM_ENABLED]),
                    'options' => $yes_no_options,
                    'required' => true,
                ],
                [
                    'type' => 'select',
                    'name' => Config::PARAM_TEST_MODE,
                    'label' => $this->l($labels[Config::PARAM_TEST_MODE]),
                    'options' => $yes_no_options,
                    'required' => true,
                ],
                [
                    'type' => 'text',
                    'name' => Config::PARAM_MERCHANT_SECRET,
                    'label' => $this->l($labels[Config::PARAM_MERCHANT_SECRET]),
                    'required' => true,
                ],
                [
                    'type' => 'text',
                    'name' => Config::PARAM_MONETHA_API_KEY,
                    'label' => $this->l($labels[Config::PARAM_MONETHA_API_KEY]),
                    'required' => true,
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ],
        ];

        if (!isset($conf)) {
            $conf = [
                'enabled' => '1',
                'testMode' => '1',
                'merchantSecret' => 'MONETHA_SANDBOX_SECRET',
                'monethaApiKey' => '',
            ];
        }

        $helper->fields_value = $conf;
        return $output . $helper->generateForm($fields_form);
    }

    private function get_form_values()
    {
        $enabled = Tools::getValue(Config::PARAM_ENABLED);
        $test_mode = Tools::getValue(Config::PARAM_TEST_MODE);
        $merchantSecret = Tools::getValue(Config::PARAM_MERCHANT_SECRET);
        $monethaApiKey = Tools::getValue(Config::PARAM_MONETHA_API_KEY);

        return [
            Config::PARAM_ENABLED => $enabled,
            Config::PARAM_TEST_MODE => $test_mode,
            Config::PARAM_MERCHANT_SECRET => $merchantSecret,
            Config::PARAM_MONETHA_API_KEY => $monethaApiKey,
        ];
    }

    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit'.$this->name)) {
            $form_values = $this->get_form_values();

            try {
                Config::validate($form_values);
                Configuration::updateValue($this->name, json_encode($form_values));
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            } catch (\Exception $e) {
                $output .= $this->displayError($this->l($e->getMessage()));
            }
        }
        return $output.$this->displayForm();
    }

    private function updateOrderByCartId(Cart $cart, $orderId) {
        $orderId = pSQL($orderId);
        $cartId = pSQL($cart->id);

        $result = Db::getInstance()->update('monetha_gateway', ['order_id' => $orderId],"`cart_id` = '$cartId'");

        return $result;
    }

    /**
     * @param string $dbName
     * @param string $tableName
     * @param string $columnName
     * @throws PrestaShopDatabaseException
     *
     * @return bool
     */
    private function columnExists($dbName, $tableName, $columnName) {
        $query = "SELECT `COLUMN_NAME` 
                  FROM `information_schema`.`COLUMNS` 
                  WHERE TABLE_SCHEMA='$dbName' 
                  AND TABLE_NAME='$tableName'";
        $data = Db::getInstance()->executeS($query);

        return $data && in_array($columnName, array_column($data, 'COLUMN_NAME'));
    }
}

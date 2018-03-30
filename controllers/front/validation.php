<?php
/*
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

use Monetha\OrderAdapter;
use Monetha\AuthorizationRequest;
use Monetha\Config;

/**
 * @since 1.5.0
 */
class MonethagatewayValidationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        /**
         * @var $this_module \Monethagateway
         */
        $this_module = $this->module;

        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this_module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'monethagateway') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this_module->l('This payment method is not available.', 'validation'));
        }

        $this->context->smarty->assign([
            'params' => $_REQUEST,
        ]);

        //$this->setTemplate('payment_return.tpl');
        $this->setTemplate('module:monethagateway/views/templates/front/payment_return.tpl');

        $orderAdapter = new OrderAdapter($cart, $this->context->currency->iso_code, _PS_BASE_URL_);

        $authorizationRequest = new AuthorizationRequest();
        $deal = $authorizationRequest->createDeal($orderAdapter, $cart->id);
        $paymentUrl = $authorizationRequest->getPaymentUrl($deal);

         $customer = new Customer($cart->id_customer);
         if (!Validate::isLoadedObject($customer))
             Tools::redirect('index.php?controller=order&step=1');

         $currency = $this->context->currency;
         $total = (float)$cart->getOrderTotal(true, Cart::BOTH);
         $mailVars = array(
             '{bankwire_owner}' => Configuration::get('BANK_WIRE_OWNER'),
             '{bankwire_details}' => nl2br(Configuration::get('BANK_WIRE_DETAILS')),
             '{bankwire_address}' => nl2br(Configuration::get('BANK_WIRE_ADDRESS')),
             '{payment_url}' => $paymentUrl,
         );

        $this_module->validateOrder($cart->id, Configuration::get(Config::ORDER_STATUS), $total, $this_module->displayName, NULL, $mailVars, (int)$currency->id, false, $customer->secure_key);
         //Tools::redirect('index.php?controller=order-confirmation&id_cart='.$cart->id.'&id_module='.$this_module->id.'&id_order='.$this_module->currentOrder.'&key='.$customer->secure_key);

        Tools::redirectLink($paymentUrl);
    }
}

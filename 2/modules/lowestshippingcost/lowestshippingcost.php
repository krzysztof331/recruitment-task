<?php
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class Lowestshippingcost extends Module
{
    public function __construct()
    {
        $this->name = 'lowestshippingcost';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Recruitment Task';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Lowest shipping cost');
        $this->description = $this->l('Displays the lowest possible shipping cost on the product page.');
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('displayProductAdditionalInfo');
    }

    public function uninstall(): bool
    {
        return parent::uninstall();
    }

    public function hookDisplayProductAdditionalInfo(array $params): string
    {
        $idProduct = $params['product']->id ?? 0;
        if ($idProduct === 0) {
            return '';
        }

        $lowest = $this->getLowestShippingCost($idProduct);
        if ($lowest === null) {
            return '';
        }

        $this->context->smarty->assign('lowest_shipping', $this->formatCost($lowest));

        return $this->display(__FILE__, 'views/templates/hook/displayProductAdditionalInfo.tpl');
    }

    private function formatCost(float $cost): string
    {
        return $cost === 0.0
            ? $this->l('Free shipping')
            : $this->context->getCurrentLocale()->formatPrice($cost, $this->context->currency->iso_code);
    }

    private function getLowestShippingCost(int $idProduct): ?float
    {
        $product = new Product($idProduct, false, (int) $this->context->language->id);
        if (!Validate::isLoadedObject($product) || $product->is_virtual || !$product->available_for_order) {
            return null;
        }

        $idCountry = (int) $this->context->country?->id;
        if ($idCountry === 0) {
            $idCountry = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        }

        $idZone = (int) Country::getIdZone($idCountry);
        if ($idZone === 0) {
            return null;
        }

        $groups = $this->context->customer?->isLogged()
            ? Customer::getGroupsStatic((int) $this->context->customer->id)
            : [(int) Configuration::get('PS_UNIDENTIFIED_GROUP')];

        $cart = new Cart();
        $cart->id_lang = (int) $this->context->language->id;
        $cart->id_currency = (int) $this->context->currency->id;
        $cart->id_shop = (int) $this->context->shop->id;
        $cart->id_shop_group = (int) $this->context->shop->id_shop_group;
        if (!$cart->add()) {
            return null;
        }

        $idAttribute = (int) Product::getDefaultAttribute($idProduct);

        $costs = [];
        try {
            $cart->updateQty(1, $idProduct, $idAttribute);

            foreach (Carrier::getCarriersForOrder($idZone, $groups, $cart) as $carrier) {
                $cost = $cart->getPackageShippingCost((int) $carrier['id_carrier'], true);
                if ($cost !== false) {
                    $costs[] = (float) $cost;
                }
            }
        } finally {
            $cart->delete();
        }

        if ($costs === []) {
            return null;
        }

        return min($costs);
    }
}

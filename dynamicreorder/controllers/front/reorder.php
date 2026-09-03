<?php
/**
 * Dynamic Reorder - front controller.
 *
 * Resolves the LAST order of the currently logged-in customer (never a fixed
 * id_order) and loads it into their cart using PrestaShop's native reorder
 * logic. Answers JSON to AJAX calls; falls back to a plain redirect flow for
 * no-JavaScript / direct-link usage. Never redirects to checkout.
 *
 * @author Anirudha Talmale
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class DynamicReorderReorderModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;
    /** We handle the "not logged in" case ourselves, so no forced auth redirect. */
    public $auth = false;

    /** True when the request is a real XHR from our own script. */
    private $isAjaxRequest = false;

    public function postProcess()
    {
        $this->isAjaxRequest = $this->detectAjax();

        if (!Configuration::get(DynamicReorder::CFG_ENABLED)) {
            // Switched off in the back office: behave as if the module were not
            // there rather than half-answering.
            if ($this->isAjaxRequest) {
                $this->respond(array('status' => 'disabled', 'message' => ''), 404);
            }
            Tools::redirect($this->context->link->getPageLink('index', null));
        }

        // CSRF protection for the AJAX path: a cross-site form post cannot set a
        // custom header, so requiring X-Requested-With on POST is enough here.
        // The GET path is the no-JS fallback and mirrors native PrestaShop's own
        // tokenless "?submitReorder&id_order=" link.
        if ($this->isAjaxRequest && Tools::strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
            $this->respond(array(
                'status' => 'error',
                'title' => $this->msg(DynamicReorder::CFG_TITLE_ERROR),
                'message' => $this->msg(DynamicReorder::CFG_MSG_ERROR),
            ), 405);
        }

        if (!$this->context->customer->isLogged()) {
            $this->handleNotLogged();
        }

        $order = $this->findLastOrder((int) $this->context->customer->id);

        if (!$order) {
            $this->finish(array(
                'status' => 'no_order',
                'title' => $this->msg(DynamicReorder::CFG_TITLE_NO_ORDER),
                'message' => $this->msg(DynamicReorder::CFG_MSG_NO_ORDER),
                'urls' => $this->urls(),
            ));
        }

        $result = Configuration::get(DynamicReorder::CFG_CART_MODE) === 'merge'
            ? $this->mergeIntoCurrentCart($order)
            : $this->replaceCartWithOrder($order);

        if ($result === false) {
            $this->finish(array(
                'status' => 'error',
                'title' => $this->msg(DynamicReorder::CFG_TITLE_ERROR),
                'message' => $this->msg(DynamicReorder::CFG_MSG_ERROR),
                'urls' => $this->urls(),
            ));
        }

        if ((int) $result['added'] === 0) {
            $this->finish(array(
                'status' => 'no_order',
                'title' => $this->msg(DynamicReorder::CFG_TITLE_NO_ORDER),
                'message' => $this->msg(DynamicReorder::CFG_MSG_NOTHING),
                'urls' => $this->urls(),
            ));
        }

        $this->fixDeliveryAddress();

        $partial = empty($result['complete']);
        $template = $partial
            ? $this->msg(DynamicReorder::CFG_MSG_PARTIAL)
            : $this->msg(DynamicReorder::CFG_MSG_SUCCESS);

        $this->finish(array(
            'status' => $partial ? 'partial' : 'success',
            'title' => $this->msg(DynamicReorder::CFG_MSG_TITLE),
            'message' => $this->renderMessage($template, $order, $result),
            'products_added' => (int) $result['added'],
            'products_total' => (int) $result['total'],
            'order' => array(
                'reference' => (string) $order['reference'],
                'date' => Tools::displayDate($order['date_add']),
            ),
            'cart' => $this->cartSummary(),
            'urls' => $this->urls(),
        ));
    }

    /**
     * $this->ajax = true means run() calls displayAjax() instead of display().
     * Everything already happened in postProcess(), this is only a safety net.
     */
    public function displayAjax()
    {
        $this->respond(array('status' => 'error', 'title' => $this->msg(DynamicReorder::CFG_TITLE_ERROR), 'message' => $this->msg(DynamicReorder::CFG_MSG_ERROR)), 400);
    }

    public function initContent()
    {
        $this->respond(array('status' => 'error', 'title' => $this->msg(DynamicReorder::CFG_TITLE_ERROR), 'message' => $this->msg(DynamicReorder::CFG_MSG_ERROR)), 400);
    }

    /* ------------------------------------------------------------------ */
    /* Order lookup                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The whole point of the module: the last order is resolved from the session,
     * so every customer gets their own.
     *
     * @return array|false
     */
    private function findLastOrder($id_customer)
    {
        if ($id_customer <= 0) {
            return false;
        }

        $scope = Configuration::get(DynamicReorder::CFG_ORDER_SCOPE);

        if ($scope === 'any') {
            return $this->queryLastOrder($id_customer, false);
        }

        $order = $this->queryLastOrder($id_customer, true);
        if ($order || $scope === 'valid') {
            return $order;
        }

        // 'valid_then_any': the customer has orders, just none marked valid yet
        // (bank wire awaiting payment, for instance). Better to reload that than
        // to tell them they have no orders.
        return $this->queryLastOrder($id_customer, false);
    }

    private function queryLastOrder($id_customer, $validOnly)
    {
        $sql = 'SELECT o.`id_order`, o.`id_cart`, o.`reference`, o.`date_add`, o.`valid`
                FROM `' . _DB_PREFIX_ . 'orders` o
                WHERE o.`id_customer` = ' . (int) $id_customer;

        if ($validOnly) {
            $sql .= ' AND o.`valid` = 1';
        }

        if (Shop::isFeatureActive()) {
            $sql .= Shop::addSqlRestriction(Shop::SHARE_ORDER, 'o');
        }

        // date_add alone is not enough: two orders can share a second.
        $sql .= ' ORDER BY o.`date_add` DESC, o.`id_order` DESC LIMIT 1';

        // Use executeS()+first row rather than getRow(): on some 1.6 installs
        // (verified on this client's shop, PS 1.6.1.6 / PHP 5.6) getRow() returns
        // an empty result for this exact query while executeS() returns the row,
        // which made every logged-in customer wrongly see "no previous order".
        // Cache is disabled explicitly so a stale/empty cached row can't recur.
        $rows = Db::getInstance()->executeS($sql, true, false);

        return (is_array($rows) && isset($rows[0])) ? $rows[0] : false;
    }

    /* ------------------------------------------------------------------ */
    /* Cart loading                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Native behaviour: duplicate the order's cart and switch the session to it.
     * This is the exact code path ParentOrderController::init() uses for
     * "submitReorder" (inherited by OrderController and OrderOpcController),
     * minus the redirect that leaves the customer on the checkout page.
     *
     * @return array|false
     */
    private function replaceCartWithOrder(array $order)
    {
        $id_cart = (int) Order::getCartIdStatic((int) $order['id_order'], (int) $this->context->customer->id);
        if (!$id_cart) {
            return false;
        }

        $oldCart = new Cart($id_cart);
        if (!Validate::isLoadedObject($oldCart)) {
            return false;
        }

        // Count from the ORDER, not from the old cart: a product deleted from the
        // catalogue since then already drops out of Cart::getProducts(), which
        // would make a partial load look complete.
        $orderObject = new Order((int) $order['id_order']);
        $expected = Validate::isLoadedObject($orderObject)
            ? $this->countOrderProducts($orderObject)
            : $this->countProducts($oldCart);

        $duplication = $oldCart->duplicate();
        if (!$duplication || !isset($duplication['cart']) || !Validate::isLoadedObject($duplication['cart'])) {
            return false;
        }

        /** @var Cart $newCart */
        $newCart = $duplication['cart'];
        $added = $this->countProducts($newCart);

        if ($added === 0) {
            // Every line is gone (discontinued, out of stock). Switching to the
            // empty duplicate would silently wipe whatever the customer already
            // had in their cart, so leave the session on the original cart.
            return array('added' => 0, 'total' => $expected, 'complete' => false);
        }

        $this->context->cart = $newCart;
        $this->context->cookie->id_cart = (int) $newCart->id;
        CartRule::autoAddToCart($this->context);
        $this->context->cookie->write();

        // duplicate() reports success = false when it could not carry every line
        // over (deleted product, out of stock, no longer available for order).
        return array(
            'added' => $added,
            'total' => max($expected, $added),
            'complete' => !empty($duplication['success']) && $added >= $expected,
        );
    }

    private function countOrderProducts(Order $order)
    {
        $count = 0;
        foreach ($order->getProducts() as $product) {
            $qty = (int) $product['product_quantity'];
            if (isset($product['product_quantity_refunded'])) {
                $qty -= (int) $product['product_quantity_refunded'];
            }
            $count += max(0, $qty);
        }

        return $count;
    }

    /**
     * Optional mode: keep whatever is already in the cart and add the order on top.
     *
     * @return array|false
     */
    private function mergeIntoCurrentCart(array $order)
    {
        if (!$this->ensureCart()) {
            return false;
        }

        $orderObject = new Order((int) $order['id_order']);
        if (!Validate::isLoadedObject($orderObject)) {
            return false;
        }

        $products = $orderObject->getProducts();
        $added = 0;
        $total = 0;

        foreach ($products as $product) {
            $qty = (int) $product['product_quantity'];
            if (isset($product['product_quantity_refunded'])) {
                $qty -= (int) $product['product_quantity_refunded'];
            }
            if ($qty <= 0) {
                continue;
            }

            // Counted in units, so the message matches replace mode.
            $total += $qty;

            $done = $this->context->cart->updateQty(
                $qty,
                (int) $product['product_id'],
                (int) $product['product_attribute_id'],
                false,
                'up'
            );

            // updateQty returns true, or an int/-1 style error code when the
            // product is unavailable. Anything not strictly true is a skip.
            if ($done === true) {
                $added += $qty;
            }
        }

        CartRule::autoAddToCart($this->context);
        $this->context->cookie->write();

        return array('added' => $added, 'total' => $total, 'complete' => $added >= $total);
    }

    private function ensureCart()
    {
        if (Validate::isLoadedObject($this->context->cart) && $this->context->cart->id) {
            return true;
        }

        $cart = new Cart();
        $cart->id_customer = (int) $this->context->customer->id;
        $cart->id_currency = (int) $this->context->currency->id;
        $cart->id_lang = (int) $this->context->language->id;
        $cart->id_shop = (int) $this->context->shop->id;
        $cart->id_shop_group = (int) $this->context->shop->id_shop_group;
        $cart->id_guest = (int) $this->context->cookie->id_guest;
        $cart->secure_key = $this->context->customer->secure_key;
        $cart->id_address_delivery = (int) Address::getFirstCustomerAddressId((int) $this->context->customer->id);
        $cart->id_address_invoice = (int) $cart->id_address_delivery;

        if (!$cart->add()) {
            return false;
        }

        $this->context->cart = $cart;
        $this->context->cookie->id_cart = (int) $cart->id;
        $this->context->cookie->write();

        return true;
    }

    /**
     * A duplicated cart carries the old order's address id. If that address has
     * since been deleted the customer hits a dead end at checkout, so re-point it.
     */
    private function fixDeliveryAddress()
    {
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            return;
        }

        $address = new Address((int) $cart->id_address_delivery);
        $valid = Validate::isLoadedObject($address)
            && !$address->deleted
            && (int) $address->id_customer === (int) $this->context->customer->id;

        if ($valid) {
            return;
        }

        $fallback = (int) Address::getFirstCustomerAddressId((int) $this->context->customer->id);
        $cart->id_address_delivery = $fallback;
        $cart->id_address_invoice = $fallback;
        $cart->update();
    }

    private function countProducts(Cart $cart)
    {
        $count = 0;
        foreach ($cart->getProducts(true) as $product) {
            $count += (int) $product['cart_quantity'];
        }

        return $count;
    }

    /* ------------------------------------------------------------------ */
    /* Not logged in                                                       */
    /* ------------------------------------------------------------------ */

    private function handleNotLogged()
    {
        $urls = $this->urls();

        if ($this->isAjaxRequest) {
            $this->respond(array(
                'status' => 'not_logged_in',
                'title' => $this->msg(DynamicReorder::CFG_TITLE_LOGIN),
                'message' => $this->msg(DynamicReorder::CFG_MSG_LOGIN),
                'urls' => $urls,
            ));
        }

        // No-JS path: straight to login, and PrestaShop brings them back here
        // afterwards so the reorder completes without a second click.
        Tools::redirect($urls['login']);
    }

    /**
     * After login the customer must land back on the HOMEPAGE with the resume
     * flag, not on the checkout - that is the whole point of the brief.
     */
    private function urls()
    {
        $home = $this->context->link->getPageLink('index', null);
        $resume = $this->addFlag($home, DynamicReorder::RESUME_FLAG, 1);

        $login = $this->context->link->getPageLink('authentication', null, null, array('back' => $resume));
        $register = $this->buildRegistrationUrl($resume);

        return array(
            'home' => $home,
            'resume' => $resume,
            'login' => $login,
            'register' => $register,
            'cart' => $this->context->link->getPageLink('cart', null, null, array('action' => 'show')),
        );
    }

    private function buildRegistrationUrl($back)
    {
        // 'registration' only became its own page in 1.7.6. On 1.6 and early 1.7
        // that controller does not exist, and getPageLink() would happily build a
        // dead URL, so gate on the version rather than on the string it returns.
        if (version_compare(_PS_VERSION_, '1.7.6.0', '>=')) {
            return $this->context->link->getPageLink('registration', null, null, array('back' => $back));
        }

        return $this->context->link->getPageLink(
            'authentication',
            true,
            null,
            array('create_account' => 1, 'back' => $back)
        );
    }

    private function addFlag($url, $key, $value)
    {
        return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . (int) $value;
    }

    /* ------------------------------------------------------------------ */
    /* Output                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * AJAX gets JSON. A direct link gets sent back to the homepage with a flag,
     * where the same pop-up is shown. Neither path touches checkout.
     */
    private function finish(array $payload)
    {
        if ($this->isAjaxRequest) {
            $this->respond($payload);
        }

        // No-JS path: stash the result so the homepage can show the same pop-up
        // after the redirect, then clear it (one-shot flash).
        $this->context->cookie->dr_flash = json_encode($payload);
        $this->context->cookie->write();

        $home = $this->context->link->getPageLink('index', null);
        $url = $home . (strpos($home, '?') === false ? '?' : '&')
            . DynamicReorder::DONE_FLAG . '=' . urlencode($payload['status']);

        Tools::redirect($url);
    }

    private function respond(array $payload, $httpCode = 200)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (!headers_sent()) {
            // http_response_code() is PHP 5.4+; PS 1.6 can still run on 5.3.
            if (function_exists('http_response_code')) {
                http_response_code($httpCode);
            } else {
                header('HTTP/1.1 ' . (int) $httpCode);
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        echo json_encode($payload);
        exit;
    }

    private function detectAjax()
    {
        $header = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? Tools::strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) : '';

        return $header === 'xmlhttprequest';
    }

    private function cartSummary()
    {
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            return array('nb' => 0, 'total' => '');
        }

        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        return array(
            'nb' => (int) $cart->nbProducts(),
            'total' => $this->formatPrice($total),
        );
    }

    private function formatPrice($amount)
    {
        if (method_exists($this->context, 'getCurrentLocale')) {
            $locale = $this->context->getCurrentLocale();
            if ($locale) {
                return $locale->formatPrice($amount, $this->context->currency->iso_code);
            }
        }

        return Tools::displayPrice($amount, $this->context->currency);
    }

    private function msg($key)
    {
        $value = Configuration::get($key);

        return $value === false ? '' : (string) $value;
    }

    private function renderMessage($template, array $order, array $result)
    {
        return str_replace(
            array('%count%', '%total%', '%reference%', '%date%'),
            array(
                (int) $result['added'],
                (int) $result['total'],
                (string) $order['reference'],
                Tools::displayDate($order['date_add']),
            ),
            $template
        );
    }
}

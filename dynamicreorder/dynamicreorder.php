<?php
/**
 * Dynamic Reorder
 *
 * Turns a static homepage "reorder" banner/button into a dynamic one: it loads the
 * CURRENT logged-in customer's LAST order into their cart, using PrestaShop's own
 * native reorder logic (Cart::duplicate + CartRule::autoAddToCart), shows a
 * confirmation pop-up and keeps the customer on the homepage instead of sending
 * them to checkout.
 *
 * @author    Anirudha Talmale
 * @license   Proprietary - delivered to the client of this project
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class DynamicReorder extends Module
{
    /** CSS selector(s) of the existing homepage banner/button */
    const CFG_SELECTOR = 'DYNAMICREORDER_SELECTOR';
    /** valid_then_any | valid | any */
    const CFG_ORDER_SCOPE = 'DYNAMICREORDER_ORDER_SCOPE';
    /** replace | merge */
    const CFG_CART_MODE = 'DYNAMICREORDER_CART_MODE';
    /** reload | stay */
    const CFG_AFTER = 'DYNAMICREORDER_AFTER';
    /** load the JS on every page (1) or only on the homepage (0) */
    const CFG_EVERYWHERE = 'DYNAMICREORDER_EVERYWHERE';

    const CFG_MSG_TITLE = 'DYNAMICREORDER_MSG_TITLE';
    const CFG_TITLE_LOGIN = 'DYNAMICREORDER_TITLE_LOGIN';
    const CFG_TITLE_NO_ORDER = 'DYNAMICREORDER_TITLE_NOORDER';
    const CFG_TITLE_ERROR = 'DYNAMICREORDER_TITLE_ERROR';
    const CFG_MSG_SUCCESS = 'DYNAMICREORDER_MSG_SUCCESS';
    const CFG_MSG_PARTIAL = 'DYNAMICREORDER_MSG_PARTIAL';
    const CFG_MSG_LOGIN = 'DYNAMICREORDER_MSG_LOGIN';
    const CFG_MSG_NO_ORDER = 'DYNAMICREORDER_MSG_NO_ORDER';
    const CFG_MSG_NOTHING = 'DYNAMICREORDER_MSG_NOTHING';
    const CFG_MSG_ERROR = 'DYNAMICREORDER_MSG_ERROR';

    /** Query-string flag used to resume the reorder after a login round-trip */
    const RESUME_FLAG = 'dr_reorder';
    /** Query-string flag set by the no-JS fallback after a successful reorder */
    const DONE_FLAG = 'dr_done';

    public function __construct()
    {
        $this->name = 'dynamicreorder';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Anirudha Talmale';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Dynamic Reorder (last order from the homepage)');
        $this->description = $this->l('Makes an existing homepage banner/button load the logged-in customer\'s last order into their cart, with a confirmation pop-up, without redirecting to checkout.');
        $this->confirmUninstall = $this->l('Remove Dynamic Reorder and its settings?');

        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
    }

    /* ------------------------------------------------------------------ */
    /* Install / uninstall                                                 */
    /* ------------------------------------------------------------------ */

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayHeader')
            && $this->installDefaults();
    }

    public function uninstall()
    {
        foreach ($this->getConfigKeys() as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    private function getConfigKeys()
    {
        return array(
            self::CFG_SELECTOR,
            self::CFG_ORDER_SCOPE,
            self::CFG_CART_MODE,
            self::CFG_AFTER,
            self::CFG_EVERYWHERE,
            self::CFG_MSG_TITLE,
            self::CFG_TITLE_LOGIN,
            self::CFG_TITLE_NO_ORDER,
            self::CFG_TITLE_ERROR,
            self::CFG_MSG_SUCCESS,
            self::CFG_MSG_PARTIAL,
            self::CFG_MSG_LOGIN,
            self::CFG_MSG_NO_ORDER,
            self::CFG_MSG_NOTHING,
            self::CFG_MSG_ERROR,
        );
    }

    private function installDefaults()
    {
        $defaults = array(
            self::CFG_SELECTOR => '#dynamic-reorder, .dynamic-reorder, .js-dynamic-reorder',
            self::CFG_ORDER_SCOPE => 'valid_then_any',
            self::CFG_CART_MODE => 'replace',
            self::CFG_AFTER => 'reload',
            self::CFG_EVERYWHERE => 0,
            self::CFG_MSG_TITLE => $this->l('Order loaded'),
            self::CFG_TITLE_LOGIN => $this->l('Please log in first'),
            self::CFG_TITLE_NO_ORDER => $this->l('No previous order found'),
            self::CFG_TITLE_ERROR => $this->l('Something went wrong'),
            self::CFG_MSG_SUCCESS => $this->l('Order loaded successfully. Your last order (%reference%) has been added to your cart - %count% product(s). You can review it and add or remove items before checking out.'),
            self::CFG_MSG_PARTIAL => $this->l('Order loaded. %count% of %total% product(s) from your last order (%reference%) were added to your cart. The remaining items are no longer available.'),
            self::CFG_MSG_LOGIN => $this->l('Please log in to load your last order. If you do not have an account yet, you can create one in a few seconds.'),
            self::CFG_MSG_NO_ORDER => $this->l('We could not find a previous order on your account yet, so there is nothing to reload.'),
            self::CFG_MSG_NOTHING => $this->l('None of the products from your last order are available any more, so your cart has been left unchanged.'),
            self::CFG_MSG_ERROR => $this->l('Sorry, your last order could not be loaded. Please try again or contact us.'),
        );

        foreach ($defaults as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Front office                                                        */
    /* ------------------------------------------------------------------ */

    public function hookDisplayHeader()
    {
        if (!$this->shouldLoadAssets()) {
            return;
        }

        $this->context->controller->registerStylesheet(
            'modules-dynamicreorder',
            'modules/' . $this->name . '/views/css/dynamicreorder.css',
            array('media' => 'all', 'priority' => 200)
        );

        $this->context->controller->registerJavascript(
            'modules-dynamicreorder',
            'modules/' . $this->name . '/views/js/dynamicreorder.js',
            array('position' => 'bottom', 'priority' => 200)
        );

        Media::addJsDef(array('dynamicReorderConfig' => $this->getFrontConfig()));
    }

    private function shouldLoadAssets()
    {
        if (Configuration::get(self::CFG_EVERYWHERE)) {
            return true;
        }

        $controller = $this->context->controller;
        if (!is_object($controller)) {
            return false;
        }

        // Homepage only, plus the CMS/other page the customer might come back to
        // after logging in (the resume flag is what actually matters there).
        if (isset($controller->php_self) && $controller->php_self === 'index') {
            return true;
        }

        return (bool) Tools::getValue(self::RESUME_FLAG) || (bool) Tools::getValue(self::DONE_FLAG);
    }

    /**
     * One-shot result left behind by the no-JavaScript redirect path, so the
     * homepage can show exactly the same pop-up as the AJAX path.
     */
    private function popFlash()
    {
        if (!Tools::getValue(self::DONE_FLAG)) {
            return null;
        }

        $raw = isset($this->context->cookie->dr_flash) ? $this->context->cookie->dr_flash : '';
        if (!$raw) {
            return null;
        }

        unset($this->context->cookie->dr_flash);
        $this->context->cookie->write();

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function getFrontConfig()
    {
        return array(
            'flash' => $this->popFlash(),
            'endpoint' => $this->context->link->getModuleLink($this->name, 'reorder', array(), true),
            'selector' => (string) Configuration::get(self::CFG_SELECTOR),
            'after' => (string) Configuration::get(self::CFG_AFTER),
            'resumeFlag' => self::RESUME_FLAG,
            'doneFlag' => self::DONE_FLAG,
            'title' => (string) Configuration::get(self::CFG_MSG_TITLE),
            'labels' => array(
                'close' => $this->l('Continue shopping'),
                'cart' => $this->l('View my cart'),
                'login' => $this->l('Log in'),
                'register' => $this->l('Create an account'),
                'cartNow' => $this->l('Your cart now holds %nb% item(s) - %total%'),
                'loading' => $this->l('Loading your last order...'),
                'network' => $this->l('The request could not be completed. Please check your connection and try again.'),
            ),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Back office configuration                                           */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitDynamicReorder')) {
            $selector = trim((string) Tools::getValue(self::CFG_SELECTOR));
            if ($selector === '') {
                $output .= $this->displayError($this->l('The button selector cannot be empty.'));
            } else {
                Configuration::updateValue(self::CFG_SELECTOR, $selector);
                Configuration::updateValue(self::CFG_ORDER_SCOPE, Tools::getValue(self::CFG_ORDER_SCOPE));
                Configuration::updateValue(self::CFG_CART_MODE, Tools::getValue(self::CFG_CART_MODE));
                Configuration::updateValue(self::CFG_AFTER, Tools::getValue(self::CFG_AFTER));
                Configuration::updateValue(self::CFG_EVERYWHERE, (int) Tools::getValue(self::CFG_EVERYWHERE));

                foreach (array(
                    self::CFG_MSG_TITLE,
                    self::CFG_TITLE_LOGIN,
                    self::CFG_TITLE_NO_ORDER,
                    self::CFG_TITLE_ERROR,
                    self::CFG_MSG_SUCCESS,
                    self::CFG_MSG_PARTIAL,
                    self::CFG_MSG_LOGIN,
                    self::CFG_MSG_NO_ORDER,
                    self::CFG_MSG_NOTHING,
                    self::CFG_MSG_ERROR,
                ) as $key) {
                    Configuration::updateValue($key, Tools::getValue($key));
                }

                $output .= $this->displayConfirmation($this->l('Settings saved.'));
            }
        }

        return $output . $this->renderHelp() . $this->renderForm();
    }

    private function renderHelp()
    {
        $link = $this->context->link->getModuleLink($this->name, 'reorder', array(), true);

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-info"></i> '
            . $this->l('How to wire this up') . '</div>';
        $html .= '<p>' . $this->l('There are two ways to connect your existing homepage banner/button. Either one works - pick whichever is easier on your theme.') . '</p>';
        $html .= '<p><strong>' . $this->l('Option A - by CSS selector (recommended, gives the pop-up).') . '</strong> '
            . $this->l('Give your banner/button an id or class, then type that selector in the field below. Example: if your button is')
            . ' <code>&lt;a href="#" id="dynamic-reorder"&gt;Reorder&lt;/a&gt;</code> ' . $this->l('then the selector is')
            . ' <code>#dynamic-reorder</code>. ' . $this->l('You can list several selectors separated by commas.') . '</p>';
        $html .= '<p><strong>' . $this->l('Option B - by direct link (works even without JavaScript).') . '</strong> '
            . $this->l('Point the banner\'s href at this URL:') . '<br /><code>' . Tools::safeOutput($link) . '</code><br />'
            . $this->l('The customer is sent to login if needed, the order is loaded, and they land back on the homepage with the confirmation pop-up. No checkout redirect either way.') . '</p>';
        $html .= '<p class="help-block">' . $this->l('The module never uses a fixed id_order. It always resolves the last order of whoever is logged in at that moment.') . '</p>';
        $html .= '</div>';

        return $html;
    }

    private function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Button / banner CSS selector'),
                        'name' => self::CFG_SELECTOR,
                        'desc' => $this->l('The id or class of your existing homepage reorder button. Several selectors can be separated by commas. Clicks are captured with event delegation, so it also works for buttons injected by a slider or by JavaScript.'),
                        'required' => true,
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Which order counts as "the last order"'),
                        'name' => self::CFG_ORDER_SCOPE,
                        'options' => array(
                            'query' => array(
                                array('id' => 'valid_then_any', 'name' => $this->l('Last valid (paid) order, otherwise the last order of any status')),
                                array('id' => 'valid', 'name' => $this->l('Last valid (paid) order only')),
                                array('id' => 'any', 'name' => $this->l('Last order, whatever its status')),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                        'desc' => $this->l('"Valid" is PrestaShop\'s own flag - it is set once the order reaches a payment-accepted state. Cancelled and refunded orders are not valid.'),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('If the cart is not empty'),
                        'name' => self::CFG_CART_MODE,
                        'options' => array(
                            'query' => array(
                                array('id' => 'replace', 'name' => $this->l('Replace the current cart (same as the native reorder button)')),
                                array('id' => 'merge', 'name' => $this->l('Add the order on top of what is already in the cart')),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                        'desc' => $this->l('Replace mode uses PrestaShop\'s native cart duplication and carries product customisations across. Merge mode adds the ordered quantities to the existing cart but cannot recreate text/file customisations.'),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('After the pop-up is closed'),
                        'name' => self::CFG_AFTER,
                        'options' => array(
                            'query' => array(
                                array('id' => 'reload', 'name' => $this->l('Reload the homepage (safest - the cart block is always correct)')),
                                array('id' => 'stay', 'name' => $this->l('Stay on the page and only refresh the cart block')),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                        'desc' => $this->l('Either way the customer stays on the homepage - there is no redirect to checkout. Reload is the safe default because some custom themes do not listen to PrestaShop\'s cart refresh event.'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Load on every page'),
                        'name' => self::CFG_EVERYWHERE,
                        'is_bool' => true,
                        'desc' => $this->l('Off = the script only loads on the homepage. Turn on if you also placed the button in the header or footer.'),
                        'values' => array(
                            array('id' => 'dr_ev_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'dr_ev_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Pop-up title - order loaded'),
                        'name' => self::CFG_MSG_TITLE,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Pop-up title - not logged in'),
                        'name' => self::CFG_TITLE_LOGIN,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Pop-up title - no previous order'),
                        'name' => self::CFG_TITLE_NO_ORDER,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Pop-up title - error'),
                        'name' => self::CFG_TITLE_ERROR,
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Success message'),
                        'name' => self::CFG_MSG_SUCCESS,
                        'desc' => $this->l('Placeholders: %count% (products added), %total% (products in the order), %reference% (order reference), %date% (order date).'),
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Message when some products are no longer available'),
                        'name' => self::CFG_MSG_PARTIAL,
                        'desc' => $this->l('Same placeholders as above.'),
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Message when the customer is not logged in'),
                        'name' => self::CFG_MSG_LOGIN,
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Message when the customer has no previous order'),
                        'name' => self::CFG_MSG_NO_ORDER,
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Message when none of the ordered products are still available'),
                        'name' => self::CFG_MSG_NOTHING,
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Message when something goes wrong'),
                        'name' => self::CFG_MSG_ERROR,
                    ),
                ),
                'submit' => array('title' => $this->l('Save')),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitDynamicReorder';
        $helper->title = $this->displayName;

        $values = array();
        foreach ($this->getConfigKeys() as $key) {
            $values[$key] = Configuration::get($key);
        }
        $helper->tpl_vars = array('fields_value' => $values);

        return $helper->generateForm(array($fields_form));
    }
}

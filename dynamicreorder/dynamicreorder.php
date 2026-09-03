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
 * Targets PrestaShop 1.6.x primarily; also runs on 1.7.x / 8.x. Everything that
 * differs between the branches goes through the compat helpers at the bottom.
 *
 * @author    Anirudha Talmale
 * @license   Proprietary - delivered to the client of this project
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class DynamicReorder extends Module
{
    /** Master on/off switch */
    const CFG_ENABLED = 'DYNAMICREORDER_ENABLED';
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

    /** Pop-up button labels - configurable so they can be written in any language */
    const CFG_BTN_CLOSE = 'DYNAMICREORDER_BTN_CLOSE';
    const CFG_BTN_CART = 'DYNAMICREORDER_BTN_CART';
    const CFG_BTN_LOGIN = 'DYNAMICREORDER_BTN_LOGIN';
    const CFG_BTN_REGISTER = 'DYNAMICREORDER_BTN_REGISTER';
    const CFG_MSG_CART_NOW = 'DYNAMICREORDER_MSG_CARTNOW';

    /** Query-string flag used to resume the reorder after a login round-trip */
    const RESUME_FLAG = 'dr_reorder';
    /** Query-string flag set by the no-JS fallback after a successful reorder */
    const DONE_FLAG = 'dr_done';

    public function __construct()
    {
        $this->name = 'dynamicreorder';
        $this->tab = 'front_office_features';
        $this->version = '1.1.1';
        $this->author = 'Anirudha Talmale';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Dynamic Reorder (last order from the homepage)');
        $this->description = $this->l('Makes an existing homepage banner/button load the logged-in customer\'s last order into their cart, with a confirmation pop-up, without redirecting to checkout.');
        $this->confirmUninstall = $this->l('Remove Dynamic Reorder and its settings?');

        $this->ps_versions_compliancy = array('min' => '1.6.0.0', 'max' => _PS_VERSION_);
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
            self::CFG_ENABLED,
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
            self::CFG_BTN_CLOSE,
            self::CFG_BTN_CART,
            self::CFG_BTN_LOGIN,
            self::CFG_BTN_REGISTER,
            self::CFG_MSG_CART_NOW,
        );
    }

    /**
     * Defaults are written in Spanish because that is the shop's language.
     * Every one of them is editable on the configuration screen.
     */
    private function installDefaults()
    {
        $defaults = array(
            self::CFG_ENABLED => 1,
            self::CFG_SELECTOR => '#dynamic-reorder, .dynamic-reorder, .js-dynamic-reorder',
            self::CFG_ORDER_SCOPE => 'valid',
            self::CFG_CART_MODE => 'replace',
            self::CFG_AFTER => 'reload',
            self::CFG_EVERYWHERE => 0,

            self::CFG_MSG_TITLE => '¡Pedido cargado!',
            self::CFG_TITLE_LOGIN => 'Iniciá sesión primero',
            self::CFG_TITLE_NO_ORDER => 'No encontramos pedidos anteriores',
            self::CFG_TITLE_ERROR => 'Hubo un problema',

            self::CFG_MSG_SUCCESS => '¡Listo! Cargamos tu último pedido (%reference%) en el carrito: %count% producto(s). Podés modificar, agregar o quitar productos antes de confirmar.',
            self::CFG_MSG_PARTIAL => 'Cargamos %count% de %total% producto(s) de tu último pedido (%reference%). El resto ya no está disponible.',
            self::CFG_MSG_LOGIN => 'Iniciá sesión para cargar tu último pedido. Si todavía no tenés cuenta, podés crearla en unos segundos.',
            self::CFG_MSG_NO_ORDER => 'Todavía no encontramos un pedido anterior en tu cuenta, así que no hay nada para repetir.',
            self::CFG_MSG_NOTHING => 'Ninguno de los productos de tu último pedido está disponible en este momento, así que dejamos tu carrito como estaba.',
            self::CFG_MSG_ERROR => 'No pudimos cargar tu último pedido. Probá de nuevo o escribinos.',

            self::CFG_BTN_CLOSE => 'Seguir comprando',
            self::CFG_BTN_CART => 'Ver mi carrito',
            self::CFG_BTN_LOGIN => 'Iniciar sesión',
            self::CFG_BTN_REGISTER => 'Crear una cuenta',
            self::CFG_MSG_CART_NOW => 'Tu carrito ahora tiene %nb% producto(s) - %total%',
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
        if (!Configuration::get(self::CFG_ENABLED) || !$this->shouldLoadAssets()) {
            return;
        }

        $controller = $this->context->controller;

        if (method_exists($controller, 'registerStylesheet')) {
            // PrestaShop 1.7 / 8 - paths are relative to the shop root.
            $controller->registerStylesheet(
                'modules-dynamicreorder',
                'modules/' . $this->name . '/views/css/dynamicreorder.css',
                array('media' => 'all', 'priority' => 200)
            );
            $controller->registerJavascript(
                'modules-dynamicreorder',
                'modules/' . $this->name . '/views/js/dynamicreorder.js',
                array('position' => 'bottom', 'priority' => 200)
            );
        } else {
            // PrestaShop 1.6 - registerStylesheet()/registerJavascript() do not
            // exist; addCSS()/addJS() take a web path built from $this->_path.
            $controller->addCSS($this->_path . 'views/css/dynamicreorder.css', 'all');
            $controller->addJS($this->_path . 'views/js/dynamicreorder.js');
        }

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

        if (isset($controller->php_self) && $controller->php_self === 'index') {
            return true;
        }

        // Also needed on whatever page carries a resume/result flag.
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
            'endpoint' => $this->context->link->getModuleLink($this->name, 'reorder', array(), null),
            'selector' => (string) Configuration::get(self::CFG_SELECTOR),
            'after' => (string) Configuration::get(self::CFG_AFTER),
            'resumeFlag' => self::RESUME_FLAG,
            'doneFlag' => self::DONE_FLAG,
            'title' => (string) Configuration::get(self::CFG_MSG_TITLE),
            'labels' => array(
                'close' => (string) Configuration::get(self::CFG_BTN_CLOSE),
                'cart' => (string) Configuration::get(self::CFG_BTN_CART),
                'login' => (string) Configuration::get(self::CFG_BTN_LOGIN),
                'register' => (string) Configuration::get(self::CFG_BTN_REGISTER),
                'cartNow' => (string) Configuration::get(self::CFG_MSG_CART_NOW),
                'network' => (string) Configuration::get(self::CFG_MSG_ERROR),
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
                Configuration::updateValue(self::CFG_ENABLED, (int) Tools::getValue(self::CFG_ENABLED));
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
                    self::CFG_BTN_CLOSE,
                    self::CFG_BTN_CART,
                    self::CFG_BTN_LOGIN,
                    self::CFG_BTN_REGISTER,
                    self::CFG_MSG_CART_NOW,
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
        $link = $this->context->link->getModuleLink($this->name, 'reorder', array(), null);

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-info"></i> '
            . $this->l('How to connect your banner') . '</div>';
        $html .= '<p>' . $this->l('Two ways - use whichever is easier on your theme. Both keep the customer on the homepage and both show the pop-up.') . '</p>';

        $html .= '<p><strong>' . $this->l('Option A - paste this link into your banner.') . '</strong> '
            . $this->l('If your banner is an image managed from the back office (for example an AP Page Builder image block), just set its link/URL field to:')
            . '</p><p><code style="user-select:all">' . Tools::safeOutput($link) . '</code></p>'
            . '<p class="help-block">' . $this->l('Nothing else to do - this also works if the customer has JavaScript disabled.') . '</p>';

        $html .= '<p><strong>' . $this->l('Option B - by CSS selector.') . '</strong> '
            . $this->l('If the banner is real HTML you can edit, give it an id or class and type that selector in the field below. Example:')
            . ' <code>&lt;a href="#" id="dynamic-reorder"&gt;...&lt;/a&gt;</code> ' . $this->l('means the selector is')
            . ' <code>#dynamic-reorder</code>. ' . $this->l('Separate several selectors with commas.') . '</p>';

        $html .= '<p class="help-block">' . $this->l('The module never uses a fixed id_order. It always resolves the last order of whoever is logged in at that moment.') . '</p>';
        $html .= '</div>';

        return $html;
    }

    private function renderForm()
    {
        $switchValues = array(
            array('id' => 'dr_on', 'value' => 1, 'label' => $this->l('Yes')),
            array('id' => 'dr_off', 'value' => 0, 'label' => $this->l('No')),
        );

        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enable dynamic reorder'),
                        'name' => self::CFG_ENABLED,
                        'is_bool' => true,
                        'desc' => $this->l('The one-click on/off switch. Turn it off and the shop behaves exactly as it did before.'),
                        'values' => $switchValues,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Button / banner CSS selector'),
                        'name' => self::CFG_SELECTOR,
                        'desc' => $this->l('Only needed for Option B above. Clicks are captured by delegation, so buttons injected later by a slider still work.'),
                        'required' => true,
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Which order counts as "the last order"'),
                        'name' => self::CFG_ORDER_SCOPE,
                        'options' => array(
                            'query' => array(
                                array('id' => 'valid', 'name' => $this->l('Last valid (paid) order only')),
                                array('id' => 'valid_then_any', 'name' => $this->l('Last valid order, otherwise the last order of any status')),
                                array('id' => 'any', 'name' => $this->l('Last order, whatever its status')),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                        'desc' => $this->l('"Valid" is PrestaShop\'s own flag, set once the order reaches a payment-accepted state. Cancelled and refunded orders are not valid.'),
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
                        'desc' => $this->l('Replace mode uses PrestaShop\'s native cart duplication and carries product customisations across. Merge mode adds the ordered quantities to the existing cart but cannot recreate customisations.'),
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
                        'desc' => $this->l('Either way the customer stays on the homepage - there is no redirect to checkout.'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Load on every page'),
                        'name' => self::CFG_EVERYWHERE,
                        'is_bool' => true,
                        'desc' => $this->l('Off = homepage only. Turn on if the button also sits in the header or footer.'),
                        'values' => $switchValues,
                    ),

                    array('type' => 'text', 'label' => $this->l('Pop-up title - order loaded'), 'name' => self::CFG_MSG_TITLE),
                    array('type' => 'text', 'label' => $this->l('Pop-up title - not logged in'), 'name' => self::CFG_TITLE_LOGIN),
                    array('type' => 'text', 'label' => $this->l('Pop-up title - no previous order'), 'name' => self::CFG_TITLE_NO_ORDER),
                    array('type' => 'text', 'label' => $this->l('Pop-up title - error'), 'name' => self::CFG_TITLE_ERROR),

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
                    array('type' => 'textarea', 'label' => $this->l('Message when the customer is not logged in'), 'name' => self::CFG_MSG_LOGIN),
                    array('type' => 'textarea', 'label' => $this->l('Message when the customer has no previous order'), 'name' => self::CFG_MSG_NO_ORDER),
                    array('type' => 'textarea', 'label' => $this->l('Message when none of the ordered products are still available'), 'name' => self::CFG_MSG_NOTHING),
                    array('type' => 'textarea', 'label' => $this->l('Message when something goes wrong'), 'name' => self::CFG_MSG_ERROR),

                    array('type' => 'text', 'label' => $this->l('Button label - continue shopping'), 'name' => self::CFG_BTN_CLOSE),
                    array('type' => 'text', 'label' => $this->l('Button label - view cart'), 'name' => self::CFG_BTN_CART),
                    array('type' => 'text', 'label' => $this->l('Button label - log in'), 'name' => self::CFG_BTN_LOGIN),
                    array('type' => 'text', 'label' => $this->l('Button label - create an account'), 'name' => self::CFG_BTN_REGISTER),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Cart summary line in the pop-up'),
                        'name' => self::CFG_MSG_CART_NOW,
                        'desc' => $this->l('Placeholders: %nb% (items in the cart), %total% (cart total). Leave empty to hide this line.'),
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

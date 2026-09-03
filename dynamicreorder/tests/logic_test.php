<?php
/**
 * Executes the real controller logic against stubs, to test the parts that do
 * not need a database: last-order resolution, partial detection, placeholders,
 * and the login/registration URL branch per PrestaShop version.
 */
define('_PS_VERSION_', getenv('PSV') ?: '1.6.1.6');
define('_DB_PREFIX_', 'ps_');

$GLOBALS['SQL'] = array();
$GLOBALS['ROWS'] = array();
$GLOBALS['CONF'] = array();
$GLOBALS['EXIT'] = null;

class Configuration {
    public static function get($k) { return isset($GLOBALS['CONF'][$k]) ? $GLOBALS['CONF'][$k] : false; }
}
class Db {
    public static function getInstance() { return new self(); }
    public function getRow($sql) {
        $GLOBALS['SQL'][] = preg_replace('/\s+/', ' ', trim($sql));
        return array_shift($GLOBALS['ROWS']);
    }
    // Mirrors PrestaShop's executeS($sql, $array=true, $use_cache=true).
    public function executeS($sql, $array = true, $use_cache = true) {
        $GLOBALS['SQL'][] = preg_replace('/\s+/', ' ', trim($sql));
        $row = array_shift($GLOBALS['ROWS']);
        return $row ? array($row) : array();
    }
}
class Shop {
    const SHARE_ORDER = 'share_order';
    public static function isFeatureActive() { return false; }
    public static function addSqlRestriction($s, $a) { return ' AND ' . $a . '.id_shop IN (1)'; }
}
class Tools {
    public static function strtoupper($s) { return strtoupper($s); }
    public static function strtolower($s) { return strtolower($s); }
    public static function getValue($k, $d = false) { return isset($_REQUEST[$k]) ? $_REQUEST[$k] : $d; }
    public static function displayDate($d) { return '14/08/2026'; }
    public static function displayPrice($p, $c = null) { return '$' . number_format($p, 2); }
    public static function redirect($u) { $GLOBALS['EXIT'] = array('redirect', $u); throw new Exception('REDIRECT:' . $u); }
    public static function toCamelCase($s, $b = false) { return $s; }
}
class Validate { public static function isLoadedObject($o) { return is_object($o) && !empty($o->id); } }
class Cart {
    const BOTH = 3;
    public $id = 0;
    public function getProducts($r = false) { return array(); }
    public function nbProducts() { return 0; }
    public function getOrderTotal($t = true, $ty = 3) { return 0; }
}
class Order { public $id = 0; public function getProducts() { return array(); } }
class CartRule { public static function autoAddToCart($c = null) {} }
class Address { public static function getFirstCustomerAddressId($id, $a = true) { return 1; } }

class StubLink {
    public function getPageLink($c, $ssl = null, $lang = null, $req = null) {
        $q = '';
        if (is_array($req)) { $parts = array(); foreach ($req as $k => $v) { $parts[] = $k . '=' . rawurlencode($v); } $q = '?' . implode('&', $parts); }
        return 'https://shop.test/index.php?controller=' . $c . ($q ? '&' . substr($q, 1) : '');
    }
    public function getModuleLink($m, $c, $p = array(), $ssl = null) { return 'https://shop.test/module/' . $m . '/' . $c; }
}
class StubCustomer { public $id = 7; public $secure_key = 'k'; private $logged; public function __construct($l = true) { $this->logged = $l; } public function isLogged() { return $this->logged; } }
class StubCookie { public $id_cart = 0; public $id_guest = 0; public function write() {} }
class StubContext {
    public $customer, $cookie, $link, $cart, $currency, $language, $shop;
    public function __construct($logged = true) {
        $this->customer = new StubCustomer($logged);
        $this->cookie = new StubCookie();
        $this->link = new StubLink();
        $this->cart = new Cart();
        $this->currency = (object) array('id' => 1, 'iso_code' => 'ARS');
        $this->language = (object) array('id' => 1);
        $this->shop = (object) array('id' => 1, 'id_shop_group' => 1);
    }
}
class ModuleFrontController { public $context; public function __construct() { $this->context = new StubContext(); } }

// Constants the controller reads off the module class.
class DynamicReorder {
    const CFG_ENABLED='EN'; const CFG_ORDER_SCOPE='SCOPE'; const CFG_CART_MODE='MODE';
    const CFG_MSG_TITLE='T'; const CFG_TITLE_LOGIN='TL'; const CFG_TITLE_NO_ORDER='TN'; const CFG_TITLE_ERROR='TE';
    const CFG_MSG_SUCCESS='MS'; const CFG_MSG_PARTIAL='MP'; const CFG_MSG_LOGIN='ML';
    const CFG_MSG_NO_ORDER='MN'; const CFG_MSG_NOTHING='MX'; const CFG_MSG_ERROR='ME';
    const RESUME_FLAG='dr_reorder'; const DONE_FLAG='dr_done';
}

require __DIR__ . "/../controllers/front/reorder.php";

function call($obj, $method, $args = array()) {
    $r = new ReflectionMethod($obj, $method);
    $r->setAccessible(true);
    return $r->invokeArgs($obj, $args);
}

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = ($got === $want);
    if ($ok) { $pass++; echo "  OK   $label\n"; }
    else { $fail++; echo "  FAIL $label\n       got:  " . var_export($got, true) . "\n       want: " . var_export($want, true) . "\n"; }
}

$c = new DynamicReorderReorderModuleFrontController();

echo "=== last-order resolution ===\n";
// scope=valid must query valid=1 and must NOT fall back
$GLOBALS['CONF']['SCOPE'] = 'valid';
$GLOBALS['ROWS'] = array(false);
$GLOBALS['SQL'] = array();
$r = call($c, 'findLastOrder', array(7));
check('scope=valid returns false when no valid order', $r, false);
check('scope=valid issued exactly ONE query (no fallback)', count($GLOBALS['SQL']), 1);
check('scope=valid filtered on valid=1', (bool) strpos($GLOBALS['SQL'][0], 'AND o.`valid` = 1'), true);

// scope=valid_then_any must fall back
$GLOBALS['CONF']['SCOPE'] = 'valid_then_any';
$GLOBALS['ROWS'] = array(false, array('id_order' => 9, 'reference' => 'ABC', 'date_add' => 'x'));
$GLOBALS['SQL'] = array();
$r = call($c, 'findLastOrder', array(7));
check('valid_then_any falls back to any', is_array($r) && $r['id_order'] == 9, true);
check('valid_then_any issued TWO queries', count($GLOBALS['SQL']), 2);
check('fallback query dropped the valid filter', strpos($GLOBALS['SQL'][1], 'valid` = 1'), false);

// scope=any must not filter at all
$GLOBALS['CONF']['SCOPE'] = 'any';
$GLOBALS['ROWS'] = array(array('id_order' => 3, 'reference' => 'Z', 'date_add' => 'x'));
$GLOBALS['SQL'] = array();
call($c, 'findLastOrder', array(7));
check('scope=any never filters on valid', strpos($GLOBALS['SQL'][0], 'valid` = 1'), false);

// guest / no customer
check('id_customer 0 short-circuits', call($c, 'findLastOrder', array(0)), false);

echo "\n=== ordering is deterministic ===\n";
check('orders by date_add then id_order DESC',
    (bool) strpos($GLOBALS['SQL'][0], 'ORDER BY o.`date_add` DESC, o.`id_order` DESC LIMIT 1'), true);

echo "\n=== message placeholders ===\n";
$msg = call($c, 'renderMessage', array(
    'Cargamos %count% de %total% (%reference%) el %date%',
    array('reference' => 'XKBKNABJK', 'date_add' => '2026-08-14'),
    array('added' => 5, 'total' => 7),
));
check('all four placeholders substituted', $msg, 'Cargamos 5 de 7 (XKBKNABJK) el 14/08/2026');

echo "\n=== registration URL branch (PS " . _PS_VERSION_ . ") ===\n";
$u = call($c, 'buildRegistrationUrl', array('https://shop.test/?dr_reorder=1'));
if (version_compare(_PS_VERSION_, '1.7.6.0', '>=')) {
    check('1.7.6+ uses the registration page', (bool) strpos($u, 'controller=registration'), true);
} else {
    check('1.6 uses authentication&create_account=1', (bool) strpos($u, 'create_account=1'), true);
    check('1.6 does NOT link to a non-existent registration controller', strpos($u, 'controller=registration'), false);
}

echo "\n=== login round-trip URL ===\n";
$urls = call($c, 'urls', array());
check('login url carries back=', (bool) strpos($urls['login'], 'back='), true);
check('back points at the HOMEPAGE with the resume flag',
    (bool) strpos(urldecode($urls['login']), 'dr_reorder=1'), true);
check('resume url is the homepage, not checkout', strpos($urls['resume'], 'controller=order'), false);

echo "\n---------------------------------------\n";
echo "PASS $pass   FAIL $fail\n";
exit($fail === 0 ? 0 : 1);

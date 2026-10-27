<?php
/** Bootstrap/gateway smoke test with WordPress doubles and optional actual original source. */
error_reporting(E_ALL);
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$stub=sys_get_temp_dir().'/csp-wp-'.bin2hex(random_bytes(8)); mkdir($stub.'/wp-admin/includes',0700,true);
file_put_contents($stub.'/wp-admin/includes/plugin.php','<?php');
register_shutdown_function(static function()use($stub){unlink($stub.'/wp-admin/includes/plugin.php');rmdir($stub.'/wp-admin/includes');rmdir($stub.'/wp-admin');rmdir($stub);});
define('ABSPATH',$stub.'/'); define('WC_VERSION','8.2.0'); $wp_version='6.2';
class WooCommerce {}
class WC_Payment_Gateway {
    public $id,$has_fields,$supports=[],$method_title,$method_description,$title,$description,$icon,$form_fields,$settings=[],$enabled='no';
    function init_settings() { $this->settings=get_option('woocommerce_'.$this->id.'_settings',[]); }
    function get_option($key,$default='') {return $this->settings[$key] ?? $default;}
    function is_available(){return $this->enabled==='yes';}
}
eval('namespace Automattic\\WooCommerce\\Utilities; class FeaturesUtil {static function declare_compatibility(...$args){$GLOBALS["compatibility"][]=$args;}}');
eval('namespace Automattic\\WooCommerce\\Blocks\\Payments\\Integrations; abstract class AbstractPaymentMethodType { protected $name; protected $settings=[]; public function get_name(){return $this->name;} }');
function add_action($tag,$callback,...$args){$GLOBALS['actions'][$tag][]=$callback;}
function add_filter($tag,$callback,...$args){$GLOBALS['filters'][$tag][]=$callback;}
function apply_filters($tag,$value,...$args){foreach($GLOBALS['filters'][$tag] ?? [] as $f){$value=$f($value,...$args);}return $value;}
function __( $value,...$args ){return $value;}
function esc_html__($value,...$args){return $value;}
function esc_url($value){return $value;}
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function get_option($key,$default=false){return $GLOBALS['options'][$key] ?? $default;}
function get_woocommerce_currency(){return 'MXN';}
function WC(){return $GLOBALS['wc'];}
function wp_strip_all_tags($text){return strip_tags($text);}
function plugins_url($path,$file){return 'https://shop.example/plugins/cannaland-payments/'.$path;}
function wp_register_script($handle,$url,...$args){$GLOBALS['scripts'][$handle]=$url;}
function plugin_basename($file){return basename($file);}
function plugin_dir_path($file){return dirname($file).'/';}
function plugin_dir_url($file){return 'https://shop.example/plugins/'.basename(dirname($file)).'/';}
function rest_url($path){return 'https://shop.example/wp-json/'.$path;}
function get_site_url($id,$path){return 'https://shop.example'.$path;}
function get_admin_url($id,$path){return 'https://shop.example/wp-admin/'.$path;}
function load_plugin_textdomain(...$args){}
function wc_get_logger(){return $GLOBALS['logger'] ?? new stdClass;}
function register_rest_route(...$args){$GLOBALS['routes'][]=$args;}
function run_action($tag,...$args){foreach($GLOBALS['actions'][$tag] ?? [] as $callback){$callback(...$args);}}
$GLOBALS['options']=[
 'woocommerce_cannaland_spei_settings'=>['enabled'=>'yes','environment'=>'sandbox','api_key'=>'synthetic-only','title'=>'Transferencia SPEI'],
 'woocommerce_cannaland_cards_settings'=>['enabled'=>'yes','title'=>'Tarjetas personalizadas'],
 'woocommerce_cannaland_cash_settings'=>['enabled'=>'yes','title'=>'Efectivo personalizado'],
];
$original=getenv('CSP_ORIGINAL_ROOT');
if($original){require $original.'/dapp-woo.php';}
$plugin_root=getenv('CSP_PLUGIN_ROOT') ?: dirname(__DIR__);
require $plugin_root.'/cannaland-payments.php';
run_action('before_woocommerce_init');
run_action('plugins_loaded');
$gateways=[];
foreach($GLOBALS['filters']['woocommerce_payment_gateways'] ?? [] as $callback){$gateways=$callback($gateways);}
if(!in_array(Cannaland\Payments\Gateway::class,$gateways,true)){throw new Exception('Gateway missing');}
$gateway=new Cannaland\Payments\Gateway;
if($gateway->id!=='cannaland_spei'){throw new Exception('Wrong gateway ID');}
$instances=[];
foreach(Cannaland\Payments\Methods::all() as $id=>$definition){
 if(!in_array($definition['gateway'],$gateways,true)){throw new Exception('Missing gateway '.$id);}
 $instance=new $definition['gateway'];
 if($instance->id!==$id){throw new Exception('Gateway ID mismatch');}
 $instances[$id]=$instance;
 if(!isset($GLOBALS['actions']['woocommerce_update_options_payment_gateways_'.$id])){throw new Exception('Missing independent settings hook');}
}
foreach(['woocommerce_email_order_details','woocommerce_email_before_order_table','woocommerce_admin_order_data_after_order_details'] as $hook) {
 if(count($GLOBALS['actions'][$hook] ?? [])!==1){throw new Exception('Presentation hooks must be registered once independently of gateways');}
}
$GLOBALS['wc']=new class($instances){
 private $gateways;
 function __construct($gateways){$this->gateways=$gateways;}
 function payment_gateways(){return new class($this->gateways){private $items;function __construct($items){$this->items=$items;}function payment_gateways(){return $this->items;}};}
};
$available=array_keys(array_filter($instances,fn($gateway)=>$gateway->is_available()));
if($available!==['cannaland_spei']){throw new Exception('Classic availability must exclude pending methods');}
run_action('rest_api_init');
if(count($GLOBALS['routes'])!==1 || $GLOBALS['routes'][0][2]['methods']!=='POST'){throw new Exception('REST route invalid');}
run_action('woocommerce_blocks_loaded');
$registry=new class { public $items=[]; function register($item){$this->items[]=$item;} };
run_action('woocommerce_blocks_payment_method_type_registration',$registry);
if(count($registry->items)!==3){throw new Exception('Three Blocks registrations required');}
$names=[];
foreach($registry->items as $item){
 $item->initialize(); $id=$item->get_name(); $names[]=$id;
 $data=$item->get_payment_method_data(); $definition=Cannaland\Payments\Methods::get($id);
 if($data['available']!==$instances[$id]->is_available()){throw new Exception('Blocks/classic availability divergence');}
 if($data['title']!==$instances[$id]->title){throw new Exception('Blocks settings not independent');}
 if(isset($data['api_key'])){throw new Exception('Secret exposed to Blocks');}
 if($item->get_payment_method_script_handles()!==[$definition['handle']]){throw new Exception('Wrong independent script');}
}
if(count(array_unique($names))!==3 || count($GLOBALS['scripts'])!==3){throw new Exception('Blocks collision');}
if($original){
    $legacy=new Conexa\DappWoo\Gateway\WC_DappWoo;
    if($legacy->id===$gateway->id || !in_array('\\Conexa\\DappWoo\\Gateway\\WC_DappWoo',$gateways,true)){throw new Exception('Original collision');}
}
echo 'PASS: bootstrap, three independent gateways/settings/scripts, classic/Blocks availability, POST route and HPOS declaration'.($original?' coexist with original base source':'')." (WordPress doubles).\n";

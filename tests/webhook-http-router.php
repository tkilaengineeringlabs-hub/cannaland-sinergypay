<?php
/** Loopback-only HTTP adapter for tests. WordPress routing/transport are doubles, not a WP installation. */
if ( PHP_SAPI !== 'cli-server' || ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) ) { http_response_code( 404 ); exit; }
class WP_Error { private $code; function __construct($code,...$unused){$this->code=$code;} function get_error_code(){return $this->code;} }
class WP_REST_Response { public $data,$status;function __construct($data,$status){$this->data=$data;$this->status=$status;} }
function is_wp_error($value){return $value instanceof WP_Error;}
function __return_true(){return true;}
function wc_get_orders(...$unused){throw new RuntimeException('Order access forbidden in unsigned HTTP probe');}
function wc_get_order(...$unused){throw new RuntimeException('Order access forbidden in unsigned HTTP probe');}
function add_option(...$unused){throw new RuntimeException('Writes forbidden in unsigned HTTP probe');}
$GLOBALS['logger']=new class {
    function info($event,$context){$this->write($event,$context);}
    function error($event,$context){$this->write($event,$context);}
    private function write($event,$context){
        $path=getenv('CANNALAND_WEBHOOK_TEST_LOG');
        if($path){file_put_contents($path,json_encode(array($event,$context))."\n",FILE_APPEND|LOCK_EX);}
    }
};
ob_start();require __DIR__.'/bootstrap-smoke.php';ob_end_clean();
$GLOBALS['options'][Cannaland\Payments\Support::OPTION]['logging']='yes';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$route=$GLOBALS['routes'][0];
if($path!=='/wp-json/'.$route[0].$route[1] || $_SERVER['REQUEST_METHOD']!==$route[2]['methods']){http_response_code(404);exit;}
if(!call_user_func($route[2]['permission_callback'])){http_response_code(403);exit;}
$request=new class {function get_body(){return file_get_contents('php://input');}};
$response=call_user_func($route[2]['callback'],$request);
http_response_code($response->status);header('Content-Type: application/json');
// Match the standard WordPress REST null-data behavior: no response body.
if(null!==$response->data){echo json_encode($response->data);}

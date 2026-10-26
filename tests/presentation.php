<?php
// Read-only rendering tests. No new webhook fixture or provider/network call.
use Cannaland\Payments\SpeiPresentation;
use Cannaland\Payments\Support;
function presentation_output($callback) { ob_start(); $callback(); return ob_get_clean(); }
reset_state(); $o=order(); $o->status='on-hold';
// Metadata from previous versions, without creating/migrating any additional records.
foreach (['preparation'=>'ready','order_state'=>'succeeded','clabe_state'=>'succeeded','clabe'=>'012345678901234567','amount'=>'8700.00','currency'=>'MXN','reference'=>'existing-reference','reference_num'=>'123456','remote_order_id'=>'existing-remote','expiration_date'=>'2026-11-01T07:10:10.068551+00:00'] as $key=>$value) { Support::put($o,$key,$value); }
$before=[$o->meta,$o->save_count,$o->status,$o->completions];
$GLOBALS['options']['timezone_string']='America/Mexico_City';
$GLOBALS['options']['date_format']='d/m/Y'; $GLOBALS['options']['time_format']='H:i';
check('provider ISO microseconds converted to configured WordPress timezone',SpeiPresentation::expiration(Support::meta($o,'expiration_date'))==='01/11/2026, 01:10');
check('wp_date receives configured format and actual timestamp',end($GLOBALS['date_calls'])===['d/m/Y, H:i',1793517010,'America/Mexico_City']);
$GLOBALS['options']['timezone_string']='Europe/Madrid';
check('timezone is not hardcoded',SpeiPresentation::expiration(Support::meta($o,'expiration_date'))==='01/11/2026, 08:10');
$GLOBALS['options']['date_format']='Y/m/d'; $GLOBALS['options']['time_format']='g:i a';
check('date and time formats follow WordPress options',SpeiPresentation::expiration(Support::meta($o,'expiration_date'))==='2026/11/01, 8:10 am');
check('missing and invalid expiration handled without technical ISO',SpeiPresentation::expiration('')==='' && SpeiPresentation::expiration('invalid-date')==='Fecha no disponible');
$html=presentation_output(fn()=>SpeiPresentation::render($o));
check('customer table shows CLABE reference formatted amount and expiration',str_contains($html,'012345678901234567') && str_contains($html,'existing-reference') && str_contains($html,'123456') && str_contains($html,'$8,700.00 MXN') && str_contains($html,'2026/11/01, 8:10 am'));
check('theme-friendly WooCommerce table and selectable data',str_contains($html,'shop_table') && str_contains($html,'scope="row"') && str_contains($html,'user-select:all'));
check('wc_price receives original decimal and currency',end($GLOBALS['price_calls'])===['8700.00',['currency'=>'MXN']]);
$GLOBALS['options']['woocommerce_price_decimal_sep']=','; $GLOBALS['options']['woocommerce_price_thousand_sep']='.';
check('amount presentation respects WooCommerce separators',str_contains(presentation_output(fn()=>SpeiPresentation::render($o)),'$8.700,00 MXN'));
unset($GLOBALS['options']['woocommerce_price_decimal_sep'],$GLOBALS['options']['woocommerce_price_thousand_sep']);
$p=new SpeiPresentation;
$email=new class { public function is_customer_email(){return true;} };
$html=presentation_output(fn()=>$p->email($o,false,false,$email));
check('customer awaiting-payment HTML email includes instructions',str_contains($html,'Transferencia SPEI') && str_contains($html,'012345678901234567') && str_contains($html,'$8,700.00 MXN') && str_contains($html,'existing-reference') && str_contains($html,'2026/11/01'));
check('same email render cannot duplicate instructions',presentation_output(fn()=>$p->email($o,false,false,$email))==='');
$plain=presentation_output(fn()=>$p->email($o,false,true,$email));
check('plain email includes four data fields without HTML',str_contains($plain,'CLABE: 012345678901234567') && str_contains($plain,'Importe: $8,700.00 MXN') && str_contains($plain,'Referencia: existing-reference') && str_contains($plain,'Válida hasta: 2026/11/01') && strip_tags($plain)===$plain && !str_contains($plain,'<'));
check('plain email also deduplicated',presentation_output(fn()=>$p->email($o,false,true,$email))==='');
$p->begin_email($o,false,false,$email);
check('legitimate later email resend retains instructions',str_contains(presentation_output(fn()=>$p->email($o,false,false,$email)),'CLABE'));
check('admin email excluded',presentation_output(fn()=>$p->email($o,true,false,$email))==='');
$noncustomer=new class { public function is_customer_email(){return false;} };
check('noncustomer email excluded',presentation_output(fn()=>$p->email($o,false,false,$noncustomer))==='');
foreach(['cannaland_cards','cannaland_cash','other_gateway'] as $method) {
    $o->method=$method; $p->begin_email($o);
    check('other gateway has no SPEI email or admin panel '.$method,presentation_output(fn()=>$p->email($o,false,false,$email))==='' && presentation_output(fn()=>$p->admin($o))==='');
}
$o->method=Support::GATEWAY;
foreach(['processing','completed','cancelled','refunded','failed'] as $status) {
    $o->status=$status; $p->begin_email($o);
    check('non-waiting order has no payment instructions '.$status,presentation_output(fn()=>$p->email($o,false,false,$email))==='');
}
$o->status='on-hold';
$admin=presentation_output(fn()=>$p->admin($o));
check('admin reads existing remote ID reference CLABE expiry and phase states',str_contains($admin,'existing-remote') && str_contains($admin,'existing-reference') && str_contains($admin,'012345678901234567') && str_contains($admin,'2026/11/01') && str_contains($admin,'Instrucciones preparadas') && str_contains($admin,'Completado'));
$GLOBALS['can_edit_order']=false;
check('admin display requires order edit capability',presentation_output(fn()=>$p->admin($o))===''); unset($GLOBALS['can_edit_order']);
$secret='SYNTHETIC-SECRET-NOT-FOR-DISPLAY';
$GLOBALS['options'][Support::OPTION]['api_key']=$secret;
Support::put($o,'signature',$secret); Support::put($o,'token',$secret);
$p->begin_email($o);
$views=presentation_output(fn()=>SpeiPresentation::render($o)).presentation_output(fn()=>$p->email($o,false,false,$email)).presentation_output(fn()=>$p->email($o,false,true,$email)).presentation_output(fn()=>$p->admin($o));
check('only allowlisted payment data shown no secrets or signatures',!str_contains($views,$secret) && !str_contains($views,'api_key') && !str_contains($views,'signature'));
check('remote provider ID never appears in customer page or email',!str_contains(presentation_output(fn()=>SpeiPresentation::render($o)),'existing-remote') && !str_contains($plain,'existing-remote') && !str_contains($html,'existing-remote'));
Support::put($o,'reference_num',"<script>unsafe</script>\r\ninjected"); $p->begin_email($o);
$escaped=presentation_output(fn()=>$p->email($o,false,false,$email)); $plain=presentation_output(fn()=>$p->email($o,false,true,$email));
check('HTML emails escape provider reference',!str_contains($escaped,'<script>') && str_contains($escaped,'&lt;script&gt;'));
check('plain email strips markup and prevents reference line injection',!str_contains($plain,'<') && !str_contains($plain,"\ninjected"));
unset($o->meta[Support::PREFIX.'signature'],$o->meta[Support::PREFIX.'token']); Support::put($o,'reference_num','123456');
check('presentation preserves old metadata status and storage without network calls',$before===[$o->meta,$o->save_count,$o->status,$o->completions] && count($GLOBALS['requests'])===0);
SpeiPresentation::register(); SpeiPresentation::register();
check('email and admin hooks registered only once',count($GLOBALS['actions']['woocommerce_email_order_details'])===1 && count($GLOBALS['actions']['woocommerce_email_before_order_table'])===1 && count($GLOBALS['actions']['woocommerce_admin_order_data_after_order_details'])===1);

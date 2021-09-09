<?php

$_REQUEST['redirected'] = 1;
$_REQUEST['newApi'] = 1;

$_GET['fuse']='billing';
$_GET['action'] = 'gatewaycallback';
$_GET['plugin'] = 'paypal';

chdir('../../..');

require_once dirname(__FILE__).'/../../../library/front.php';

?>

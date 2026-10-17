<?php
require '/home/claude/messenger/app/bootstrap.php';
use App\Services\{Settings,Crypto};
$on = ($argv[1] ?? '1') === '1';
Settings::set('meets_key_enc', Crypto::encrypt('trsm_testkey1234567890'));
Settings::set('meets_webhook_secret_enc', Crypto::encrypt('whsec_testsecret123456'));
Settings::set('meets_enabled', $on ? '1' : '0');
echo "ok\n";

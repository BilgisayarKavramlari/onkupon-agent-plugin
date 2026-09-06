<?php
namespace OnKupon\Agent\Security; class Nonce { public static function verify(string $a): void { check_admin_referer($a); } }
<?php
namespace OnKupon\Agent; class Deactivator { public static function deactivate(): void { \OnKupon\Agent\Scheduler\JobRegistrar::unschedule_all(); } }
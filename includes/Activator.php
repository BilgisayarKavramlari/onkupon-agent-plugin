<?php
namespace OnKupon\Agent; class Activator { public static function activate(): void { Installer::install(); \OnKupon\Agent\Scheduler\JobRegistrar::schedule_defaults(); } }
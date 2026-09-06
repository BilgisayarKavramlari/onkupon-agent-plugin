<?php
namespace OnKupon\Agent\Security; class CapabilityManager { public static function capability(): string { return apply_filters("onkupon_agent_capability","manage_options"); } }
<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
// Retain builds/settings by design. Never remove a published site during uninstall.
wp_clear_scheduled_hook('wpr_static_tick');

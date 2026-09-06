<?php
namespace OnKupon\Agent\Security;

class LockManager {
    public function acquire( string $key, int $ttl = 900 ): bool {
        $transient = 'oka_lock_' . sanitize_key( $key );
        if ( get_transient( $transient ) ) { return false; }
        set_transient( $transient, 1, $ttl );
        return true;
    }
    public function release( string $key ): void { delete_transient( 'oka_lock_' . sanitize_key( $key ) ); }
    public function clear(): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_oka_lock_' ) . '%' ) );
    }
}

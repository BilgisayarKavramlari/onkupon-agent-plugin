<?php
namespace OnKupon\Agent\Publishing;

use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;

class AuthorManager {
    public function author_id(): int {
        $configured = absint( Plugin::settings()['default_author_id'] ?? 0 );
        if ( $configured && get_user_by( 'id', $configured ) ) {
            return $configured;
        }
        $current = get_current_user_id();
        if ( $current ) {
            ( new Logger() )->log( 'info', 'publishing', 'Default author missing; using current admin as post author', [ 'author_id' => $current ] );
            return $current;
        }
        $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
        $fallback = absint( $admins[0] ?? 1 );
        ( new Logger() )->log( 'warning', 'publishing', 'Default author missing; using first administrator as post author', [ 'author_id' => $fallback ] );
        return $fallback;
    }
}

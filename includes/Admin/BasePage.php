<?php
namespace OnKupon\Agent\Admin;

use OnKupon\Agent\Plugin;

abstract class BasePage {
    protected function header( string $title ): void {
        echo '<div class="wrap onkupon-agent"><h1>' . esc_html( $title ) . '</h1>';
        if ( isset( $_GET['oka_notice'] ) ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Action completed.', 'onkupon-agent' ) . '</p></div>';
        }
        if ( isset( $_GET['oka_run_now'] ) ) {
            $actions = absint( wp_unslash( $_GET['oka_actions'] ?? 0 ) );
            $scheduler = sanitize_text_field( wp_unslash( $_GET['oka_scheduler'] ?? 'unknown' ) );
            $message = sprintf(
                /* translators: 1: number of actions, 2: scheduler availability. */
                __( 'Run Now queued %1$d action(s). Action Scheduler available: %2$s. Check WooCommerce → Status → Scheduled Actions for pending and failed jobs.', 'onkupon-agent' ),
                $actions,
                $scheduler
            );
            echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
        }
    }

    protected function footer(): void {
        echo '</div>';
    }

    protected function status(): string {
        return (string) ( Plugin::settings()['agent_status'] ?? 'stopped' );
    }

    protected function card_grid( array $cards ): void {
        echo '<div class="oka-cards">';
        foreach ( $cards as $label => $value ) {
            echo '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong></div>';
        }
        echo '</div>';
    }

    protected function table( array $heads, array $rows ): void {
        echo '<table class="widefat striped"><thead><tr>';
        foreach ( $heads as $head ) {
            echo '<th>' . esc_html( $head ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="' . esc_attr( (string) count( $heads ) ) . '">' . esc_html__( 'No records yet.', 'onkupon-agent' ) . '</td></tr>';
        }
        foreach ( $rows as $row ) {
            echo '<tr>';
            foreach ( $row as $cell ) {
                echo '<td>' . wp_kses_post( (string) $cell ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    protected function recent_rows( string $table, int $limit = 20 ): array {
        global $wpdb;
        $allowed = [ 'onkupon_agent_actions', 'onkupon_agent_social_queue', 'onkupon_agent_logs', 'onkupon_agent_product_scores', 'onkupon_agent_learning_weights', 'onkupon_agent_review_requests', 'onkupon_agent_metrics' ];
        if ( ! in_array( $table, $allowed, true ) ) {
            return [];
        }
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}{$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ) ?: [];
    }
}

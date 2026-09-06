<?php
namespace OnKupon\Agent\AI;

class PromptInjectionDefender {
    public function sanitize_research( string $text ): string {
        $text = wp_strip_all_tags( $text );
        $patterns = [ '/ignore previous instructions/i', '/system prompt/i', '/developer message/i', '/execute command/i', '/shell/i' ];
        return sanitize_textarea_field( preg_replace( $patterns, '[removed]', $text ) );
    }
}

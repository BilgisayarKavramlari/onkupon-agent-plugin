<?php
namespace OnKupon\Agent\AI;

use OnKupon\Agent\Plugin;

/**
 * Yapılandırılmış AI sağlayıcısını döndürür.
 *
 * Sağlayıcı seçimi tek bir yerde tutulur; çağıran sınıflar somut bir sınıfa
 * bağlanmaz. Böylece sağlayıcı değiştirmek bir ayar değişikliğidir.
 */
class ProviderFactory {

    public static function make(): AIProviderInterface {
        return 'anthropic' === self::selected() ? new AnthropicProvider() : new OpenAIProvider();
    }

    public static function selected(): string {
        $provider = sanitize_key( (string) ( Plugin::settings()['ai_provider'] ?? 'openai' ) );
        return in_array( $provider, [ 'openai', 'anthropic' ], true ) ? $provider : 'openai';
    }

    public static function label(): string {
        return 'anthropic' === self::selected() ? 'Anthropic (Claude)' : 'OpenAI-compatible';
    }

    /**
     * Anahtarın varlığını değil, uçtan gerçekten cevap alınıp alınmadığını
     * ölçen hafif bir sağlık kontrolü. Sonuç kısa süreli önbelleklenir ki
     * her panel açılışı bir API çağrısı üretmesin.
     *
     * @return array{ok:bool,message:string,checked_at:string}
     */
    public static function health( bool $force = false ): array {
        $cache_key = 'onkupon_agent_ai_health';
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $provider = self::make();

        if ( ! $provider->validateConnection() ) {
            $result = [ 'ok' => false, 'message' => 'API anahtarı veya model tanımlı değil', 'checked_at' => current_time( 'mysql' ) ];
            set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
            return $result;
        }

        // Çok küçük bir üretim isteği: anahtarın gerçekten geçerli olduğunu doğrular.
        $text = $provider->generateText( 'Reply with the single word: ok', [ 'health_check' => true ] );
        $ok = '' !== trim( $text );

        $result = [
            'ok'         => $ok,
            'message'    => $ok ? 'Bağlantı doğrulandı' : 'Anahtar tanımlı ama uç yanıt vermedi — Kayıtlar sayfasındaki ai kanalına bakın',
            'checked_at' => current_time( 'mysql' ),
        ];

        set_transient( $cache_key, $result, $ok ? HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
        return $result;
    }
}

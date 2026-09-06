<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Plugin;

/**
 * Marketplace programlarını OnKupOn kitlesine uygunluğa göre skorlar.
 *
 * Skor 0-100 arasıdır ve beş bileşenden oluşur. Bileşenler ayrı saklanır;
 * böylece panelde "bu program neden 78 aldı" sorusu yanıtlanabilir.
 */
class MarketplaceProgramScorer {

    private const W_CATEGORY  = 35;
    private const W_KEYWORD   = 25;
    private const W_FRESHNESS = 15;
    private const W_CONTENT   = 15;
    private const W_DIVERSITY = 10;

    private const AUDIENCE_KEYWORDS = [
        'ai', 'artificial intelligence', 'machine learning', 'automation', 'saas',
        'crm', 'marketing', 'analytics', 'productivity', 'no-code', 'nocode',
        'developer', 'api', 'ecommerce', 'e-commerce', 'invoice', 'accounting',
        'hr', 'recruiting', 'project management', 'small business', 'startup',
        'data', 'dashboard', 'workflow', 'email', 'seo', 'design', 'education',
        'lms', 'course', 'security', 'backup', 'hosting', 'chatbot', 'agent',
        'lead', 'outreach', 'sales', 'video', 'content',
    ];

    /**
     * @return array{score:float,breakdown:array,reasons:string[]}
     */
    public function score( array $program, array $existing_categories = [] ): array {
        $breakdown = [];
        $reasons = [];

        $category = strtolower( trim( (string) ( $program['category'] ?? '' ) ) );
        $text = strtolower( (string) ( $program['name'] ?? '' ) . ' ' . (string) ( $program['description'] ?? '' ) );

        $targets = $this->list_setting( 'partnerstack_target_categories' );
        $blocked = $this->list_setting( 'partnerstack_blocked_categories' );

        if ( ( '' !== $category && $this->matches_any( $category, $blocked ) ) || $this->matches_any( $text, $blocked ) ) {
            return [
                'score' => 0.0,
                'breakdown' => [ 'blocked' => 1 ],
                'reasons' => [ 'Yasaklı kategori eşleşmesi' ],
            ];
        }

        if ( '' !== $category && $this->matches_any( $category, $targets ) ) {
            $breakdown['category'] = self::W_CATEGORY;
            $reasons[] = 'Hedef kategori: ' . $category;
        } elseif ( $this->matches_any( $text, $targets ) ) {
            $breakdown['category'] = (int) round( self::W_CATEGORY * 0.6 );
            $reasons[] = 'Kategori metinde dolaylı eşleşti';
        } else {
            $breakdown['category'] = 0;
            $reasons[] = 'Hedef kategori eşleşmesi yok';
        }

        $hits = 0;
        foreach ( self::AUDIENCE_KEYWORDS as $keyword ) {
            if ( false !== strpos( $text, $keyword ) ) {
                ++$hits;
            }
        }
        $breakdown['keywords'] = (int) round( min( 1.0, $hits / 4 ) * self::W_KEYWORD );
        $reasons[] = $hits . ' kitle anahtar kelimesi';

        $created = strtotime( (string) ( $program['created_at'] ?? '' ) );
        if ( $created ) {
            $age_days = max( 0, ( time() - $created ) / DAY_IN_SECONDS );
            $breakdown['freshness'] = (int) round( max( 0, 1 - ( $age_days / 90 ) ) * self::W_FRESHNESS );
            $reasons[] = 'Listelenme yaşı ' . (int) $age_days . ' gün';
        } else {
            $breakdown['freshness'] = (int) round( self::W_FRESHNESS * 0.5 );
        }

        $content = 0.0;
        if ( strlen( (string) ( $program['description'] ?? '' ) ) > 120 ) {
            $content += 0.6;
        }
        if ( ! empty( $program['logo_url'] ) ) {
            $content += 0.4;
        }
        $breakdown['content'] = (int) round( min( 1.0, $content ) * self::W_CONTENT );
        if ( $content < 0.5 ) {
            $reasons[] = 'Açıklama/logo zayıf';
        }

        $same_category = '' !== $category ? (int) ( $existing_categories[ $category ] ?? 0 ) : 0;
        $breakdown['diversity'] = (int) round( max( 0, 1 - ( $same_category / 10 ) ) * self::W_DIVERSITY );
        if ( $same_category > 5 ) {
            $reasons[] = 'Bu kategoride zaten ' . $same_category . ' iş birliği var';
        }

        return [
            'score' => round( min( 100, max( 0, (float) array_sum( $breakdown ) ) ), 2 ),
            'breakdown' => $breakdown,
            'reasons' => $reasons,
        ];
    }

    private function list_setting( string $key ): array {
        $raw = (string) ( Plugin::settings()[ $key ] ?? '' );
        return array_values(
            array_filter(
                array_map( static fn( $item ): string => strtolower( trim( (string) $item ) ), explode( ',', $raw ) )
            )
        );
    }

    private function matches_any( string $haystack, array $needles ): bool {
        foreach ( $needles as $needle ) {
            if ( '' !== $needle && false !== strpos( $haystack, $needle ) ) {
                return true;
            }
        }
        return false;
    }
}

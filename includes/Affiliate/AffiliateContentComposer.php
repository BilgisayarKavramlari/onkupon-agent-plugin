<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\AI\ProviderFactory;
use OnKupon\Agent\Logging\Logger;

/**
 * Ortaklık ürünleri için kamuya açık metni üretir.
 *
 * İki sorumluluğu vardır. Birincisi, PartnerStack'ten gelen ham metinden
 * sözleşmeye ait ticari şartları (komisyon oranı, ödeme modeli, çerez süresi)
 * ayıklamaktır: bunlar bizimle tedarikçi arasındaki özel anlaşmanın parçasıdır
 * ve bir ürün sayfasında yayımlanamaz. İkincisi, sitenin yerleşik editoryal
 * kalıbına uyan Türkçe açıklama, kategori, etiket ve SEO alanlarını üretmektir.
 *
 * Üretim, kaynak özetine (source_hash) göre önbelleklenir; aynı ortaklık her
 * senkronda yeniden yazdırılmaz.
 */
class AffiliateContentComposer {

    public const META_CONTENT_HASH = '_onkupon_affiliate_content_hash';
    public const META_OFFER_TERMS  = '_onkupon_partnerstack_offer_terms';
    public const META_PRESERVE     = '_onkupon_affiliate_preserve_editorial';

    /** Tek senkron turunda uretilecek en fazla urun metni. */
    private const PER_RUN_LIMIT = 6;

    private static int $composed_in_run = 0;

    /**
     * Bir dijital arac urununun ait olamayacagi kategoriler. Bunlar sitenin
     * egitim tarafina aittir; bir SaaS urununun burada durmasi, kategorinin
     * hic atanmamis olmasiyla ayni anlama gelir.
     */
    public const NON_PRODUCT_SLUGS = [ 'uncategorized', 'egitimler', 'sertifika', 'kitap', 'sablon' ];

    public static function reset_run(): void {
        self::$composed_in_run = 0;
    }

    public const DISCLOSURE = 'Bu harici bağlantı üzerinden yapılan uygun işlemlerden komisyon kazanabiliriz. Fiyat, kapsam ve koşullar hizmet sağlayıcıya aittir.';

    /**
     * Bir cümleyi gizli ticari şart yapan kalıplar. Eşleşen cümle metinden
     * tamamen çıkarılır; kısmen maskelemek yanıltıcı bir cümle bırakırdı.
     */
    private const CONFIDENTIAL_PATTERNS = [
        '/\bcommissions?\b/iu',
        '/komisyon/iu',
        '/\bpayouts?\b/iu',
        '/\brevenue\s+shar/iu',
        '/gelir\s+pay/iu',
        '/\bbount(y|ies)\b/iu',
        '/\breferral\s+fee/iu',
        '/\baffiliate\s+(program|partner|commission|payout)/iu',
        '/\bpartner\s+program\b/iu',
        '/\bcookie\s+(window|duration|life)/iu',
        '/\brecurring\s+(revenue|payment|commission)/iu',
        '/\bper\s+(sale|signup|sign-up|customer|referral)\b/iu',
        '/\bfirst\s+\d+\s+months?\b/iu',
        '/\bilk\s+\d+\s+ay\b/iu',
        '/\bearn(s|ing)?\b[^.!?]{0,60}(\d|%|\$)/iu',
        '/\b(\d+|%\s?\d+)[^.!?]{0,30}(commission|komisyon|recurring|payout)/iu',
        '/\bEPC\b/u',
        '/\blifetime\s+(value|commission|payout)/iu',
    ];

    /**
     * Gizli ticari şart taşıyan cümleleri metinden çıkarır.
     */
    public static function redact( string $text ): string {
        $text = (string) $text;
        if ( '' === trim( $text ) ) {
            return '';
        }

        // Blok yapısını koru: satır ve madde sınırlarını cümle sınırı say.
        $blocks = preg_split( '/(\r\n|\r|\n)/u', $text ) ?: [ $text ];
        $kept_blocks = [];

        foreach ( $blocks as $block ) {
            $sentences = preg_split( '/(?<=[.!?])\s+/u', $block ) ?: [ $block ];
            $kept = [];
            foreach ( $sentences as $sentence ) {
                if ( ! self::is_confidential( $sentence ) ) {
                    $kept[] = $sentence;
                }
            }
            $line = trim( implode( ' ', $kept ) );
            // Ayıklama sonrası geriye yalnız madde imi kaldıysa satırı da at.
            if ( '' !== $line && ! preg_match( '/^[•\-\*\|\s]+$/u', $line ) ) {
                $kept_blocks[] = $line;
            }
        }

        return trim( implode( "\n", $kept_blocks ) );
    }

    public static function is_confidential( string $sentence ): bool {
        // Kendi is ortakligi bildirimimiz "komisyon" kelimesini icerir ve
        // suzgece takilir. Bu cumle gizli bir sart degil, aksine kamuya acik
        // olmasi gereken bildirimin ta kendisidir; muaf tutulur.
        $normalized = trim( wp_strip_all_tags( $sentence ) );
        if ( '' !== $normalized && false !== mb_strpos( self::DISCLOSURE, $normalized ) ) {
            return false;
        }

        foreach ( self::CONFIDENTIAL_PATTERNS as $pattern ) {
            if ( preg_match( $pattern, $sentence ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Eski surumlerin aciklama govdesine yazdigi is ortakligi bildirimini
     * temizler. Bildirim kaldirilmaz, yalnizca yerini degistirir.
     */
    public static function strip_disclosure( string $html ): string {
        $needles = [
            '<p>' . esc_html( self::DISCLOSURE ) . '</p>',
            '<p>' . self::DISCLOSURE . '</p>',
            self::DISCLOSURE,
        ];
        foreach ( $needles as $needle ) {
            $html = str_replace( $needle, '', $html );
        }
        return trim( preg_replace( '/(\R\s*){3,}/u', "\n\n", $html ) ?: $html );
    }

    public static function contains_confidential( string $text ): bool {
        return self::redact( $text ) !== trim( (string) $text );
    }

    /**
     * Ürün için yeni metin üretilmesi gerekip gerekmediğine karar verir.
     *
     * Elle düzenlenmiş, uzun ve temiz bir açıklama asla ezilmez; yalnızca
     * gizli şart içeren, boş veya kaynağı değişmiş içerik yeniden yazılır.
     */
    public function needs_content( $product, array $program ): bool {
        if ( ! $product ) {
            return true;
        }
        $product_id = (int) $product->get_id();
        if ( $product_id && '1' === (string) get_post_meta( $product_id, self::META_PRESERVE, true ) ) {
            return false;
        }

        $description = (string) $product->get_description();
        if ( '' === trim( wp_strip_all_tags( $description ) ) ) {
            return true;
        }
        if ( self::contains_confidential( $description ) || self::contains_confidential( (string) $product->get_short_description() ) ) {
            return true;
        }

        $stored_hash = (string) get_post_meta( $product_id, self::META_CONTENT_HASH, true );
        if ( '' === $stored_hash ) {
            // Bu ajan tarafından üretilmemiş, elle hazırlanmış uzun bir metin.
            return mb_strlen( wp_strip_all_tags( $description ) ) < 400;
        }

        return $stored_hash !== (string) ( $program['source_hash'] ?? '' );
    }

    /**
     * @return array{}|array{title:string,short:string,long:string,category_ids:int[],tags:string[],focus_keyphrase:string,meta_description:string}
     */
    public function compose( array $program, string $destination_url ): array {
        $brand = trim( (string) ( $program['name'] ?? '' ) );
        if ( '' === $brand ) {
            return [];
        }

        if ( self::$composed_in_run >= self::PER_RUN_LIMIT ) {
            return [];
        }
        ++self::$composed_in_run;

        $source_text = self::redact( (string) ( $program['description'] ?? '' ) );
        $facts = $this->destination_facts( $destination_url );

        // PartnerStack birçok program için yalnızca ticari şartı gönderiyor;
        // o ayıklandığında elde hiçbir olgu kalmayabilir. Böyle bir durumda
        // metin uydurmak yerine üretimden vazgeçilir.
        if ( '' === trim( $source_text ) && empty( $facts['summary'] ) ) {
            ( new Logger() )->log( 'warning', 'affiliate', 'Affiliate content skipped: no factual source', [ 'brand' => $brand ] );
            return [];
        }

        $payload = [
            'brand'               => $brand,
            'official_summary'    => mb_substr( $source_text, 0, 1200 ),
            'destination_domain'  => (string) wp_parse_url( $facts['url'] ?: $destination_url, PHP_URL_HOST ),
            'destination_title'   => $facts['title'],
            'destination_summary' => $facts['summary'],
            'allowed_categories'  => $this->category_options(),
        ];

        $prompt = $this->prompt( $payload );
        $provider = ProviderFactory::make();
        $data = $provider->generateJson( $prompt, $this->schema() );

        // Model ara sira use_cases alanini dizi yerine metin donduruyor ve sema
        // dogrulamasi hakli olarak reddediyor. Bir kez daha, bicim uyarisi
        // eklenmis halde denenir; bu, tek seferlik bicim hatalarini kapatir.
        if ( ! is_array( $data ) || empty( $data['intro'] ) ) {
            $data = $provider->generateJson(
                $prompt . "\n\nBICIM UYARISI: use_cases, category_slugs ve tags alanlari MUTLAKA JSON dizisi olmalidir; tek bir metin veya satir sonuyla ayrilmis liste kabul edilmez.",
                $this->schema()
            );
        }

        if ( ! is_array( $data ) || empty( $data['intro'] ) ) {
            ( new Logger() )->log( 'warning', 'affiliate', 'Affiliate content composition returned no payload', [ 'brand' => $brand ] );
            return [];
        }

        return $this->assemble( $brand, $data );
    }

    private function assemble( string $brand, array $data ): array {
        $title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
        if ( '' === $title || false === mb_stripos( $title, $brand ) ) {
            $title = $brand . ( $title ? ' – ' . $title : '' );
        }

        $intro = self::redact( wp_strip_all_tags( (string) $data['intro'] ) );
        $audience = self::redact( wp_strip_all_tags( (string) ( $data['audience'] ?? '' ) ) );
        $short = self::redact( wp_strip_all_tags( (string) ( $data['short_description'] ?? '' ) ) );
        $meta = self::redact( wp_strip_all_tags( (string) ( $data['meta_description'] ?? '' ) ) );

        $use_cases = [];
        foreach ( (array) ( $data['use_cases'] ?? [] ) as $line ) {
            $line = self::redact( wp_strip_all_tags( (string) $line ) );
            $line = trim( ltrim( $line, "•-* \t" ) );
            if ( '' !== $line ) {
                $use_cases[] = esc_html( $line );
            }
        }
        $use_cases = array_slice( array_values( array_unique( $use_cases ) ), 0, 8 );

        if ( '' === $intro ) {
            return [];
        }

        $html = '<p>' . esc_html( $intro ) . '</p>' . "\n";
        if ( $use_cases ) {
            $html .= '<p>Öne çıkan kullanım alanları</p>' . "\n";
            $html .= '<p>• ' . implode( '<br>' . "\n" . '• ', $use_cases ) . '</p>' . "\n";
        }
        if ( '' !== $audience ) {
            $html .= '<p>Kimler için uygun?</p>' . "\n";
            $html .= '<p>' . esc_html( $audience ) . '</p>';
        }

        // Is ortakligi bildirimi artik aciklama govdesine yazilmaz; urun
        // sayfasinda baglantinin altinda ayri bir not olarak gosterilir.
        $html = trim( $html );

        $tags = [];
        foreach ( (array) ( $data['tags'] ?? [] ) as $tag ) {
            $tag = trim( wp_strip_all_tags( (string) $tag ) );
            if ( '' !== $tag && ! self::is_confidential( $tag ) && mb_strlen( $tag ) <= 40 ) {
                $tags[] = sanitize_text_field( $tag );
            }
        }
        $tags[] = $brand;
        $tags = array_slice( array_values( array_unique( $tags ) ), 0, 8 );

        return [
            'title'            => $title,
            'short'            => $short ?: $meta,
            'long'             => $html,
            'category_ids'     => $this->resolve_category_ids( (array) ( $data['category_slugs'] ?? [] ), $brand . ' ' . $intro ),
            'tags'             => $tags,
            'focus_keyphrase'  => sanitize_text_field( self::redact( (string) ( $data['focus_keyphrase'] ?? '' ) ) ),
            'meta_description' => mb_substr( $meta ?: $short, 0, 160 ),
        ];
    }

    /**
     * Markanın kendi sayfasından olgu toplar. PartnerStack açıklama alanını
     * çoğu programda boş bırakır; metnin gerçeğe dayanması için birincil
     * kaynak hedef sitenin kendisidir.
     *
     * @return array{url:string,title:string,summary:string}
     */
    private function destination_facts( string $destination_url ): array {
        $empty = [ 'url' => '', 'title' => '', 'summary' => '' ];
        $destination_url = esc_url_raw( $destination_url );
        if ( '' === $destination_url ) {
            return $empty;
        }

        [ $body, $final ] = $this->fetch_page( $destination_url );

        // Bazi markalar ortaklik yonlendirme adresine govde dondurmuyor ya da
        // bot korumasi nedeniyle bos sayfa veriyor. Bu durumda markanin kendi
        // ana sayfasi dogrudan denenir.
        if ( '' === trim( $body ) ) {
            $host = (string) wp_parse_url( $final ?: $destination_url, PHP_URL_HOST );
            if ( '' !== $host ) {
                [ $body, $root ] = $this->fetch_page( 'https://' . $host . '/' );
                if ( '' !== trim( $body ) ) {
                    $final = $root;
                }
            }
        }

        if ( '' === trim( $body ) ) {
            return $empty;
        }

        $title = '';
        if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $body, $matches ) ) {
            $title = $this->clean( $matches[1] );
        }

        $parts = [];
        $meta_patterns = [
            '#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']#i',
            '#<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']#i',
        ];
        foreach ( $meta_patterns as $pattern ) {
            if ( preg_match( $pattern, $body, $matches ) ) {
                $parts[] = $this->clean( $matches[1] );
            }
        }
        if ( preg_match( '#<h1[^>]*>(.*?)</h1>#is', $body, $matches ) ) {
            $parts[] = $this->clean( $matches[1] );
        }

        $text = preg_replace( '#<(script|style|noscript|svg)[^>]*>.*?</\1>#is', ' ', $body ) ?: $body;
        $text = $this->clean( $text );
        if ( '' !== $text ) {
            $parts[] = mb_substr( $text, 0, 1500 );
        }

        $summary = self::redact( trim( implode( ' ', array_values( array_unique( array_filter( $parts ) ) ) ) ) );

        return [ 'url' => esc_url_raw( $final ), 'title' => $title, 'summary' => mb_substr( $summary, 0, 2000 ) ];
    }

    /**
     * Sayfayi tarayici benzeri basliklarla ceker. Durum kodu 200 olmasa bile
     * govde varsa kullanilir: bot korumasi uygulayan siteler cogu zaman 403
     * ile birlikte tam HTML dondurur ve o HTML tanitim metnini icerir.
     *
     * @return array{0:string,1:string} govde ve son adres
     */
    private function fetch_page( string $url ): array {
        $response = wp_remote_get(
            $url,
            [
                'timeout'     => 15,
                'redirection' => 6,
                'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
                'headers'     => [
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,tr;q=0.8',
                ],
            ]
        );
        if ( is_wp_error( $response ) ) {
            return [ '', $url ];
        }

        $final = $url;
        $http = $response['http_response'] ?? null;
        if ( is_object( $http ) && method_exists( $http, 'get_response_object' ) ) {
            $object = $http->get_response_object();
            if ( is_object( $object ) && ! empty( $object->url ) ) {
                $final = (string) $object->url;
            }
        }

        return [ (string) wp_remote_retrieve_body( $response ), esc_url_raw( $final ) ];
    }

    private function clean( string $value ): string {
        $value = wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) );
        return trim( preg_replace( '/\s+/u', ' ', $value ) ?: '' );
    }

    private function prompt( array $payload ): string {
        $json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        return <<<PROMPT
Sen onkupon.com adlı Türkçe dijital ürün rehberinin editörüsün. Aşağıdaki markanın ürün sayfası metnini yazacaksın.

MUTLAK KURAL — GİZLİLİK: Ortaklık/iş birliği şartlarına dair TEK BİR KELİME yazma. Komisyon oranı, komisyon süresi, yinelenen ödeme, çerez süresi, satış başına ödeme, gelir paylaşımı, "affiliate", "partner programı", kazanç vaadi veya para tutarı geçmeyecek. Bunlar okuyucuya değil, yalnızca bize ait sözleşme bilgileridir. Bu bilgiyi kaynak metinde görsen bile aktarma.

Diğer kurallar:
- Dil Türkçe, üslup ansiklopedik ve bilgilendirici; abartılı pazarlama dili ve ünlem kullanma.
- Yalnızca girdideki official_summary, destination_title ve destination_summary alanlarındaki olgulara dayan. Marka hakkında uydurma özellik, fiyat, müşteri sayısı veya ödül yazma. Emin olmadığın somut iddiayı yazma.
- title: "Marka – Türkçe konu tanımı" biçiminde, en fazla 70 karakter.
- short_description ve meta_description: tek cümle, 120-160 karakter, ürünün ne işe yaradığını söyler.
- intro: 2-4 cümlelik tek paragraf; ürünün ne olduğunu ve nasıl çalıştığını anlatır.
- use_cases: 5-7 madde, her biri kısa bir yetenek/kullanım alanı, madde imi koyma.
- audience: 2-3 cümle; kimler için uygun olduğunu anlatır.
- category_slugs: yalnızca allowed_categories listesindeki slug değerlerinden 2-4 tanesini seç. Listede olmayan bir slug uydurma.
- tags: 3-5 küçük harfli Türkçe anahtar kelime (marka adı hariç).
- focus_keyphrase: 2-4 kelimelik tek bir Türkçe arama ifadesi.

Girdi:
{$json}
PROMPT;
    }

    private function schema(): array {
        $properties = [
            'title'             => [ 'type' => 'string' ],
            'short_description' => [ 'type' => 'string' ],
            'meta_description'  => [ 'type' => 'string' ],
            'intro'             => [ 'type' => 'string' ],
            'use_cases'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            'audience'          => [ 'type' => 'string' ],
            'category_slugs'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            'tags'              => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            'focus_keyphrase'   => [ 'type' => 'string' ],
        ];

        return [
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => [ 'title', 'short_description', 'meta_description', 'intro', 'use_cases' ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Sitede gerçekten var olan kategoriler. Varsayılan "uncategorized" terimi
     * dışarıda bırakılır; ürünlerin oraya düşmesi tam olarak düzeltilen hatadır.
     */
    private function category_options(): array {
        $terms = get_terms(
            [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'number'     => 60,
            ]
        );
        if ( is_wp_error( $terms ) ) {
            return [];
        }

        $options = [];
        foreach ( $terms as $term ) {
            if ( in_array( $term->slug, self::NON_PRODUCT_SLUGS, true ) ) {
                continue;
            }
            $options[] = [ 'slug' => $term->slug, 'name' => $term->name ];
        }
        return $options;
    }

    /**
     * @param string[] $slugs
     * @return int[]
     */
    private function resolve_category_ids( array $slugs, string $context ): array {
        $ids = [];
        foreach ( $slugs as $slug ) {
            $slug = sanitize_title( (string) $slug );
            if ( '' === $slug || in_array( $slug, self::NON_PRODUCT_SLUGS, true ) ) {
                continue;
            }
            $term = get_term_by( 'slug', $slug, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $ids[] = (int) $term->term_id;
            }
        }

        if ( ! $ids ) {
            $ids = $this->keyword_category_ids( $context );
        }

        return array_slice( array_values( array_unique( $ids ) ), 0, 4 );
    }

    /**
     * Yapay zeka devre dışıyken veya geçersiz slug döndüğünde kullanılan
     * deterministik yedek eşleme.
     *
     * @return int[]
     */
    public function keyword_category_ids( string $context ): array {
        $context = function_exists( 'mb_strtolower' ) ? mb_strtolower( $context ) : strtolower( $context );
        $map = [
            'yapay-zeka-araclari'        => [ ' ai ', 'ai-', 'yapay zeka', 'gpt', 'llm', 'machine learning', 'chatbot', 'agent' ],
            'otomasyon-araclari'         => [ 'automation', 'otomasyon', 'workflow', 'zapier', 'integrat', 'no-code automation' ],
            'lead-generation'            => [ 'lead', 'crm', 'sales', 'prospect', 'outreach', 'satış' ],
            'cold-mailing'               => [ 'cold email', 'email marketing', 'e-posta pazarlama', 'newsletter', 'mailing' ],
            'low-code-no-code'           => [ 'no-code', 'no code', 'low-code', 'low code', 'website builder', 'site kur' ],
            'yazilim-gelistirme-araclari' => [ 'developer', 'api', 'sdk', 'devops', 'hosting', 'server', 'github', 'kod' ],
            'uretkenlik-araclari'        => [ 'productivity', 'üretkenlik', 'project management', 'proje yönet', 'task', 'not al', 'takvim' ],
            'ses-ve-video-araclari'      => [ 'video', 'audio', 'ses', 'podcast', 'transcri', 'görüntülü' ],
            'hizmetler'                  => [ 'payroll', 'bordro', 'accounting', 'muhasebe', 'legal', 'hukuk', 'insan kaynak' ],
        ];

        $ids = [];
        foreach ( $map as $slug => $needles ) {
            foreach ( $needles as $needle ) {
                if ( false !== mb_strpos( $context, $needle ) ) {
                    $term = get_term_by( 'slug', $slug, 'product_cat' );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $ids[] = (int) $term->term_id;
                    }
                    break;
                }
            }
        }

        // Her ortaklık ürünü çatı kategoriye de girer.
        $root = get_term_by( 'slug', 'saas', 'product_cat' );
        if ( $root && ! is_wp_error( $root ) ) {
            $ids[] = (int) $root->term_id;
        }

        return array_slice( array_values( array_unique( $ids ) ), 0, 4 );
    }
}

<?php
namespace OnKupon\Agent\SEO;

class CommercialContentPlanner {
    public const META_KEY = '_onkupon_comparison_key';

    public function next_plan(): ?array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return null;
        }

        $catalog_products = wc_get_products(
            [
                'status' => 'publish',
                'limit' => -1,
                'return' => 'objects',
            ]
        );

        foreach ( ( new CommercialContentPlanCatalog() )->all() as $plan ) {
            if ( $this->already_exists( $plan ) ) {
                continue;
            }
            $products = $this->resolve_products( (array) $plan['product_aliases'], $catalog_products );
            if ( count( $products ) !== count( (array) $plan['product_aliases'] ) ) {
                continue;
            }
            $plan['products'] = $products;
            $plan['required_source_urls'] = array_values(
                array_filter(
                    array_map( fn( $product ): string => $this->product_sources( $product->get_id() )[0] ?? '', $products )
                )
            );
            if ( count( $plan['required_source_urls'] ) !== count( $products ) ) {
                continue;
            }
            return $plan;
        }

        return null;
    }

    public function status(): array {
        $plans = ( new CommercialContentPlanCatalog() )->all();
        $published = 0;
        foreach ( $plans as $plan ) {
            if ( $this->already_exists( $plan ) ) {
                $published++;
            }
        }
        $next = $this->next_plan();
        return [
            'published_plans' => $published,
            'total_plans' => count( $plans ),
            'next_plan_key' => sanitize_key( (string) ( $next['key'] ?? '' ) ),
            'next_plan_topic' => sanitize_text_field( (string) ( $next['topic'] ?? '' ) ),
        ];
    }

    public function product_sources( int $product_id ): array {
        $decoded = json_decode( (string) get_post_meta( $product_id, '_onkupon_affiliate_editorial_sources', true ), true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }
        $sources = [];
        foreach ( $decoded as $source ) {
            $url = esc_url_raw( (string) $source );
            if ( $url && wp_http_validate_url( $url ) && 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
                $sources[] = $url;
            }
        }
        return array_values( array_unique( $sources ) );
    }

    private function resolve_products( array $aliases, array $catalog_products ): array {
        $resolved = [];
        $used = [];
        foreach ( $aliases as $alias ) {
            $alias_key = $this->normalize( (string) $alias );
            $match = null;
            foreach ( $catalog_products as $product ) {
                $product_id = absint( $product->get_id() );
                if ( isset( $used[ $product_id ] ) || ! $this->eligible( $product_id ) ) {
                    continue;
                }
                $name_key = $this->normalize( (string) $product->get_name() );
                if ( $name_key === $alias_key || str_starts_with( $name_key, $alias_key . ' ' ) ) {
                    $match = $product;
                    break;
                }
            }
            if ( ! $match ) {
                return [];
            }
            $resolved[] = $match;
            $used[ $match->get_id() ] = true;
        }
        return $resolved;
    }

    private function eligible( int $product_id ): bool {
        return 'partnerstack' === sanitize_key( (string) get_post_meta( $product_id, '_onkupon_affiliate_provider', true ) )
            && '1' === (string) get_post_meta( $product_id, '_onkupon_affiliate_active', true )
            && '1' === (string) get_post_meta( $product_id, '_onkupon_affiliate_preserve_editorial', true )
            && [] !== $this->product_sources( $product_id );
    }

    private function already_exists( array $plan ): bool {
        $existing = get_posts(
            [
                'post_type' => 'post',
                'post_status' => [ 'publish', 'draft', 'pending', 'future', 'private' ],
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => self::META_KEY,
                'meta_value' => sanitize_key( (string) $plan['key'] ),
                'no_found_rows' => true,
            ]
        );
        if ( $existing ) {
            return true;
        }
        return null !== get_page_by_path( sanitize_title( (string) $plan['slug'] ), OBJECT, 'post' );
    }

    private function normalize( string $value ): string {
        $value = strtolower( remove_accents( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        return trim( preg_replace( '/[^a-z0-9]+/', ' ', $value ) ?: '' );
    }
}

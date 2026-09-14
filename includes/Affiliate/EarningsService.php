<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;

/**
 * PartnerStack kazanç tablosu.
 *
 * Ajan bugüne kadar yalnızca ortaklıkları ve ürünleri izliyordu; parayı hiç
 * görmüyordu. Bu servis Partner API'nin salt okunur kazanç uçlarını okur ve
 * program başına "bekleyen / onaylanan / ödenen" tablosunu çıkarır. Böylece
 * hangi programdan ne kadar alacağımız olduğu ve hangisinin hiç ödeme
 * üretmediği panelden görülebilir.
 *
 * Ödeme başlatmak Partner API'nin kapsamında değildir; bu servis para
 * hareketi yapmaz, yalnızca raporlar.
 */
class EarningsService {

    public const OPTION = 'onkupon_agent_earnings';

    /** Tutarlar API'de kuruş/cent cinsinden gelir. */
    private const MINOR_UNITS = 100;

    public function refresh(): array {
        $client = new PartnerStackClient();
        if ( ! $client->configured() ) {
            return [ 'ok' => false, 'error' => 'configuration' ];
        }

        $rewards = $client->list_rewards( 500 );
        if ( empty( $rewards['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) ( $rewards['error'] ?? 'rewards' ) ];
        }
        $payouts = $client->list_payouts( 200 );

        $programs = [];
        $totals = [ 'pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'declined' => 0.0, 'count' => 0 ];
        $currency = '';

        foreach ( (array) $rewards['items'] as $row ) {
            $reward = $this->normalize_reward( $row );
            if ( '' === $reward['program'] ) {
                continue;
            }
            $currency = $currency ?: $reward['currency'];

            $key = $reward['program'];
            if ( ! isset( $programs[ $key ] ) ) {
                $programs[ $key ] = [
                    'program'  => $key,
                    'pending'  => 0.0,
                    'approved' => 0.0,
                    'paid'     => 0.0,
                    'declined' => 0.0,
                    'count'    => 0,
                    'last_at'  => '',
                    'currency' => $reward['currency'],
                ];
            }

            $bucket = $reward['bucket'];
            $programs[ $key ][ $bucket ] += $reward['amount'];
            $totals[ $bucket ] += $reward['amount'];
            ++$programs[ $key ]['count'];
            ++$totals['count'];
            if ( $reward['created_at'] > $programs[ $key ]['last_at'] ) {
                $programs[ $key ]['last_at'] = $reward['created_at'];
            }
        }

        // Alacak = onaylanmış ama henüz ödenmemiş + beklemede olan.
        foreach ( $programs as $key => $row ) {
            $programs[ $key ]['outstanding'] = round( $row['pending'] + $row['approved'], 2 );
            foreach ( [ 'pending', 'approved', 'paid', 'declined' ] as $bucket ) {
                $programs[ $key ][ $bucket ] = round( $row[ $bucket ], 2 );
            }
        }
        uasort( $programs, static fn( $a, $b ): int => $b['outstanding'] <=> $a['outstanding'] );

        $payout_rows = [];
        foreach ( (array) ( $payouts['items'] ?? [] ) as $row ) {
            $payout_rows[] = $this->normalize_payout( $row );
        }
        usort( $payout_rows, static fn( $a, $b ): int => strcmp( (string) $b['created_at'], (string) $a['created_at'] ) );

        $snapshot = [
            'at'           => current_time( 'mysql' ),
            'currency'     => $currency ?: 'USD',
            'totals'       => [
                'pending'     => round( $totals['pending'], 2 ),
                'approved'    => round( $totals['approved'], 2 ),
                'paid'        => round( $totals['paid'], 2 ),
                'declined'    => round( $totals['declined'], 2 ),
                'outstanding' => round( $totals['pending'] + $totals['approved'], 2 ),
                'count'       => (int) $totals['count'],
            ],
            'programs'     => array_values( $programs ),
            'payouts'      => array_slice( $payout_rows, 0, 40 ),
            'payouts_ok'   => ! empty( $payouts['ok'] ),
        ];

        update_option( self::OPTION, $snapshot, false );

        ( new Logger() )->log( 'info', 'affiliate', 'PartnerStack kazanç anlık görüntüsü alındı', [ 'programs' => count( $programs ), 'rewards' => $totals['count'], 'outstanding' => $snapshot['totals']['outstanding'] ] );
        ( new ActionTimelineRepository() )->record(
            'partnerstack_earnings',
            'completed',
            [ 'notes' => sprintf( '%d programda %s bekleyen alacak', count( $programs ), $snapshot['totals']['outstanding'] . ' ' . $snapshot['currency'] ) ]
        );

        return [ 'ok' => true ] + $snapshot;
    }

    /**
     * @return array{program:string,amount:float,bucket:string,currency:string,created_at:string}
     */
    private function normalize_reward( array $row ): array {
        $program = '';
        foreach ( [ 'group_name', 'company_name', 'program_name', 'partnership_name' ] as $key ) {
            if ( ! empty( $row[ $key ] ) && is_string( $row[ $key ] ) ) {
                $program = $row[ $key ];
                break;
            }
        }
        if ( '' === $program ) {
            foreach ( [ 'company', 'group', 'partnership', 'program' ] as $key ) {
                if ( is_array( $row[ $key ] ?? null ) ) {
                    $nested = $row[ $key ];
                    $program = (string) ( $nested['name'] ?? $nested['title'] ?? $nested['company_name'] ?? '' );
                    if ( '' !== $program ) {
                        break;
                    }
                }
            }
        }

        $amount = 0.0;
        foreach ( [ 'amount', 'amount_total', 'value', 'reward_amount' ] as $key ) {
            if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
                $amount = (float) $row[ $key ];
                break;
            }
        }
        // Tam sayı gelen tutarlar küçük birimdedir (cent); ondalıklı gelenler değil.
        if ( $amount > 0 && (float) (int) $amount === $amount ) {
            $amount = $amount / self::MINOR_UNITS;
        }

        $status = strtolower( (string) ( $row['status'] ?? $row['state'] ?? '' ) );
        $bucket = 'pending';
        if ( in_array( $status, [ 'paid', 'withdrawn', 'settled', 'complete', 'completed' ], true ) ) {
            $bucket = 'paid';
        } elseif ( in_array( $status, [ 'approved', 'available', 'ready', 'eligible' ], true ) ) {
            $bucket = 'approved';
        } elseif ( in_array( $status, [ 'declined', 'rejected', 'refunded', 'voided', 'cancelled', 'canceled' ], true ) ) {
            $bucket = 'declined';
        }

        return [
            'program'    => sanitize_text_field( $program ),
            'amount'     => round( $amount, 2 ),
            'bucket'     => $bucket,
            'currency'   => sanitize_text_field( (string) ( $row['currency'] ?? 'USD' ) ),
            'created_at' => sanitize_text_field( (string) ( $row['created_at'] ?? $row['created'] ?? '' ) ),
        ];
    }

    private function normalize_payout( array $row ): array {
        $amount = 0.0;
        foreach ( [ 'amount', 'amount_total', 'value' ] as $key ) {
            if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
                $amount = (float) $row[ $key ];
                break;
            }
        }
        if ( $amount > 0 && (float) (int) $amount === $amount ) {
            $amount = $amount / self::MINOR_UNITS;
        }

        return [
            'amount'     => round( $amount, 2 ),
            'currency'   => sanitize_text_field( (string) ( $row['currency'] ?? 'USD' ) ),
            'status'     => sanitize_text_field( (string) ( $row['status'] ?? $row['state'] ?? '' ) ),
            'created_at' => sanitize_text_field( (string) ( $row['created_at'] ?? $row['created'] ?? '' ) ),
        ];
    }

    public static function snapshot(): array {
        $snapshot = get_option( self::OPTION, [] );
        return is_array( $snapshot ) ? $snapshot : [];
    }
}

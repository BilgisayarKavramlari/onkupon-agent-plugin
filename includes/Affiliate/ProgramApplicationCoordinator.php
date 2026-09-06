<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;

/**
 * Onay kuyruğu ve karar kaydı.
 *
 * MİMARİ KISIT: PartnerStack Partner API salt okunurdur. Başvuru oluşturan uç
 * (POST /v2/applications) Vendor API'ye aittir, Basic auth ile program
 * sahibinin kimliğini bekler ve bir partner tarafından kendi adına
 * çağrılamaz. Bu yüzden başvurunun kendisi API ile otomatikleştirilemez.
 *
 * Kendi otomasyonunuzu bağlamak isterseniz `onkupon_agent_apply_to_program`
 * filtresine bir handler ekleyin; true dönerse sistem başvuruyu yapılmış sayar.
 */
class ProgramApplicationCoordinator {

    private MarketplaceProgramRepository $repository;

    public function __construct() {
        $this->repository = new MarketplaceProgramRepository();
    }

    /**
     * Eşiği geçmiş programı onay kuyruğuna alır.
     */
    public function enqueue( string $company_key ): bool {
        $program = $this->repository->find( $company_key );
        if ( ! $program ) {
            return false;
        }

        $limit = absint( Plugin::settings()['partnerstack_discovery_daily_limit'] ?? 5 );
        if ( $limit > 0 && $this->repository->approved_today() >= $limit ) {
            ( new ActionTimelineRepository() )->record(
                'program_deferred',
                'skipped',
                [ 'notes' => sprintf( 'Günlük başvuru limiti (%d) doldu', $limit ), 'metadata' => [ 'company_key' => $company_key ] ]
            );
            return false;
        }

        /**
         * Dış otomasyon köprüsü. Bir handler başvuruyu üstlenip true dönerse
         * program doğrudan "approved" işaretlenir ve onay bağlantısı üretilmez.
         *
         * @param bool  $handled
         * @param array $program
         */
        if ( (bool) apply_filters( 'onkupon_agent_apply_to_program', false, $program ) ) {
            $this->repository->decide( $company_key, 'approved', 'Dış köprü üzerinden başvuru yapıldı' );
            ( new Logger() )->log( 'info', 'affiliate', 'Program applied through external bridge', [ 'company_key' => $company_key ] );
            return true;
        }

        if ( empty( $program['approval_token'] ) ) {
            $this->repository->upsert(
                [
                    'company_key' => $company_key,
                    'approval_token' => wp_generate_password( 48, false, false ),
                ]
            );
        }

        ( new ActionTimelineRepository() )->record(
            'program_queued',
            'pending',
            [ 'notes' => sprintf( 'Onay kuyruğuna alındı (skor %.1f)', (float) $program['score'] ), 'metadata' => [ 'company_key' => $company_key ] ]
        );

        return true;
    }

    public function approve( string $company_key ): void {
        $this->repository->decide( $company_key, 'approved', 'Operatör onayladı' );
        ( new Logger() )->log( 'info', 'affiliate', 'Marketplace program approved', [ 'company_key' => $company_key ] );

        /**
         * Onay sonrası kanca.
         *
         * @param array $program
         */
        do_action( 'onkupon_agent_program_approved', $this->repository->find( $company_key ) );
    }

    public function reject( string $company_key ): void {
        $this->repository->decide( $company_key, 'rejected', 'Operatör atladı' );
        ( new Logger() )->log( 'info', 'affiliate', 'Marketplace program rejected', [ 'company_key' => $company_key ] );
    }
}

<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * Máquina de estados de pedido. Lógica pura — não toca no banco.
 */
class OrderStatusTest extends TestCase
{
    public function test_pendente_validacao_so_avanca_para_confirmado_ou_cancelado(): void
    {
        $this->assertTrue(OrderStatus::PENDENTE_VALIDACAO->canTransitionTo(OrderStatus::CONFIRMADO));
        $this->assertTrue(OrderStatus::PENDENTE_VALIDACAO->canTransitionTo(OrderStatus::CANCELADO));
        $this->assertFalse(OrderStatus::PENDENTE_VALIDACAO->canTransitionTo(OrderStatus::PRONTO));
        $this->assertFalse(OrderStatus::PENDENTE_VALIDACAO->canTransitionTo(OrderStatus::PAGO));
    }

    public function test_fluxo_feliz_completo_e_permitido_passo_a_passo(): void
    {
        $this->assertTrue(OrderStatus::CONFIRMADO->canTransitionTo(OrderStatus::PREPARANDO));
        $this->assertTrue(OrderStatus::PREPARANDO->canTransitionTo(OrderStatus::PRONTO));
        $this->assertTrue(OrderStatus::PRONTO->canTransitionTo(OrderStatus::ENTREGUE));
        $this->assertTrue(OrderStatus::ENTREGUE->canTransitionTo(OrderStatus::PAGO));
    }

    public function test_nao_pode_pular_etapas(): void
    {
        $this->assertFalse(OrderStatus::CONFIRMADO->canTransitionTo(OrderStatus::PRONTO));
        $this->assertFalse(OrderStatus::CONFIRMADO->canTransitionTo(OrderStatus::ENTREGUE));
        $this->assertFalse(OrderStatus::PREPARANDO->canTransitionTo(OrderStatus::ENTREGUE));
    }

    public function test_entregue_nao_pode_ser_cancelado(): void
    {
        // Após entregue, só resta pagar — cancelar não é permitido.
        $this->assertFalse(OrderStatus::ENTREGUE->canTransitionTo(OrderStatus::CANCELADO));
        $this->assertTrue(OrderStatus::ENTREGUE->canTransitionTo(OrderStatus::PAGO));
    }

    public function test_estados_finais_nao_tem_saida(): void
    {
        $this->assertSame([], OrderStatus::PAGO->allowedTransitions());
        $this->assertSame([], OrderStatus::CANCELADO->allowedTransitions());
    }

    public function test_cancelamento_permitido_ate_pronto(): void
    {
        foreach ([OrderStatus::PENDENTE_VALIDACAO, OrderStatus::CONFIRMADO, OrderStatus::PREPARANDO, OrderStatus::PRONTO] as $status) {
            $this->assertTrue(
                $status->canTransitionTo(OrderStatus::CANCELADO),
                "Esperava poder cancelar a partir de {$status->value}"
            );
        }
    }
}

<script setup>
import { useRouter } from 'vue-router'

var router = useRouter()

// Versão exibida deve casar com ClientAuthController::TERMS_VERSION (API).
var TERMS_VERSION = '2026-10-19'

function back() {
  if (window.history.length > 1) router.back()
  else router.push('/')
}
</script>

<template>
  <div>
    <header class="acc-header">
      <button class="acc-header-back" aria-label="Voltar" @click="back">←</button>
      <div class="acc-header-text">
        <div class="acc-header-restaurant">PagFood</div>
        <div class="acc-header-title">Termos e privacidade</div>
      </div>
    </header>

    <main class="acc-page terms">
      <p class="terms-version">Versão de {{ TERMS_VERSION.split('-').reverse().join('/') }}</p>

      <h2>1. O que é a conta</h2>
      <p>
        A conta é criada em cada restaurante que usa o PagFood. Ela vale só no restaurante
        em que você se cadastrou: serve para entrar na mesa já identificado e para ver o
        histórico dos seus pedidos ali.
      </p>

      <h2>2. Quais dados usamos</h2>
      <p>
        Nome, telefone, senha (guardada de forma cifrada, ninguém consegue lê-la) e os
        pedidos que você faz com a conta: itens, valores, mesa, data e situação.
      </p>

      <h2>3. Para quê</h2>
      <p>
        Para identificar você no restaurante, registrar e acompanhar seus pedidos e mostrar
        seu histórico. O restaurante vê seu nome, telefone e pedidos feitos lá, para atender
        você e manter os registros da casa. Não vendemos seus dados.
      </p>

      <h2>4. Seus direitos</h2>
      <p>
        Em <strong>Minha conta → Meus dados</strong> você corrige seu nome, troca a senha ou
        exclui a conta a qualquer momento. Ao excluir, seu nome, telefone e senha são
        apagados; os pedidos continuam no registro do restaurante, sem identificação.
        Outras solicitações sobre seus dados podem ser feitas ao restaurante.
      </p>

      <h2>5. Segurança</h2>
      <p>
        Não compartilhe sua senha. Se esquecer, peça ao restaurante para redefinir seu acesso
        e crie a conta de novo com o mesmo telefone.
      </p>
    </main>
  </div>
</template>

<style scoped>
.terms h2 {
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  margin: 20px 0 6px;
}
.terms p {
  font-size: var(--fs-sm);
  color: var(--text-secondary);
  line-height: 1.6;
}
.terms-version {
  font-size: var(--fs-xs);
  color: var(--text-muted);
}
</style>

<script setup>
import { useRouter } from 'vue-router'

var router = useRouter()
</script>

<template>
  <div class="account-page">
    <div class="account-card">
      <div class="account-icon">👤</div>
      <h2 class="account-title">Área do Cliente</h2>
      <p class="account-desc">
        Em breve você poderá criar sua conta e ter acesso a
        benefícios exclusivos como cashback, histórico de pedidos
        e promoções especiais.
      </p>

      <div class="perks-list">
        <div class="perk-item">
          <span class="perk-icon">💰</span>
          <span>Cashback em pedidos</span>
        </div>
        <div class="perk-item">
          <span class="perk-icon">📋</span>
          <span>Histórico de pedidos</span>
        </div>
        <div class="perk-item">
          <span class="perk-icon">🎁</span>
          <span>Promoções exclusivas</span>
        </div>
        <div class="perk-item">
          <span class="perk-icon">⭐</span>
          <span>Programa de fidelidade</span>
        </div>
      </div>

      <div class="coming-badge">Em breve</div>

      <button class="back-btn" @click="router.push('/')">← Voltar ao início</button>
    </div>
  </div>
</template>

<style scoped>
.account-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(180deg, #fff 0%, #f5f5f7 100%);
  padding: 20px;
}
.account-card {
  background: #fff;
  border-radius: 20px;
  padding: 40px 28px;
  width: 100%;
  max-width: 400px;
  text-align: center;
  box-shadow: 0 4px 24px rgba(0,0,0,0.08);
}
.account-icon {
  font-size: 48px;
  margin-bottom: 12px;
}
.account-title {
  font-size: 22px;
  font-weight: 700;
  color: #1a1a2e;
  margin: 0 0 8px;
}
.account-desc {
  font-size: 14px;
  color: #999;
  line-height: 1.5;
  margin-bottom: 24px;
}
.perks-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 24px;
  text-align: left;
}
.perk-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  background: #f8f8fa;
  border-radius: 10px;
  font-size: 14px;
  color: #333;
  font-weight: 500;
}
.perk-icon {
  font-size: 20px;
  flex-shrink: 0;
}
.coming-badge {
  display: inline-block;
  background: rgba(52,152,219,0.1);
  color: #3498db;
  padding: 6px 20px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
  margin-bottom: 20px;
}
.back-btn {
  background: none;
  border: none;
  color: #999;
  font-size: 14px;
  cursor: pointer;
  padding: 10px;
  font-family: inherit;
  touch-action: manipulation;
}
.back-btn:hover {
  color: #e63946;
}
</style>

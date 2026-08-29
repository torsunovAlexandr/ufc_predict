<script setup>
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="card" style="text-align: center; padding: 48px">
    <h1>Страница не найдена</h1>
    <p class="muted">Такого раздела нет — возможно, ссылка устарела.</p>
    <RouterLink to="/" class="btn btn--primary" style="margin-top: 12px">На дашборд</RouterLink>
  </div>
</template>

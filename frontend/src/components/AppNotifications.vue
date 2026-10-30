<script setup>
import { notifications, dismissNotification } from '../lib/notifications'
import { X } from '@lucide/vue'
</script>

<template>
  <Teleport to="body">
    <div class="notification-stack" aria-live="polite" aria-relevant="additions">
      <TransitionGroup name="page">
        <div v-for="item in notifications" :key="item.id" class="notification" :class="item.tone" :role="item.tone === 'error' ? 'alert' : 'status'">
          <span>{{ item.message }}</span>
          <button class="icon-button" type="button" aria-label="Cerrar aviso" @click="dismissNotification(item.id)"><X :size="16" /></button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<script setup>
import { CircleAlert, CircleHelp } from '@lucide/vue'
import { acceptDialog, cancelDialog, useDialogState } from '../lib/dialog'

const dialog = useDialogState()
</script>

<template>
  <Teleport to="body">
    <div v-if="dialog.open" class="dialog-backdrop" @click.self="cancelDialog">
      <section class="dialog-card" :class="dialog.tone" role="dialog" aria-modal="true">
        <div class="dialog-icon" :class="dialog.tone">
          <CircleAlert v-if="dialog.tone === 'danger'" :size="22" />
          <CircleHelp v-else :size="22" />
        </div>
        <div class="dialog-copy">
          <h2>{{ dialog.title }}</h2>
          <p>{{ dialog.message }}</p>
          <label v-if="dialog.mode === 'prompt'">
            {{ dialog.inputLabel }}
            <input v-model="dialog.input" type="text" maxlength="1000" @keydown.enter.prevent="acceptDialog" />
          </label>
        </div>
        <div class="dialog-actions">
          <button class="button secondary" type="button" @click="cancelDialog">{{ dialog.cancelText }}</button>
          <button class="button" :class="dialog.tone === 'danger' ? 'danger' : 'primary'" type="button" @click="acceptDialog">
            {{ dialog.confirmText }}
          </button>
        </div>
      </section>
    </div>
  </Teleport>
</template>

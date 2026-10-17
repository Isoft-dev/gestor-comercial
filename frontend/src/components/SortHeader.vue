<script setup>
defineProps({
  label: { type: String, required: true },
  column: { type: String, required: true },
  sort: { type: String, required: true },
  direction: { type: String, required: true },
})

defineEmits(['sort'])
</script>

<template>
  <th>
    <button
      class="sort-button"
      type="button"
      :aria-sort="sort === column ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'"
      @click="$emit('sort', column)"
    >
      {{ label }}
    </button>
  </th>
</template>

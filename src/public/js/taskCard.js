document.addEventListener('alpine:init', () => {
  Alpine.data('taskCard', (task) => ({
    task,

    init() {
      // Keep local reference in sync when store updates
      this.$watch('$store.tasks.items', items => {
        const updated = items.find(t => t.id === this.task.id);
        if (updated) this.task = updated;
      });
    },

    open() {
      this.$store.tasks.openDetail(this.task.id);
    },

    daysInStatus() {
      const updated = new Date(this.task.updated_at);
      const now = new Date();
      return Math.floor((now - updated) / 86400000);
    },

    planioUrl() {
      const base = this.$store.tasks.settings?.planio_base_url ?? '';
      return base ? `${base}/issues/${this.task.planio_issue_id}` : '#';
    },

    cardClass() {
      if (this.task.status !== 'awaiting_feedback') return 'bg-white border-gray-200';

      const warningDays = parseInt(this.$store.tasks.settings?.feedback_warning_days ?? '3', 10);
      const days = this.daysInStatus();

      if (days >= 7) return 'bg-red-50 border-red-300';
      if (days >= warningDays) return 'bg-amber-50 border-amber-300';
      return 'bg-white border-gray-200';
    },
  }));
});

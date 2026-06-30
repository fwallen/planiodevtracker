document.addEventListener('alpine:init', () => {
  Alpine.data('boardComponent', () => ({
    columns: [
      { status: 'new',               label: 'New' },
      { status: 'on_hold',           label: 'On Hold' },
      { status: 'in_progress',       label: 'In Progress' },
      { status: 'awaiting_feedback', label: 'Awaiting Feedback' },
      { status: 'feedback_received', label: 'Feedback Received' },
      { status: 'done',              label: 'Done' },
    ],

    init() {},

    tasksFor(status) {
      return Alpine.store('tasks').items.filter(t => t.status === status);
    },

    myDayTasks() {
      const active = ['in_progress', 'feedback_received'];
      return Alpine.store('tasks').items
        .filter(t => active.includes(t.status))
        .sort((a, b) => {
          if (!a.due_date && !b.due_date) return 0;
          if (!a.due_date) return 1;
          if (!b.due_date) return -1;
          return a.due_date.localeCompare(b.due_date);
        });
    },
  }));

  Alpine.data('detailPanel', () => ({
    notes: '',
    feedbackNote: '',
    feedbackHandedTo: '',
    statuses: [
      { value: 'new',               label: 'New' },
      { value: 'on_hold',           label: 'On Hold' },
      { value: 'in_progress',       label: 'In Progress' },
      { value: 'awaiting_feedback', label: 'Awaiting Feedback' },
      { value: 'feedback_received', label: 'Feedback Received' },
      { value: 'done',              label: 'Done' },
    ],

    init() {
      this.$watch('$store.tasks.detail', id => {
        if (id) this.notes = this.task().notes ?? '';
        this.feedbackNote = '';
        this.feedbackHandedTo = '';
      });
      if (this.$store.tasks.detail) this.notes = this.task().notes ?? '';
    },

    task() {
      const id = this.$store.tasks.detail;
      return this.$store.tasks.items.find(t => t.id === id) ?? {};
    },

    async setStatus(status) {
      await this.$store.tasks.updateTask(this.task().id, { status });
    },

    async sendFeedback() {
      await this.$store.tasks.sendFeedback(this.task().id, this.feedbackNote, this.feedbackHandedTo);
      this.feedbackNote = '';
      this.feedbackHandedTo = '';
    },

    async gotFeedback() {
      await this.$store.tasks.gotFeedback(this.task().id);
    },

    async saveNotes() {
      await this.$store.tasks.updateTask(this.task().id, { notes: this.notes });
    },

    async deleteTask() {
      if (!confirm('Delete this task?')) return;
      await this.$store.tasks.deleteTask(this.task().id);
    },
  }));
});

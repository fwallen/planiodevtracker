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

    init() {
      // Wire drag-and-drop once the x-for lists have rendered.
      this.$nextTick(() => this.initSortables());
    },

    initSortables() {
      if (typeof Sortable === 'undefined') {
        console.error('[devtracker] SortableJS did not load — drag-and-drop disabled.');
        return;
      }
      // Scope to this component's DOM so the Board and My Day instances only
      // wire their own lists.
      this.$el.querySelectorAll('[data-status]').forEach(el => {
        this.initColumnSortable(el, el.dataset.status);
      });
      const myday = this.$el.querySelector('[data-myday]');
      if (myday) this.initMyDaySortable(myday);
    },

    tasksFor(status) {
      return Alpine.store('tasks').items.filter(t => t.status === status);
    },

    // Manual drag order wins on My Day. items arrives sorted by sort_order from
    // the API and stays in that order after local reorders, so we just filter.
    myDayTasks() {
      const active = ['in_progress', 'feedback_received'];
      return Alpine.store('tasks').items.filter(t => active.includes(t.status));
    },

    // Read the destination list's card ids in their current DOM order.
    _cardIds(listEl) {
      return [...listEl.querySelectorAll('.task-card')].map(el => Number(el.dataset.taskId));
    },

    // Board: every column is a drop target (drag between columns changes status),
    // but only In Progress keeps a manually arranged order (sort: true).
    initColumnSortable(listEl, status) {
      Sortable.create(listEl, {
        group: 'board',
        draggable: '.task-card',
        sort: status === 'in_progress',
        animation: 150,
        onStart: () => Alpine.store('tasks').beginDrag(),
        onEnd: (evt) => {
          Alpine.store('tasks').endDrag();
          const taskId     = Number(evt.item.dataset.taskId);
          const fromStatus = evt.from.dataset.status;
          const toStatus   = evt.to.dataset.status;
          const toIds      = this._cardIds(evt.to);
          // Revert Sortable's cross-list DOM move so Alpine's x-for stays the
          // source of truth and re-renders both columns from the data.
          if (evt.from !== evt.to) evt.from.appendChild(evt.item);
          Alpine.store('tasks').handleBoardDrag(taskId, fromStatus, toStatus, toIds);
        },
      });
    },

    // My Day: single reorderable list, shares the persisted sort_order.
    initMyDaySortable(listEl) {
      Sortable.create(listEl, {
        group: 'myday',
        draggable: '.task-card',
        animation: 150,
        onStart: () => Alpine.store('tasks').beginDrag(),
        onEnd: (evt) => {
          Alpine.store('tasks').endDrag();
          Alpine.store('tasks').reorder(this._cardIds(evt.to));
        },
      });
    },
  }));

  Alpine.data('detailPanel', () => ({
    notes: '',
    feedbackNote: '',
    feedbackHandedTo: '',
    linkTitle: '',
    linkUrl: '',
    addingLink: false,
    editingLink: null,
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
        this.linkTitle = '';
        this.linkUrl = '';
        this.addingLink = false;
        this.editingLink = null;
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

    async addLink() {
      const title = this.linkTitle.trim();
      const url   = this.linkUrl.trim();
      if (!title || !url) return;
      await this.$store.tasks.addLink(this.task().id, title, url);
      this.linkTitle = '';
      this.linkUrl   = '';
      this.addingLink = false;
    },

    startEditLink(link) {
      this.editingLink = { ...link };
    },

    cancelEditLink() {
      this.editingLink = null;
    },

    async saveEditLink() {
      const title = this.editingLink.title.trim();
      const url   = this.editingLink.url.trim();
      if (!title || !url) return;
      await this.$store.tasks.updateLink(this.task().id, this.editingLink.id, title, url);
      this.editingLink = null;
    },

    async deleteLink(linkId) {
      if (!confirm('Remove this link?')) return;
      await this.$store.tasks.deleteLink(this.task().id, linkId);
    },
  }));
});

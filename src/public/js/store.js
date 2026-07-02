document.addEventListener('alpine:init', () => {
  Alpine.store('tasks', {
    items: [],
    view: 'board',
    detail: null,
    settingsOpen: false,
    syncing: false,
    syncMsg: '',
    syncError: false,
    settings: {},
    _skipClick: false,
    _flashTimer: null,

    // Show a transient status message. Errors persist; successes auto-clear
    // after 4s. Always cancels any pending clear so a stale success timer
    // can't wipe a message set after it.
    _flash(msg, isError = false) {
      this.syncMsg = msg;
      this.syncError = isError;
      clearTimeout(this._flashTimer);
      if (!isError) {
        this._flashTimer = setTimeout(() => { this.syncMsg = ''; this.syncError = false; }, 4000);
      }
    },

    async load() {
      this.items = await api.get('/tasks');
    },

    find(id) {
      return this.items.find(t => t.id === id) ?? null;
    },

    async openDetail(id) {
      const task = await api.get('/tasks/' + id);
      const idx = this.items.findIndex(t => t.id === id);
      if (idx !== -1) this.items[idx] = task;
      this.detail = id;
    },

    async quickAdd(input) {
      const title = input.value.trim();
      if (!title) return;
      await this.createTask(title);
      input.value = '';
      input.focus();
    },

    async createTask(title) {
      const task = await api.post('/tasks', { title });
      this.items.unshift(task);
      return task;
    },

    async updateTask(id, patch) {
      const task = await api.patch('/tasks/' + id, patch);
      this._replace(task);
      return task;
    },

    async deleteTask(id) {
      await api.delete('/tasks/' + id);
      this.items = this.items.filter(t => t.id !== id);
      if (this.detail === id) this.detail = null;
    },

    async sendFeedback(id, note, handedTo) {
      await api.post('/tasks/' + id + '/send-feedback', { note, handed_to: handedTo });
      await this.openDetail(id);
    },

    async importRm(input) {
      const rmId = input.value.trim();
      if (!rmId) return;
      this.syncing = true;
      this._flash('');
      try {
        const { task, created } = await api.post('/planio/import', { rm_id: parseInt(rmId, 10) });
        this._replace(task);
        input.value = '';
        this._flash((created ? 'Imported RM' : 'Refreshed RM') + rmId);
      } catch (e) {
        this._flash('Import failed: ' + e.message, true);
      } finally {
        this.syncing = false;
      }
    },

    async gotFeedback(id) {
      const task = await api.post('/tasks/' + id + '/got-feedback', {});
      this._replace(task);
    },

    async addLink(taskId, title, url) {
      await api.post('/tasks/' + taskId + '/links', { title, url });
      await this.openDetail(taskId);
    },

    async updateLink(taskId, linkId, title, url) {
      await api.put('/tasks/' + taskId + '/links/' + linkId, { title, url });
      await this.openDetail(taskId);
    },

    async deleteLink(taskId, linkId) {
      await api.delete('/tasks/' + taskId + '/links/' + linkId);
      await this.openDetail(taskId);
    },

    async sync() {
      this.syncing = true;
      this._flash('');
      try {
        const result = await api.get('/planio/sync');
        await this.load();
        this._flash('Synced: ' + result.imported + ' new, ' + result.updated + ' updated');
      } catch (e) {
        this._flash('Sync failed: ' + e.message, true);
      } finally {
        this.syncing = false;
      }
    },

    // A drag is starting: mark so the trailing click doesn't open the detail modal.
    beginDrag() {
      this._skipClick = true;
    },

    // Drag finished: keep suppressing the click that fires right after mouseup,
    // then re-enable normal clicks shortly after.
    endDrag() {
      setTimeout(() => { this._skipClick = false; }, 100);
    },

    // Persist a manual ordering for the given task ids (in the desired order).
    // Reorders the local list in place (keeping each id in one of the slots the
    // group currently occupies) and writes the new sort_order server-side.
    async reorder(orderedIds) {
      const idSet = new Set(orderedIds);
      const seq = orderedIds.map(id => this.items.find(t => t.id === id)).filter(Boolean);
      let k = 0;
      this.items = this.items.map(t => (idSet.has(t.id) ? seq[k++] : t));
      await api.post('/tasks/reorder', { ids: orderedIds });
    },

    // Handle a board drag: a status change when the card lands in a different
    // column, plus a manual reorder whenever the destination is In Progress.
    // `toIds` is the destination column's task ids in their new visual order.
    async handleBoardDrag(taskId, fromStatus, toStatus, toIds) {
      if (fromStatus !== toStatus) {
        await this.updateTask(taskId, { status: toStatus });
      }
      if (toStatus === 'in_progress') {
        await this.reorder(toIds);
      }
    },

    _replace(task) {
      const idx = this.items.findIndex(t => t.id === task.id);
      if (idx !== -1) this.items[idx] = task;
      else this.items.unshift(task);
    },
  });
});

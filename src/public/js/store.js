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
      this.syncMsg = '';
      this.syncError = false;
      try {
        const task = await api.post('/planio/import', { rm_id: parseInt(rmId, 10) });
        this._replace(task);
        this.syncMsg = 'Imported RM' + rmId;
        input.value = '';
        setTimeout(() => { this.syncMsg = ''; this.syncError = false; }, 4000);
      } catch (e) {
        this.syncError = true;
        this.syncMsg = 'Import failed: ' + e.message;
      } finally {
        this.syncing = false;
      }
    },

    async gotFeedback(id) {
      const task = await api.post('/tasks/' + id + '/got-feedback', {});
      this._replace(task);
    },

    async sync() {
      this.syncing = true;
      this.syncMsg = '';
      this.syncError = false;
      try {
        const result = await api.get('/planio/sync');
        this.syncMsg = 'Synced: ' + result.imported + ' new, ' + result.updated + ' updated';
        await this.load();
        setTimeout(() => { this.syncMsg = ''; this.syncError = false; }, 5000);
      } catch (e) {
        this.syncError = true;
        this.syncMsg = 'Sync failed: ' + e.message;
      } finally {
        this.syncing = false;
      }
    },

    _replace(task) {
      const idx = this.items.findIndex(t => t.id === task.id);
      if (idx !== -1) this.items[idx] = task;
      else this.items.unshift(task);
    },
  });
});

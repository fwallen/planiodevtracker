document.addEventListener('alpine:init', () => {
  Alpine.store('tasks', {
    items: [],
    view: 'board',
    detail: null,
    settingsOpen: false,
    syncing: false,
    syncMsg: '',
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

    async sendFeedback(id, note) {
      await api.post('/tasks/' + id + '/send-feedback', { note });
      await this.openDetail(id);
    },

    async gotFeedback(id) {
      const task = await api.post('/tasks/' + id + '/got-feedback', {});
      this._replace(task);
    },

    async sync() {
      this.syncing = true;
      this.syncMsg = '';
      try {
        const result = await api.get('/planio/sync');
        this.syncMsg = 'Synced: ' + result.imported + ' new, ' + result.updated + ' updated';
        await this.load();
        setTimeout(() => { this.syncMsg = ''; }, 5000);
      } catch (e) {
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

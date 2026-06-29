document.addEventListener('alpine:init', () => {
  Alpine.data('settingsPanel', () => ({
    form: {
      planio_base_url: '',
      planio_api_key: '',
      feedback_warning_days: '3',
    },
    statusMsg: '',
    statusOk: true,

    async load() {
      try {
        const s = await api.get('/settings');
        this.form.planio_base_url       = s.planio_base_url ?? '';
        this.form.planio_api_key        = s.planio_api_key ?? '';
        this.form.feedback_warning_days = s.feedback_warning_days ?? '3';
        Alpine.store('tasks').settings  = s;
      } catch (_) {}
    },

    async save() {
      try {
        await api.post('/settings', this.form);
        Alpine.store('tasks').settings = Object.assign({}, this.form);
        this.statusOk  = true;
        this.statusMsg = 'Settings saved.';
        setTimeout(() => {
          Alpine.store('tasks').settingsOpen = false;
          this.statusMsg = '';
        }, 800);
      } catch (e) {
        this.statusOk  = false;
        this.statusMsg = e.message;
      }
    },

    async testConnection() {
      try {
        const user     = await api.get('/planio/status');
        this.statusOk  = true;
        this.statusMsg = 'Connected as ' + user.firstname + ' ' + user.lastname;
      } catch (e) {
        this.statusOk  = false;
        this.statusMsg = 'Failed: ' + e.message;
      }
    },
  }));
});

/**
 * ተክሉ ጌታቸው ቢዝነስ - የኤፒአይ እና የማመሳሰያ ሞተር (API & Sync Client)
 */

const API = {
  baseUrl: './api',
  token: localStorage.getItem('teklu_token') || '',
  user: JSON.parse(localStorage.getItem('teklu_user') || 'null'),
  isOnline: navigator.onLine,
  deviceUuid: localStorage.getItem('teklu_device_uuid') || '',

  init() {
    if (!this.deviceUuid) {
      this.deviceUuid = 'dev-' + Math.random().toString(36).substring(2, 10) + '-' + Date.now();
      localStorage.setItem('teklu_device_uuid', this.deviceUuid);
    }

    // የመስመር ላይ እና ከመስመር ውጭ ክስተቶችን መከታተል
    window.addEventListener('online', () => {
      this.isOnline = true;
      this.updateStatusBadge();
      this.syncPending();
    });

    window.addEventListener('offline', () => {
      this.isOnline = false;
      this.updateStatusBadge();
    });

    // በየ 30 ሰከንዱ ማመሳሰልን ማረጋገጥ
    setInterval(() => {
      if (this.isOnline && this.token) {
        this.syncPending();
      }
      this.updateStatusBadge();
    }, 30000);

    this.updateStatusBadge();
  },

  async request(endpoint, options = {}) {
    const headers = {
      'Content-Type': 'application/json; charset=utf-8',
      'X-Device-Id': this.deviceUuid,
      ...(options.headers || {})
    };

    if (this.token) {
      headers['Authorization'] = `Bearer ${this.token}`;
    }

    const config = {
      ...options,
      headers
    };

    // 60s timeout: a cold Render free service can hang for a long time;
    // turn a hang into a visible error instead of an endless silent wait.
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 60000);
    config.signal = controller.signal;

    try {
      const response = await fetch(`${this.baseUrl}/${endpoint}`, config);
      clearTimeout(timeoutId);

      let data;
      try {
        data = await response.json();
      } catch (parseErr) {
        // Server returned non-JSON (e.g. a PHP fatal error -> 500 HTML).
        // Return a structured error instead of throwing, so one broken
        // endpoint never kills the whole initial-data chain in
        // loadInitialData() and leaves every list empty.
        return { success: false, error: 'የሰርቨር ስህተት (HTTP ' + response.status + ')' };
      }

      // ከመስመር ውጭ የ SW መልስ (HTTP 200, {offline:true}) ሲመጣ fetch
      // አይጠፋም — ስለዚህ እዚህ ላይ ነው የኦንላይን ምልክቱ የሚዘመነው፤
      // ካልሆነ badge እስከ 30 ሰከንድ interval ድረስ "online" ሆኖ ይቀራል።
      if (data && data.offline) {
        this.isOnline = false;
        this.updateStatusBadge();
      }

      if (!response.ok && response.status === 401) {
        // የተጠቃሚ ቶከን ካበቃ — ግን በመግቢያ ጥያቄ ላይ ወይም ቶከን በሌለበት
        // logout/reload አድርጎ ስህተቱን መደበቅ የለበትም። የስህተት ዳታውን
        // እንመልሳለን፤ handleLogin() ስህተቱን በ #login-error-alert ውስጥ ያሳያል።
        const isLoginRequest = endpoint.includes('auth.php') && endpoint.includes('action=login');
        if (this.token && !isLoginRequest) {
          // ቶከኑ ሲንክ በሚደረግበት ጊዜ ከሰርቨር ሲreject — reload ከመደረጉ
          // በፊት ምልክት አስቀምጥ፤ በመግቢያ ስክሪን ላይ "ክፍለ ጊዜው አልቋል፤
          // ኦንላይን ሆነው እንደገና ይግቡ" የሚለው መልእክት ይታያል። ያልተመሳሰሉ
          // ግብይቶች በ IndexedDB sync_queue ውስጥ ይቀመጣሉ — አይጠፉም።
          try { sessionStorage.setItem('teklu_session_expired', '1'); } catch (e) {}
          this.logout();
          throw new Error(data.error || 'ክፍለ ጊዜው አልቋል፤ እባክዎ እንደገና ይግቡ');
        }
        return data;
      }

      return data;
    } catch (err) {
      clearTimeout(timeoutId);
      // ኢንተርኔት ከሌለ ወይም ኔትወርክ ከተቋረጠ
      if (err.name === 'AbortError') {
        return { success: false, error: 'ሰርቨሩ መልስ መስጠት ረጅም ጊዜ ወስዷል (60 ሰከንድ)፤ እባክዎ እንደገና ይሞክሩ' };
      }
      if (!navigator.onLine || err.message.includes('Failed to fetch')) {
        this.isOnline = false;
        this.updateStatusBadge();
        return {
          success: false,
          offline: true,
          error: 'ኔትወርክ የለም (Offline Mode)'
        };
      }
      throw err;
    }
  },

  async login(username, password) {
    const res = await this.request('auth.php?action=login', {
      method: 'POST',
      body: JSON.stringify({
        username,
        password,
        device_uuid: this.deviceUuid,
        device_name: navigator.userAgent.includes('Mobile') ? 'Mobile Phone' : 'Browser App'
      })
    });

    if (res.success) {
      this.token = res.data.token;
      this.user = res.data.user;
      localStorage.setItem('teklu_token', this.token);
      localStorage.setItem('teklu_user', JSON.stringify(this.user));
      try { sessionStorage.removeItem('teklu_session_expired'); } catch (e) {}
      try { sessionStorage.removeItem('teklu_offline_session'); } catch (e) {}

      // ከመስመር ውጭ ለመጠቀም መሸጎጥ — best-effort ብቻ። IndexedDB ችግር
      // (ለምሳሌ "The database connection is closing") መግቢያው ስኬታማ
      // ሆኖ ሳለ እንደ ስህተት እንዳይታይ ማድረግ የለበትም።
      try {
        await DB.cacheAuth(this.token, this.user);
      } catch (cacheErr) {
        console.warn('Auth cache failed (ignored):', cacheErr);
      }
      // ከመስመር ውጭ መግቢያ ማረጋገጫ አድስ — የይለፍ ቃል ከተቀየረ ከመስመር
      // ውጭም አዲሱ ብቻ ነው የሚሰራው (አሮጌው verifier አይሰራም)።
      await this.cacheOfflineCredentials(username, password, this.user);
      return res;
    }

    // ኔትወርክ ብቻ ሲቋረጥ (ሰርቨሩ ሲደርስና ሲreject አይደለም) — በአካባቢው
    // የተቀመጠ ማረጋገጫ ሞክር። ሰርቨሩ የይለፍ ቃሉን ሲreject (401/403)
    // ወደዚህ አንደርስም — የተቀየረ የይለፍ ቃል በአሮጌው verifier ማለፍ አይችልም።
    if (res.offline) {
      return this.tryOfflineLogin(username, password);
    }
    return res;
  },

  // ከመስመር ውጭ መግቢያ — የይለፍ ቃል ማረጋገጫ (SHA-256፣ የተጠቃሚ ስም
  // እንደ salt)። 1000 ዙር ማዞር (iteration) — ከተሰረቀ መሣሪያ ላይ
  // በፍጥነት መሞከር (brute force) እንዳይቻል። በ cache እና በ login ጊዜ
  // ተመሳሳይ ስሌት ስለሚሰራ ውጤቱ ሁልጊዜ አንድ ነው። crypto.subtle
  // የሚገኘው በ secure context (https) ብቻ ነው — ካልተገኘ null ይመልሳል።
  async hashOfflinePassword(username, password) {
    if (!window.crypto || !crypto.subtle) return null;
    const enc = new TextEncoder();
    let buf = await crypto.subtle.digest('SHA-256', enc.encode(String(username).toLowerCase() + '\n' + password));
    for (let i = 1; i < 1000; i++) {
      buf = await crypto.subtle.digest('SHA-256', buf);
    }
    return Array.from(new Uint8Array(buf)).map((b) => b.toString(16).padStart(2, '0')).join('');
  },

  async cacheOfflineCredentials(username, password, user) {
    try {
      const verifier = await this.hashOfflinePassword(username, password);
      if (!verifier) return;
      await DB.cacheOfflineCredentials(username, verifier, user);
    } catch (e) {
      console.warn('Offline credentials cache failed (ignored):', e);
    }
  },

  // ከመስመር ውጭ መግቢያ — ሰርቨሩ በማይደረስበት ጊዜ ብቻ ነው የሚሞከረው
  // (API.login ውስጥ res.offline ሲሆን ብቻ)።
  async tryOfflineLogin(username, password) {
    try {
      const rec = await DB.getOfflineCredentials(username);
      if (!rec || !rec.verifier) {
        return { success: false, offline: true, error: 'ከመስመር ውጭ መግቢያ በዚህ መሣሪያ ላይ ገና አልተዘጋጀም፤ እባክዎ ኦንላይን ሆነው አንድ ጊዜ ይግቡ' };
      }
      const hash = await this.hashOfflinePassword(username, password);
      if (!hash || hash !== rec.verifier) {
        return { success: false, offline: true, error: 'የተሳሳተ የተጠቃሚ ስም ወይም የይለፍ ቃል' };
      }
      // ማረጋገጫ ተሳክቷል — የአካባቢ ክፍለ ጊዜ ፍጠር (ከመስመር ውጭ)
      this.user = { id: rec.user_id, username: rec.username, role: rec.role, full_name: rec.full_name };
      // ለኋላ ማመሳሰያ የመጨረሻውን የሰርቨር ቶከን እንደገና ተጠቀም
      let cachedToken = '';
      try {
        const cached = await DB.getCachedAuth();
        if (cached && cached.token) cachedToken = cached.token;
      } catch (e) { /* ቶከን ከሌለ sync እስኪገቡ ድረስ ይጠብቃል */ }
      this.token = cachedToken;
      this.isOnline = false;
      this.updateStatusBadge();
      localStorage.setItem('teklu_user', JSON.stringify(this.user));
      if (cachedToken) localStorage.setItem('teklu_token', cachedToken);
      else localStorage.removeItem('teklu_token');
      try { sessionStorage.setItem('teklu_offline_session', '1'); } catch (e) {}
      return { success: true, offline: true, data: { user: this.user } };
    } catch (e) {
      console.warn('Offline login failed:', e);
      return { success: false, offline: true, error: 'ከመስመር ውጭ መግቢያ አልተሳካም፤ እባክዎ ኦንላይን ይግቡ' };
    }
  },

  // Logout must also clear the IndexedDB auth cache: the boot code
  // restores a cached session when no localStorage token exists, so
  // clearing only localStorage left the user signed in after the reload
  // (logout "did nothing"). The reload happens only after the cached
  // auth delete settles (best-effort — a cache failure must not block
  // logout, localStorage is already cleared).
  async logout() {
    this.token = '';
    this.user = null;
    localStorage.removeItem('teklu_token');
    localStorage.removeItem('teklu_user');
    try {
      await DB.clearCachedAuth();
    } catch (e) {
      console.warn('Auth cache clear failed (ignored):', e);
    }
    window.location.reload();
  },

  // ያልተመሳሰሉትን ግብይቶች ወደ ሰርቨር መላክ (Batch Sync)
  async syncPending() {
    if (!this.isOnline || !this.token) return;

    const pending = await DB.getPendingTransactions();
    if (pending.length === 0) {
      this.updateStatusBadge();
      return;
    }

    try {
      const res = await this.request('sync.php', {
        method: 'POST',
        body: JSON.stringify({
          device_uuid: this.deviceUuid,
          transactions: pending
        })
      });

      if (res.success && res.data) {
        // ሲንክ ተሳክቷል — የሰርቨር ቶከኑ ይሰራል፤ ከመስመር ውጭ የመግቢያ
        // ምልክቱን አጽዳ (በኋላ 401 ቢመጣ session-expired መልእክቱ ይታያል)
        try { sessionStorage.removeItem('teklu_offline_session'); } catch (e) {}
        if (res.data.synced_uuids && res.data.synced_uuids.length > 0) {
          await DB.markTransactionsSynced(res.data.synced_uuids);
          // An offline-created food whose UUID sync.php confirmed is
          // on the server now: drop its local 'local-food-*' ghost row.
          // A uuid sync.php reported in errors[] was NOT saved — it
          // stays local so the food is not lost.
          const failedUuids = new Set((res.data.errors || []).map((e) => e.uuid));
          const confirmedUuids = res.data.synced_uuids.filter((u) => !failedUuids.has(u));
          if (confirmedUuids.length > 0) {
            await DB.pruneLocalRecipesByUuids(confirmedUuids).catch(() => {});
          }
        }

        if (res.data.server_state && res.data.server_state.products) {
          await DB.cacheProducts(res.data.server_state.products);
        }
      }
    } catch (e) {
      console.warn('Sync attempt failed:', e);
    } finally {
      this.updateStatusBadge();
    }
  },

  async updateStatusBadge() {
    const badge = document.getElementById('connection-badge');
    const syncCountEl = document.getElementById('pending-sync-count');
    if (!badge) return;

    const pendingCount = await DB.getPendingCount();

    if (syncCountEl) {
      syncCountEl.textContent = pendingCount > 0 ? `(${pendingCount})` : '';
    }

    if (this.isOnline) {
      badge.className = 'status-badge online';
      badge.innerHTML = `<span class="dot"></span> <span>${I18N.t('online')}</span> ${pendingCount > 0 ? `<span class="badge-pending">${pendingCount} ${I18N.t('pendingSync')}</span>` : ''}`;
    } else {
      badge.className = 'status-badge offline';
      badge.innerHTML = `<span class="dot"></span> <span>${I18N.t('offline')}</span> ${pendingCount > 0 ? `<span class="badge-pending">${pendingCount} ${I18N.t('pendingSync')}</span>` : ''}`;
    }
  }
};

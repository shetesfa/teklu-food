/**
 * ተክሉ ጌታቸው ቢዝነስ - የአካባቢ ዳታቤዝ አስተዳዳሪ (IndexedDB Manager)
 * ከመስመር ውጭ ለሚከናወኑ ስራዎች እና ለማመሳሰል ወረፋ
 */

const DB = {
  dbName: 'TekluBusinessDB',
  dbVersion: 1,
  instance: null,

  async init() {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(this.dbName, this.dbVersion);

      request.onupgradeneeded = (event) => {
        const db = event.target.result;

        // 1. የምርቶች ማከማቻ
        if (!db.objectStoreNames.contains('products')) {
          db.createObjectStore('products', { keyPath: 'id' });
        }

        // 2. የክምችት ማከማቻ (product_id + location_id)
        if (!db.objectStoreNames.contains('stock_levels')) {
          db.createObjectStore('stock_levels', { keyPath: 'id', autoIncrement: true });
        }

        // 3. የፋይናንስ ሂሳቦች
        if (!db.objectStoreNames.contains('financial_accounts')) {
          db.createObjectStore('financial_accounts', { keyPath: 'id' });
        }

        // 4. ደንበኞች እና አቅራቢዎች
        if (!db.objectStoreNames.contains('contacts')) {
          db.createObjectStore('contacts', { keyPath: 'id' });
        }

        // 5. ከመስመር ውጭ የማመሳሰያ ወረፋ (Offline Sync Queue)
        if (!db.objectStoreNames.contains('sync_queue')) {
          const syncStore = db.createObjectStore('sync_queue', { keyPath: 'uuid' });
          syncStore.createIndex('status', 'status', { unique: false });
          syncStore.createIndex('created_at', 'created_at', { unique: false });
        }

        // 6. ከመስመር ውጭ የተጠቃሚ መረጃ ማከማቻ
        if (!db.objectStoreNames.contains('auth_cache')) {
          db.createObjectStore('auth_cache', { keyPath: 'key' });
        }
      };

      request.onsuccess = (event) => {
        this.instance = event.target.result;
        resolve(this.instance);
      };

      request.onerror = (event) => {
        reject(event.target.error);
      };
    });
  },

  // 1. ያልተመሳሰለ ግብይት ወደ ወረፋ ማስገባት
  async enqueueTransaction(type, payload) {
    if (!this.instance) await this.init();
    const uuid = payload.uuid || ('offline-' + type + '-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9));
    payload.uuid = uuid;

    const item = {
      uuid: uuid,
      type: type,
      payload: payload,
      status: 'pending',
      created_at: new Date().toISOString()
    };

    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction('sync_queue', 'readwrite');
      const store = tx.objectStore('sync_queue');
      const req = store.put(item);
      req.onsuccess = () => resolve(item);
      req.onerror = () => reject(req.error);
    });
  },

  // 2. ያልተመሳሰሉትን ወረፋ ማግኘት
  async getPendingTransactions() {
    if (!this.instance) await this.init();
    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction('sync_queue', 'readonly');
      const store = tx.objectStore('sync_queue');
      const req = store.getAll();
      req.onsuccess = () => {
        const all = req.result || [];
        resolve(all.filter((it) => it.status === 'pending'));
      };
      req.onerror = () => reject(req.error);
    });
  },

  // 3. የተሳኩትን ግብይቶች ከወረፋ ማስወጣት ወይም status ማስተካከል
  async markTransactionsSynced(syncedUuids) {
    if (!this.instance || !syncedUuids || syncedUuids.length === 0) return;
    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction('sync_queue', 'readwrite');
      const store = tx.objectStore('sync_queue');
      for (const uuid of syncedUuids) {
        store.delete(uuid);
      }
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  },

  // 4. ያልተመሳሰሉ ግብይቶች ብዛት
  async getPendingCount() {
    const pending = await this.getPendingTransactions();
    return pending.length;
  },

  // 5. የአካባቢውን ክምችት ከመስመር ውጭ መቀነስ
  async adjustLocalProductStock(productId, delta) {
    if (!this.instance) await this.init();
    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction('products', 'readwrite');
      const store = tx.objectStore('products');
      const req = store.get(productId);
      req.onsuccess = () => {
        const prod = req.result;
        if (prod) {
          prod.total_stock = (prod.total_stock || 0) + delta;
          store.put(prod);
        }
        resolve();
      };
      req.onerror = () => reject(req.error);
    });
  },

  // 6. ሰርቨሩ ሲያመሳስል ምርቶችን እና ክምችትን ማደስ
  async cacheProducts(productsList) {
    if (!this.instance) await this.init();
    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction('products', 'readwrite');
      const store = tx.objectStore('products');
      for (const p of productsList) {
        store.put(p);
      }
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  },

  // 7. ከመስመር ውጭ ያሉ ምርቶችን ማምጣት
  async getLocalProducts() {
    if (!this.instance) await this.init();
    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction('products', 'readonly');
      const store = tx.objectStore('products');
      const req = store.getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error);
    });
  },

  // 8. የተጠቃሚ መረጃ መሸጎጥ (ከመስመር ውጭ ለመግባት)
  async cacheAuth(token, user) {
    if (!this.instance) await this.init();
    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction('auth_cache', 'readwrite');
      const store = tx.objectStore('auth_cache');
      store.put({ key: 'current_auth', token, user, saved_at: Date.now() });
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  },

  // 9. ከመስመር ውጭ የተሸጎጠ ተጠቃሚ ማግኘት
  async getCachedAuth() {
    if (!this.instance) await this.init();
    return new Promise((resolve) => {
      const tx = this.instance.transaction('auth_cache', 'readonly');
      const store = tx.objectStore('auth_cache');
      const req = store.get('current_auth');
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = () => resolve(null);
    });
  }
};
